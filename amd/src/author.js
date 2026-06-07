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
import {setup as setupProjectFiles} from 'qtype_codeatelier/projectfiles';
import {render as renderTemplate} from 'core/templates';
import Ajax from 'core/ajax';

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
 * @return {Array} {input, expected, matchmode, checkerquery, expectedEl}
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
        const matchEl = document.querySelector('[name="matchmode[' + i + ']"]');
        const checkerEl = document.querySelector('[name="checkerquery[' + i + ']"]');
        const input = ta.value;
        const expected = expectedEl ? expectedEl.value : '';
        const checkerquery = checkerEl ? checkerEl.value : '';
        // A row is active if any of its inputs is set (SQL SELECT rows have no
        // stdin, so a checker query or an expected value also counts).
        if (input.trim() === '' && expected.trim() === '' && checkerquery.trim() === '') {
            return;
        }
        rows.push({
            input: input,
            expected: expected,
            matchmode: matchEl ? matchEl.value : 'trimmed',
            checkerquery: checkerquery,
            expectedEl: expectedEl,
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

    // The multi-file project starter editor: the same file-tab editor the student
    // attempt uses, mounted on the projectfiles field while the project scenario is
    // selected and re-mounted on a scenario or language change.
    const projectfilesTa = byId(config.projectfilesid);
    const entryfileField = byId(config.entryfileid);
    if (projectfilesTa && scenSel) {
        let projectEditor = null;
        const mountProject = () => {
            if (projectEditor) {
                projectEditor.destroy();
                projectEditor = null;
            }
            if (scenSel.value === 'project') {
                projectEditor = setupProjectFiles(null, projectfilesTa, {
                    language: langSel ? langSel.value : 'cpp',
                    entryfile: entryfileField ? entryfileField.value.trim() : 'main',
                    readonly: false,
                }, config.strings || {});
            }
        };
        mountProject();
        scenSel.addEventListener('change', mountProject);
        if (langSel) {
            langSel.addEventListener('change', () => {
                if (scenSel.value === 'project') {
                    mountProject();
                }
            });
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

    // The reference solution: a single CodeMirror editor in the primary language,
    // or — for a project — a file-tab editor on the same field (a complete file set
    // used for capture and validate-on-save). Re-mounted on a scenario/language change.
    const refSolTa = byId(config.referencesolutionid);
    let refEditor = null;
    if (refSolTa) {
        const mountRef = () => {
            if (refEditor) {
                refEditor.destroy();
            }
            if (scenSel && scenSel.value === 'project') {
                refEditor = setupProjectFiles(null, refSolTa, {
                    language: langSel ? langSel.value : 'cpp',
                    entryfile: entryfileField ? entryfileField.value.trim() : 'main',
                    readonly: false,
                }, config.strings || {});
            } else {
                refEditor = createEditor(refSolTa, langSel ? langSel.value : 'python', {});
            }
        };
        mountRef();
        if (langSel) {
            langSel.addEventListener('change', mountRef);
        }
        if (scenSel) {
            scenSel.addEventListener('change', mountRef);
        }
    }
    const referenceValue = () => (refEditor ? refEditor.getValue() : (refSolTa ? refSolTa.value : ''));

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

    // Run the reference in-browser; one captured-output string (or null) per row.
    const capturePractice = (code, rows) => {
        const scenario = scenSel ? scenSel.value : 'stdinstdout';
        const cases = rows.map((row) => ({input: row.input, checkerquery: row.checkerquery}));
        return runCode(code, langSel ? langSel.value : 'python', cases, {
            scenario: scenario,
            entrypoint: byId(config.entrypointid) ? byId(config.entrypointid).value : '',
            schemasql: schemaTa ? schemaTa.value : '',
            pyodideBaseUrl: config.pyodidebaseurl,
            sqljsBaseUrl: config.sqljsbaseurl,
            timeoutMs: config.timeoutms,
        }).then((res) => {
            const results = res.results || [];
            return rows.map((row, k) => capturedExpected(results[k], scenario));
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
                entryfile: entryfileField ? entryfileField.value.trim() : '',
                schemasql: schemaTa ? schemaTa.value : '',
                cputimelimit: byId('id_cputimelimit') ? byId('id_cputimelimit').value : '',
                memorylimit: byId('id_memorylimit') ? byId('id_memorylimit').value : '',
                tests: rows.map((row) => ({
                    input: row.input,
                    matchmode: row.matchmode,
                    checkerquery: row.checkerquery,
                })),
            },
        }])[0].then((res) => (res.results || []).map((r) => (r.ok ? r.output : null)));
    };

    const reportCapture = (ok, failed, total) => {
        if (failed === 0) {
            setStatus((cstr.capturedone || 'Captured {$a} test(s).').replace('{$a}', String(ok)));
        } else {
            const tmpl = cstr.captureerrors || 'Captured {$a->ok} of {$a->total}; {$a->failed} errored.';
            setStatus(tmpl.replace('{$a->ok}', String(ok))
                .replace('{$a->total}', String(total))
                .replace('{$a->failed}', String(failed)));
        }
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
            captureBtn.disabled = true;
            (isJudge() ? captureJudge(code, rows) : capturePractice(code, rows)).then((outputs) => {
                let ok = 0;
                let failed = 0;
                rows.forEach((row, k) => {
                    const out = outputs[k];
                    if (out === null || out === undefined || !row.expectedEl) {
                        failed++;
                        return;
                    }
                    row.expectedEl.value = out;
                    row.expectedEl.dispatchEvent(new Event('change', {bubbles: true}));
                    ok++;
                });
                reportCapture(ok, failed, rows.length);
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
