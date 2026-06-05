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
import {render as renderTemplate} from 'core/templates';

const byId = (id) => document.getElementById(id);

/**
 * Read the test-case rows out of the repeated form elements, skipping blank
 * rows exactly as the server-side save does.
 *
 * @return {Array} test-case objects shaped like the renderer ships to the grader
 */
const gatherTests = () => {
    const tests = [];
    document.querySelectorAll('textarea[name^="testinput["]').forEach((ta) => {
        const match = ta.name.match(/\[(\d+)\]/);
        if (!match) {
            return;
        }
        const i = match[1];
        const field = (base) => {
            const el = document.querySelector('[name="' + base + '[' + i + ']"]');
            return el ? el.value : '';
        };
        const input = ta.value;
        const expected = field('testexpected');
        if (input.trim() === '' && expected.trim() === '') {
            return;
        }
        const visibility = parseInt(field('testvisibility'), 10);
        tests.push({
            input: input,
            expected: expected,
            matchmode: field('matchmode'),
            tolerance: field('tolerance'),
            weight: field('testweight'),
            visibility: visibility,
            checkerquery: field('checkerquery'),
            // Mirror the renderer: hidden tests reveal nothing client-side, feedback included.
            feedback: visibility === 1 ? field('testfeedback') : null,
        });
    });
    return tests;
};

/**
 * The non-blank test rows with their form elements, for run-to-capture.
 *
 * @return {Array} {input, checkerquery, expectedEl}
 */
const activeRows = () => {
    const rows = [];
    document.querySelectorAll('textarea[name^="testinput["]').forEach((ta) => {
        const match = ta.name.match(/\[(\d+)\]/);
        if (!match) {
            return;
        }
        const i = match[1];
        const expectedEl = document.querySelector('[name="testexpected[' + i + ']"]');
        const checkerEl = document.querySelector('[name="checkerquery[' + i + ']"]');
        const input = ta.value;
        const expected = expectedEl ? expectedEl.value : '';
        if (input.trim() === '' && expected.trim() === '') {
            return;
        }
        rows.push({input: input, checkerquery: checkerEl ? checkerEl.value : '', expectedEl: expectedEl});
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
 * @param {Object} config field ids, preview targets, strings and runtime URLs
 */
export const init = (config) => {
    const langSel = byId(config.languageid);
    const scenSel = byId(config.scenarioid);
    const starterTa = byId(config.startercodeid);
    const schemaTa = byId(config.schemasqlid);

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

        const rebuild = () => {
            if (otherEditor && currentOther) {
                map[currentOther] = otherEditor.getValue();
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

    const btn = byId(config.previewbtnid);
    const container = byId(config.previewid);
    if (!btn || !container) {
        return;
    }

    btn.addEventListener('click', () => {
        const wrapperid = config.previewid + '_wb';
        const context = Object.assign({}, config.previewcontext, {
            wrapperid: wrapperid,
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
            initControl({
                wrapperid: wrapperid,
                language: langSel ? langSel.value : 'python',
                scenario: scenSel ? scenSel.value : 'stdinstdout',
                entrypoint: byId(config.entrypointid) ? byId(config.entrypointid).value : '',
                schemasql: schemaTa ? schemaTa.value : '',
                pyodidebaseurl: config.pyodidebaseurl,
                sqljsbaseurl: config.sqljsbaseurl,
                tests: gatherTests(),
                timeoutms: config.timeoutms,
                readonly: false,
                strings: config.strings,
            });
            return null;
        }).catch(() => {
            container.textContent = config.strings.runerror || 'Preview could not be rendered.';
        });
    });

    // Run-to-capture: run the solution written in the preview editor against every
    // test input and write the captured output into the Expected fields.
    const captureBtn = byId(config.captureid);
    const statusEl = byId(config.statusid);
    const strings = config.strings || {};
    const setStatus = (text) => {
        if (statusEl) {
            statusEl.textContent = text;
        }
    };
    if (captureBtn) {
        captureBtn.addEventListener('click', () => {
            const refTa = container.querySelector('[data-role="answer"]');
            if (!refTa) {
                setStatus(strings.capturepreviewfirst || 'Refresh the preview and write a solution first.');
                return;
            }
            const rows = activeRows();
            if (!rows.length) {
                setStatus(strings.capturenotests || 'Add at least one test case first.');
                return;
            }
            const scenario = scenSel ? scenSel.value : 'stdinstdout';
            const cases = rows.map((row) => ({input: row.input, checkerquery: row.checkerquery}));
            setStatus(strings.running || 'Running…');
            captureBtn.disabled = true;
            runCode(refTa.value, langSel ? langSel.value : 'python', cases, {
                scenario: scenario,
                entrypoint: byId(config.entrypointid) ? byId(config.entrypointid).value : '',
                schemasql: schemaTa ? schemaTa.value : '',
                pyodideBaseUrl: config.pyodidebaseurl,
                sqljsBaseUrl: config.sqljsbaseurl,
                timeoutMs: config.timeoutms,
            }).then((res) => {
                const results = res.results || [];
                let ok = 0;
                let failed = 0;
                rows.forEach((row, k) => {
                    const expected = capturedExpected(results[k], scenario);
                    if (expected === null || !row.expectedEl) {
                        failed++;
                        return;
                    }
                    row.expectedEl.value = expected;
                    row.expectedEl.dispatchEvent(new Event('change', {bubbles: true}));
                    ok++;
                });
                if (failed === 0) {
                    setStatus((strings.capturedone || 'Captured {$a} test(s).').replace('{$a}', String(ok)));
                } else {
                    const tmpl = strings.captureerrors || 'Captured {$a->ok} of {$a->total}; {$a->failed} errored.';
                    setStatus(tmpl.replace('{$a->ok}', String(ok))
                        .replace('{$a->total}', String(rows.length))
                        .replace('{$a->failed}', String(failed)));
                }
                return null;
            }).catch((err) => {
                setStatus((strings.runerror || 'Could not run') + ': ' + String(err));
            }).then(() => {
                captureBtn.disabled = false;
            });
        });
    }
};
