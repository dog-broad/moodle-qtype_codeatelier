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
 * This is the single abstraction both tiers sit behind: run(code, language,
 * cases, limits) -> results. The practice tier (this module) executes the code
 * in a terminable Web Worker so the UI thread never blocks and runaway code can
 * be killed. The judge tier (M2) will be a second implementation of the same
 * shape that calls a server sandbox instead.
 *
 * @module qtype_codeatelier/runner
 */

/** @var {number} default per-run wall-clock limit. */
const DEFAULT_TIMEOUT_MS = 5000;

/**
 * URL of the built worker script, served as a static file by Moodle.
 *
 * @return {String}
 */
const workerUrl = () => {
    return M.cfg.wwwroot + '/question/type/codeatelier/amd/build/worker.min.js';
};

/**
 * Run student code against a set of cases in a terminable Web Worker.
 *
 * The worker runs one job then is discarded. A host-side timer is the runaway
 * guard: a worker stuck in an infinite loop cannot process its own messages, so
 * the host terminates it and reports a timeout. Execution only — comparing
 * output against expected values is the grader's job, not the runner's.
 *
 * @param {String} code the student source
 * @param {String} language python, sql or javascript
 * @param {Array} cases the test cases, each at least {input}
 * @param {Object} limits optional, e.g. {timeoutMs}
 * @return {Promise<Object>} resolves to {results: [...]} or {timedout|error, results}
 */
const run = (code, language, cases, limits) => {
    const timeoutMs = (limits && limits.timeoutMs) || DEFAULT_TIMEOUT_MS;
    const jobcases = (cases && cases.length) ? cases : [{input: ''}];

    return new Promise((resolve) => {
        const worker = new Worker(workerUrl());
        let settled = false;

        const finish = (value) => {
            if (settled) {
                return;
            }
            settled = true;
            window.clearTimeout(timer);
            worker.terminate();
            resolve(value);
        };

        const timer = window.setTimeout(() => {
            finish({
                timedout: true,
                results: jobcases.map(() => ({timedout: true})),
            });
        }, timeoutMs);

        worker.onmessage = (event) => {
            finish(event.data);
        };
        worker.onerror = (event) => {
            finish({error: event.message || 'workererror', results: []});
        };

        worker.postMessage({type: 'run', language: language, code: code, cases: jobcases});
    });
};

export {run};
