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
 * Question controller for qtype_codeatelier.
 *
 * Wires the editor, the runner and the grader to the question's form fields:
 * the student's code lives in the 'answer' textarea; clicking run executes it
 * against the test cases, grades it, writes the fraction into the hidden 'score'
 * field, and shows per-test results. Editing after a run clears the score so the
 * student must re-run before submitting.
 *
 * @module qtype_codeatelier/control
 */

import {create} from 'qtype_codeatelier/editor';
import {run} from 'qtype_codeatelier/runner';
import {grade} from 'qtype_codeatelier/grader';

/**
 * Initialise a CodeAtelier question.
 *
 * @param {Object} config {wrapperid, language, tests, timeoutms, readonly, strings}
 */
export const init = (config) => {
    const root = document.getElementById(config.wrapperid);
    if (!root) {
        return;
    }

    const textarea = root.querySelector('[data-role="answer"]');
    const scoreinput = root.querySelector('[data-role="score"]');
    const runbutton = root.querySelector('[data-role="run"]');
    const statusel = root.querySelector('[data-role="status"]');
    const resultsel = root.querySelector('[data-role="results"]');
    const strings = config.strings || {};

    const editor = create(textarea, config.language, {
        readonly: config.readonly,
        onChange: () => {
            if (scoreinput) {
                scoreinput.value = '';
            }
            if (resultsel) {
                resultsel.textContent = '';
            }
        },
    });

    if (config.readonly || !runbutton) {
        return;
    }

    const renderResults = (graded) => {
        resultsel.textContent = '';

        const pct = Math.round(graded.fraction * 1000) / 10;
        const summary = document.createElement('p');
        summary.className = 'codeatelier-score';
        summary.textContent = (strings.score || 'Score') + ': ' + pct + '% (' + graded.verdict + ')';
        resultsel.appendChild(summary);

        const list = document.createElement('ul');
        list.className = 'codeatelier-testlist';
        graded.testresults.forEach((testresult, index) => {
            const item = document.createElement('li');
            const label = testresult.visibility
                ? (strings.test || 'Test') + ' ' + (index + 1)
                : (strings.hidden || 'Hidden test');
            const outcome = testresult.passed ? (strings.pass || 'passed') : (strings.fail || 'failed');
            item.textContent = label + ': ' + outcome;
            item.className = testresult.passed ? 'codeatelier-pass' : 'codeatelier-fail';
            list.appendChild(item);
        });
        resultsel.appendChild(list);
    };

    const showError = (detail) => {
        if (scoreinput) {
            scoreinput.value = '';
        }
        resultsel.textContent = (strings.runerror || 'Could not run your code') + ' (' + detail + ')';
    };

    runbutton.addEventListener('click', () => {
        runbutton.disabled = true;
        statusel.textContent = strings.running || 'Running...';
        resultsel.textContent = '';

        run(editor.getValue(), config.language, config.tests, {
            timeoutMs: config.timeoutms,
            scenario: config.scenario,
            entrypoint: config.entrypoint,
            pyodideBaseUrl: config.pyodidebaseurl,
            onStatus: (state) => {
                statusel.textContent = (state === 'loading')
                    ? (strings.loading || 'Preparing…')
                    : (strings.running || 'Running…');
            },
        })
            .then((result) => {
                statusel.textContent = '';
                if (result.error) {
                    showError(result.error);
                    return;
                }
                const graded = grade(result.results, config.tests);
                if (scoreinput) {
                    scoreinput.value = graded.fraction;
                }
                renderResults(graded);
            })
            .catch((err) => {
                statusel.textContent = '';
                showError(err);
            })
            .then(() => {
                runbutton.disabled = false;
            });
    });
};
