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
import {python} from '@codemirror/lang-python';

/**
 * Mount a CodeMirror 6 editor inside the given container.
 *
 * @param {String} elementid the id of the container element
 * @param {String} initialcode the starting source shown in the editor
 */
export const init = (elementid, initialcode) => {
    const parent = document.getElementById(elementid);
    if (!parent) {
        return;
    }
    // eslint-disable-next-line no-new
    new EditorView({
        doc: initialcode,
        extensions: [basicSetup, python()],
        parent,
    });
};
