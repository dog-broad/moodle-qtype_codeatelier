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

let sqlPromise = null;

/**
 * Lazily load sql.js (SQLite) once, from the given base URL. The loader is a UMD
 * module imported for its side effect (it defines self.initSqlJs).
 *
 * @param {String} baseurl the sql.js dist base URL (trailing slash)
 * @return {Promise<Object>} the SQL namespace (with .Database)
 */
const loadSqlRuntime = (baseurl) => {
    if (!sqlPromise) {
        // sql.js ships a UMD loader that does not attach initSqlJs to the worker
        // global when imported as a module, so fetch and evaluate it to capture
        // the factory (eval is permitted in the worker, as for JS execution).
        sqlPromise = fetch(baseurl + 'sql-wasm.js')
            .then((response) => response.text())
            .then((code) => {
                // eslint-disable-next-line no-new-func
                const initSqlJs = new Function(code + '\nreturn initSqlJs;')();
                return initSqlJs({locateFile: (file) => baseurl + file});
            });
    }
    return sqlPromise;
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

/**
 * Run one SQL case: load the schema + seed into a fresh database, then run the
 * student query (or, for a mutation, the student statements followed by the
 * checker query) and return the result set.
 *
 * @param {Object} sql the SQL namespace
 * @param {String} schemasql the schema + seed
 * @param {String} code the student SQL
 * @param {String} checkerquery optional verification query for mutations
 * @return {Object} {columns, rows} or {error}
 */
const runSql = (sql, schemasql, code, checkerquery) => {
    const db = new sql.Database();
    try {
        if (schemasql) {
            db.run(schemasql);
        }
        let res;
        if (checkerquery && checkerquery.trim() !== '') {
            db.run(code);
            res = db.exec(checkerquery);
        } else {
            res = db.exec(code);
        }
        const last = res.length ? res[res.length - 1] : {columns: [], values: []};
        return {columns: last.columns, rows: last.values};
    } catch (err) {
        return {error: String(err.message || err)};
    } finally {
        db.close();
    }
};

/**
 * Introspect the schema for the student-facing viewer: tables, their columns,
 * and a few sample rows each.
 *
 * @param {Object} sql the SQL namespace
 * @param {String} schemasql the schema + seed
 * @return {Object} {tables: [...]} or {error}
 */
const describeSchema = (sql, schemasql) => {
    const db = new sql.Database();
    try {
        if (schemasql) {
            db.run(schemasql);
        }
        const tres = db.exec(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        const names = tres.length ? tres[0].values.map((row) => String(row[0])) : [];
        const tables = names.map((name) => {
            const quoted = '"' + name.replace(/"/g, '""') + '"';
            const colres = db.exec('PRAGMA table_info(' + quoted + ')');
            const columns = colres.length
                ? colres[0].values.map((row) => ({name: row[1], type: row[2]}))
                : [];
            const sample = db.exec('SELECT * FROM ' + quoted + ' LIMIT 5');
            return {
                name: name,
                columns: columns,
                sampleColumns: sample.length ? sample[0].columns : columns.map((col) => col.name),
                sampleRows: sample.length ? sample[0].values : [],
            };
        });
        return {tables: tables};
    } catch (err) {
        return {error: String(err.message || err)};
    } finally {
        db.close();
    }
};

self.onmessage = async (event) => {
    const message = event.data;
    if (!message) {
        return;
    }

    if (message.type === 'describe') {
        if (!message.sqljsBaseUrl) {
            self.postMessage({type: 'describe', error: 'nosqljsurl'});
            return;
        }
        try {
            const sql = await loadSqlRuntime(message.sqljsBaseUrl);
            self.postMessage(Object.assign({type: 'describe'}, describeSchema(sql, message.schemasql)));
        } catch (err) {
            self.postMessage({type: 'describe', error: 'sqljsloadfailed: ' + String(err)});
        }
        return;
    }

    if (message.type !== 'run') {
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

    if (message.language === 'sql') {
        if (!message.sqljsBaseUrl) {
            self.postMessage({error: 'nosqljsurl', results: []});
            return;
        }
        self.postMessage({type: 'status', state: 'loading'});
        let sql;
        try {
            sql = await loadSqlRuntime(message.sqljsBaseUrl);
        } catch (err) {
            self.postMessage({error: 'sqljsloadfailed: ' + String(err), results: []});
            return;
        }
        self.postMessage({type: 'status', state: 'running'});
        const results = message.cases.map((testcase) => {
            return runSql(sql, message.schemasql, message.code, testcase.checkerquery);
        });
        self.postMessage({type: 'result', results: results});
        return;
    }

    self.postMessage({error: 'languagenotsupported', language: message.language, results: []});
};
