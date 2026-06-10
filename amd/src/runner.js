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
 * The practice-tier runner seam for qtype_codeatelier.
 *
 * The single abstraction both tiers sit behind: run(code, language, cases,
 * options) -> results. The practice tier (this module) executes in a terminable
 * ES-module Web Worker so the UI thread never blocks and runaway code can be
 * killed. One worker is kept per language and reused across runs and across
 * same-language questions on the page, so a heavy runtime (Pyodide) downloads
 * and initialises only once. Runs serialise per language.
 *
 * @module qtype_codeatelier/runner
 */

/** @var {number} default per-run execution limit (excludes runtime load time). */
const DEFAULT_TIMEOUT_MS = 5000;

/** @var {number} ceiling for first-run runtime download + initialisation. */
const INIT_TIMEOUT_MS = 60000;

/** @var {Object} live workers keyed by language. */
const workers = {};

/** @var {Object} per-language promise chains that serialise runs. */
const queues = {};

/** @var {Object} the in-flight run's resolver per language, for stop(). */
const pending = {};

/**
 * @return {String} URL of the built worker script (served statically by Moodle).
 */
const workerUrl = () => {
    return M.cfg.wwwroot + '/question/type/codeatelier/amd/build/worker.min.js';
};

/**
 * Get (creating if needed) the reusable module worker for a language.
 *
 * @param {String} language
 * @return {Worker}
 */
const getWorker = (language) => {
    if (!workers[language]) {
        workers[language] = new Worker(workerUrl(), {type: 'module'});
    }
    return workers[language];
};

/**
 * Terminate and forget a language's worker (recreated on the next run). Used
 * after a timeout or error, since a killed worker also loses its loaded runtime.
 *
 * @param {String} language
 */
const dropWorker = (language) => {
    if (workers[language]) {
        workers[language].terminate();
        delete workers[language];
    }
};

/**
 * Run one job on the (reused) worker for its language.
 *
 * @param {String} code
 * @param {String} language
 * @param {Array} jobcases
 * @param {Object} opts
 * @return {Promise<Object>}
 */
const runOnce = (code, language, jobcases, opts) => {
    const execTimeout = opts.timeoutMs || DEFAULT_TIMEOUT_MS;

    return new Promise((resolve) => {
        const worker = getWorker(language);
        let settled = false;
        let timer = window.setTimeout(onInitTimeout, INIT_TIMEOUT_MS);

        const finish = (value) => {
            if (settled) {
                return;
            }
            settled = true;
            delete pending[language];
            window.clearTimeout(timer);
            worker.onmessage = null;
            worker.onerror = null;
            resolve(value);
        };
        pending[language] = finish;

        /**
         * Give up waiting for the runtime to load and report the timeout.
         */
        function onInitTimeout() {
            dropWorker(language);
            finish({error: 'runtimeloadtimeout', results: []});
        }

        worker.onmessage = (event) => {
            const data = event.data;
            if (data && data.type === 'describe') {
                // Schema-introspection reply (see describe()); not a run result.
                return;
            }
            if (data && data.type === 'status') {
                if (data.state === 'running') {
                    // Runtime is ready; only now arm the execution timeout.
                    window.clearTimeout(timer);
                    timer = window.setTimeout(() => {
                        dropWorker(language);
                        finish({timedout: true, results: jobcases.map(() => ({timedout: true}))});
                    }, execTimeout);
                }
                if (typeof opts.onStatus === 'function') {
                    opts.onStatus(data.state);
                }
                return;
            }
            finish(data);
        };

        worker.onerror = () => {
            dropWorker(language);
            finish({error: 'workererror', results: []});
        };

        worker.postMessage({
            type: 'run',
            language: language,
            scenario: opts.scenario || 'stdinstdout',
            entrypoint: opts.entrypoint || null,
            pyodideBaseUrl: opts.pyodideBaseUrl || null,
            sqljsBaseUrl: opts.sqljsBaseUrl || null,
            schemasql: opts.schemasql || null,
            code: code,
            cases: jobcases,
        });
    });
};

/**
 * Run student code against a set of cases.
 *
 * Execution only — comparing output against expected values is the grader's job.
 * Runs of the same language serialise (one worker, one job at a time).
 *
 * @param {String} code the student source
 * @param {String} language python, sql or javascript
 * @param {Array} cases the test cases, each at least {input}
 * @param {Object} options optional {timeoutMs, scenario, entrypoint, pyodideBaseUrl, onStatus}
 * @return {Promise<Object>} resolves to {results: [...]} or {timedout|error, results}
 */
const run = (code, language, cases, options) => {
    const opts = options || {};
    const jobcases = (cases && cases.length) ? cases : [{input: ''}];
    const previous = queues[language] || Promise.resolve();
    const next = previous.catch(() => { /* Ignore a prior run's failure; the queue continues. */ })
        .then(() => runOnce(code, language, jobcases, opts));
    queues[language] = next;
    return next;
};

/**
 * Introspect a SQL question's schema for the student-facing viewer: returns
 * {tables: [{name, columns, sampleColumns, sampleRows}]} or {error}. Reuses the
 * persistent sql worker.
 *
 * @param {String} sqljsBaseUrl
 * @param {String} schemasql
 * @return {Promise<Object>}
 */
const describe = (sqljsBaseUrl, schemasql) => {
    return new Promise((resolve) => {
        const worker = getWorker('sql');
        let settled = false;
        const timer = window.setTimeout(() => {
            if (!settled) {
                settled = true;
                resolve({error: 'describetimeout'});
            }
        }, INIT_TIMEOUT_MS);
        const handler = (event) => {
            const data = event.data;
            if (!data || data.type !== 'describe' || settled) {
                return;
            }
            settled = true;
            window.clearTimeout(timer);
            worker.removeEventListener('message', handler);
            resolve(data);
        };
        worker.addEventListener('message', handler);
        worker.postMessage({type: 'describe', sqljsBaseUrl: sqljsBaseUrl, schemasql: schemasql});
    });
};

/**
 * Cancel the in-flight run for a language: terminate the worker (runaway guard,
 * user-driven) and resolve the pending run as aborted. The runtime reloads on
 * the next run.
 *
 * @param {String} language
 */
const stop = (language) => {
    dropWorker(language);
    if (pending[language]) {
        pending[language]({aborted: true, results: []});
    }
};

export {run, describe, stop};
