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

import {create, highlightWithin, resolveAutoTheme} from 'qtype_codeatelier/editor';
import {run, describe, stop} from 'qtype_codeatelier/runner';
import {grade} from 'qtype_codeatelier/grader';
import {tokenDiff} from 'qtype_codeatelier/diff';
import {setup as setupProjectWorkspace} from 'qtype_codeatelier/projectworkspace';
import {setUserPreference} from 'core_user/repository';
import {setupPreview, gradeWeb} from 'qtype_codeatelier/webruntime';
import {setupFunctionArgs} from './argfields.js';
import {resultSetTable, extraRowKeys} from './resulttable.js';

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
 * Turn a raw error into a student-facing message; runtime-load failures get a
 * friendly "degraded" message rather than an internal code.
 *
 * @param {String} detail
 * @param {Object} strings
 * @return {Object} {degraded, message}
 */
const friendlyError = (detail, strings) => {
    const text = String(detail);
    if (/loadfailed|nopyodideurl|nosqljsurl|runtimeloadtimeout/i.test(text)) {
        return {
            degraded: true,
            message: strings.degraded
                || 'The code runtime could not be loaded. Check your connection, or ask your teacher.',
        };
    }
    return {degraded: false, message: text};
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
        details.appendChild(resultSetTable(table.sampleColumns, table.sampleRows, null, {strings: strings}));
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
 * A value cell for the examples table. Short values render inline; long or
 * multi-line values are clamped to a few lines inside a native <details> so the
 * row stays compact but the full value is one click (and keyboard/SR) away.
 *
 * @param {String} text
 * @return {HTMLElement}
 */
const exampleValue = (text) => {
    const s = String(text === null || text === undefined ? '' : text);
    const pre = document.createElement('pre');
    pre.className = 'codeatelier-cell';
    pre.textContent = s;
    if (s.length <= 80 && s.indexOf('\n') < 0) {
        return pre;
    }
    pre.classList.add('codeatelier-clamp');
    const details = document.createElement('details');
    details.className = 'codeatelier-cell-expand';
    const summary = document.createElement('summary');
    summary.appendChild(pre);
    details.appendChild(summary);
    return details;
};

/**
 * Build the examples heading + a height-capped, scrollable, focusable region
 * wrapping a semantic table, and return its tbody to fill.
 *
 * @param {HTMLElement} element the examples container
 * @param {String} headingText e.g. "Examples (8)"
 * @param {String} ariaLabel the scroll-region label
 * @param {String[]|null} headers column headers, or null for a single-column table
 * @return {HTMLElement} the tbody
 */
const examplesScaffold = (element, headingText, ariaLabel, headers) => {
    const heading = document.createElement('p');
    heading.className = 'codeatelier-examples-heading';
    heading.textContent = headingText;
    element.appendChild(heading);

    const wrap = document.createElement('div');
    wrap.className = 'codeatelier-examples-wrap';
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-label', ariaLabel);
    wrap.tabIndex = 0;

    const table = document.createElement('table');
    table.className = 'codeatelier-examples-table';
    const caption = document.createElement('caption');
    caption.className = 'visually-hidden';
    caption.textContent = headingText;
    table.appendChild(caption);
    if (headers && headers.length) {
        const thead = document.createElement('thead');
        const tr = document.createElement('tr');
        headers.forEach((h) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = h;
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);
    }
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    wrap.appendChild(table);
    element.appendChild(wrap);
    return tbody;
};

/**
 * A "Use this input" button that loads an example into the scratch box.
 *
 * @param {HTMLElement} scratch the scratch input
 * @param {Object} test the test whose input loads
 * @param {Object} strings
 * @return {HTMLElement}
 */
const useInputButton = (scratch, test, strings) => {
    const use = document.createElement('button');
    use.type = 'button';
    use.className = 'btn btn-link btn-sm codeatelier-use-input';
    use.textContent = strings.useinput || 'Use this input';
    use.addEventListener('click', () => {
        // For a function question the scratch box is driven by per-argument fields;
        // distribute the example's argument array into them.
        if (typeof scratch.codeatelierSetArgs === 'function') {
            scratch.codeatelierSetArgs(String(test.input));
        } else {
            scratch.value = String(test.input);
            scratch.dispatchEvent(new Event('input', {bubbles: true}));
            scratch.focus();
        }
    });
    return use;
};

/**
 * A "Use this dataset" button for a SQL example: loads the example's own dataset
 * as the active Run dataset and runs the student's query against it, so the data
 * is revealed by running rather than dumped as DDL. Drives the override exposed on
 * the workbench root by init() (mirrors the codeatelierSetArgs pattern).
 *
 * @param {HTMLElement} root the workbench root carrying codeatelierUseDataset
 * @param {Object} test the test whose dataset loads
 * @param {String} label what to name the active dataset in the banner
 * @param {Object} strings
 * @return {HTMLElement}
 */
const useDatasetButton = (root, test, label, strings) => {
    const use = document.createElement('button');
    use.type = 'button';
    use.className = 'btn btn-link btn-sm codeatelier-use-dataset';
    use.textContent = strings.usedataset || 'Use this dataset';
    use.addEventListener('click', () => {
        if (typeof root.codeatelierUseDataset === 'function') {
            root.codeatelierUseDataset(String(test.datasetsql), label);
        }
    });
    return use;
};

/**
 * A value <td>, with an optional "Use this input" button (input/call cell only).
 *
 * @param {String} value
 * @param {HTMLElement|null} scratch the scratch input to load on click, or null
 * @param {Object|null} test the test whose input loads into the scratch box
 * @param {Object} strings
 * @return {HTMLElement}
 */
const exampleCell = (value, scratch, test, strings) => {
    const td = document.createElement('td');
    td.appendChild(exampleValue(value));
    if (scratch && test) {
        td.appendChild(useInputButton(scratch, test, strings));
    }
    return td;
};

/**
 * Build the examples heading + a height-capped, scrollable, focusable region for
 * a card layout (used by the multi-line scenarios), and return the region.
 *
 * @param {HTMLElement} element
 * @param {String} headingText
 * @param {String} ariaLabel
 * @return {HTMLElement}
 */
const examplesCardRegion = (element, headingText, ariaLabel) => {
    const heading = document.createElement('p');
    heading.className = 'codeatelier-examples-heading';
    heading.textContent = headingText;
    element.appendChild(heading);
    const wrap = document.createElement('div');
    wrap.className = 'codeatelier-examples-wrap codeatelier-examples-cards';
    wrap.setAttribute('role', 'region');
    wrap.setAttribute('aria-label', ariaLabel);
    wrap.tabIndex = 0;
    element.appendChild(wrap);
    return wrap;
};

/**
 * Append the "N hidden" note (below the scroll region so it is always visible).
 *
 * @param {HTMLElement} element
 * @param {Number} hidden
 * @param {Object} strings
 */
const hiddenNote = (element, hidden, strings) => {
    if (!hidden) {
        return;
    }
    const note = document.createElement('p');
    note.className = 'codeatelier-hidden-note';
    note.textContent = hidden + ' ' + (strings.hidden || 'hidden') + (hidden === 1 ? '' : 's');
    element.appendChild(note);
};

/**
 * Render the worked examples from the visible test cases as a compact,
 * scenario-aware, scrollable table (function: Call|Expected; stdin: Input|
 * Expected; sql: a result-set card per row).
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

    // The scratch input to load an example into ("Use this input"): the judge-tier
    // run-with-my-own-input box, or the practice-tier Run box. Absent on review and
    // for SQL (no per-input scratch), so the affordance simply does not appear.
    const wrapper = config.wrapperid ? document.getElementById(config.wrapperid) : null;
    const scratch = (wrapper && !config.readonly && config.scenario !== 'sql')
        ? wrapper.querySelector('[data-role="scratchinput"], [data-role="scratch"]')
        : null;

    if (visible.length) {
        const heading = (strings.examples || 'Examples') + ' (' + visible.length + ')';
        const region = strings.examplesregion || 'Worked examples';

        if (config.scenario === 'function') {
            // A function call and its return are short and naturally tabular.
            const tbody = examplesScaffold(element, heading, region,
                [strings.call || 'Call', strings.expected || 'Expected']);
            visible.forEach((test) => {
                const tr = document.createElement('tr');
                tr.appendChild(exampleCell(formatCall(config.entrypoint, test.input), scratch, test, strings));
                tr.appendChild(exampleCell(String(test.expected), null, null, strings));
                tbody.appendChild(tr);
            });
        } else {
            // stdin/stdout, SQL and project I/O is multi-line, so show full-width
            // Input / Expected boxes per example (the competitive-programming layout)
            // instead of cramming them into table columns.
            const wrap = examplesCardRegion(element, heading, region);
            visible.forEach((test, i) => {
                const card = document.createElement('div');
                card.className = 'codeatelier-example';
                const title = document.createElement('span');
                title.className = 'codeatelier-block-label';
                title.textContent = (strings.test || 'Test') + ' ' + (i + 1);
                card.appendChild(title);
                if (config.scenario === 'sql') {
                    addBlock(card, strings.expected || 'Expected',
                        resultSetTable(null, parseJson(test.expected) || [], null, {strings: strings}));
                    // An example with its own dataset doesn't dump the DDL; instead it
                    // offers to run the student's query against that data ("Use this
                    // dataset"), so the data is revealed by running. Practice tier only:
                    // the in-browser Run is what the dataset loads into, and the judge
                    // tier has no free-form SQL run to drive (the override is wired by
                    // the practice init, which the judge tier returns before reaching).
                    if (wrapper && !config.readonly && !config.isjudge
                            && test.datasetsql && String(test.datasetsql).trim() !== '') {
                        const label = (strings.test || 'Test') + ' ' + (i + 1);
                        card.appendChild(useDatasetButton(wrapper, test, label, strings));
                    }
                } else {
                    if (test.input) {
                        addBlock(card, strings.input || 'Input', String(test.input));
                    }
                    addBlock(card, strings.expected || 'Expected', String(test.expected));
                }
                if (scratch) {
                    card.appendChild(useInputButton(scratch, test, strings));
                }
                wrap.appendChild(card);
            });
        }
    }

    hiddenNote(element, hidden, strings);
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
        const test = config.tests[index];
        const status = (strings.test || 'Test') + ' ' + (index + 1) + ': '
            + (tr.passed ? (strings.pass || 'passed') : (strings.fail || 'failed'));
        // A passed test with no error and no teacher feedback has nothing to show
        // on expand, so render it as a plain checklist line, not an empty accordion.
        const hasbody = !!result.error || !tr.passed || !!(test && test.feedback);
        if (!hasbody) {
            const line = document.createElement('p');
            line.className = 'codeatelier-feedback-line codeatelier-pass';
            line.textContent = status;
            element.appendChild(line);
            return;
        }

        const details = document.createElement('details');
        details.className = 'codeatelier-feedback ' + (tr.passed ? 'codeatelier-pass' : 'codeatelier-fail');
        if (!tr.passed && !firstfailureopen) {
            details.open = true;
            firstfailureopen = true;
        }
        const summary = document.createElement('summary');
        summary.textContent = status;
        details.appendChild(summary);

        const body = document.createElement('div');
        body.className = 'codeatelier-feedback-body';

        if (result.error) {
            addBlock(body, strings.runerror || 'Error', String(result.error));
        } else if (!tr.passed) {
            renderMismatch(body, config, config.tests[index], result, strings);
        }
        if (test && test.feedback) {
            addBlock(body, strings.teacherfeedback || 'Feedback', String(test.feedback));
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
        // Label both tables with the query's column names so they line up and stay
        // readable. The stored Expected has no column names of its own, but its rows
        // share the query's shape, so reuse the result's columns — unless the column
        // counts differ (a wrong-shape answer), where headers would mislabel its
        // values, in which case Expected falls back to headerless.
        const expectedrows = parseJson(test.expected) || [];
        const actualrows = result.rows || [];
        const cols = result.columns || null;
        const expectedcols = (cols && expectedrows.length && expectedrows[0].length === cols.length)
            ? cols : null;
        // Grading feedback uses a generous cap so a differing row is never hidden
        // by truncation (small/medium result sets show in full; scroll handles the rest).
        const diffopts = {strings: strings, cap: 200};
        addBlock(body, strings.expected || 'Expected',
            resultSetTable(expectedcols, expectedrows, extraRowKeys(expectedrows, actualrows),
                {...diffopts, diffside: 'expected'}));
        addBlock(body, strings.got || 'Got',
            resultSetTable(cols, actualrows, extraRowKeys(actualrows, expectedrows),
                {...diffopts, diffside: 'got'}));
        const legend = document.createElement('p');
        legend.className = 'codeatelier-diff-legend';
        legend.textContent = strings.resultdifflegend || '';
        body.appendChild(legend);
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
 * Render a single scratch-Run result into the Console.
 *
 * @param {HTMLElement} element
 * @param {Object} result a single run result (or {aborted}/{error})
 * @param {Object} config
 * @param {Object} strings
 */
const renderConsole = (element, result, config, strings) => {
    element.textContent = '';
    if (!result) {
        return;
    }
    if (result.aborted) {
        addBlock(element, strings.output || 'Output', strings.stopped || 'Stopped.');
        return;
    }
    if (result.error) {
        addBlock(element, strings.runerror || 'Error', friendlyError(result.error, strings).message);
        return;
    }
    if (config.scenario === 'sql') {
        const rows = result.rows || [];
        if (rows.length || (result.columns && result.columns.length)) {
            addBlock(element, strings.output || 'Output', resultSetTable(result.columns, rows, null, {strings: strings}));
        } else {
            addBlock(element, strings.output || 'Output', strings.noresult || '(no rows)');
        }
        return;
    }
    let text;
    if (config.scenario === 'function') {
        text = (result.returnvalue === undefined || result.returnvalue === null) ? '' : String(result.returnvalue);
    } else {
        text = (result.stdout === undefined || result.stdout === null) ? '' : String(result.stdout);
    }
    addBlock(element, strings.output || 'Output', text);
};

/**
 * Describe one leaf condition in words, e.g. `#title · text equals "Todo"`.
 *
 * @param {Object} node a leaf {selector, property, matcher, expected}
 * @param {Object} strings
 * @return {String}
 */
const describeLeaf = (node, strings) => {
    const sel = node.selector || '';
    const prop = node.property || 'text';
    if (prop === 'exists') {
        const absent = /^(false|0|no)$/i.test(String(node.expected || '').trim());
        return sel + ' ' + (absent ? (strings.assertabsent || 'is absent') : (strings.assertexists || 'exists'));
    }
    const matcher = node.matcher || 'equals';
    const expected = (node.expected === null || node.expected === undefined) ? '' : node.expected;
    return sel + ' · ' + prop + ' ' + matcher + ' ' + JSON.stringify(String(expected));
};

/**
 * Describe an assertion node (leaf or compound) in words.
 *
 * @param {Object} node
 * @param {Object} strings
 * @return {String}
 */
const describeNode = (node, strings) => {
    if (!node) {
        return '';
    }
    if (node.all || node.any) {
        const arr = node.all || node.any;
        const word = node.all ? (strings.assertall || 'all of') : (strings.assertany || 'any of');
        return word + ': (' + arr.map((c) => describeNode(c, strings)).join('; ') + ')';
    }
    if (node.not) {
        return (strings.assertnotof || 'not') + ' (' + describeNode(node.not, strings) + ')';
    }
    return describeLeaf(node, strings);
};

/**
 * Describe a test's assertion: the compound tree when present, else the flat leaf.
 *
 * @param {Object} test the assertion row
 * @param {Object} strings
 * @return {String}
 */
const describeAssertion = (test, strings) => {
    if (test.assertion && typeof test.assertion === 'object') {
        return describeNode(test.assertion, strings);
    }
    return describeLeaf({selector: test.selector, property: test.assertproperty,
        matcher: test.assertmatcher, expected: test.expected}, strings);
};

/**
 * A per-condition pass/fail breakdown of a compound result, pairing the assertion
 * tree with its evaluator result so a student sees which sub-check failed.
 *
 * @param {Object} node the assertion node
 * @param {Object} result the matching evaluator result node
 * @param {Object} strings
 * @return {HTMLElement}
 */
const renderNodeBreakdown = (node, result, strings) => {
    const list = document.createElement('ul');
    list.className = 'codeatelier-checktree';
    const arr = node.all || node.any || (node.not ? [node.not] : null);
    const rarr = result && result.children ? result.children : [];
    if (!arr) {
        // Leaf.
        const li = document.createElement('li');
        li.className = (result && result.pass) ? 'codeatelier-pass' : 'codeatelier-fail';
        li.textContent = (result && result.pass ? '✓ ' : '✗ ') + describeLeaf(node, strings);
        list.appendChild(li);
        return list;
    }
    arr.forEach((child, i) => {
        const rchild = rarr[i];
        const li = document.createElement('li');
        const pass = rchild && rchild.pass;
        li.className = pass ? 'codeatelier-pass' : 'codeatelier-fail';
        const label = node.not ? (strings.assertnotof || 'not') : '';
        li.appendChild(document.createTextNode((pass ? '✓ ' : '✗ ') + (label ? label + ' ' : '')));
        if (child.all || child.any || child.not) {
            li.appendChild(renderNodeBreakdown(child, rchild, strings));
        } else {
            li.appendChild(document.createTextNode(describeLeaf(child, strings)));
        }
        list.appendChild(li);
    });
    return list;
};

/**
 * The selector of the first failing leaf in a compound result, for the highlight
 * button.
 *
 * @param {Object} node
 * @param {Object} result
 * @return {String}
 */
const firstFailingSelector = (node, result) => {
    if (!node) {
        return '';
    }
    const arr = node.all || node.any;
    if (arr) {
        const rarr = (result && result.children) || [];
        for (let i = 0; i < arr.length; i++) {
            if (!rarr[i] || !rarr[i].pass) {
                const sub = firstFailingSelector(arr[i], rarr[i]);
                if (sub) {
                    return sub;
                }
            }
        }
        return '';
    }
    if (node.not) {
        return node.not.selector || '';
    }
    return node.selector || '';
};

/**
 * Render the web question's visible assertions as the upfront "checks" list.
 *
 * @param {HTMLElement} element
 * @param {Object} config
 * @param {Object} strings
 */
const renderWebExamples = (element, config, strings) => {
    element.textContent = '';
    const tests = config.tests || [];
    const visible = tests.filter((t) => Number(t.visibility) === 1);
    const hidden = tests.length - visible.length;
    if (!visible.length && !hidden) {
        return;
    }
    if (visible.length) {
        const heading = (strings.checks || 'What is checked') + ' (' + visible.length + ')';
        const tbody = examplesScaffold(element, heading, strings.checks || 'What is checked', null);
        visible.forEach((test) => {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.appendChild(exampleValue(describeAssertion(test, strings)));
            if (test.actions && String(test.actions).trim()) {
                const after = document.createElement('div');
                after.className = 'codeatelier-example-after';
                const label = document.createElement('span');
                label.className = 'codeatelier-block-label';
                label.textContent = strings.assertafter || 'After doing';
                after.appendChild(label);
                after.appendChild(exampleValue(String(test.actions)));
                td.appendChild(after);
            }
            tr.appendChild(td);
            tbody.appendChild(tr);
        });
    }
    hiddenNote(element, hidden, strings);
};

/**
 * Render the diagnostic feedback for a web Check: per-assertion pass/fail with
 * the expected check and the actual value; hidden assertions stay pass/fail only.
 *
 * @param {HTMLElement} element
 * @param {Object} graded from gradeWeb
 * @param {Object} config
 * @param {Object} strings
 * @param {Function} [highlight] called with a selector to outline it in the preview
 */
const renderWebFeedback = (element, graded, config, strings, highlight) => {
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
        const test = config.tests[index] || {};
        const details = document.createElement('details');
        details.className = 'codeatelier-feedback ' + (tr.passed ? 'codeatelier-pass' : 'codeatelier-fail');
        if (!tr.passed && !firstfailureopen) {
            details.open = true;
            firstfailureopen = true;
        }
        const summary = document.createElement('summary');
        summary.textContent = (strings.check || 'Check') + ' ' + (index + 1) + ': '
            + (tr.passed ? (strings.pass || 'passed') : (strings.fail || 'failed'));
        details.appendChild(summary);

        const body = document.createElement('div');
        body.className = 'codeatelier-feedback-body';
        const compound = test.assertion && typeof test.assertion === 'object';
        addBlock(body, strings.assertexpect || 'Expect', describeAssertion(test, strings));
        if (!tr.passed) {
            if (compound) {
                // Show which sub-condition failed, not a single "Got" value.
                body.appendChild(renderNodeBreakdown(test.assertion, tr.result, strings));
            } else {
                const got = (tr.actual === null || tr.actual === undefined)
                    ? (strings.assertnotfound || '(element not found)') : String(tr.actual);
                addBlock(body, strings.got || 'Got', got);
            }
            const hlsel = test.selector || (compound ? firstFailingSelector(test.assertion, tr.result) : '');
            if (highlight && hlsel) {
                const hl = document.createElement('button');
                hl.type = 'button';
                hl.className = 'btn btn-link btn-sm codeatelier-highlight';
                hl.textContent = strings.showinpreview || 'Show in preview';
                hl.addEventListener('click', () => highlight(hlsel));
                body.appendChild(hl);
            }
        }
        if (test.feedback) {
            addBlock(body, strings.teacherfeedback || 'Feedback', String(test.feedback));
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
 * Wire the editor theme controller for a question: resolve the effective theme
 * (following the page when the choice is "auto"), set data-ca-theme on the
 * wrapper so the surrounding panels theme too, persist a picker change as a
 * Moodle user preference, and keep the (possibly re-created) editor in sync.
 *
 * @param {HTMLElement} root the question wrapper
 * @param {Object} config the question config
 * @return {Object} {currentEffective, setEditor}
 */
const setupThemeControl = (root, config) => {
    let chosen = config.editortheme || 'auto';
    let editorRef = null;
    const effectiveOf = (mode) => (mode === 'auto' ? resolveAutoTheme() : mode);
    const apply = () => {
        const effective = effectiveOf(chosen);
        root.dataset.caTheme = effective;
        if (editorRef && typeof editorRef.setTheme === 'function') {
            editorRef.setTheme(effective);
        }
    };
    root.dataset.caTheme = effectiveOf(chosen);

    const select = root.querySelector('[data-role="editor-theme"]');
    if (select) {
        select.value = chosen;
        select.addEventListener('change', () => {
            chosen = select.value;
            setUserPreference('qtype_codeatelier_editortheme', chosen).catch(() => {
                // A failed persist is non-fatal — the theme still applies this attempt.
            });
            apply();
        });
    }

    // "Auto" follows the OS colour scheme too: re-resolve if it flips mid-attempt.
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onSchemeChange = () => {
            if (chosen === 'auto') {
                apply();
            }
        };
        if (mq.addEventListener) {
            mq.addEventListener('change', onSchemeChange);
        } else if (mq.addListener) {
            mq.addListener(onSchemeChange);
        }
    }

    return {
        currentEffective: () => effectiveOf(chosen),
        setEditor: (ref) => {
            editorRef = ref;
        },
    };
};

/**
 * Initialise a CodeAtelier question.
 *
 * The bulky config (visible tests, UI strings, per-language starters) is carried
 * on the wrapper's data-config attribute rather than the AMD init args, which
 * Moodle caps at 1024 chars. Only the wrapper id arrives through params.
 *
 * @param {Object} params - {wrapperid}
 */
export const init = (params) => {
    const root = document.getElementById(params.wrapperid);
    if (!root || !root.dataset.config) {
        return;
    }
    const config = JSON.parse(root.dataset.config);

    // Syntax-highlight any code blocks the author put in the question prompt.
    highlightWithin(root.querySelector('.qtext') || root);

    const textarea = root.querySelector('[data-role="answer"]');
    const scoreinput = root.querySelector('[data-role="score"]');
    const runbutton = root.querySelector('[data-role="run"]');
    const checkbutton = root.querySelector('[data-role="check"]');
    const stopbutton = root.querySelector('[data-role="stop"]');
    const statusel = root.querySelector('[data-role="status"]');
    const resultsel = root.querySelector('[data-role="results"]');
    const consoleel = root.querySelector('[data-role="console"]');
    const skeleton = root.querySelector('[data-role="skeleton"]');
    const examplesel = root.querySelector('[data-role="examples"]');
    const scratchwrap = root.querySelector('[data-role="scratchwrap"]');
    const scratchinput = root.querySelector('[data-role="scratch"]');
    const scratchlabel = root.querySelector('[data-role="scratchlabel"]');
    const strings = config.strings || {};

    // Editor theme: set data-ca-theme on the wrapper (panels) and track the
    // editor so the picker can swap both. Runs for every scenario, incl. review.
    const themeCtl = setupThemeControl(root, config);

    // The web (frontend) scenario is a file-set editor next to a sandboxed live
    // preview, graded by DOM assertions in the frame. It wires its own panels and
    // returns rather than falling through the code-runner (stdin) controls.
    if (config.scenario === 'web') {
        const previewel = root.querySelector('[data-role="preview"]');
        const preview = previewel ? setupPreview(previewel, textarea, config, strings) : null;
        // Editing clears the last Check score (it no longer matches the page).
        const webStale = () => {
            if (scoreinput) {
                scoreinput.value = '';
            }
            if (resultsel && resultsel.textContent.trim()) {
                resultsel.textContent = '';
                const note = document.createElement('p');
                note.className = 'codeatelier-stale';
                note.textContent = strings.stale
                    || 'You have edited your files since the last check — Check again.';
                resultsel.appendChild(note);
            }
        };
        const webeditor = setupProjectWorkspace(root, textarea, config, strings, () => {
            if (preview) {
                preview.scheduleRefresh();
            }
            webStale();
        }, () => themeCtl.currentEffective());
        themeCtl.setEditor(webeditor);
        if (skeleton) {
            skeleton.style.display = 'none';
        }
        const webfindbtn = root.querySelector('[data-role="editor-find"]');
        if (webfindbtn) {
            webfindbtn.addEventListener('click', () => {
                if (webeditor && typeof webeditor.openSearch === 'function') {
                    webeditor.openSearch();
                }
            });
        }
        if (examplesel) {
            renderWebExamples(examplesel, config, strings);
        }
        if (config.readonly || !checkbutton) {
            return;
        }
        checkbutton.addEventListener('click', () => {
            checkbutton.disabled = true;
            statusel.textContent = strings.running || 'Running…';
            resultsel.textContent = '';
            let files = [];
            try {
                files = JSON.parse(webeditor.getValue() || '[]');
            } catch (e) {
                files = [];
            }
            gradeWeb(files, config.tests, {allOrNothing: config.allornothing})
                .then((graded) => {
                    if (scoreinput) {
                        scoreinput.value = graded.fraction;
                    }
                    renderWebFeedback(resultsel, graded, config, strings,
                        preview ? (sel) => preview.highlight(sel) : null);
                })
                .catch((err) => {
                    resultsel.textContent = '';
                    const para = document.createElement('p');
                    para.className = 'codeatelier-runerror';
                    para.textContent = (strings.runerror || 'Could not run the checks') + ' (' + String(err) + ')';
                    resultsel.appendChild(para);
                })
                .then(() => {
                    checkbutton.disabled = false;
                    statusel.textContent = '';
                });
        });
        return;
    }

    if (examplesel) {
        renderExamples(examplesel, config, strings);
    }

    const langselect = root.querySelector('[data-role="language-select"]');
    const langhidden = root.querySelector('[data-role="language"]');
    // The language in effect for this attempt; the student may change it below.
    let currentLanguage = config.language;
    if (langhidden && !langhidden.value) {
        langhidden.value = currentLanguage;
    }

    const editorlabel = root.querySelector('.codeatelier-editor label');
    const markStale = () => {
        if (scoreinput) {
            scoreinput.value = '';
        }
        if (resultsel && resultsel.textContent.trim()) {
            // Stale: the shown results no longer match the edited code.
            resultsel.textContent = '';
            const note = document.createElement('p');
            note.className = 'codeatelier-stale';
            note.textContent = strings.stale
                || 'You have edited your code since the last check — Run or Check again.';
            resultsel.appendChild(note);
        }
    };
    // Secure (judge) tier: the editor runs a sandboxed in-memory clipboard, and an
    // external-paste/drop attempt is reported to the proctor module (the single
    // reporter to the server-side event log) via a CustomEvent on the question wrapper.
    const sandbox = !!config.isjudge && !config.readonly;
    const editorOptions = {
        readonly: config.readonly,
        ariaLabel: editorlabel ? editorlabel.textContent : null,
        onChange: markStale,
        sandbox: sandbox,
        onExternal: sandbox
            ? (type) => root.dispatchEvent(new CustomEvent('codeatelier:clip-external', {detail: {type: type}}))
            : null,
        theme: themeCtl.currentEffective(),
    };
    let editor;
    if (config.scenario === 'project') {
        // A multi-file project: the editor is a two-pane file explorer over one
        // CodeMirror, keeping the hidden answer field synced to the canonical JSON
        // file set.
        editor = setupProjectWorkspace(root, textarea, config, strings, null, () => themeCtl.currentEffective());
    } else {
        try {
            editor = create(textarea, currentLanguage, editorOptions);
        } catch (e) {
            textarea.style.display = 'block';
            editor = {getValue: () => textarea.value};
        }
    }
    themeCtl.setEditor(editor);
    if (skeleton) {
        skeleton.style.display = 'none';
    }

    // Visible find/replace control — the touch-friendly path to Ctrl/Cmd+F. Reads
    // the current editor (it is re-created on a language switch).
    const findbtn = root.querySelector('[data-role="editor-find"]');
    if (findbtn) {
        findbtn.addEventListener('click', () => {
            if (editor && typeof editor.openSearch === 'function') {
                editor.openSearch();
            }
        });
    }

    // Reformat: re-indent the whole document per the language's indentation rules.
    const formatbtn = root.querySelector('[data-role="editor-format"]');
    if (formatbtn) {
        formatbtn.addEventListener('click', () => {
            if (editor && typeof editor.reindent === 'function') {
                editor.reindent();
                markStale();
            }
        });
    }

    // Language picker: re-mount the editor in the chosen language and record the
    // choice for submit / run / run-examples. Each language keeps its OWN code in a
    // per-language buffer (like a multi-language CP editor), so switching language
    // never carries code across or discards it — switching back restores that
    // language's work, and the first visit to a language shows its starter.
    if (langselect && config.scenario !== 'project') {
        const codeByLang = {};
        codeByLang[currentLanguage] = editor.getValue();
        langselect.addEventListener('change', () => {
            const next = langselect.value;
            if (next === currentLanguage) {
                return;
            }
            const starters = config.starters || {};
            codeByLang[currentLanguage] = editor.getValue();
            const nextValue = (next in codeByLang) ? codeByLang[next] : (starters[next] || '');
            try {
                if (editor.destroy) {
                    editor.destroy();
                }
                textarea.value = nextValue;
                editorOptions.theme = themeCtl.currentEffective();
                editor = create(textarea, next, editorOptions);
                themeCtl.setEditor(editor);
            } catch (e) {
                textarea.style.display = 'block';
                textarea.value = nextValue;
                editor = {getValue: () => textarea.value};
            }
            currentLanguage = next;
            if (langhidden) {
                langhidden.value = next;
            }
            markStale();
        });
    }

    // A multi-language project: each language has its own file environment. Switching
    // language loads that language's starter file set (keeping per-language edits in a
    // buffer so switching back restores the student's work) and re-mounts the file
    // editor so per-file highlighting + the entry use the new language.
    if (langselect && config.scenario === 'project') {
        const filesByLang = {};
        filesByLang[currentLanguage] = textarea.value;
        langselect.addEventListener('change', () => {
            const next = langselect.value;
            if (next === currentLanguage) {
                return;
            }
            const starters = config.projectstarters || {};
            filesByLang[currentLanguage] = editor.getValue ? editor.getValue() : textarea.value;
            const nextValue = (next in filesByLang) ? filesByLang[next] : (starters[next] || '[]');
            if (editor.destroy) {
                editor.destroy();
            }
            textarea.value = nextValue;
            currentLanguage = next;
            if (langhidden) {
                langhidden.value = next;
            }
            const cfg = Object.assign({}, config, {language: next});
            editor = setupProjectWorkspace(root, textarea, cfg, strings, null, () => themeCtl.currentEffective());
            themeCtl.setEditor(editor);
            markStale();
        });
    }

    const schemael = root.querySelector('[data-role="schema"]');
    if (config.scenario === 'sql' && schemael) {
        describe(config.sqljsbaseurl, config.schemasql).then((info) => {
            renderSchema(schemael, info, strings);
        });
    }

    if (config.readonly || !checkbutton) {
        return;
    }

    // Scenario-shaped scratch input for Run. A function question gets per-argument
    // fields (shared with the judge tier) instead of one raw args-array box.
    const buildArgFields = () => setupFunctionArgs(scratchinput, {
        wrapperid: config.wrapperid,
        entrypoint: config.entrypoint,
        tests: config.tests,
        strings: strings,
    });
    if (scratchwrap) {
        if (config.scenario === 'sql') {
            scratchwrap.style.display = 'none';
        } else if (config.scenario === 'function' && buildArgFields()) {
            scratchwrap.style.display = '';
            scratchlabel.textContent = strings.argsheading || 'Run with your own arguments';
        } else {
            scratchwrap.style.display = '';
            scratchlabel.textContent = (config.scenario === 'function')
                ? (strings.argslabel || 'Arguments (JSON)')
                : (strings.input || 'Input');
            if (scratchinput && !scratchinput.value) {
                scratchinput.placeholder = (config.scenario === 'function')
                    ? (strings.scratchplaceholderfn
                        || 'e.g. [[1, 2, 3]] — or click "Use this input" on an example below')
                    : (strings.scratchplaceholderio
                        || 'Type the program input here, or click "Use this input" on an example below');
            }
        }
    }

    // SQL "Use this dataset": a per-run dataset override for the Run (default = the
    // question schema). A visible example loads its own dataset here and Run executes
    // the student's query against it; the banner shows what's active and reverts.
    let activeDataset = null;
    let datasetBanner = null;

    // Console | Tests tabs (WAI-ARIA tabs: roving tabindex + arrow-key navigation).
    const tabs = Array.prototype.slice.call(root.querySelectorAll('[data-role="tab"]'));
    const setTab = (name) => {
        if (consoleel) {
            consoleel.style.display = (name === 'console') ? '' : 'none';
        }
        if (datasetBanner) {
            datasetBanner.style.display = (name === 'console' && activeDataset) ? '' : 'none';
        }
        resultsel.style.display = (name === 'tests') ? '' : 'none';
        tabs.forEach((tab) => {
            const selected = tab.getAttribute('data-tab') === name;
            tab.classList.toggle('codeatelier-tab-active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => setTab(tab.getAttribute('data-tab')));
        tab.addEventListener('keydown', (e) => {
            let target = null;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                target = tabs[(index + 1) % tabs.length];
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                target = tabs[(index - 1 + tabs.length) % tabs.length];
            } else if (e.key === 'Home') {
                target = tabs[0];
            } else if (e.key === 'End') {
                target = tabs[tabs.length - 1];
            }
            if (target) {
                e.preventDefault();
                setTab(target.getAttribute('data-tab'));
                target.focus();
            }
        });
    });
    // Output starts empty: hide the tabs + panels behind a single call-to-action
    // until the first Run/Check populates a panel, so there's no empty chrome up
    // front. revealOutput() unhides them on first use.
    const tablistEl = root.querySelector('.codeatelier-tabs');
    const outputEmptyEl = root.querySelector('[data-role="output-empty"]');
    let outputRevealed = false;
    const revealOutput = (name) => {
        if (!outputRevealed) {
            outputRevealed = true;
            if (tablistEl) {
                tablistEl.hidden = false;
            }
            if (outputEmptyEl) {
                outputEmptyEl.hidden = true;
            }
        }
        setTab(name);
    };
    if (tablistEl) {
        tablistEl.hidden = true;
    }
    if (consoleel) {
        consoleel.style.display = 'none';
    }
    resultsel.style.display = 'none';

    // Focus mode: an opt-in split-pane that fills the viewport. Implemented as a
    // class on the question root so the editor, results and form fields keep
    // their DOM position (the score/answer fields must stay inside the quiz
    // form) and CodeMirror is never remounted.
    const focusbutton = root.querySelector('[data-role="focus"]');
    if (focusbutton) {
        const cmContent = () => root.querySelector('.cm-content');
        const focusables = () => Array.prototype.slice.call(root.querySelectorAll(
            '.cm-content, a[href], button:not([disabled]), textarea:not([disabled]),'
            + ' input:not([type="hidden"]):not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((el) => el.offsetParent !== null || el === document.activeElement);

        let focusActive = false;
        let restoreTo = null;

        const setFocusMode = (on) => {
            focusActive = on;
            root.classList.toggle('codeatelier-focus', on);
            focusbutton.setAttribute('aria-pressed', on ? 'true' : 'false');
            focusbutton.textContent = on
                ? (strings.exitfocus || 'Exit focus')
                : (strings.focus || 'Focus');
            if (on) {
                root.setAttribute('role', 'dialog');
                root.setAttribute('aria-modal', 'true');
                root.setAttribute('aria-label', strings.focusmode || 'Code workbench (focus mode)');
                restoreTo = document.activeElement;
                const cm = cmContent();
                if (cm) {
                    cm.focus();
                }
            } else {
                root.removeAttribute('role');
                root.removeAttribute('aria-modal');
                root.removeAttribute('aria-label');
                if (restoreTo && typeof restoreTo.focus === 'function') {
                    restoreTo.focus();
                } else {
                    focusbutton.focus();
                }
            }
        };

        focusbutton.addEventListener('click', () => setFocusMode(!focusActive));

        root.addEventListener('keydown', (e) => {
            if (!focusActive) {
                return;
            }
            if (e.key === 'Escape') {
                // Let the editor keep Escape (its Escape-then-Tab focus escape hatch).
                const cm = cmContent();
                if (!(cm && cm.contains(document.activeElement))) {
                    setFocusMode(false);
                    e.preventDefault();
                }
                return;
            }
            if (e.key === 'Tab') {
                // CodeMirror owns Tab while the caret is in the editor (it indents).
                const cm = cmContent();
                if (cm && cm.contains(document.activeElement)) {
                    return;
                }
                const items = focusables();
                if (!items.length) {
                    return;
                }
                const first = items[0];
                const last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    last.focus();
                    e.preventDefault();
                } else if (!e.shiftKey && document.activeElement === last) {
                    first.focus();
                    e.preventDefault();
                }
            }
        });
    }

    const runOptions = () => ({
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
    });

    const setRunning = (running) => {
        runbutton.disabled = running;
        checkbutton.disabled = running;
        if (stopbutton) {
            stopbutton.style.display = running ? '' : 'none';
        }
        if (!running) {
            statusel.textContent = '';
        }
    };

    const showError = (detail) => {
        if (scoreinput) {
            scoreinput.value = '';
        }
        const info = friendlyError(detail, strings);
        resultsel.textContent = '';
        const para = document.createElement('p');
        para.className = info.degraded ? 'codeatelier-degraded' : 'codeatelier-runerror';
        para.textContent = info.degraded
            ? info.message
            : ((strings.runerror || 'Could not run your code') + ' (' + info.message + ')');
        resultsel.appendChild(para);
    };

    // Mark the first errored result's line in the editor (gutter + inline).
    const markEditorError = (results) => {
        if (!editor.showError) {
            return;
        }
        const errored = (results || []).find((result) => result && result.error && result.errorline);
        if (errored) {
            editor.showError(errored.errorline, errored.error);
        }
    };

    // Check: grade against the test cases.
    checkbutton.addEventListener('click', () => {
        setRunning(true);
        statusel.textContent = strings.running || 'Running…';
        resultsel.textContent = '';
        if (editor.clearErrors) {
            editor.clearErrors();
        }
        revealOutput('tests');
        run(editor.getValue(), currentLanguage, config.tests, runOptions())
            .then((result) => {
                if (result.aborted) {
                    return;
                }
                if (result.error) {
                    showError(result.error);
                    return;
                }
                const graded = grade(result.results, config.tests, {allOrNothing: config.allornothing});
                if (scoreinput) {
                    scoreinput.value = graded.fraction;
                }
                renderFeedback(resultsel, graded, result.results, config, strings);
                markEditorError(result.results);
            })
            .catch((err) => {
                showError(err);
            })
            .then(() => {
                setRunning(false);
            });
    });

    // Run: execute once with the scratch input (and, for SQL, the active dataset
    // override), no grading. Factored so "Use this dataset" can trigger it too.
    const doRun = () => {
        setRunning(true);
        statusel.textContent = strings.running || 'Running…';
        if (consoleel) {
            consoleel.textContent = '';
        }
        if (editor.clearErrors) {
            editor.clearErrors();
        }
        revealOutput('console');
        const scratch = scratchinput ? scratchinput.value : '';
        const onecase = {input: scratch};
        if (config.scenario === 'sql' && activeDataset) {
            onecase.datasetsql = activeDataset;
        }
        run(editor.getValue(), currentLanguage, [onecase], runOptions())
            .then((result) => {
                const single = (result.results && result.results.length) ? result.results[0] : result;
                renderConsole(consoleel, result.aborted ? result : single, config, strings);
                if (!result.aborted) {
                    markEditorError([single]);
                }
            })
            .catch((err) => {
                renderConsole(consoleel, {error: String(err)}, config, strings);
            })
            .then(() => {
                setRunning(false);
            });
    };
    runbutton.addEventListener('click', doRun);

    // Examples drive the SQL dataset override through a function on the workbench
    // root (mirrors scratch.codeatelierSetArgs). Only visible examples expose the
    // button, so a hidden test's dataset is never runnable here.
    const showActiveDataset = (label) => {
        if (!consoleel || !consoleel.parentNode) {
            return;
        }
        if (!datasetBanner) {
            datasetBanner = document.createElement('div');
            datasetBanner.className = 'codeatelier-active-dataset';
            const text = document.createElement('span');
            text.setAttribute('data-role', 'active-dataset-text');
            const revert = document.createElement('button');
            revert.type = 'button';
            revert.className = 'btn btn-link btn-sm codeatelier-use-default';
            revert.textContent = strings.usedefaultdata || 'Use default data';
            revert.addEventListener('click', () => {
                activeDataset = null;
                datasetBanner.style.display = 'none';
                doRun();
            });
            datasetBanner.appendChild(text);
            datasetBanner.appendChild(revert);
            consoleel.parentNode.insertBefore(datasetBanner, consoleel);
        }
        const tpl = strings.activedataset || 'Running against {$a}\'s data';
        datasetBanner.querySelector('[data-role="active-dataset-text"]').textContent =
            tpl.replace('{$a}', label);
        datasetBanner.style.display = '';
    };
    root.codeatelierUseDataset = (datasetsql, label) => {
        activeDataset = datasetsql;
        showActiveDataset(label);
        doRun();
    };

    // Stop: cancel an in-flight run.
    if (stopbutton) {
        stopbutton.addEventListener('click', () => {
            stop(currentLanguage);
        });
    }
};
