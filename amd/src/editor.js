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
 * CodeMirror 6 editor for qtype_codeatelier.
 *
 * The CodeMirror packages are bundled into this module at build time (see
 * rollup.config.mjs), so it ships as a single AMD module served by Moodle's
 * requirejs loader. No code is fetched from an external CDN at runtime.
 *
 * @module qtype_codeatelier/editor
 */

import {EditorView, basicSetup} from 'codemirror';
import {EditorState} from '@codemirror/state';
import {keymap} from '@codemirror/view';
import {indentWithTab} from '@codemirror/commands';
import {python} from '@codemirror/lang-python';
import {sql} from '@codemirror/lang-sql';
import {javascript} from '@codemirror/lang-javascript';

/**
 * Resolve the CodeMirror language extension for a CodeAtelier language code.
 *
 * @param {String} language python, sql or javascript
 * @return {Object|null} the language extension, or null for plain text
 */
const languageExtension = (language) => {
    switch (language) {
        case 'sql':
            return sql();
        case 'javascript':
            return javascript();
        case 'python':
            return python();
        default:
            return null;
    }
};

/**
 * Enhance a textarea with a CodeMirror 6 editor.
 *
 * The textarea remains the form field: edits are mirrored back into it so the
 * student's code is submitted and persisted. The textarea is hidden visually.
 *
 * @param {HTMLTextAreaElement} textarea the backing form field
 * @param {String} language the syntax-highlighting language
 * @param {Object} options optional {readonly: bool, onChange: function}
 * @return {Object} {getValue} accessor for the current source
 */
export const create = (textarea, language, options) => {
    const opts = options || {};
    const extensions = [basicSetup];

    const langext = languageExtension(language);
    if (langext) {
        extensions.push(langext);
    }

    if (opts.readonly) {
        extensions.push(EditorView.editable.of(false));
        extensions.push(EditorState.readOnly.of(true));
    } else {
        // Make Tab indent in the editor instead of moving focus. Accessibility
        // escape hatch: press Escape, then Tab, to leave the editor.
        extensions.push(keymap.of([indentWithTab]));
        extensions.push(EditorView.updateListener.of((update) => {
            if (update.docChanged) {
                textarea.value = update.state.doc.toString();
                if (typeof opts.onChange === 'function') {
                    opts.onChange();
                }
            }
        }));
    }

    const view = new EditorView({
        doc: textarea.value,
        extensions: extensions,
        parent: textarea.parentNode,
    });

    textarea.style.display = 'none';

    return {
        getValue: () => view.state.doc.toString(),
    };
};
