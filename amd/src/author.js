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
};
