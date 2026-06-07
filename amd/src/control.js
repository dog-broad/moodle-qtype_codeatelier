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

import {create, highlightWithin} from 'qtype_codeatelier/editor';
import {run, describe, stop} from 'qtype_codeatelier/runner';
import {grade} from 'qtype_codeatelier/grader';
import {tokenDiff} from 'qtype_codeatelier/diff';
import {setup as setupProjectFiles} from 'qtype_codeatelier/projectfiles';
import {setupPreview} from 'qtype_codeatelier/webruntime';

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
        return {degraded: true, message: strings.degraded || 'The code runtime could not be loaded. Check your connection, or ask your teacher.'};
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

    // The scratch input to load an example into ("Use this input"): the judge-tier
    // run-with-my-own-input box, or the practice-tier Run box. Absent on review and
    // for SQL (no per-input scratch), so the affordance simply does not appear.
    const wrapper = config.wrapperid ? document.getElementById(config.wrapperid) : null;
    const scratch = (wrapper && !config.readonly && config.scenario !== 'sql')
        ? wrapper.querySelector('[data-role="scratchinput"], [data-role="scratch"]')
        : null;

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

        // Let the student load this example's input into the scratch box with one
        // click, so they have a correct, runnable starting point to tweak.
        if (scratch) {
            const use = document.createElement('button');
            use.type = 'button';
            use.className = 'btn btn-link btn-sm codeatelier-use-input';
            use.textContent = strings.useinput || 'Use this input';
            use.addEventListener('click', () => {
                scratch.value = String(test.input);
                scratch.dispatchEvent(new Event('input', {bubbles: true}));
                scratch.focus();
            });
            block.appendChild(use);
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
        const test = config.tests[index];
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
            addBlock(element, strings.output || 'Output', resultSetTable(result.columns, rows, null));
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
 * Initialise a CodeAtelier question.
 *
 * @param {Object} config
 */
export const init = (config) => {
    const root = document.getElementById(config.wrapperid);
    if (!root) {
        return;
    }

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

    // The web (frontend) scenario is a file-set editor next to a sandboxed live
    // preview; it has no stdin/Run or test-Check in this tier yet, so it wires its
    // own panels and returns rather than falling through the code-runner controls.
    if (config.scenario === 'web') {
        const previewel = root.querySelector('[data-role="preview"]');
        const preview = previewel ? setupPreview(previewel, textarea, config, strings) : null;
        setupProjectFiles(root, textarea, config, strings, preview ? preview.scheduleRefresh : undefined);
        if (skeleton) {
            skeleton.style.display = 'none';
        }
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
    const editorOptions = {
        readonly: config.readonly,
        ariaLabel: editorlabel ? editorlabel.textContent : null,
        onChange: markStale,
    };
    let editor;
    if (config.scenario === 'project') {
        // A multi-file project: the editor is a file-tab list over one CodeMirror,
        // keeping the hidden answer field synced to the canonical JSON file set.
        editor = setupProjectFiles(root, textarea, config, strings);
    } else {
        try {
            editor = create(textarea, currentLanguage, editorOptions);
        } catch (e) {
            textarea.style.display = 'block';
            editor = {getValue: () => textarea.value};
        }
    }
    if (skeleton) {
        skeleton.style.display = 'none';
    }

    // Language picker: re-mount the editor in the chosen language and record the
    // choice for submit / run / run-examples. The starter is only swapped when the
    // student has not typed over it, so a language switch never discards real work.
    if (langselect && config.scenario !== 'project') {
        langselect.addEventListener('change', () => {
            const next = langselect.value;
            if (next === currentLanguage) {
                return;
            }
            const starters = config.starters || {};
            const current = editor.getValue();
            const untouched = current.trim() === '' || current === (starters[currentLanguage] || '');
            const nextValue = untouched ? (starters[next] || '') : current;
            try {
                if (editor.destroy) {
                    editor.destroy();
                }
                textarea.value = nextValue;
                editor = create(textarea, next, editorOptions);
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

    const schemael = root.querySelector('[data-role="schema"]');
    if (config.scenario === 'sql' && schemael) {
        describe(config.sqljsbaseurl, config.schemasql).then((info) => {
            renderSchema(schemael, info, strings);
        });
    }

    if (config.readonly || !checkbutton) {
        return;
    }

    // Scenario-shaped scratch input for Run.
    if (scratchwrap) {
        if (config.scenario === 'sql') {
            scratchwrap.style.display = 'none';
        } else {
            scratchwrap.style.display = '';
            scratchlabel.textContent = (config.scenario === 'function')
                ? (strings.argslabel || 'Arguments (JSON)')
                : (strings.input || 'Input');
        }
    }

    // Console | Tests tabs (WAI-ARIA tabs: roving tabindex + arrow-key navigation).
    const tabs = Array.prototype.slice.call(root.querySelectorAll('[data-role="tab"]'));
    const setTab = (name) => {
        if (consoleel) {
            consoleel.style.display = (name === 'console') ? '' : 'none';
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
    setTab('tests');

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
        setTab('tests');
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

    // Run: execute once with the scratch input, no grading.
    runbutton.addEventListener('click', () => {
        setRunning(true);
        statusel.textContent = strings.running || 'Running…';
        if (consoleel) {
            consoleel.textContent = '';
        }
        if (editor.clearErrors) {
            editor.clearErrors();
        }
        setTab('console');
        const scratch = scratchinput ? scratchinput.value : '';
        run(editor.getValue(), currentLanguage, [{input: scratch}], runOptions())
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
    });

    // Stop: cancel an in-flight run.
    if (stopbutton) {
        stopbutton.addEventListener('click', () => {
            stop(currentLanguage);
        });
    }
};
