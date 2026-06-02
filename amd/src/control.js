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
 * Wires the editor, runner and grader to the question's form fields, renders the
 * worked examples (from the visible test cases) and the diagnostic per-test
 * feedback after a Check. Hidden tests are only ever shown as pass/fail — their
 * expected/actual values never reach the DOM (the integrity boundary).
 *
 * @module qtype_codeatelier/control
 */

import {create} from 'qtype_codeatelier/editor';
import {run, describe} from 'qtype_codeatelier/runner';
import {grade} from 'qtype_codeatelier/grader';
import {tokenDiff} from 'qtype_codeatelier/diff';

/**
 * @param {String} value
 * @return {*} parsed JSON, or null on failure
 */
const parseJson = (value) => {
    try {
        return JSON.parse(value);
    } catch (e) {
        return null;
    }
};

/**
 * Build a <pre> showing one side of a diff: equal tokens plus the kept change
 * type (del for the expected side, ins for the actual side) highlighted.
 *
 * @param {Array} ops from tokenDiff
 * @param {String} keep 'del' or 'ins'
 * @return {HTMLElement}
 */
const diffPre = (ops, keep) => {
    const pre = document.createElement('pre');
    pre.className = 'codeatelier-diff';
    ops.forEach((op) => {
        if (op.type === 'equal' || op.type === keep) {
            const span = document.createElement('span');
            span.textContent = op.text;
            if (op.type === keep) {
                span.className = (keep === 'del') ? 'codeatelier-diff-del' : 'codeatelier-diff-ins';
            }
            pre.appendChild(span);
        }
    });
    return pre;
};

/**
 * Build a result-set table.
 *
 * @param {Array} columns optional column names
 * @param {Array} rows array of row arrays
 * @param {Object} extrakeys set of JSON-stringified rows to highlight
 * @return {HTMLElement}
 */
const resultSetTable = (columns, rows, extrakeys) => {
    const table = document.createElement('table');
    table.className = 'codeatelier-resultset';
    if (columns && columns.length) {
        const thead = document.createElement('thead');
        const tr = document.createElement('tr');
        columns.forEach((col) => {
            const th = document.createElement('th');
            th.textContent = col;
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);
    }
    const tbody = document.createElement('tbody');
    (rows || []).forEach((row) => {
        const tr = document.createElement('tr');
        if (extrakeys && extrakeys[JSON.stringify(row)]) {
            tr.className = 'codeatelier-diff-row';
        }
        row.forEach((value) => {
            const td = document.createElement('td');
            td.textContent = (value === null) ? 'NULL' : String(value);
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    return table;
};

/**
 * Rows present in `rows` but not in `other` (as a multiset by JSON key).
 *
 * @param {Array} rows
 * @param {Array} other
 * @return {Object} set of JSON keys
 */
const extraRowKeys = (rows, other) => {
    const counts = {};
    (other || []).forEach((row) => {
        const key = JSON.stringify(row);
        counts[key] = (counts[key] || 0) + 1;
    });
    const extra = {};
    (rows || []).forEach((row) => {
        const key = JSON.stringify(row);
        if (counts[key]) {
            counts[key]--;
        } else {
            extra[key] = true;
        }
    });
    return extra;
};

/**
 * Render the SQL schema + sample-data viewer.
 *
 * @param {HTMLElement} element
 * @param {Object} info {tables: [...]} from runner.describe
 * @param {Object} strings
 */
const renderSchema = (element, info, strings) => {
    element.textContent = '';
    if (!info || info.error || !info.tables || !info.tables.length) {
        return;
    }
    const heading = document.createElement('p');
    heading.className = 'codeatelier-schema-heading';
    heading.textContent = strings.schematables || 'Tables and sample data';
    element.appendChild(heading);
    info.tables.forEach((table) => {
        const details = document.createElement('details');
        details.className = 'codeatelier-schema-table';
        const summary = document.createElement('summary');
        summary.textContent = table.name + ' (' + table.columns.map((c) => c.name + ' ' + c.type).join(', ') + ')';
        details.appendChild(summary);
        details.appendChild(resultSetTable(table.sampleColumns, table.sampleRows, null));
        element.appendChild(details);
    });
};

/**
 * Format a function call for display: entrypoint(arg1, arg2).
 *
 * @param {String} entrypoint
 * @param {String} input JSON array of arguments
 * @return {String}
 */
const formatCall = (entrypoint, input) => {
    const args = parseJson(input);
    const list = Array.isArray(args) ? args.map((a) => JSON.stringify(a)).join(', ') : '';
    return (entrypoint || 'solve') + '(' + list + ')';
};

/**
 * Add a labelled block to a container.
 *
 * @param {HTMLElement} parent
 * @param {String} label
 * @param {HTMLElement|String} body
 */
const addBlock = (parent, label, body) => {
    const wrap = document.createElement('div');
    wrap.className = 'codeatelier-block';
    const lab = document.createElement('span');
    lab.className = 'codeatelier-block-label';
    lab.textContent = label;
    wrap.appendChild(lab);
    if (typeof body === 'string') {
        const pre = document.createElement('pre');
        pre.className = 'codeatelier-diff';
        pre.textContent = body;
        wrap.appendChild(pre);
    } else {
        wrap.appendChild(body);
    }
    parent.appendChild(wrap);
};

/**
 * Render the worked examples from the visible test cases.
 *
 * @param {HTMLElement} element
 * @param {Object} config
 * @param {Object} strings
 */
const renderExamples = (element, config, strings) => {
    element.textContent = '';
    const tests = config.tests || [];
    const visible = tests.filter((t) => Number(t.visibility) === 1);
    const hidden = tests.length - visible.length;
    if (!visible.length && !hidden) {
        return;
    }

    if (visible.length) {
        const heading = document.createElement('p');
        heading.className = 'codeatelier-examples-heading';
        heading.textContent = strings.examples || 'Examples';
        element.appendChild(heading);
    }

    visible.forEach((test, i) => {
        const block = document.createElement('div');
        block.className = 'codeatelier-example';
        const title = document.createElement('span');
        title.className = 'codeatelier-block-label';
        title.textContent = (strings.test || 'Test') + ' ' + (i + 1);
        block.appendChild(title);

        if (config.scenario === 'function') {
            addBlock(block, strings.call || 'Call', formatCall(config.entrypoint, test.input));
            addBlock(block, strings.expected || 'Expected', String(test.expected));
        } else if (config.scenario === 'sql') {
            const rows = parseJson(test.expected) || [];
            addBlock(block, strings.expected || 'Expected', resultSetTable(null, rows, null));
        } else {
            if (test.input) {
                addBlock(block, strings.input || 'Input', String(test.input));
            }
            addBlock(block, strings.expected || 'Expected', String(test.expected));
        }
        element.appendChild(block);
    });

    if (hidden) {
        const note = document.createElement('p');
        note.className = 'codeatelier-hidden-note';
        note.textContent = hidden + ' ' + (strings.hidden || 'hidden') + (hidden === 1 ? '' : 's');
        element.appendChild(note);
    }
};

/**
 * Render the diagnostic feedback for a graded run.
 *
 * @param {HTMLElement} element
 * @param {Object} graded from grade()
 * @param {Array} results from run()
 * @param {Object} config
 * @param {Object} strings
 */
const renderFeedback = (element, graded, results, config, strings) => {
    element.textContent = '';

    const pct = Math.round(graded.fraction * 1000) / 10;
    const header = document.createElement('p');
    header.className = 'codeatelier-score codeatelier-verdict-' + graded.verdict;
    header.textContent = (strings.score || 'Score') + ': ' + pct + '% (' + graded.verdict + ')';
    element.appendChild(header);

    let firstfailureopen = false;
    let hiddenpassed = 0;
    let hiddentotal = 0;

    graded.testresults.forEach((tr, index) => {
        if (!Number(tr.visibility)) {
            hiddentotal++;
            if (tr.passed) {
                hiddenpassed++;
            }
            return;
        }

        const result = results[index] || {};
        const details = document.createElement('details');
        details.className = 'codeatelier-feedback ' + (tr.passed ? 'codeatelier-pass' : 'codeatelier-fail');
        if (!tr.passed && !firstfailureopen) {
            details.open = true;
            firstfailureopen = true;
        }
        const summary = document.createElement('summary');
        summary.textContent = (strings.test || 'Test') + ' ' + (index + 1) + ': '
            + (tr.passed ? (strings.pass || 'passed') : (strings.fail || 'failed'));
        details.appendChild(summary);

        const body = document.createElement('div');
        body.className = 'codeatelier-feedback-body';

        if (result.error) {
            addBlock(body, strings.runerror || 'Error', String(result.error));
        } else if (!tr.passed) {
            renderMismatch(body, config, config.tests[index], result, strings);
        }
        details.appendChild(body);
        element.appendChild(details);
    });

    if (hiddentotal) {
        const note = document.createElement('p');
        note.className = 'codeatelier-hidden-note';
        note.textContent = (strings.hidden || 'Hidden') + ': ' + hiddenpassed + '/' + hiddentotal;
        element.appendChild(note);
    }
};

/**
 * Render an expected-vs-actual mismatch for one visible test, by scenario.
 *
 * @param {HTMLElement} body
 * @param {Object} config
 * @param {Object} test
 * @param {Object} result
 * @param {Object} strings
 */
const renderMismatch = (body, config, test, result, strings) => {
    if (config.scenario === 'sql') {
        const expectedrows = parseJson(test.expected) || [];
        const actualrows = result.rows || [];
        addBlock(body, strings.expected || 'Expected',
            resultSetTable(null, expectedrows, extraRowKeys(expectedrows, actualrows)));
        addBlock(body, strings.got || 'Got',
            resultSetTable(result.columns, actualrows, extraRowKeys(actualrows, expectedrows)));
        return;
    }

    let actualtext;
    if (config.scenario === 'function') {
        addBlock(body, strings.call || 'Call', formatCall(config.entrypoint, test.input));
        actualtext = (result.returnvalue === undefined || result.returnvalue === null)
            ? '' : String(result.returnvalue);
    } else {
        actualtext = (result.stdout === undefined || result.stdout === null) ? '' : String(result.stdout);
    }
    const ops = tokenDiff(String(test.expected), actualtext);
    addBlock(body, strings.expected || 'Expected', diffPre(ops, 'del'));
    addBlock(body, strings.got || 'Got', diffPre(ops, 'ins'));
};

/**
 * Initialise a CodeAtelier question.
 *
 * @param {Object} config
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
    const skeleton = root.querySelector('[data-role="skeleton"]');
    const examplesel = root.querySelector('[data-role="examples"]');
    const strings = config.strings || {};

    if (examplesel) {
        renderExamples(examplesel, config, strings);
    }

    let editor;
    try {
        editor = create(textarea, config.language, {
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
    } catch (e) {
        textarea.style.display = 'block';
        editor = {getValue: () => textarea.value};
    }
    if (skeleton) {
        skeleton.style.display = 'none';
    }

    const schemael = root.querySelector('[data-role="schema"]');
    if (config.scenario === 'sql' && schemael) {
        describe(config.sqljsbaseurl, config.schemasql).then((info) => {
            renderSchema(schemael, info, strings);
        });
    }

    if (config.readonly || !runbutton) {
        return;
    }

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
            sqljsBaseUrl: config.sqljsbaseurl,
            schemasql: config.schemasql,
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
                renderFeedback(resultsel, graded, result.results, config, strings);
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
