// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Practice-tier execution worker for qtype_codeatelier.
 *
 * Runs off the UI thread as an ES-module worker. It speaks a narrow protocol:
 * a {type:'run', language, scenario, ...} message produces zero or more
 * {type:'status', state} messages (loading/running) and finally a
 * {type:'result', results} (or {error}). It only executes and captures output;
 * grading happens on the main thread. It cannot guard against runaway code — the
 * host terminates it on timeout.
 *
 * JavaScript runs natively. Python runs via Pyodide, lazily imported from a
 * base URL supplied by the host and initialised once per worker.
 */

/* eslint-disable promise/avoid-new */

let pyodidePromise = null;

/**
 * Lazily import and initialise Pyodide once, from the given base URL.
 *
 * @param {String} baseurl the Pyodide distribution base URL (trailing slash)
 * @return {Promise<Object>} the Pyodide instance
 */
const loadPyodideRuntime = (baseurl) => {
    if (!pyodidePromise) {
        pyodidePromise = import(baseurl + 'pyodide.mjs').then((module) => {
            return module.loadPyodide({indexURL: baseurl});
        });
    }
    return pyodidePromise;
};

/**
 * Execute one stdin/stdout JavaScript case, capturing console.log as stdout.
 *
 * @param {String} code the student source
 * @param {String} input the case input, exposed to the code as `input`
 * @return {Object} {stdout, returnvalue} or {stdout, error}
 */
const runJavascript = (code, input) => {
    const lines = [];
    const sandboxconsole = {
        log: function() {
            lines.push(Array.prototype.slice.call(arguments).join(' '));
        },
    };
    try {
        // eslint-disable-next-line no-new-func
        const fn = new Function('input', 'console', code);
        const returnvalue = fn(input, sandboxconsole);
        return {
            stdout: lines.join('\n'),
            returnvalue: (returnvalue === undefined) ? null : String(returnvalue),
        };
    } catch (err) {
        return {stdout: lines.join('\n'), error: String(err)};
    }
};

/**
 * Execute one function-style JavaScript case.
 *
 * @param {String} code the student source (defines the entry point)
 * @param {String} entrypoint the function name to call
 * @param {String} input the arguments as a JSON array
 * @return {Object} {stdout, returnvalue} or {stdout, error}
 */
const runJavascriptFunction = (code, entrypoint, input) => {
    const lines = [];
    const sandboxconsole = {
        log: function() {
            lines.push(Array.prototype.slice.call(arguments).join(' '));
        },
    };
    let args;
    try {
        args = input ? JSON.parse(input) : [];
    } catch (err) {
        return {stdout: '', error: 'Invalid arguments JSON: ' + String(err)};
    }
    if (!Array.isArray(args)) {
        args = [args];
    }
    try {
        // eslint-disable-next-line no-new-func
        const factory = new Function('console', code + '\n; return ' + entrypoint + ';');
        const fn = factory(sandboxconsole);
        if (typeof fn !== 'function') {
            return {stdout: lines.join('\n'), error: 'Function "' + entrypoint + '" is not defined'};
        }
        const returnvalue = fn.apply(null, args);
        return {stdout: lines.join('\n'), returnvalue: returnvalue};
    } catch (err) {
        return {stdout: lines.join('\n'), error: String(err)};
    }
};

// Python wrapper: runs the student code with stdout captured and stdin fed from
// a StringIO, then exposes stdout, an optional traceback, and (for the function
// scenario) the JSON-encoded return value as module globals.
const PYTHON_WRAPPER = `
import sys, io, json, traceback
_ca_out = io.StringIO()
_ca_err = None
_ca_result = None
_ca_old_stdout = sys.stdout
_ca_old_stdin = sys.stdin
sys.stdin = io.StringIO(_ca_stdin)
sys.stdout = _ca_out
try:
    _ca_g = {'__name__': '__main__'}
    exec(_ca_code, _ca_g)
    if _ca_entry:
        _ca_result = json.dumps(_ca_g[_ca_entry](*_ca_args))
except Exception:
    _ca_err = traceback.format_exc()
finally:
    sys.stdout = _ca_old_stdout
    sys.stdin = _ca_old_stdin
_ca_stdout = _ca_out.getvalue()
`;

/**
 * Execute one Python case (stdin/stdout or function) via Pyodide.
 *
 * @param {Object} pyodide the Pyodide instance
 * @param {String} scenario stdinstdout or function
 * @param {String} code the student source
 * @param {String} entrypoint the function name (function scenario) or null
 * @param {String} input stdin text, or a JSON argument array for function scenario
 * @return {Object} {stdout, returnvalue} or {stdout, error}
 */
const runPython = (pyodide, scenario, code, entrypoint, input) => {
    let args = [];
    let stdin = '';
    if (scenario === 'function') {
        try {
            args = input ? JSON.parse(input) : [];
        } catch (err) {
            return {stdout: '', error: 'Invalid arguments JSON: ' + String(err)};
        }
        if (!Array.isArray(args)) {
            args = [args];
        }
    } else {
        stdin = input || '';
    }

    pyodide.globals.set('_ca_code', code);
    pyodide.globals.set('_ca_stdin', stdin);
    pyodide.globals.set('_ca_entry', scenario === 'function' ? entrypoint : null);
    pyodide.globals.set('_ca_args', pyodide.toPy(args));

    pyodide.runPython(PYTHON_WRAPPER);

    const stdout = pyodide.globals.get('_ca_stdout') || '';
    const error = pyodide.globals.get('_ca_err');
    const resultjson = pyodide.globals.get('_ca_result');

    if (error) {
        return {stdout: stdout, error: String(error)};
    }
    if (scenario === 'function') {
        return {stdout: stdout, returnvalue: (resultjson === undefined || resultjson === null) ? null : String(resultjson)};
    }
    return {stdout: stdout};
};

self.onmessage = async (event) => {
    const message = event.data;
    if (!message || message.type !== 'run') {
        return;
    }
    const scenario = message.scenario || 'stdinstdout';

    if (message.language === 'javascript') {
        self.postMessage({type: 'status', state: 'running'});
        const results = message.cases.map((testcase) => {
            return scenario === 'function'
                ? runJavascriptFunction(message.code, message.entrypoint, testcase.input || '')
                : runJavascript(message.code, testcase.input || '');
        });
        self.postMessage({type: 'result', results: results});
        return;
    }

    if (message.language === 'python') {
        if (!message.pyodideBaseUrl) {
            self.postMessage({error: 'nopyodideurl', results: []});
            return;
        }
        self.postMessage({type: 'status', state: 'loading'});
        let pyodide;
        try {
            pyodide = await loadPyodideRuntime(message.pyodideBaseUrl);
        } catch (err) {
            self.postMessage({error: 'pyodideloadfailed: ' + String(err), results: []});
            return;
        }
        self.postMessage({type: 'status', state: 'running'});
        const results = message.cases.map((testcase) => {
            return runPython(pyodide, scenario, message.code, message.entrypoint, testcase.input || '');
        });
        self.postMessage({type: 'result', results: results});
        return;
    }

    self.postMessage({error: 'languagenotsupported', language: message.language, results: []});
};
