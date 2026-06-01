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

    const results = message.cases.map((testcase) => {
        return runJavascript(message.code, testcase.input || '');
    });
    self.postMessage({type: 'result', results: results});
};
