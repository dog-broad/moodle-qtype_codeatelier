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
 * Scalable test-case editor for the question edit form.
 *
 * Test cases are stored in one hidden textarea (`testcasesjson`, a JSON array)
 * instead of one Moodle repeat row per case — so a question can carry hundreds
 * of tests without crossing PHP's max_input_vars cap. This module enhances that
 * textarea into a paginated, searchable editor with bulk import (spreadsheet
 * paste / CSV / JSON). With JS off the raw JSON textarea is still editable.
 *
 * @module     qtype_codeatelier/testeditor
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const PER_PAGE = 20;

// The fields a test object can carry; column aliases accepted on import.
const FIELD_ALIASES = {
    input: 'input', stdin: 'input',
    expected: 'expected', output: 'expected', expectedoutput: 'expected',
    matchmode: 'matchmode', match: 'matchmode', mode: 'matchmode',
    tolerance: 'tolerance', tol: 'tolerance',
    weight: 'weight',
    visibility: 'visibility', visible: 'visibility', hidden: 'visibility',
    feedback: 'feedback',
    checkerquery: 'checkerquery', checker: 'checkerquery',
    datasetsql: 'datasetsql', dataset: 'datasetsql', data: 'datasetsql',
    selector: 'selector', assertproperty: 'assertproperty', property: 'assertproperty',
    assertmatcher: 'assertmatcher', matcher: 'assertmatcher',
    actions: 'actions', assertionjson: 'assertionjson', assertion: 'assertionjson',
    subtask: 'subtask',
};

/**
 * A blank test object with sensible defaults.
 *
 * @return {Object}
 */
const blankTest = () => ({
    input: '', expected: '', matchmode: 'trimmed', tolerance: '', weight: 1,
    visibility: 1, feedback: '', checkerquery: '', datasetsql: '', selector: '',
    assertproperty: '', assertmatcher: 'equals', actions: '', assertionjson: '', subtask: 0,
});

/**
 * A terse one-line summary of a compound assertion tree, for the row header
 * (e.g. "All of 3 conditions").
 *
 * @param {String} json the assertionjson string
 * @param {Object} s localised strings
 * @return {String}
 */
const describeTree = (json, s) => {
    let tree;
    try {
        tree = JSON.parse(json);
    } catch (e) {
        return s.assertmust || 'Combined check';
    }
    const count = (node) => {
        if (!node) {
            return 0;
        }
        if (node.all) {
            return node.all.reduce((n, c) => n + count(c), 0);
        }
        if (node.any) {
            return node.any.reduce((n, c) => n + count(c), 0);
        }
        if (node.not) {
            return count(node.not);
        }
        return 1;
    };
    const label = tree.all ? (s.assertgroupall || 'All') : (tree.any ? (s.assertgroupany || 'Any') : '');
    const op = label.replace(/\s*\(.*\)\s*/, '');
    const n = count(tree);
    return (op ? op + ' of ' : '') + n + ' condition' + (n === 1 ? '' : 's');
};

/**
 * Coerce an imported row (object keyed by field/alias) into a test object.
 *
 * @param {Object} row
 * @return {Object}
 */
const normaliseRow = (row) => {
    const t = blankTest();
    Object.keys(row).forEach((rawkey) => {
        const key = FIELD_ALIASES[String(rawkey).trim().toLowerCase().replace(/[\s_]/g, '')];
        if (!key) {
            return;
        }
        let value = row[rawkey];
        if (key === 'visibility') {
            const s = String(value).trim().toLowerCase();
            // "hidden" column inverts; otherwise truthy = visible.
            value = (rawkey.toLowerCase().indexOf('hidden') === 0)
                ? (/^(1|true|yes)$/.test(s) ? 0 : 1)
                : (/^(0|false|no|hidden)$/.test(s) ? 0 : 1);
        } else if (key === 'weight') {
            value = (value === '' || value === null || isNaN(value)) ? 1 : Number(value);
        } else if (key === 'subtask') {
            value = parseInt(value, 10) || 0;
        } else if (value !== null && typeof value === 'object') {
            // A text field given a structured JSON value — most often SQL `expected`
            // written as a nested array of rows, [["Alice",230],...]. Store its
            // canonical JSON string (the form the grader parses) rather than letting
            // it coerce to a flattened "Alice,230,..." string.
            value = JSON.stringify(value);
        }
        t[key] = value;
    });
    return t;
};

/**
 * Parse a delimited table (CSV or TSV) into test objects. Handles RFC-4180
 * quoting (commas/tabs/newlines inside double-quoted cells, "" escapes). The
 * first row is a header naming the columns.
 *
 * @param {String} text
 * @param {String} delim a single character: ',' or '\t'
 * @return {Object[]}
 */
const parseDelimited = (text, delim) => {
    const rows = [];
    let row = [];
    let cell = '';
    let inquotes = false;
    const src = text.replace(/\r\n?/g, '\n');
    for (let i = 0; i < src.length; i++) {
        const ch = src[i];
        if (inquotes) {
            if (ch === '"') {
                if (src[i + 1] === '"') {
                    cell += '"';
                    i++;
                } else {
                    inquotes = false;
                }
            } else {
                cell += ch;
            }
        } else if (ch === '"' && cell === '') {
            // A quote only opens a quoted field at the start of a cell (RFC-4180).
            // A stray quote mid-cell (e.g. 5' 6" in hand-written data) stays literal.
            inquotes = true;
        } else if (ch === delim) {
            row.push(cell);
            cell = '';
        } else if (ch === '\n') {
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += ch;
        }
    }
    if (cell !== '' || row.length) {
        row.push(cell);
        rows.push(row);
    }
    if (!rows.length) {
        return [];
    }
    const header = rows.shift().map((h) => h.trim());
    return rows
        .filter((r) => r.some((c) => c.trim() !== ''))
        .map((r) => {
            const obj = {};
            header.forEach((h, idx) => {
                obj[h] = r[idx] === undefined ? '' : r[idx];
            });
            return normaliseRow(obj);
        });
};

/**
 * Parse the chosen import format into test objects.
 *
 * @param {String} format 'tsv' | 'csv' | 'json'
 * @param {String} text
 * @return {Object[]}
 */
const parseImport = (format, text) => {
    if (format === 'json') {
        const data = JSON.parse(text);
        if (!Array.isArray(data)) {
            throw new Error('expected a JSON array');
        }
        return data.map((row) => normaliseRow(row || {}));
    }
    return parseDelimited(text, format === 'tsv' ? '\t' : ',');
};

/**
 * The columns shown when exporting or templating, scoped to the scenario so a
 * teacher sees only the fields that matter for the question format they author.
 *
 * @param {String} scenario
 * @return {String[]}
 */
const columnsFor = (scenario) => {
    if (scenario === 'web') {
        return ['selector', 'assertproperty', 'assertmatcher', 'actions', 'expected', 'weight', 'visibility', 'feedback'];
    }
    if (scenario === 'sql') {
        // SQL has no stdin: the student writes the query, so no input column.
        // datasetsql is the per-test schema + seed (empty = use the question schema).
        return ['datasetsql', 'expected', 'matchmode', 'checkerquery', 'weight', 'visibility', 'feedback'];
    }
    return ['input', 'expected', 'matchmode', 'tolerance', 'weight', 'visibility', 'subtask', 'feedback'];
};

/**
 * The cell value for one column when serialising. Visibility renders as the
 * human-friendly visible/hidden the importer also accepts.
 *
 * @param {String} key
 * @param {Object} t a test object
 * @return {String|Number}
 */
const exportValue = (key, t) => {
    if (key === 'visibility') {
        return Number(t.visibility) === 0 ? 'hidden' : 'visible';
    }
    return (t[key] === undefined || t[key] === null) ? '' : t[key];
};

/**
 * Quote a delimited cell per RFC-4180 (wrap in quotes and double internal
 * quotes when it contains the delimiter, a quote, or a newline).
 *
 * @param {String|Number} value
 * @param {String} delim
 * @return {String}
 */
const csvCell = (value, delim) => {
    const str = String(value === undefined || value === null ? '' : value);
    if (str.indexOf(delim) >= 0 || str.indexOf('"') >= 0 || str.indexOf('\n') >= 0) {
        return '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
};

/**
 * Serialise test objects to the chosen format (header row + one row per test),
 * scoped to the scenario's columns.
 *
 * @param {String} format 'tsv' | 'csv' | 'json'
 * @param {Object[]} rows
 * @param {String} scenario
 * @return {String}
 */
const serialize = (format, rows, scenario) => {
    const cols = columnsFor(scenario);
    if (format === 'json') {
        return JSON.stringify(rows.map((t) => {
            const o = {};
            cols.forEach((c) => {
                let v = exportValue(c, t);
                // For SQL, emit `expected` as the nested array of rows rather than an
                // escaped JSON string, so the exported/template JSON is readable and
                // round-trips through import unchanged.
                if (scenario === 'sql' && c === 'expected' && typeof v === 'string' && v.trim() !== '') {
                    try {
                        v = JSON.parse(v);
                    } catch (e) {
                        // Not valid JSON — leave it as the raw string.
                    }
                }
                o[c] = v;
            });
            return o;
        }), null, 2);
    }
    const delim = format === 'tsv' ? '\t' : ',';
    const lines = [cols.join(delim)];
    rows.forEach((t) => {
        lines.push(cols.map((c) => csvCell(exportValue(c, t), delim)).join(delim));
    });
    return lines.join('\n');
};

/**
 * Two worked example test cases for the scenario, used to build a template a
 * teacher can download, edit, and re-import.
 *
 * @param {String} scenario
 * @return {Object[]}
 */
const exampleRows = (scenario) => {
    if (scenario === 'web') {
        // Property: text | attr:<name> | style:<css-prop>. Matcher: equals | contains | regex.
        // Actions DSL (run before the check): `click <selector>` / `type <selector> <value>`,
        // one per line or separated by ';'.
        return [
            Object.assign(blankTest(), {selector: '#title', assertproperty: 'text',
                assertmatcher: 'equals', expected: 'Welcome', weight: 1, visibility: 1,
                feedback: 'The heading reads "Welcome".'}),
            Object.assign(blankTest(), {selector: 'a.home', assertproperty: 'attr:href',
                assertmatcher: 'equals', expected: '/index.html', weight: 1, visibility: 1,
                feedback: 'The home link points to /index.html.'}),
            Object.assign(blankTest(), {selector: '#result', assertproperty: 'text',
                assertmatcher: 'equals', actions: 'type #a 2\ntype #b 3\nclick #add',
                expected: '5', weight: 1, visibility: 0,
                feedback: 'Type 2 and 3, click Add — the result reads 5.'}),
        ];
    }
    if (scenario === 'sql') {
        // expected = the query result as a JSON array of rows; resultset (any order)
        // or resultset_ordered (exact order). Set checkerquery to grade a mutation:
        // it runs after the student's statement and its result is compared to expected.
        return [
            Object.assign(blankTest(), {expected: '[["Ada",55000],["Ben",70000]]',
                matchmode: 'resultset_ordered', weight: 1, visibility: 1,
                feedback: 'Employees ordered by name; columns name, salary.'}),
            Object.assign(blankTest(), {expected: '[["Engineering"],["Sales"]]',
                matchmode: 'resultset', weight: 1, visibility: 1,
                feedback: 'The distinct departments, in any order.'}),
            Object.assign(blankTest(), {
                datasetsql: 'CREATE TABLE employees (id INTEGER, name TEXT, salary INTEGER);\n'
                    + "INSERT INTO employees VALUES (1, 'Mara', 90000), (2, 'Nils', 60000);",
                expected: '[["Mara",90000],["Nils",60000]]', matchmode: 'resultset_ordered',
                weight: 1, visibility: 0,
                feedback: 'Hidden test with its OWN dataset (replaces the question schema), so a '
                    + 'hardcoded answer that matches the visible example fails here.'}),
        ];
    }
    if (scenario === 'function') {
        // input = the call arguments as a JSON array; expected = the return value as
        // JSON; structured deep-compares (order-independent for objects).
        return [
            Object.assign(blankTest(), {input: '[3, 4]', expected: '7', matchmode: 'structured',
                weight: 1, visibility: 1, feedback: 'add(3, 4) returns 7.'}),
            Object.assign(blankTest(), {input: '[[5, 1, 4, 2]]', expected: '[1, 2, 4, 5]',
                matchmode: 'structured', weight: 1, visibility: 1,
                feedback: 'Sorts the list ascending.'}),
            Object.assign(blankTest(), {input: '[[2, 7, 11, 15], 9]', expected: '[0, 1]',
                matchmode: 'structured', weight: 1, visibility: 0,
                feedback: 'Indices of the two values that sum to the target.'}),
        ];
    }
    // stdin/stdout (and project): input = stdin, expected = stdout.
    return [
        Object.assign(blankTest(), {input: '3 4', expected: '7', matchmode: 'trimmed',
            weight: 1, visibility: 1, feedback: 'Reads two integers and prints their sum.'}),
        Object.assign(blankTest(), {input: '4\n3 1 4 1', expected: '9', matchmode: 'trimmed',
            weight: 1, visibility: 1,
            feedback: 'First line is the count, second line the numbers (multi-line input).'}),
        Object.assign(blankTest(), {input: '1 2 3 4', expected: '2.5', matchmode: 'float',
            tolerance: '0.001', weight: 2, visibility: 0,
            feedback: 'Average of the inputs; float match within tolerance.'}),
    ];
};

/**
 * The download file name extension and MIME type for a format.
 *
 * @param {String} format
 * @return {Object} {ext, mime}
 */
const formatMeta = (format) => {
    if (format === 'json') {
        return {ext: 'json', mime: 'application/json'};
    }
    if (format === 'tsv') {
        return {ext: 'tsv', mime: 'text/tab-separated-values'};
    }
    return {ext: 'csv', mime: 'text/csv'};
};

/**
 * Format a function call for the authoring preview: entrypoint(arg1, arg2) from
 * the test's Input (a JSON array of arguments). Returns null when the input is
 * not a JSON array, so the editor can prompt for the right shape — this is what
 * makes a single-bracket mistake ([3,4] vs [[3,4]]) visible before saving. Kept
 * in sync with the attempt-page formatCall in control.js.
 *
 * @param {String} entrypoint
 * @param {String} input
 * @return {String|null}
 */
const formatCall = (entrypoint, input) => {
    let args;
    try {
        args = JSON.parse(input);
    } catch (e) {
        return null;
    }
    if (!Array.isArray(args)) {
        return null;
    }
    return (entrypoint || 'solve') + '(' + args.map((a) => JSON.stringify(a)).join(', ') + ')';
};

/** Small DOM builder. */
const el = (tag, attrs, children) => {
    const node = document.createElement(tag);
    Object.keys(attrs || {}).forEach((k) => {
        if (k === 'text') {
            node.textContent = attrs[k];
        } else if (k === 'html') {
            node.innerHTML = attrs[k];
        } else if (k === 'class') {
            node.className = attrs[k];
        } else {
            node.setAttribute(k, attrs[k]);
        }
    });
    (children || []).forEach((c) => node.appendChild(c));
    return node;
};

/**
 * Trigger a client-side file download.
 *
 * @param {String} filename
 * @param {String} text
 * @param {String} mime
 */
const download = (filename, text, mime) => {
    const blob = new Blob([text], {type: mime});
    const a = el('a', {href: URL.createObjectURL(blob), download: filename}, []);
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
};

/**
 * Initialise the editor on a question edit form.
 *
 * @param {Object} params - {fieldid} the id of the testcasesjson textarea
 */
export const init = (params) => {
    const field = document.getElementById(params.fieldid);
    if (!field || !field.dataset.config) {
        return;
    }
    const config = JSON.parse(field.dataset.config);
    const s = config.strings || {};
    const scenarioEl = document.getElementById(config.scenarioid);
    const scenario = () => (scenarioEl ? scenarioEl.value : 'stdinstdout');
    const entrypointEl = config.entrypointid ? document.getElementById(config.entrypointid) : null;
    const entrypoint = () => (entrypointEl ? entrypointEl.value : '');

    let tests;
    try {
        tests = JSON.parse(field.value || '[]');
        if (!Array.isArray(tests)) {
            tests = [];
        }
    } catch (e) {
        tests = [];
    }

    let page = 0;
    let query = '';

    const sync = () => {
        field.value = JSON.stringify(tests);
    };

    // Hide the raw textarea; the editor mounts after it.
    field.style.display = 'none';
    const ui = el('div', {class: 'codeatelier-testeditor', 'data-role': 'testeditor-ui'}, []);
    field.parentNode.insertBefore(ui, field.nextSibling);

    const isWeb = () => scenario() === 'web';
    const isSql = () => scenario() === 'sql';

    const filtered = () => {
        const q = query.trim().toLowerCase();
        if (!q) {
            return tests.map((t, i) => ({t: t, i: i}));
        }
        return tests
            .map((t, i) => ({t: t, i: i}))
            .filter(({t}) => (t.input + ' ' + t.expected + ' ' + t.selector).toLowerCase().indexOf(q) >= 0);
    };

    const preview = (str) => {
        const oneLine = String(str || '').replace(/\s+/g, ' ').trim();
        return oneLine.length > 48 ? oneLine.slice(0, 47) + '…' : oneLine;
    };

    // Build the labelled edit body for one test (scenario-aware fields).
    const editBody = (t) => {
        const body = el('div', {class: 'codeatelier-te-body'}, []);
        const fieldRow = (labelText, control) => {
            const id = 'cate_' + Math.random().toString(36).slice(2);
            control.id = id;
            return el('div', {class: 'codeatelier-te-field'}, [
                el('label', {text: labelText, for: id}), control,
            ]);
        };
        const textarea = (key, rows) => {
            const ta = el('textarea', {class: 'form-control', rows: rows || 3, spellcheck: 'false'}, []);
            const v = t[key];
            // Defensive: never let a structured value coerce to a flattened string.
            ta.value = (v === undefined || v === null) ? ''
                : (typeof v === 'object' ? JSON.stringify(v) : v);
            ta.addEventListener('input', () => { t[key] = ta.value; sync(); });
            return ta;
        };
        const textInput = (key) => {
            const inp = el('input', {type: 'text', class: 'form-control'}, []);
            inp.value = t[key] === undefined ? '' : t[key];
            inp.addEventListener('input', () => { t[key] = inp.value; sync(); });
            return inp;
        };
        const select = (key, options) => {
            const sel = el('select', {class: 'form-select form-select-sm'}, []);
            Object.keys(options).forEach((val) => {
                const opt = el('option', {value: val, text: options[val]}, []);
                if (String(t[key]) === String(val)) {
                    opt.selected = true;
                }
                sel.appendChild(opt);
            });
            sel.addEventListener('change', () => { t[key] = sel.value; sync(); refreshHead(); });
            return sel;
        };

        if (isWeb()) {
            // A web check is a flat single condition by default, promotable to a
            // compound AND/OR/NOT tree. The bound widgets operate on an arbitrary
            // {object, key} so the same controls serve the simple form and each leaf.
            const blankLeaf = () => ({selector: '', property: 'text', matcher: 'equals', expected: ''});
            const boundText = (obj, key, onChange, placeholder) => {
                const inp = el('input', {type: 'text', class: 'form-control form-control-sm',
                    placeholder: placeholder || ''}, []);
                inp.value = obj[key] === undefined ? '' : obj[key];
                inp.addEventListener('input', () => { obj[key] = inp.value; onChange(); });
                return inp;
            };
            const boundSelect = (obj, key, options, onChange) => {
                const sel = el('select', {class: 'form-select form-select-sm'}, []);
                Object.keys(options).forEach((val) => {
                    const opt = el('option', {value: val, text: options[val]}, []);
                    if (String(obj[key]) === String(val)) {
                        opt.selected = true;
                    }
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', () => { obj[key] = sel.value; onChange(); });
                return sel;
            };
            // Property dropdown: the fixed properties plus attribute…/style… which
            // reveal a name sub-input and write attr:<name> / style:<prop>.
            const propertyControl = (obj, key, onChange) => {
                const wrap = el('div', {class: 'codeatelier-propfield'}, []);
                const sel = el('select', {class: 'form-select form-select-sm'}, []);
                const opts = config.assertproperties || {text: 'text'};
                Object.keys(opts).forEach((v) => sel.appendChild(el('option', {value: v, text: opts[v]}, [])));
                sel.appendChild(el('option', {value: 'attr', text: s.assertprop_attr || 'attribute…'}, []));
                sel.appendChild(el('option', {value: 'style', text: s.assertprop_style || 'style…'}, []));
                const sub = el('input', {type: 'text', class: 'form-control form-control-sm codeatelier-propsub'}, []);
                const cur = String(obj[key] || 'text');
                let base = cur;
                if (cur.indexOf('attr:') === 0) { base = 'attr'; sub.value = cur.slice(5); } else if (cur.indexOf('style:') === 0) { base = 'style'; sub.value = cur.slice(6); }
                sel.value = (opts[base] !== undefined || base === 'attr' || base === 'style') ? base : 'text';
                const apply = () => {
                    if (sel.value === 'attr') {
                        sub.style.display = ''; sub.placeholder = s.assertattrname || 'Attribute name';
                        obj[key] = 'attr:' + sub.value.trim();
                    } else if (sel.value === 'style') {
                        sub.style.display = ''; sub.placeholder = s.assertstylename || 'Style property';
                        obj[key] = 'style:' + sub.value.trim();
                    } else {
                        sub.style.display = 'none'; obj[key] = sel.value;
                    }
                    onChange();
                };
                sel.addEventListener('change', apply);
                sub.addEventListener('input', apply);
                sub.style.display = (sel.value === 'attr' || sel.value === 'style') ? '' : 'none';
                wrap.appendChild(sel); wrap.appendChild(sub);
                return wrap;
            };
            // One condition row in a group: a leaf (selector/property/matcher/expected)
            // or a nested group, each with a Not toggle and a remove button.
            const renderChild = (arr, i, persist, rebuild, depth) => {
                const child = arr[i];
                const isNot = !!(child && child.not);
                const inner = isNot ? child.not : child;
                const isGroup = !!(inner && (inner.all || inner.any));
                const row = el('div', {class: 'codeatelier-te-cond' + (isGroup ? ' codeatelier-te-cond-group' : '')}, []);
                if (isGroup) {
                    row.appendChild(renderGroup(inner, persist, rebuild, depth + 1, null));
                } else {
                    row.appendChild(boundText(inner, 'selector', persist, s.assertselector || 'CSS selector'));
                    row.appendChild(propertyControl(inner, 'property', persist));
                    row.appendChild(boundSelect(inner, 'matcher', config.assertmatchers || {}, persist));
                    row.appendChild(boundText(inner, 'expected', persist, s.expected || 'Expected'));
                }
                const notLabel = el('label', {class: 'codeatelier-te-not'}, []);
                const notCb = el('input', {type: 'checkbox'}, []);
                notCb.checked = isNot;
                notCb.addEventListener('change', () => { arr[i] = notCb.checked ? {not: inner} : inner; rebuild(); });
                notLabel.appendChild(notCb);
                notLabel.appendChild(el('span', {text: s.assertnot || 'Not'}, []));
                row.appendChild(notLabel);
                const rm = el('button', {type: 'button', class: 'codeatelier-filedelete', text: '×',
                    title: s.assertremovecond || 'Remove this condition'}, []);
                rm.addEventListener('click', () => {
                    arr.splice(i, 1);
                    if (arr.length === 0) { arr.push(blankLeaf()); }
                    rebuild();
                });
                row.appendChild(rm);
                return row;
            };
            // A group node: operator (All/Any), its condition rows, and add controls.
            const renderGroup = (group, persist, rebuild, depth, onCollapse) => {
                const box = el('div', {class: 'codeatelier-te-group'}, []);
                const op = el('select', {class: 'form-select form-select-sm codeatelier-te-op'}, [
                    el('option', {value: 'all', text: s.assertgroupall || 'All (AND)'}, []),
                    el('option', {value: 'any', text: s.assertgroupany || 'Any (OR)'}, []),
                ]);
                op.value = group.all ? 'all' : 'any';
                op.addEventListener('change', () => {
                    const items = group.all || group.any;
                    delete group.all; delete group.any;
                    group[op.value] = items;
                    persist();
                });
                box.appendChild(el('div', {class: 'codeatelier-te-grouphead'}, [op]));
                const arr = group.all || group.any;
                arr.forEach((child, i) => box.appendChild(renderChild(arr, i, persist, rebuild, depth)));
                const tools = el('div', {class: 'codeatelier-te-grouptools'}, []);
                const addCond = el('button', {type: 'button', class: 'btn btn-link btn-sm',
                    text: s.assertaddcondition || '+ Condition'}, []);
                addCond.addEventListener('click', () => { arr.push(blankLeaf()); rebuild(); });
                tools.appendChild(addCond);
                if (depth < 3) {
                    const addGroup = el('button', {type: 'button', class: 'btn btn-link btn-sm',
                        text: s.assertaddgroup || '+ Group'}, []);
                    addGroup.addEventListener('click', () => { arr.push({all: [blankLeaf()]}); rebuild(); });
                    tools.appendChild(addGroup);
                }
                if (onCollapse && arr.length === 1 && !arr[0].all && !arr[0].any && !arr[0].not) {
                    const coll = el('button', {type: 'button', class: 'btn btn-link btn-sm',
                        text: s.assertcollapse || 'Simplify to one condition'}, []);
                    coll.addEventListener('click', onCollapse);
                    tools.appendChild(coll);
                }
                box.appendChild(tools);
                return box;
            };

            const webwrap = el('div', {class: 'codeatelier-webcheck'}, []);
            const renderWeb = () => {
                webwrap.textContent = '';
                if (String(t.assertionjson || '').trim() === '') {
                    webwrap.appendChild(fieldRow(s.assertselector || 'Selector', textInput('selector')));
                    webwrap.appendChild(fieldRow(s.assertproperty || 'Property', propertyControl(t, 'assertproperty', sync)));
                    webwrap.appendChild(fieldRow(s.assertmatcher || 'Compare', select('assertmatcher', config.assertmatchers || {})));
                    webwrap.appendChild(fieldRow(s.expected || 'Expected', textarea('expected', 2)));
                    const combine = el('button', {type: 'button', class: 'btn btn-link btn-sm codeatelier-te-combine',
                        text: s.assertcombine || '+ Combine with another condition'}, []);
                    combine.addEventListener('click', () => {
                        const leaf = {selector: t.selector || '', property: t.assertproperty || 'text',
                            matcher: t.assertmatcher || 'equals', expected: t.expected || ''};
                        t.assertionjson = JSON.stringify({all: [leaf, blankLeaf()]});
                        t.selector = ''; t.assertproperty = ''; t.assertmatcher = 'equals'; t.expected = '';
                        sync(); renderWeb();
                    });
                    webwrap.appendChild(combine);
                } else {
                    let root;
                    try { root = JSON.parse(t.assertionjson); } catch (e) { root = {all: [blankLeaf()]}; }
                    if (!root.all && !root.any) { root = {all: [root]}; }
                    const persist = () => { t.assertionjson = JSON.stringify(root); sync(); };
                    const rebuild = () => { persist(); renderWeb(); };
                    const collapse = () => {
                        const only = (root.all || root.any)[0];
                        t.selector = only.selector || ''; t.assertproperty = only.property || 'text';
                        t.assertmatcher = only.matcher || 'equals'; t.expected = only.expected || '';
                        t.assertionjson = ''; sync(); renderWeb();
                    };
                    webwrap.appendChild(el('span', {class: 'codeatelier-block-label', text: s.assertmust || 'Must satisfy'}, []));
                    webwrap.appendChild(renderGroup(root, persist, rebuild, 0, collapse));
                }
                webwrap.appendChild(fieldRow(s.assertactions || 'Actions', textarea('actions', 2)));
            };
            renderWeb();
            body.appendChild(webwrap);
        } else {
            const inputTa = textarea('input', 3);
            body.appendChild(fieldRow(s.input || 'Input', inputTa));
            // Function scenario: show the literal call the Input produces, so a
            // wrong argument-array nesting ([3,4] vs [[3,4]]) is caught before save.
            if (scenario() === 'function') {
                const preview = el('code', {class: 'codeatelier-te-callpreview', 'data-role': 'callpreview'}, []);
                const refreshPreview = () => {
                    const call = formatCall(entrypoint(), t.input);
                    preview.textContent = (call === null)
                        ? (s.callpreviewhint || 'Enter arguments as a JSON array, e.g. [3, 4]')
                        : call;
                    preview.classList.toggle('codeatelier-te-callpreview-hint', call === null);
                };
                inputTa.addEventListener('input', refreshPreview);
                refreshPreview();
                body.appendChild(el('div', {class: 'codeatelier-te-field'}, [
                    el('span', {class: 'codeatelier-te-callpreview-label', text: s.callpreview || 'Call preview'}),
                    preview,
                ]));
            }
            body.appendChild(fieldRow(s.expected || 'Expected', textarea('expected', 3)));
            body.appendChild(fieldRow(s.matchmode || 'Match mode', select('matchmode', config.matchmodes || {})));
            body.appendChild(fieldRow(s.tolerance || 'Tolerance', textInput('tolerance')));
            body.appendChild(fieldRow(s.subtask || 'Subtask', textInput('subtask')));
            if (isSql()) {
                body.appendChild(fieldRow(s.testdataset || 'Dataset (schema + seed) — overrides the question schema',
                    textarea('datasetsql', 4)));
                body.appendChild(fieldRow(s.checkerquery || 'Checker query', textarea('checkerquery', 2)));
            }
        }
        body.appendChild(fieldRow(s.weight || 'Weight', textInput('weight')));
        body.appendChild(fieldRow(s.visibility || 'Visibility', select('visibility', config.visibilities || {1: 'Visible', 0: 'Hidden'})));
        body.appendChild(fieldRow(s.testfeedback || 'Feedback', textarea('feedback', 2)));
        return body;
    };

    // Forward declaration so a field change can refresh its row header preview.
    let refreshHead = () => {};

    const list = el('ol', {class: 'codeatelier-te-list', 'data-role': 'te-list'}, []);
    const pager = el('nav', {class: 'codeatelier-te-pager', 'aria-label': 'Test case pages'}, []);
    const countEl = el('span', {class: 'codeatelier-te-count', 'aria-live': 'polite'}, []);

    const renderList = () => {
        list.textContent = '';
        const rows = filtered();
        const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
        if (page >= pages) {
            page = pages - 1;
        }
        countEl.textContent = (s.editor_count || '{$a} test cases').replace('{$a}', tests.length);
        if (!rows.length) {
            list.appendChild(el('li', {class: 'codeatelier-te-empty', text: s.editor_empty || 'No test cases yet.'}, []));
        }
        rows.slice(page * PER_PAGE, (page + 1) * PER_PAGE).forEach(({t, i}) => {
            const head = el('button', {
                type: 'button', class: 'codeatelier-te-head', 'aria-expanded': 'false',
            }, []);
            const setHead = () => {
                const vis = (Number(t.visibility) === 1) ? (s.editor_visible || 'Visible') : (s.editor_hidden || 'Hidden');
                const meta = '   [' + vis + ' · w' + t.weight + ']';
                // A compound web check has no single selector/expected; summarise it.
                if (isWeb() && String(t.assertionjson || '').trim() !== '') {
                    head.textContent = '#' + (i + 1) + '  ' + describeTree(t.assertionjson, s) + meta;
                    return;
                }
                const left = isWeb() ? (t.selector || '') : (t.input || '');
                head.textContent = '#' + (i + 1) + '  ' + preview(left) + ' → ' + preview(t.expected) + meta;
            };
            setHead();
            const body = editBody(t);
            body.hidden = true;
            const tools = el('div', {class: 'codeatelier-te-rowtools'}, []);
            const toolBtn = (label, fn) => {
                const b = el('button', {type: 'button', class: 'btn btn-link btn-sm', text: label}, []);
                b.addEventListener('click', fn);
                return b;
            };
            tools.appendChild(toolBtn(s.editor_moveup || 'Move up', () => {
                if (i > 0) {
                    tests.splice(i - 1, 0, tests.splice(i, 1)[0]);
                    sync();
                    renderList();
                }
            }));
            tools.appendChild(toolBtn(s.editor_movedown || 'Move down', () => {
                if (i < tests.length - 1) {
                    tests.splice(i + 1, 0, tests.splice(i, 1)[0]);
                    sync();
                    renderList();
                }
            }));
            tools.appendChild(toolBtn(s.editor_delete || 'Delete', () => {
                tests.splice(i, 1);
                sync();
                renderList();
            }));
            body.appendChild(tools);
            head.addEventListener('click', () => {
                const open = body.hidden;
                body.hidden = !open;
                head.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            refreshHead = setHead;
            list.appendChild(el('li', {class: 'codeatelier-te-row'}, [head, body]));
        });
        renderPager(pages);
    };

    const renderPager = (pages) => {
        pager.textContent = '';
        if (pages <= 1) {
            return;
        }
        const mk = (label, target, disabled) => {
            const b = el('button', {type: 'button', class: 'btn btn-secondary btn-sm', text: label}, []);
            b.disabled = !!disabled;
            b.addEventListener('click', () => { page = target; renderList(); });
            return b;
        };
        pager.appendChild(mk('‹', page - 1, page === 0));
        pager.appendChild(el('span', {class: 'codeatelier-te-pageinfo',
            text: (s.editor_page || 'Page {$a->page} of {$a->pages}')
                .replace('{$a->page}', page + 1).replace('{$a->pages}', pages)}, []));
        pager.appendChild(mk('›', page + 1, page >= pages - 1));
    };

    // Toolbar: count, search, add, and the import/export disclosure.
    const search = el('input', {type: 'search', class: 'form-control form-control-sm',
        placeholder: s.editor_search || 'Search', 'aria-label': s.editor_search || 'Search'}, []);
    search.addEventListener('input', () => { query = search.value; page = 0; renderList(); });

    const addBtn = el('button', {type: 'button', class: 'btn btn-secondary btn-sm',
        text: s.editor_addtest || 'Add test case'}, []);
    addBtn.addEventListener('click', () => {
        tests.push(blankTest());
        sync();
        page = Math.ceil(tests.length / PER_PAGE) - 1;
        renderList();
    });

    ui.appendChild(el('div', {class: 'codeatelier-te-toolbar'}, [countEl, search, addBtn]));
    ui.appendChild(buildImportPanel());
    ui.appendChild(list);
    ui.appendChild(pager);

    /**
     * The collapsible bulk-import / export panel.
     *
     * @return {HTMLElement}
     */
    function buildImportPanel() {
        const det = el('details', {class: 'codeatelier-te-import'}, []);
        det.appendChild(el('summary', {text: s.editor_import || 'Bulk add / import'}, []));
        const fmt = el('select', {class: 'form-select form-select-sm', 'aria-label': s.editor_importformat || 'Format'}, [
            el('option', {value: 'tsv', text: s.editor_format_tsv || 'Spreadsheet (TSV)'}, []),
            el('option', {value: 'csv', text: s.editor_format_csv || 'CSV'}, []),
            el('option', {value: 'json', text: s.editor_format_json || 'JSON'}, []),
        ]);
        const paste = el('textarea', {class: 'form-control', rows: 4, spellcheck: 'false',
            placeholder: s.editor_paste || 'Paste data here, or upload a file'}, []);
        const file = el('input', {type: 'file', class: 'form-control form-control-sm',
            accept: '.csv,.tsv,.txt,.json,text/csv,application/json'}, []);
        file.addEventListener('change', () => {
            if (!file.files || !file.files[0]) {
                return;
            }
            const reader = new FileReader();
            reader.onload = () => { paste.value = String(reader.result || ''); };
            reader.readAsText(file.files[0]);
        });
        const mode = (val, label, checked) => {
            const r = el('input', {type: 'radio', name: 'cate_importmode', value: val}, []);
            r.checked = !!checked;
            const id = 'catem_' + val;
            r.id = id;
            return el('span', {class: 'codeatelier-te-mode'}, [r, el('label', {text: label, for: id}, [])]);
        };
        const appendR = mode('append', s.editor_append || 'Append', true);
        const replaceR = mode('replace', s.editor_replace || 'Replace all', false);
        const status = el('span', {class: 'codeatelier-te-status', 'aria-live': 'polite'}, []);
        const importBtn = el('button', {type: 'button', class: 'btn btn-primary btn-sm',
            text: s.editor_import || 'Import'}, []);
        importBtn.addEventListener('click', () => {
            try {
                const parsed = parseImport(fmt.value, paste.value);
                const replace = replaceR.querySelector('input').checked;
                if (replace) {
                    tests = parsed;
                } else {
                    tests = tests.concat(parsed);
                }
                sync();
                page = 0;
                renderList();
                status.textContent = (s.editor_importok || 'Imported {$a}.').replace('{$a}', parsed.length);
                paste.value = '';
            } catch (e) {
                status.textContent = (s.editor_importerror || 'Could not parse: {$a}').replace('{$a}', e.message);
            }
        });
        // Export the current set in the selected format (round-trips through
        // a spreadsheet); the columns are scoped to the scenario.
        const exportBtn = el('button', {type: 'button', class: 'btn btn-secondary btn-sm',
            text: s.editor_export || 'Export'}, []);
        exportBtn.addEventListener('click', () => {
            const meta = formatMeta(fmt.value);
            download('testcases.' + meta.ext, serialize(fmt.value, tests, scenario()), meta.mime);
        });
        // Download a ready-to-edit template for the selected format: the header
        // row plus two worked examples, so a teacher sees the exact columns and
        // shape for this question format without guessing.
        const templateBtn = el('button', {type: 'button', class: 'btn btn-link btn-sm',
            text: s.editor_template || 'Download template'}, []);
        templateBtn.addEventListener('click', () => {
            const meta = formatMeta(fmt.value);
            download('testcases-template.' + meta.ext,
                serialize(fmt.value, exampleRows(scenario()), scenario()), meta.mime);
        });
        det.appendChild(el('div', {class: 'codeatelier-te-importbody'}, [
            el('p', {class: 'codeatelier-te-importhelp small text-muted',
                text: s.editor_importhelp || 'The first row names the columns. Download a template '
                    + 'to see the columns and an example for this question format. Columns you omit use '
                    + 'their defaults; unrecognised columns are ignored.'}, []),
            fmt, templateBtn, paste, file,
            el('div', {class: 'codeatelier-te-importactions'}, [appendR, replaceR, importBtn, exportBtn, status]),
        ]));
        return det;
    }

    // Re-render when the scenario picker changes (fields shown differ by scenario).
    if (scenarioEl) {
        scenarioEl.addEventListener('change', () => renderList());
    }
    // Re-render on entrypoint change so the function call previews track the name.
    if (entrypointEl) {
        entrypointEl.addEventListener('change', () => {
            if (scenario() === 'function') {
                renderList();
            }
        });
    }

    // Public API for author.js (preview/capture/web-picker), which can no longer
    // read per-row form fields now that test cases live in this one model. Attached
    // to the field element so author.js can find it lazily at click time.
    field.codeatelierTestEditor = {
        /** @return {Object[]} a shallow copy of the current test objects. */
        getTests: () => tests.map((t) => Object.assign({}, t)),
        /**
         * Write a captured expected value into one test (by model index).
         *
         * @param {Number} index
         * @param {String} value
         */
        setExpected: (index, value) => {
            if (tests[index]) {
                tests[index].expected = value;
                sync();
                renderList();
            }
        },
        /**
         * Fill the first test with an empty selector from a picked element (web
         * scenario), appending a new test if none is empty. Returns the model index.
         *
         * @param {String} selector
         * @param {String} text the element's text, used as a default expected value
         * @return {Number}
         */
        fillAssertion: (selector, text) => {
            let idx = tests.findIndex((t) => !String(t.selector || '').trim());
            if (idx < 0) {
                tests.push(blankTest());
                idx = tests.length - 1;
            }
            const t = tests[idx];
            t.selector = selector;
            if (!String(t.assertproperty || '').trim()) {
                t.assertproperty = 'text';
            }
            if (!String(t.expected || '').trim()) {
                t.expected = text;
            }
            sync();
            page = Math.floor(idx / PER_PAGE);
            renderList();
            return idx;
        },
    };

    sync();
    renderList();
};
