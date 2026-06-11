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
    selector: 'selector', assertproperty: 'assertproperty', property: 'assertproperty',
    assertmatcher: 'assertmatcher', matcher: 'assertmatcher',
    actions: 'actions', subtask: 'subtask',
};

/**
 * A blank test object with sensible defaults.
 *
 * @return {Object}
 */
const blankTest = () => ({
    input: '', expected: '', matchmode: 'trimmed', tolerance: '', weight: 1,
    visibility: 1, feedback: '', checkerquery: '', selector: '',
    assertproperty: '', assertmatcher: 'equals', actions: '', subtask: 0,
});

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
        } else if (ch === '"') {
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
        return ['input', 'expected', 'matchmode', 'checkerquery', 'weight', 'visibility', 'feedback'];
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
                o[c] = exportValue(c, t);
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
        return [
            Object.assign(blankTest(), {selector: '#total', assertproperty: 'text',
                assertmatcher: 'equals', expected: '42', weight: 1, visibility: 1,
                feedback: 'The total should read 42.'}),
            Object.assign(blankTest(), {selector: 'h1', assertproperty: 'text',
                assertmatcher: 'contains', expected: 'Welcome', weight: 1, visibility: 0}),
        ];
    }
    if (scenario === 'sql') {
        return [
            Object.assign(blankTest(), {expected: '[["Alice",30],["Bob",25]]', matchmode: 'exact',
                weight: 1, visibility: 1, feedback: 'Returns all users ordered by name.'}),
            Object.assign(blankTest(), {checkerquery: 'SELECT COUNT(*) FROM users', matchmode: 'trimmed',
                weight: 1, visibility: 0}),
        ];
    }
    const input1 = scenario === 'function' ? '[3, 4]' : '3 4';
    const input2 = scenario === 'function' ? '[10, -2]' : '10 -2';
    return [
        Object.assign(blankTest(), {input: input1, expected: '7', matchmode: 'trimmed',
            weight: 1, visibility: 1, feedback: 'Adds the two numbers.'}),
        Object.assign(blankTest(), {input: input2, expected: '8', matchmode: 'trimmed',
            weight: 2, visibility: 0}),
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
            ta.value = t[key] || '';
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
            body.appendChild(fieldRow(s.assertselector || 'Selector', textInput('selector')));
            body.appendChild(fieldRow(s.assertproperty || 'Property', textInput('assertproperty')));
            body.appendChild(fieldRow(s.assertmatcher || 'Matcher', select('assertmatcher', config.assertmatchers || {})));
            body.appendChild(fieldRow(s.assertactions || 'Actions', textarea('actions', 2)));
            body.appendChild(fieldRow(s.expected || 'Expected', textarea('expected', 2)));
        } else {
            body.appendChild(fieldRow(s.input || 'Input', textarea('input', 3)));
            body.appendChild(fieldRow(s.expected || 'Expected', textarea('expected', 3)));
            body.appendChild(fieldRow(s.matchmode || 'Match mode', select('matchmode', config.matchmodes || {})));
            body.appendChild(fieldRow(s.tolerance || 'Tolerance', textInput('tolerance')));
            body.appendChild(fieldRow(s.subtask || 'Subtask', textInput('subtask')));
            if (isSql()) {
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
                const left = isWeb() ? (t.selector || '') : (t.input || '');
                head.textContent = '#' + (i + 1) + '  ' + preview(left) + ' → ' + preview(t.expected)
                    + '   [' + vis + ' · w' + t.weight + ']';
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
