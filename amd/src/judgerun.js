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
 * Judge-tier "run against examples": send the current code to the server, which
 * runs it in the sandbox against the question's VISIBLE example inputs and
 * returns the output. Formative only — no grade, no hidden tests. This gives
 * compiled-language students (no in-browser runtime) a feedback loop before the
 * authoritative submit.
 *
 * @module qtype_codeatelier/judgerun
 */

import Ajax from 'core/ajax';
import {setupFunctionArgs} from './argfields.js';
import {resultSetTable, extraRowKeys, parseRows} from './resulttable.js';

/**
 * @param {Object} config
 * @param {String} config.wrapperid the question wrapper element id
 * @param {Number} config.questionid the question id
 * @param {String} config.language the default language (when the student has not picked one)
 * @param {Object} config.strings localised labels
 */
export const init = (config) => {
    const root = document.getElementById(config.wrapperid);
    if (!root) {
        return;
    }
    const button = root.querySelector('[data-role="runexamples"]');
    const out = root.querySelector('[data-role="runresults"]');
    const answer = root.querySelector('[data-role="answer"]');
    if (!button || !out || !answer) {
        return;
    }
    const langField = root.querySelector('[data-role="language"]');
    const strings = config.strings || {};

    // The scenario (from the workbench config) decides how to render a result:
    // a SQL example's expected/actual arrive as JSON row arrays, rendered as a
    // table for parity with the practice tier rather than dumped as raw text.
    let scenario = '';
    try {
        scenario = JSON.parse(root.dataset.config || '{}').scenario || '';
    } catch (e) {
        scenario = '';
    }

    const language = () => (langField && langField.value) ? langField.value : config.language;

    // A compact Clear sits next to the Run button (shown only when there are
    // results) so dismissing them costs no vertical space.
    const clearBtn = root.querySelector('[data-role="runexamples-clear"]');
    if (clearBtn) {
        clearBtn.textContent = strings.clear || 'Clear';
        clearBtn.addEventListener('click', () => {
            out.textContent = '';
            clearBtn.hidden = true;
        });
    }

    button.addEventListener('click', () => {
        button.disabled = true;
        out.textContent = strings.running || 'Running…';
        Ajax.call([{
            methodname: 'qtype_codeatelier_run_examples',
            args: {questionid: config.questionid, code: answer.value, language: language()},
        }])[0].then((result) => {
            render(out, result, strings, scenario);
            if (clearBtn) {
                clearBtn.hidden = false;
            }
            button.disabled = false;
            return result;
        }).catch((error) => {
            out.textContent = (error && error.message) ? error.message : String(error);
            if (clearBtn) {
                clearBtn.hidden = false;
            }
            button.disabled = false;
        });
    });

    // "Run with my own input": run the current code once against the student's own
    // input (stdin, or a JSON argument array for the function scenario). Formative,
    // no grade — the judge-tier counterpart of the practice tier's Run verb.
    const scratchBtn = root.querySelector('[data-role="runscratch"]');
    const scratchIn = root.querySelector('[data-role="scratchinput"]');
    const scratchOut = root.querySelector('[data-role="scratchoutput"]');
    if (scratchBtn && scratchIn && scratchOut) {
        // Function questions get the same per-argument fields as the practice tier
        // (so a single list argument is [1, 2, 3], not [[1, 2, 3]]); the assembled
        // args array lands in scratchIn, which the run reads. The example tests and
        // entrypoint come from the workbench config on the wrapper.
        let wcfg = {};
        try {
            wcfg = JSON.parse(root.dataset.config || '{}');
        } catch (e) {
            wcfg = {};
        }
        if (wcfg.scenario === 'function') {
            const built = setupFunctionArgs(scratchIn, {
                wrapperid: config.wrapperid,
                entrypoint: wcfg.entrypoint,
                tests: wcfg.tests,
                strings: strings,
            });
            const label = scratchIn.closest('.codeatelier-scratch-judge');
            if (built && label) {
                const heading = label.querySelector('label');
                if (heading) {
                    heading.textContent = strings.argsheading || 'Run with your own arguments';
                }
            }
        }
        scratchBtn.addEventListener('click', () => {
            scratchBtn.disabled = true;
            scratchOut.textContent = strings.running || 'Running…';
            Ajax.call([{
                methodname: 'qtype_codeatelier_run_scratch',
                args: {questionid: config.questionid, code: answer.value,
                    language: language(), input: scratchIn.value},
            }])[0].then((result) => {
                renderScratch(scratchOut, result, strings);
                scratchBtn.disabled = false;
                return result;
            }).catch((error) => {
                scratchOut.textContent = (error && error.message) ? error.message : String(error);
                scratchBtn.disabled = false;
            });
        });
    }
};

/**
 * Render a single scratch run: compile error, runtime error output, or stdout.
 *
 * @param {HTMLElement} out
 * @param {Object} result {verdict, compileoutput, stdout, stderr}
 * @param {Object} strings
 */
/**
 * Append a labelled compiler-output block.
 *
 * @param {HTMLElement} out
 * @param {String} text
 * @param {Object} strings
 */
const compileBlock = (out, text, strings) => {
    const heading = document.createElement('p');
    heading.className = 'codeatelier-block-label';
    heading.textContent = strings.compileoutput || 'What the compiler reported:';
    out.appendChild(heading);
    out.appendChild(pre('codeatelier-compileoutput', text));
};

const renderScratch = (out, result, strings) => {
    out.textContent = '';
    if (result.verdict === 'CE' && result.compileoutput) {
        compileBlock(out, result.compileoutput, strings);
        return;
    }
    if (result.stderr && result.stderr.trim() !== '') {
        out.appendChild(pre('codeatelier-test-error', result.stderr));
    }
    const text = (result.stdout && result.stdout !== '') ? result.stdout : (strings.nooutput || '(no output)');
    out.appendChild(pre('codeatelier-diff-value', text));
};

/**
 * Render the run result into the output area.
 *
 * @param {HTMLElement} out
 * @param {Object} result
 * @param {Object} strings
 * @param {String} scenario the question scenario (sql renders result tables)
 */
const render = (out, result, strings, scenario) => {
    out.textContent = '';
    if (result.verdict === 'CE' && result.compileoutput) {
        compileBlock(out, result.compileoutput, strings);
        return;
    }
    const list = document.createElement('ul');
    list.className = 'codeatelier-judge-tests';
    result.examples.forEach((ex) => {
        list.appendChild(renderExample(ex, strings, scenario));
    });
    out.appendChild(list);
};

/**
 * A labelled SQL result-set table diff row (expected or got), with the rows that
 * differ from the other side highlighted — the same rendering the practice tier
 * uses, so both tiers show an identical table instead of raw CSV/JSON text.
 *
 * @param {String} label
 * @param {String} rowsjson the side's rows as a JSON array string
 * @param {String} otherjson the other side's rows (for diff highlighting)
 * @param {Object} strings
 * @return {HTMLElement|null} null when the value is not a JSON row array
 */
const sqlDiffRow = (label, rowsjson, otherjson, strings) => {
    const rows = parseRows(rowsjson);
    if (rows === null) {
        return null;
    }
    const other = parseRows(otherjson);
    const row = document.createElement('div');
    row.className = 'codeatelier-diff-row';
    const l = document.createElement('span');
    l.className = 'codeatelier-diff-label';
    l.textContent = label;
    row.appendChild(l);
    row.appendChild(resultSetTable(null, rows, other !== null ? extraRowKeys(rows, other) : null, {strings: strings}));
    return row;
};

/**
 * @param {Object} ex one example result
 * @param {Object} strings
 * @param {String} scenario the question scenario
 * @return {HTMLElement}
 */
const renderExample = (ex, strings, scenario) => {
    const li = document.createElement('li');
    li.className = 'codeatelier-judge-test ' + (ex.passed ? 'codeatelier-pass' : 'codeatelier-fail');

    const status = document.createElement('span');
    status.className = 'codeatelier-test-status codeatelier-status-' + (ex.passed ? 'pass' : ex.status);
    status.textContent = (strings.example || 'Example') + ' ' + (ex.index + 1) + ': '
        + (ex.passed ? (strings.pass || 'passed') : statusLabel(ex.status, strings));
    li.appendChild(status);

    if (!ex.passed) {
        if ((ex.status === 're' || ex.status === 'mle' || ex.status === 'tle') && ex.stderr) {
            li.appendChild(pre('codeatelier-test-error', ex.stderr));
        } else {
            const diff = document.createElement('div');
            diff.className = 'codeatelier-test-diff';
            // SQL: expected/actual are JSON row arrays — render result tables (with
            // differing rows highlighted), matching the practice tier. Fall back to
            // text if they are not parseable rows.
            const exprow = (scenario === 'sql') ? sqlDiffRow(strings.expected || 'Expected', ex.expected, ex.actual, strings) : null;
            const gotrow = (scenario === 'sql') ? sqlDiffRow(strings.got || 'Got', ex.actual, ex.expected, strings) : null;
            if (exprow && gotrow) {
                diff.appendChild(exprow);
                diff.appendChild(gotrow);
            } else {
                if (ex.input && ex.input.trim() !== '') {
                    diff.appendChild(diffRow(strings.input || 'Input', ex.input));
                }
                diff.appendChild(diffRow(strings.expected || 'Expected', ex.expected));
                diff.appendChild(diffRow(strings.got || 'Got', ex.actual));
            }
            li.appendChild(diff);
        }
    }
    return li;
};

/**
 * @param {String} status
 * @param {Object} strings
 * @return {String}
 */
const statusLabel = (status, strings) => {
    const map = {re: strings.re, tle: strings.tle, mle: strings.mle};
    return map[status] || strings.fail || 'failed';
};

/**
 * @param {String} label
 * @param {String} value
 * @return {HTMLElement}
 */
const diffRow = (label, value) => {
    const row = document.createElement('div');
    row.className = 'codeatelier-diff-row';
    const l = document.createElement('span');
    l.className = 'codeatelier-diff-label';
    l.textContent = label;
    row.appendChild(l);
    row.appendChild(pre('codeatelier-diff-value', value));
    return row;
};

/**
 * @param {String} cls
 * @param {String} text
 * @return {HTMLElement}
 */
const pre = (cls, text) => {
    const el = document.createElement('pre');
    el.className = cls;
    el.textContent = text;
    return el;
};
