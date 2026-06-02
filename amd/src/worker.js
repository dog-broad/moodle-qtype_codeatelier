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
 * Runs in a Web Worker off the UI thread. It speaks a narrow protocol: it
 * receives a single {type:'run', language, code, cases} message and replies with
 * {type:'result', results} (or {error}). It only executes and captures output;
 * grading against expected values happens elsewhere. It does not (and cannot)
 * guard against runaway code itself — the host terminates it on timeout.
 *
 * This build executes native JavaScript only. The Python (Pyodide) and SQL
 * (sql.js) runtimes are added behind this same protocol in their own tasks; for
 * now they report that they are unsupported rather than failing silently.
 */

/* eslint-env worker */

/**
 * Execute one JavaScript case, capturing console.log output as stdout.
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
 * Execute one function-style case: call the named entry point with the case's
 * arguments (a JSON array) and capture its return value.
 *
 * @param {String} code the student source (defines the entry-point function)
 * @param {String} entrypoint the function name to call
 * @param {String} input the arguments as a JSON array
 * @return {Object} {stdout, returnvalue} or {stdout, error}
 */
const runFunction = (code, entrypoint, input) => {
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

self.onmessage = (event) => {
    const message = event.data;
    if (!message || message.type !== 'run') {
        return;
    }

    if (message.language !== 'javascript') {
        self.postMessage({
            error: 'languagenotsupported',
            language: message.language,
            results: [],
        });
        return;
    }

    const scenario = message.scenario || 'stdinstdout';
    const results = message.cases.map((testcase) => {
        if (scenario === 'function') {
            return runFunction(message.code, message.entrypoint, testcase.input || '');
        }
        return runJavascript(message.code, testcase.input || '');
    });
    self.postMessage({type: 'result', results: results});
};
