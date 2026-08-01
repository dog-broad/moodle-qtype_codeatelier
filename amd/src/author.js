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
 * Authoring enhancements for the CodeAtelier edit form: CodeMirror on the
 * code-data fields and a live preview of the real student workbench.
 *
 * @module qtype_codeatelier/author
 */

import {create as createEditor} from 'qtype_codeatelier/editor';
import {init as initControl} from 'qtype_codeatelier/control';
import {run as runCode} from 'qtype_codeatelier/runner';
import {grade} from 'qtype_codeatelier/grader';
import {setup as setupProjectWorkspace} from 'qtype_codeatelier/projectworkspace';
import {setupPreview} from 'qtype_codeatelier/webruntime';
import {render as renderTemplate} from 'core/templates';
import Ajax from 'core/ajax';
import {resultSetTable, parseRows} from './resulttable.js';

const byId = (id) => document.getElementById(id);

/** @var {String[]} the signature types the builder offers (mirrors signature.php). */
const SIG_TYPES = ['int', 'long', 'double', 'bool', 'string',
    'list<int>', 'list<long>', 'list<double>', 'list<bool>', 'list<string>',
    'map<string,int>', 'map<string,long>', 'map<string,double>', 'map<string,bool>', 'map<string,string>'];

/**
 * Normalise a type token to a known type (strips whitespace), or null.
 *
 * @param {String} token a raw type string
 * @return {String|null}
 */
const normaliseSigType = (token) => {
    const n = (token || '').replace(/\s+/g, '');
    return SIG_TYPES.indexOf(n) >= 0 ? n : null;
};

/**
 * Split a parameter list on commas that are not inside angle brackets.
 *
 * @param {String} list the parameter part of a signature
 * @return {String[]}
 */
const splitSigParams = (list) => {
    const parts = [];
    let depth = 0;
    let cur = '';
    for (let i = 0; i < list.length; i++) {
        const ch = list[i];
        if (ch === '<') {
            depth++;
        } else if (ch === '>') {
            depth--;
        }
        if (ch === ',' && depth === 0) {
            parts.push(cur);
            cur = '';
        } else {
            cur += ch;
        }
    }
    if (cur.trim() !== '') {
        parts.push(cur);
    }
    return parts;
};

/**
 * A type <select> preset to the given value.
 *
 * @param {String} value the selected type
 * @param {String} labelId id of the visible row label, associated for an accessible name
 * @return {HTMLSelectElement}
 */
const sigTypeSelect = (value, labelId) => {
    const sel = document.createElement('select');
    sel.className = 'form-select form-select-sm codeatelier-sigtype';
    // Associate the visible row label so the select has an accessible name
    // (it carries no <label for>, only an adjacent span).
    if (labelId) {
        sel.setAttribute('aria-labelledby', labelId);
    }
    SIG_TYPES.forEach((t) => {
        const opt = document.createElement('option');
        opt.value = t;
        opt.textContent = t;
        if (t === value) {
            opt.selected = true;
        }
        sel.appendChild(opt);
    });
    return sel;
};

/**
 * The structured signature builder: parameter/return pickers that write the
 * signature string into the functionsignature field.
 *
 * @param {Object} config the authoring config
 */
const initSignatureBuilder = (config) => {
    const field = byId(config.functionsignatureid);
    const host = byId(config.signaturebuilderid);
    if (!field || !host) {
        return;
    }
    const str = config.sigbuilderstrings || {};
    let params = [];
    let ret = 'int';

    const writeField = () => {
        field.value = params.join(', ') + ' -> ' + ret;
        field.dispatchEvent(new Event('change', {bubbles: true}));
    };

    const render = () => {
        host.textContent = '';
        params.forEach((p, idx) => {
            const row = document.createElement('div');
            row.className = 'codeatelier-sigparam';
            const label = document.createElement('span');
            label.className = 'codeatelier-block-label me-1';
            label.id = config.signaturebuilderid + '_plabel_' + idx;
            label.textContent = (str.param || 'Parameter') + ' ' + (idx + 1);
            const sel = sigTypeSelect(p, label.id);
            sel.addEventListener('change', () => {
                params[idx] = sel.value;
                writeField();
            });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-link btn-sm';
            remove.textContent = '×';
            remove.setAttribute('aria-label', (str.removeparam || 'Remove parameter') + ' ' + (idx + 1));
            remove.addEventListener('click', () => {
                params.splice(idx, 1);
                render();
                writeField();
            });
            row.appendChild(label);
            row.appendChild(sel);
            row.appendChild(remove);
            host.appendChild(row);
        });
        if (!params.length) {
            const none = document.createElement('p');
            none.className = 'text-muted small mb-1';
            none.textContent = str.noparams || 'No parameters';
            host.appendChild(none);
        }
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'btn btn-secondary btn-sm';
        add.textContent = str.addparam || 'Add parameter';
        add.addEventListener('click', () => {
            params.push('int');
            render();
            writeField();
        });
        host.appendChild(add);

        const retRow = document.createElement('div');
        retRow.className = 'codeatelier-sigreturn mt-2';
        const retLabel = document.createElement('span');
        retLabel.className = 'codeatelier-block-label me-1';
        retLabel.id = config.signaturebuilderid + '_rlabel';
        retLabel.textContent = str.returns || 'Returns';
        const retSel = sigTypeSelect(ret, retLabel.id);
        retSel.addEventListener('change', () => {
            ret = retSel.value;
            writeField();
        });
        retRow.appendChild(retLabel);
        retRow.appendChild(retSel);
        host.appendChild(retRow);
    };

    // Populate from the existing signature; default to one int parameter when empty.
    const text = (field.value || '').trim();
    const arrow = text.indexOf('->');
    if (arrow < 0) {
        params = ['int'];
        ret = 'int';
        render();
        writeField();
        return;
    }
    const before = text.slice(0, arrow).trim();
    ret = normaliseSigType(text.slice(arrow + 2)) || 'int';
    params = before === '' ? [] : splitSigParams(before).map((p) => normaliseSigType(p)).filter(Boolean);
    render();
};

/**
 * The closest Moodle form-item wrapper for an element.
 *
 * @param {HTMLElement} el
 * @return {HTMLElement|null}
 */
const fitemOf = (el) => (el ? (el.closest('.fitem') || el.closest('.form-group') || el.parentNode) : null);

/**
 * Gate the function signature on the chosen languages, and explain the contract.
 *
 * A typed signature only applies to compiled languages, which must declare their
 * argument types over a bounded grammar (scalars, list<scalar>, map<string,scalar>).
 * Dynamic languages (Python/JavaScript/…) receive the arguments as JSON of any
 * shape — including lists of objects — and need no signature. So for a
 * dynamic-only function question the signature field and builder are hidden and a
 * note explains the JSON-arguments contract; when a compiled language is allowed
 * the builder is shown with a note about the type limits. This keeps the teacher
 * from guessing whether a nested-type question is supportable.
 *
 * @param {Object} config the authoring config
 */
const initSignatureVisibility = (config) => {
    const field = byId(config.functionsignatureid);
    const host = byId(config.signaturebuilderid);
    const scenSel = byId(config.scenarioid);
    if (!field || !scenSel) {
        return;
    }
    const langSel = byId(config.languageid);
    const langsSel = byId(config.languagesid);
    const str = config.sigbuilderstrings || {};
    const staticLangs = config.staticfunctionlangs || [];

    const note = document.createElement('div');
    note.className = 'codeatelier-signote text-muted small';
    note.setAttribute('data-role', 'signature-note');
    const anchor = fitemOf(host) || fitemOf(field);
    if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(note, anchor.nextSibling);
    }

    const allowedLangs = () => {
        const set = [langSel ? langSel.value : ''];
        if (langsSel) {
            Array.prototype.slice.call(langsSel.selectedOptions || []).forEach((o) => {
                if (set.indexOf(o.value) === -1) {
                    set.push(o.value);
                }
            });
        }
        return set.filter(Boolean);
    };

    const update = () => {
        const isfunc = scenSel.value === 'function';
        const needsSig = isfunc && allowedLangs().some((l) => staticLangs.indexOf(l) !== -1);
        // Hide the signature field + builder for a dynamic-only function question.
        // A class (not inline style) so it survives Moodle's hideIf re-showing the
        // field on scenario changes — the rule carries !important.
        const hide = isfunc && !needsSig;
        [fitemOf(field), fitemOf(host)].forEach((item) => {
            if (item) {
                item.classList.toggle('codeatelier-sighide', hide);
            }
        });
        note.style.display = isfunc ? '' : 'none';
        if (isfunc) {
            note.textContent = needsSig ? (str.staticnote || '') : (str.dynamicnote || '');
        }
    };

    update();
    scenSel.addEventListener('change', update);
    if (langSel) {
        langSel.addEventListener('change', update);
    }
    if (langsSel) {
        langsSel.addEventListener('change', update);
    }
};

/**
 * The test-editor model API, attached to the testcasesjson field by the
 * testeditor module. Read lazily (at click time) since both modules init
 * independently. Null if the editor has not mounted (e.g. JS-off fallback).
 *
 * @return {Object|null} {getTests, setExpected, fillAssertion}
 */
const testEditor = () => {
    const field = byId('id_testcasesjson');
    return field && field.codeatelierTestEditor ? field.codeatelierTestEditor : null;
};

/**
 * Read the test cases from the editor model, skipping blank rows exactly as the
 * server-side save does.
 *
 * @return {Array} test-case objects shaped like the renderer ships to the grader
 */
const gatherTests = () => {
    const api = testEditor();
    if (!api) {
        return [];
    }
    return api.getTests().map((t) => {
        const visibility = parseInt(t.visibility, 10);
        return {
            input: t.input || '',
            expected: t.expected || '',
            matchmode: t.matchmode,
            tolerance: t.tolerance,
            weight: t.weight,
            visibility: visibility,
            checkerquery: t.checkerquery || '',
            // SQL per-test dataset (replaces the question schema for this test) — must
            // ride along or the preview runs every test on the question schema.
            datasetsql: t.datasetsql || '',
            // Mirror the renderer: hidden tests reveal nothing client-side, feedback included.
            feedback: visibility === 1 ? (t.feedback || '') : null,
        };
    }).filter((t) => !(t.input.trim() === '' && t.expected.trim() === ''
        && t.checkerquery.trim() === '' && t.datasetsql.trim() === ''));
};

/**
 * The non-blank test cases with their model index, for run-to-capture.
 *
 * @return {Array} {input, expected, matchmode, checkerquery, index}
 */
const activeRows = () => {
    const api = testEditor();
    if (!api) {
        return [];
    }
    const rows = [];
    api.getTests().forEach((t, index) => {
        const input = t.input || '';
        const expected = t.expected || '';
        const checkerquery = t.checkerquery || '';
        const datasetsql = t.datasetsql || '';
        // A row is active if any of its inputs is set (SQL SELECT rows have no
        // stdin, so a checker query, a per-test dataset, or an expected value
        // also counts — a dataset-only row still needs its Expected captured).
        if (input.trim() === '' && expected.trim() === ''
                && checkerquery.trim() === '' && datasetsql.trim() === '') {
            return;
        }
        rows.push({
            input: input,
            expected: expected,
            matchmode: t.matchmode || 'trimmed',
            checkerquery: checkerquery,
            datasetsql: datasetsql,
            index: index,
        });
    });
    return rows;
};

/**
 * Turn one run result into the text written into a test's Expected field.
 *
 * @param {Object} result a single runner result
 * @param {String} scenario stdinstdout | function | sql
 * @return {String|null} the expected text, or null if the run errored
 */
const capturedExpected = (result, scenario) => {
    if (!result || result.error) {
        return null;
    }
    if (scenario === 'sql') {
        return JSON.stringify(result.rows || []);
    }
    if (scenario === 'function') {
        const value = result.returnvalue;
        // Python returns JSON already; JavaScript returns the raw value.
        return (typeof value === 'string') ? value : JSON.stringify(value);
    }
    return (result.stdout === undefined || result.stdout === null) ? '' : String(result.stdout);
};

/**
 * Initialise the authoring enhancements.
 *
 * The config (field ids, preview targets, every UI string, runtime URLs) is
 * carried on the preview pane's data-config attribute rather than the AMD init
 * args, which Moodle caps at 1024 chars. Only the pane id arrives through params.
 *
 * @param {Object} params - {previewid}
 */
export const init = (params) => {
    const pane = document.getElementById(params.previewid);
    if (!pane || !pane.dataset.config) {
        return;
    }
    const config = JSON.parse(pane.dataset.config);
    const langSel = byId(config.languageid);
    const scenSel = byId(config.scenarioid);
    const starterTa = byId(config.startercodeid);
    const schemaTa = byId(config.schemasqlid);

    // Trust badge: show whether grading is judge-secure or practice-only, and keep
    // it in sync with the tier picker.
    const tierBadge = byId(config.tierbadgeid);
    const tierSelForBadge = byId(config.tierid);
    if (tierBadge && tierSelForBadge) {
        const badges = config.tierbadges || {};
        const paintBadge = () => {
            const isjudge = tierSelForBadge.value === 'judge';
            tierBadge.textContent = isjudge ? (badges.judge || '') : (badges.practice || '');
            // bg-* + text-* (not the BS4 badge-* contextual classes, which BS5
            // deprecates): these utilities exist identically in Bootstrap 4 and 5.
            tierBadge.className = 'badge ' + (isjudge ? 'bg-success text-white' : 'bg-warning text-dark');
        };
        paintBadge();
        tierSelForBadge.addEventListener('change', paintBadge);
    }

    // Signature builder: structured parameter/return type pickers that write the
    // signature string into the functionsignature field (which still accepts typed
    // input — the builder is an enhancement that keeps it in sync).
    initSignatureBuilder(config);
    initSignatureVisibility(config);

    // CodeMirror on the starter-code field, re-mounted when the language changes.
    if (starterTa) {
        let starterEditor = null;
        const mountStarter = () => {
            if (starterEditor) {
                starterEditor.destroy();
            }
            starterEditor = createEditor(starterTa, langSel ? langSel.value : 'python', {});
        };
        mountStarter();
        if (langSel) {
            langSel.addEventListener('change', mountStarter);
        }
    }

    // The file-set starter editor: the same file-tab editor the student attempt
    // uses, mounted on the projectfiles field while a file-set scenario (project or
    // web) is selected and re-mounted on a scenario or language change.
    const projectfilesTa = byId(config.projectfilesid);
    const entryfileField = byId(config.entryfileid);
    // A sensible default frontend file set so a new web question is editable at once.
    const WEB_STARTER = JSON.stringify([
        {name: 'index.html', content: '<!doctype html>\n<html>\n<head>\n'
            + '  <link rel="stylesheet" href="style.css">\n</head>\n<body>\n'
            + '  <h1>Hello</h1>\n  <script src="script.js"></script>\n</body>\n</html>\n'},
        {name: 'style.css', content: 'h1 {\n  color: navy;\n}\n'},
        {name: 'script.js', content: '// Your JavaScript runs when the page loads.\n'},
    ]);
    if (projectfilesTa && scenSel) {
        const strings = config.strings || {};
        let projectEditor = null;
        let webPicker = null;

        // Fill an assertion from a picked element via the editor model: its
        // selector, plus sensible defaults (check the text equals the element's).
        const fillAssertionRow = (selector, text, message) => {
            const api = testEditor();
            if (!api) {
                return;
            }
            api.fillAssertion(selector, text);
            if (message) {
                message.textContent = (strings.pickfilled || 'Added a check for') + ' ' + selector;
            }
        };

        // Build the "pick an element" preview + button for a web question.
        const buildWebPicker = () => {
            const fitem = projectfilesTa.closest('.fitem') || projectfilesTa.parentNode;
            const wrap = document.createElement('div');
            wrap.className = 'codeatelier-webpicker';
            const label = document.createElement('span');
            label.className = 'codeatelier-block-label';
            label.textContent = strings.previewpick || 'Preview — pick an element to add a check';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-secondary codeatelier-pickbtn';
            btn.textContent = strings.pickelement || 'Pick element';
            const message = document.createElement('span');
            message.className = 'codeatelier-pickmsg small text-muted';
            const host = document.createElement('div');
            host.className = 'codeatelier-preview-host';
            wrap.appendChild(label);
            wrap.appendChild(btn);
            wrap.appendChild(message);
            wrap.appendChild(host);
            fitem.parentNode.insertBefore(wrap, fitem.nextSibling);

            const preview = setupPreview(host, projectfilesTa, {readonly: false}, strings);
            let picking = false;
            const stop = () => {
                picking = false;
                preview.setPickMode(false);
                btn.classList.remove('btn-primary');
                btn.textContent = strings.pickelement || 'Pick element';
            };
            preview.onPick((selector, text) => {
                fillAssertionRow(selector, text, message);
                stop();
            });
            btn.addEventListener('click', () => {
                picking = !picking;
                preview.setPickMode(picking);
                btn.classList.toggle('btn-primary', picking);
                btn.textContent = picking
                    ? (strings.pickcancel || 'Click an element in the preview…')
                    : (strings.pickelement || 'Pick element');
                if (picking) {
                    message.textContent = '';
                }
            });
            return {preview: preview, wrap: wrap, refresh: () => preview.scheduleRefresh()};
        };

        const teardownWebPicker = () => {
            if (webPicker) {
                webPicker.preview.destroy();
                if (webPicker.wrap.parentNode) {
                    webPicker.wrap.parentNode.removeChild(webPicker.wrap);
                }
                webPicker = null;
            }
        };

        const mountProject = () => {
            if (projectEditor) {
                projectEditor.destroy();
                projectEditor = null;
            }
            teardownWebPicker();
            const scen = scenSel.value;
            if (scen !== 'project' && scen !== 'web') {
                return;
            }
            // Seed the HTML/CSS/JS scaffold for web unless the field already holds a
            // real web file set — so a fresh web question, or one switched in from
            // another scenario (whose field holds a project blob, not a flat set),
            // starts from the helpful three-file starter rather than a bare file.
            if (scen === 'web') {
                let flat = null;
                try {
                    flat = JSON.parse(projectfilesTa.value || 'null');
                } catch (e) {
                    flat = null;
                }
                if (!Array.isArray(flat) || !flat.length) {
                    projectfilesTa.value = WEB_STARTER;
                }
            }
            // For web, refresh the picker preview as the starter files change.
            const onChange = scen === 'web'
                ? () => {
                    if (webPicker) {
                        webPicker.refresh();
                    }
                }
                : undefined;
            const fblang = langSel ? langSel.value : 'cpp';
            if (scen === 'project') {
                // The project workspace: a two-pane explorer over the language-keyed
                // env blob, with the allowed-language switch and Starter|Solution toggle.
                // Look the allowed-languages select up locally (the shared `langsSel`
                // const is declared later in init, so referencing it here — mountProject
                // runs at init for an existing project — is a temporal-dead-zone error).
                const allowedSel = byId(config.languagesid);
                const allowedlangs = [fblang];
                if (allowedSel) {
                    Array.prototype.slice.call(allowedSel.selectedOptions || []).forEach((o) => {
                        if (allowedlangs.indexOf(o.value) === -1) {
                            allowedlangs.push(o.value);
                        }
                    });
                }
                projectEditor = setupProjectWorkspace(null, projectfilesTa, {
                    author: true,
                    language: fblang,
                    languages: allowedlangs,
                    readonly: false,
                }, config.strings || {});
            } else {
                // The web (frontend) scenario is a single, flat HTML/CSS/JS file set
                // (no language choice, no Starter|Solution layer — web grades against
                // DOM assertions). The explorer workspace in its flat mode (no author
                // blob) is exactly that; index.html stays the served page by convention.
                projectEditor = setupProjectWorkspace(null, projectfilesTa, {
                    web: true,
                    language: 'html',
                    entryfile: 'index.html',
                    readonly: false,
                }, config.strings || {}, onChange);
                webPicker = buildWebPicker();
            }
        };
        mountProject();
        scenSel.addEventListener('change', mountProject);
        const remountProject = () => {
            if (scenSel.value === 'project') {
                mountProject();
            }
        };
        if (langSel) {
            langSel.addEventListener('change', remountProject);
        }
        const projLangsSel = byId(config.languagesid);
        if (projLangsSel) {
            projLangsSel.addEventListener('change', remountProject);
        }
    }

    // Per-language starter code for the ADDITIONAL allowed languages (the primary
    // language uses the Starter code field above). One editor that swaps as the
    // author picks a language; the JSON map is kept in a hidden field. The section
    // hides itself when only the primary language is allowed.
    const langsSel = byId(config.languagesid);
    const starterLangSel = byId(config.starterlangid);
    const starterEditTa = byId(config.startereditid);
    const startersHidden = byId(config.startersid);
    if (starterLangSel && starterEditTa && startersHidden) {
        let map = {};
        try {
            map = JSON.parse(startersHidden.value || '{}') || {};
        } catch (e) {
            map = {};
        }
        let otherEditor = null;
        let currentOther = null;

        const primary = () => (langSel ? langSel.value : 'python');
        const allowed = () => {
            const set = [primary()];
            if (langsSel) {
                Array.prototype.slice.call(langsSel.selectedOptions || []).forEach((o) => {
                    if (set.indexOf(o.value) === -1) {
                        set.push(o.value);
                    }
                });
            }
            return set;
        };
        const others = () => allowed().filter((l) => l !== primary());

        const serialize = () => {
            const al = allowed();
            const out = {};
            Object.keys(map).forEach((k) => {
                if (k !== primary() && al.indexOf(k) !== -1 && String(map[k]).trim() !== '') {
                    out[k] = map[k];
                }
            });
            startersHidden.value = Object.keys(out).length ? JSON.stringify(out) : '';
        };

        const toggle = (visible) => {
            [config.starterlangid, config.startereditid].forEach((id) => {
                const item = byId('fitem_' + id);
                if (item) {
                    item.style.display = visible ? '' : 'none';
                }
            });
        };

        const mountOther = (language) => {
            if (otherEditor) {
                otherEditor.destroy();
                otherEditor = null;
            }
            starterEditTa.value = map[language] || '';
            otherEditor = createEditor(starterEditTa, language, {
                onChange: () => {
                    map[language] = otherEditor.getValue();
                    serialize();
                },
            });
        };

        // Project and web questions author their per-language starters in the file
        // workspace (per-file, with a Starter|Solution layer), so the single-file
        // per-language starter editor must not appear for them.
        const fileSetScenario = () => {
            const sc = byId(config.scenarioid);
            return !!sc && (sc.value === 'project' || sc.value === 'web');
        };

        const rebuild = () => {
            if (otherEditor && currentOther) {
                map[currentOther] = otherEditor.getValue();
            }
            if (fileSetScenario()) {
                if (otherEditor) {
                    otherEditor.destroy();
                    otherEditor = null;
                }
                currentOther = null;
                toggle(false);
                return;
            }
            const list = others();
            starterLangSel.innerHTML = '';
            list.forEach((l) => {
                const opt = document.createElement('option');
                opt.value = l;
                opt.textContent = l;
                starterLangSel.appendChild(opt);
            });
            if (!list.length) {
                if (otherEditor) {
                    otherEditor.destroy();
                    otherEditor = null;
                }
                currentOther = null;
                toggle(false);
                serialize();
                return;
            }
            toggle(true);
            if (list.indexOf(currentOther) === -1) {
                currentOther = list[0];
            }
            starterLangSel.value = currentOther;
            mountOther(currentOther);
            serialize();
        };

        starterLangSel.addEventListener('change', () => {
            if (otherEditor && currentOther) {
                map[currentOther] = otherEditor.getValue();
            }
            currentOther = starterLangSel.value;
            mountOther(currentOther);
        });
        if (langSel) {
            langSel.addEventListener('change', rebuild);
        }
        if (langsSel) {
            langsSel.addEventListener('change', rebuild);
        }
        const starterScen = byId(config.scenarioid);
        if (starterScen) {
            starterScen.addEventListener('change', rebuild);
        }
        rebuild();
    }

    // CodeMirror (SQL) on the schema field, mounted the first time SQL is active
    // (mounting while the field is hidden would give CodeMirror no layout).
    if (schemaTa) {
        let schemaEditor = null;
        const ensureSchema = () => {
            if (!schemaEditor && scenSel && scenSel.value === 'sql') {
                schemaEditor = createEditor(schemaTa, 'sql', {});
            }
        };
        ensureSchema();
        if (scenSel) {
            scenSel.addEventListener('change', ensureSchema);
        }
    }

    // The reference solution: a single CodeMirror editor in the primary language,
    // or — for a project — a file-tab editor on the same field (a complete file set
    // used for capture and validate-on-save). Re-mounted on a scenario/language change.
    const refSolTa = byId(config.referencesolutionid);
    let refEditor = null;
    if (refSolTa) {
        const mountRef = () => {
            if (refEditor) {
                refEditor.destroy();
                refEditor = null;
            }
            // A project's reference is the workspace's Solution layer (per language,
            // in the projectfiles blob) — no separate reference editor for projects.
            if (scenSel && scenSel.value === 'project') {
                return;
            }
            refEditor = createEditor(refSolTa, langSel ? langSel.value : 'python', {});
        };
        mountRef();
        if (langSel) {
            langSel.addEventListener('change', mountRef);
        }
        if (scenSel) {
            scenSel.addEventListener('change', mountRef);
        }
    }
    // For a project, the reference is the workspace Solution layer of the primary
    // language as a flat file set (solution content, falling back to starter), plus
    // its entry — the shape run-reference packages and runs to capture/validate.
    const projectReference = () => {
        let blob = null;
        try {
            blob = JSON.parse(projectfilesTa ? projectfilesTa.value : '');
        } catch (e) {
            blob = null;
        }
        const primary = langSel ? langSel.value : 'python';
        const envs = (blob && blob.envs) ? blob.envs : {};
        const env = envs[primary] || envs[Object.keys(envs)[0]];
        if (!env || !Array.isArray(env.files) || !env.files.length) {
            return {code: '[]', entry: ''};
        }
        const files = env.files.map((f) => ({
            name: f.name,
            content: (typeof f.solution === 'string' && f.solution !== '') ? f.solution : (f.starter || ''),
        }));
        return {code: JSON.stringify(files), entry: env.entry || (files[0] && files[0].name) || ''};
    };
    const referenceValue = () => {
        if (scenSel && scenSel.value === 'project') {
            return projectReference().code;
        }
        if (refEditor) {
            return refEditor.getValue();
        }
        return refSolTa ? refSolTa.value : '';
    };

    const btn = byId(config.previewbtnid);
    const container = byId(config.previewid);
    if (!btn || !container) {
        return;
    }

    btn.addEventListener('click', () => {
        const wrapperid = config.previewid + '_wb';
        const scen = scenSel ? scenSel.value : 'stdinstdout';
        const entryfileField = byId(config.entryfileid);
        const langsSel = byId(config.languagesid);
        const tierSel = byId(config.tierid);
        const allornothing = byId('id_allornothing');

        const allowedLangs = () => {
            const set = [langSel ? langSel.value : 'python'];
            if (langsSel) {
                Array.prototype.slice.call(langsSel.selectedOptions || []).forEach((o) => {
                    if (set.indexOf(o.value) === -1) {
                        set.push(o.value);
                    }
                });
            }
            return set;
        };
        let starterMap = {};
        const startersHidden = byId(config.startersid);
        if (startersHidden) {
            try {
                starterMap = JSON.parse(startersHidden.value || '{}') || {};
            } catch (e) {
                starterMap = {};
            }
        }

        // control.js reads its config from the wrapper's data-config attribute (the
        // student renderer ships it the same way), so build the full control config
        // and hand it to the template — not as initControl params, which are ignored
        // beyond the wrapper id.
        const controlconfig = {
            wrapperid: wrapperid,
            language: langSel ? langSel.value : 'python',
            languages: allowedLangs(),
            starters: starterMap,
            scenario: scen,
            entrypoint: byId(config.entrypointid) ? byId(config.entrypointid).value : '',
            entryfile: scen === 'web' ? 'index.html' : (entryfileField ? entryfileField.value.trim() : ''),
            schemasql: (scen === 'sql' && schemaTa) ? schemaTa.value : null,
            pyodidebaseurl: config.pyodidebaseurl,
            sqljsbaseurl: config.sqljsbaseurl,
            tests: gatherTests(),
            timeoutms: config.timeoutms,
            readonly: false,
            isjudge: !!(tierSel && tierSel.value === 'judge'),
            allornothing: !!(allornothing && allornothing.checked),
            editortheme: 'auto',
            strings: config.strings,
        };

        const context = Object.assign({}, config.previewcontext, {
            wrapperid: wrapperid,
            controlconfig: JSON.stringify(controlconfig),
            questiontext: byId(config.questiontextid) ? byId(config.questiontextid).value : '',
            answername: wrapperid + '_answer',
            answerid: wrapperid + '_answer',
            answervalue: starterTa ? starterTa.value : '',
            scorename: wrapperid + '_score',
            scorevalue: '',
            readonly: false,
            hasvalidationerror: false,
            validationerror: '',
        });

        renderTemplate('qtype_codeatelier/editor_control', context).then((html) => {
            container.innerHTML = html;
            // The preview is inside the edit form: its fields must never be submitted.
            container.querySelectorAll('[data-role="answer"], [data-role="score"]').forEach((el) => {
                el.removeAttribute('name');
            });
            initControl({wrapperid: wrapperid});
            return null;
        }).catch((err) => {
            container.textContent = (config.strings.runerror || 'Preview could not be rendered.')
                + ' ' + String(err && err.message ? err.message : err);
        });
    });

    // Run-to-capture + validate: run the REFERENCE solution against every test and
    // write each produced output into the Expected fields. Practice runs in the
    // browser; judge runs server-side via Judge0 (so it covers compiled languages).
    const captureBtn = byId(config.captureid);
    const statusEl = byId(config.statusid);
    const tierSel = byId(config.tierid);
    const cstr = config.capturestrings || {};
    const isJudge = () => !!(tierSel && tierSel.value === 'judge');
    const setStatus = (text) => {
        if (statusEl) {
            statusEl.textContent = text;
        }
    };

    // Per-test review panel rendered after a capture, so the author can confirm
    // what was written into each Expected field (and which tests errored) without
    // opening every row. Replaces itself on each capture; cleared on the next run.
    const clearCaptureReview = () => {
        if (!statusEl || !statusEl.parentNode) {
            return;
        }
        const existing = statusEl.parentNode.querySelector('[data-role="capture-review"]');
        if (existing) {
            existing.remove();
        }
    };
    const renderCaptureReview = (rows, items, scenario) => {
        clearCaptureReview();
        if (!statusEl || !statusEl.parentNode || !rows.length) {
            return;
        }
        const panel = document.createElement('div');
        panel.className = 'codeatelier-capture-review';
        panel.setAttribute('data-role', 'capture-review');
        const heading = document.createElement('p');
        heading.className = 'codeatelier-block-label';
        heading.textContent = cstr.reviewheading || 'Captured outputs — review each before saving';
        panel.appendChild(heading);

        const list = document.createElement('ul');
        list.className = 'codeatelier-capture-list';
        rows.forEach((row, k) => {
            const item = items[k] || {};
            const errored = (item.output === null || item.output === undefined);
            const li = document.createElement('li');
            li.className = 'codeatelier-capture-item ' + (errored ? 'codeatelier-fail' : 'codeatelier-pass');

            const status = document.createElement('span');
            status.className = 'codeatelier-test-status codeatelier-status-' + (errored ? 'fail' : 'pass');
            status.textContent = (cstr.test || 'Test') + ' ' + (row.index + 1) + ': '
                + (errored ? (cstr.itemerror || 'Could not run') : (cstr.itemok || 'Captured'));
            li.appendChild(status);

            if (errored) {
                if (item.error) {
                    const err = document.createElement('pre');
                    err.className = 'codeatelier-test-error';
                    err.textContent = String(item.error);
                    li.appendChild(err);
                }
            } else {
                const label = document.createElement('span');
                label.className = 'codeatelier-diff-label';
                label.textContent = cstr.expected || 'Expected';
                li.appendChild(label);
                const sqlrows = (scenario === 'sql') ? parseRows(item.output) : null;
                if (sqlrows !== null) {
                    const cols = item.columns ? parseRows(item.columns) : null;
                    li.appendChild(resultSetTable(cols, sqlrows, null, {strings: {rowsnote: cstr.rowsnote}}));
                } else {
                    const pre = document.createElement('pre');
                    pre.className = 'codeatelier-diff-value';
                    pre.textContent = String(item.output);
                    li.appendChild(pre);
                }
            }
            list.appendChild(li);
        });
        panel.appendChild(list);
        statusEl.parentNode.insertBefore(panel, statusEl.nextSibling);
    };

    // Run the reference in-browser; one captured-output string (or null) per row.
    const capturePractice = (code, rows) => {
        const scenario = scenSel ? scenSel.value : 'stdinstdout';
        const cases = rows.map((row) => ({
            input: row.input, checkerquery: row.checkerquery, datasetsql: row.datasetsql,
        }));
        return runCode(code, langSel ? langSel.value : 'python', cases, {
            scenario: scenario,
            entrypoint: byId(config.entrypointid) ? byId(config.entrypointid).value : '',
            schemasql: schemaTa ? schemaTa.value : '',
            pyodideBaseUrl: config.pyodidebaseurl,
            sqljsBaseUrl: config.sqljsbaseurl,
            timeoutMs: config.timeoutms,
        }).then((res) => {
            const results = res.results || [];
            return rows.map((row, k) => {
                const r = results[k];
                return {
                    output: capturedExpected(r, scenario),
                    error: (r && r.error) ? String(r.error) : null,
                    columns: (scenario === 'sql' && r && r.columns) ? JSON.stringify(r.columns) : '',
                };
            });
        });
    };

    // Run the reference server-side (Judge0) against all rows; one produced-output
    // string (or null on a per-test error) per row.
    const captureJudge = (code, rows) => {
        return Ajax.call([{
            methodname: 'qtype_codeatelier_run_reference',
            args: {
                contextid: config.contextid,
                code: code,
                language: langSel ? langSel.value : 'python',
                scenario: scenSel ? scenSel.value : 'stdinstdout',
                entrypoint: byId(config.entrypointid) ? byId(config.entrypointid).value : '',
                functionsignature: byId('id_functionsignature') ? byId('id_functionsignature').value : '',
                entryfile: (scenSel && scenSel.value === 'project')
                    ? projectReference().entry
                    : (entryfileField ? entryfileField.value.trim() : ''),
                schemasql: schemaTa ? schemaTa.value : '',
                cputimelimit: byId('id_cputimelimit') ? byId('id_cputimelimit').value : '',
                memorylimit: byId('id_memorylimit') ? byId('id_memorylimit').value : '',
                tests: rows.map((row) => ({
                    input: row.input,
                    matchmode: row.matchmode,
                    checkerquery: row.checkerquery,
                    datasetsql: row.datasetsql,
                })),
            },
        }])[0].then((res) => (res.results || []).map((r) => ({
            output: r.ok ? r.output : null,
            error: r.ok ? null : (r.error || r.message || null),
            columns: r.columns || '',
        })));
    };

    const reportCapture = (ok, failed, total, firsterror) => {
        if (failed === 0) {
            setStatus((cstr.capturedone || 'Captured {$a} test(s).').replace('{$a}', String(ok)));
            return;
        }
        const tmpl = cstr.captureerrors || 'Captured {$a->ok} of {$a->total}; {$a->failed} errored.';
        let msg = tmpl.replace('{$a->ok}', String(ok))
            .replace('{$a->total}', String(total))
            .replace('{$a->failed}', String(failed));
        // Surface the first underlying error so the teacher can fix the cause (a
        // common one for function questions: the Input must be the JSON argument
        // list, so a single list argument is wrapped — [[...]], not [...]).
        if (firsterror) {
            msg += ' ' + (cstr.capturefirsterror || 'First error: {$a}').replace('{$a}', firsterror);
        }
        setStatus(msg);
    };

    if (captureBtn) {
        captureBtn.addEventListener('click', () => {
            const code = referenceValue();
            if (!code || code.trim() === '') {
                setStatus(cstr.capturenoreference || 'Write a reference solution first.');
                return;
            }
            const rows = activeRows();
            if (!rows.length) {
                setStatus(cstr.capturenotests || 'Add at least one test case first.');
                return;
            }
            setStatus(cstr.running || 'Running…');
            clearCaptureReview();
            captureBtn.disabled = true;
            const api = testEditor();
            const scenario = scenSel ? scenSel.value : 'stdinstdout';
            (isJudge() ? captureJudge(code, rows) : capturePractice(code, rows)).then((items) => {
                let ok = 0;
                let failed = 0;
                let firsterror = null;
                rows.forEach((row, k) => {
                    const item = items[k] || {};
                    if (item.output === null || item.output === undefined || !api) {
                        failed++;
                        if (!firsterror && item.error) {
                            firsterror = item.error;
                        }
                        return;
                    }
                    api.setExpected(row.index, item.output);
                    ok++;
                });
                reportCapture(ok, failed, rows.length, firsterror);
                renderCaptureReview(rows, items, scenario);
                return null;
            }).catch((err) => {
                setStatus(String(err && err.message ? err.message : err));
            }).then(() => {
                captureBtn.disabled = false;
            });
        });
    }

    // Practice validate-on-save: warn (never block) if the reference fails a
    // visible test. Judge questions are validated server-side on save instead.
    const refForm = refSolTa ? refSolTa.closest('form') : null;
    if (refForm) {
        let warned = false;
        refForm.addEventListener('submit', (e) => {
            // Skip cancel (and any non-save submitter) and judge (server-validated).
            if (warned || isJudge() || (e.submitter && e.submitter.name === 'cancel')) {
                return;
            }
            const code = referenceValue();
            const tests = gatherTests().filter((t) => Number(t.visibility) === 1);
            if (!code || code.trim() === '' || !tests.length) {
                return;
            }
            e.preventDefault();
            warned = true;
            const submitter = e.submitter;
            const resubmit = () => (submitter ? refForm.requestSubmit(submitter) : refForm.requestSubmit());
            const cases = tests.map((t) => ({input: t.input, checkerquery: t.checkerquery}));
            runCode(code, langSel ? langSel.value : 'python', cases, {
                scenario: scenSel ? scenSel.value : 'stdinstdout',
                entrypoint: byId(config.entrypointid) ? byId(config.entrypointid).value : '',
                schemasql: schemaTa ? schemaTa.value : '',
                pyodideBaseUrl: config.pyodidebaseurl,
                sqljsBaseUrl: config.sqljsbaseurl,
                timeoutMs: config.timeoutms,
            }).then((res) => {
                const graded = grade(res.results || [], tests);
                if (graded.fraction >= 1) {
                    resubmit();
                    return null;
                }
                // A failing reference only warns; clicking Save again proceeds.
                const fail = graded.testresults.find((tr) => !tr.passed);
                setStatus((cstr.referencewarning || 'Warning: the reference fails test {$a}.')
                    .replace('{$a}', String((fail ? fail.index : 0) + 1)));
                return null;
            }).catch(() => {
                // Could not verify (the runtime did not load): allow the save.
                setStatus(cstr.referenceverifyfail || 'Could not verify the reference; saving anyway.');
                resubmit();
            });
        });
    }
};
