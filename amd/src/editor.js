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
import {lintGutter, setDiagnostics} from '@codemirror/lint';
import {python} from '@codemirror/lang-python';
import {sql} from '@codemirror/lang-sql';
import {javascript} from '@codemirror/lang-javascript';
import {cpp} from '@codemirror/lang-cpp';
import {java} from '@codemirror/lang-java';
import {go} from '@codemirror/lang-go';
import {rust} from '@codemirror/lang-rust';
import {php} from '@codemirror/lang-php';
import {csharp} from '@replit/codemirror-lang-csharp';
import {html} from '@codemirror/lang-html';
import {css} from '@codemirror/lang-css';
import {highlightTree, classHighlighter} from '@lezer/highlight';

/**
 * Resolve the CodeMirror language extension for a CodeAtelier language code.
 *
 * @param {String} language a CodeAtelier language code
 * @return {Object|null} the language extension, or null for plain text
 *     (Kotlin and Ruby have no CodeMirror 6 grammar, so they edit as plain text).
 */
const languageExtension = (language) => {
    switch (language) {
        case 'sql':
            return sql();
        case 'javascript':
            return javascript();
        case 'typescript':
            return javascript({typescript: true});
        case 'python':
            return python();
        case 'c':
        case 'cpp':
            return cpp();
        case 'java':
            return java();
        case 'go':
            return go();
        case 'rust':
            return rust();
        case 'php':
            return php();
        case 'csharp':
            return csharp();
        case 'html':
            // The HTML grammar also highlights embedded <style> and <script>.
            return html();
        case 'css':
            return css();
        default:
            return null;
    }
};

// Cache the Lezer parser per language (parsing the prompt's code blocks reuses
// the same language packages the editor bundles, so no extra dependency).
const parserCache = {};

/**
 * The Lezer parser for a language, or null for plain text.
 *
 * @param {String} language python, sql or javascript
 * @return {Object|null}
 */
const parserFor = (language) => {
    if (!(language in parserCache)) {
        const support = languageExtension(language);
        parserCache[language] = (support && support.language) ? support.language.parser : null;
    }
    return parserCache[language];
};

/**
 * Map a `language-*` CSS class to one of our supported languages.
 *
 * @param {String} className
 * @return {String|null}
 */
const languageFromClass = (className) => {
    const match = (className || '').match(/language-([\w+-]+)/i);
    if (!match) {
        return null;
    }
    const name = match[1].toLowerCase();
    const alias = {py: 'python', js: 'javascript', node: 'javascript', ts: 'typescript',
        'c++': 'cpp', 'c#': 'csharp', cs: 'csharp', golang: 'go', rs: 'rust', rb: 'ruby',
        htm: 'html'};
    const resolved = alias[name] || name;
    // Highlight a prompt code block only for a language we have a grammar for.
    const known = ['python', 'sql', 'javascript', 'typescript', 'c', 'cpp', 'java',
        'go', 'rust', 'php', 'csharp', 'html', 'css'];
    return known.indexOf(resolved) !== -1 ? resolved : null;
};

const escapeHtml = (value) => value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

/**
 * Syntax-highlight `<pre><code class="language-*">` blocks within an element,
 * in place, using the bundled CodeMirror language parsers. Inline `<code>` is
 * left to CSS. Idempotent: a block is highlighted at most once.
 *
 * @param {HTMLElement} root the container to scan (e.g. the question text)
 */
export const highlightWithin = (root) => {
    if (!root) {
        return;
    }
    root.querySelectorAll('pre > code[class*="language-"]').forEach((code) => {
        if (code.dataset.codeatelierHl) {
            return;
        }
        const parser = parserFor(languageFromClass(code.className));
        if (!parser) {
            return;
        }
        const text = code.textContent;
        const tree = parser.parse(text);
        let html = '';
        let pos = 0;
        highlightTree(tree, classHighlighter, (from, to, classes) => {
            if (from > pos) {
                html += escapeHtml(text.slice(pos, from));
            }
            html += '<span class="' + classes + '">' + escapeHtml(text.slice(from, to)) + '</span>';
            pos = to;
        });
        html += escapeHtml(text.slice(pos));
        code.innerHTML = html;
        code.dataset.codeatelierHl = '1';
        code.classList.add('codeatelier-hl');
    });
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
    const extensions = [basicSetup, lintGutter()];

    // Give the contenteditable an accessible name: the backing textarea (which
    // carries the visible label) is hidden, so a screen reader needs it here.
    extensions.push(EditorView.contentAttributes.of({
        'aria-label': opts.ariaLabel || 'Code editor',
    }));

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

    const clearErrors = () => {
        view.dispatch(setDiagnostics(view.state, []));
    };

    return {
        getValue: () => view.state.doc.toString(),
        /**
         * Tear the editor down and reveal the backing textarea again. Used when
         * an editor must be re-created for a different language.
         */
        destroy: () => {
            view.destroy();
            textarea.style.display = '';
        },
        clearErrors: clearErrors,
        /**
         * Mark a runtime error on a 1-based line (gutter + inline + hover).
         *
         * @param {Number} line 1-based line number
         * @param {String} message
         */
        showError: (line, message) => {
            const doc = view.state.doc;
            if (!line || line < 1 || line > doc.lines) {
                clearErrors();
                return;
            }
            const text = doc.line(line);
            view.dispatch(setDiagnostics(view.state, [{
                from: text.from,
                to: text.to,
                severity: 'error',
                message: message,
            }]));
        },
    };
};
