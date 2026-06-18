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
 * Parsers for importing files into the project workspace: a pasted directory
 * tree (many ASCII dialects) and a pasted filename + fenced-code-block listing.
 * Pure functions, no DOM, so they are unit-testable.
 *
 * @module     qtype_codeatelier/projectimport
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Normalise a path: trim, drop a leading `./` or `/`, collapse `//`. Returns the
 * cleaned path, or '' if it is empty or unsafe (contains `..` or a backslash).
 *
 * @param {String} name
 * @return {String}
 */
export const sanitisePath = (name) => {
    let p = String(name == null ? '' : name).trim().replace(/\\/g, '/');
    p = p.replace(/^\.\//, '').replace(/^\/+/, '').replace(/\/{2,}/g, '/').replace(/\/+$/, '');
    if (p === '' || p.split('/').some((seg) => seg === '' || seg === '.' || seg === '..')) {
        return '';
    }
    return p;
};

/**
 * Parse a pasted directory tree into a de-duplicated list of file paths. Robust
 * across dialects: Unix box-drawing (├── └── │), ASCII (|-- +-- `--), plain
 * indentation (2/4-space or tabs), bullets (- *), and flat path-per-line. Depth
 * comes from a relative-indent stack (no hardcoded indent unit), so any consistent
 * style works. A trailing `/` marks a folder (folders set the prefix; empty ones
 * are not emitted). Annotations after the name (` # …`, ` <- …`) are dropped.
 *
 * @param {String} text
 * @return {{files: String[], skipped: Number}}
 */
export const parseTree = (text) => {
    const seen = {};
    const files = [];
    let skipped = 0;
    const stack = []; // folders forming the current path: {indent, name}

    String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n').forEach((line) => {
        if (line.trim() === '') {
            return;
        }
        // Strip the connector prefix: leading whitespace + vertical guides + box
        // glyphs/dashes, then an optional ASCII branch (`--`/`-`) or bullet (`-`/`*`)
        // followed by a space. What remains begins the name; `-`/`*`/`_`/`.` are kept
        // when they start a real filename.
        let m = line.match(/^[\s─-╿|+`]*/);
        let rest = line.slice(m[0].length);
        const bullet = rest.match(/^[-*]+\s+/);
        if (bullet) {
            rest = rest.slice(bullet[0].length);
        }
        const indent = line.length - rest.length;
        const token = rest.trim().split(/\s+/)[0] || '';
        if (token === '' || /^[#<>(]/.test(token)) {
            skipped++;
            return;
        }
        const isfolder = /\/$/.test(token);
        const leaf = token.replace(/\/+$/, '');

        if (token.indexOf('/') !== -1 && token.indexOf('/') !== token.length - 1) {
            // Already a full path (flat list) — emit as-is, no tree prefix.
            const full = sanitisePath(token);
            if (!isfolder && full && !seen[full]) {
                seen[full] = true;
                files.push(full);
            }
            return;
        }
        // Tree node: pop to the parent (nearest strictly-shallower line).
        while (stack.length && stack[stack.length - 1].indent >= indent) {
            stack.pop();
        }
        const prefix = stack.map((s) => s.name).join('/');
        const full = sanitisePath(prefix ? prefix + '/' + leaf : leaf);
        if (full === '') {
            skipped++;
            return;
        }
        if (isfolder) {
            stack.push({indent: indent, name: leaf});
        } else if (!seen[full]) {
            seen[full] = true;
            files.push(full);
        }
    });
    return {files: files, skipped: skipped};
};

/**
 * Strip markdown decoration from a heading line and return a path-looking token
 * (one with an extension or a slash), or '' if the line is not a filename.
 *
 * @param {String} s
 * @return {String}
 */
const headingPath = (s) => {
    const t = String(s).trim().replace(/^#+\s*/, '').replace(/\*+/g, '').replace(/`/g, '').replace(/:\s*$/, '').trim();
    const m = t.match(/[\w.\-/]+\.[\w]+|[\w.\-]+\/[\w.\-/]+/);
    return m ? sanitisePath(m[0]) : '';
};

/**
 * Parse a pasted filename + fenced-code listing into files with content. The
 * filename is the fence info-string when it is a path (``` ```src/Main.java ```),
 * else the nearest preceding non-blank line that reads as a filename (handles
 * `**Main.java**` then a ```` ```java ```` block — the question-spec format).
 *
 * @param {String} text
 * @return {Array<{name: String, content: String}>}
 */
export const parseFilesWithContent = (text) => {
    const lines = String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n');
    const out = [];
    let heading = '';
    let i = 0;
    while (i < lines.length) {
        const open = lines[i].match(/^\s*(`{3,}|~{3,})(.*)$/);
        if (open) {
            const marker = open[1][0];
            const close = new RegExp('^\\s*\\' + marker + '{3,}\\s*$');
            const info = open[2].trim().split(/\s+/)[0] || '';
            let j = i + 1;
            const body = [];
            while (j < lines.length && !close.test(lines[j])) {
                body.push(lines[j]);
                j++;
            }
            let name = (/[/.]/.test(info) && sanitisePath(info)) ? sanitisePath(info) : heading;
            if (name) {
                out.push({name: name, content: body.join('\n')});
            }
            heading = '';
            i = j + 1;
        } else {
            if (lines[i].trim() !== '') {
                const h = headingPath(lines[i]);
                if (h) {
                    heading = h;
                }
            }
            i++;
        }
    }
    return out;
};

/**
 * Whether a pasted block is a files-with-content listing (has a fenced code block)
 * or a directory tree.
 *
 * @param {String} text
 * @return {String} 'files' | 'tree'
 */
export const detectPasteFormat = (text) => {
    return /^\s*(`{3,}|~{3,})/m.test(String(text == null ? '' : text)) ? 'files' : 'tree';
};

/** @var {String} a directory-tree example for the import help. */
export const EXAMPLE_TREE = 'src/\n'
    + '├─ Main.java\n'
    + '├─ parser/\n'
    + '│   └─ LogParser.java\n'
    + '└─ Util.java\n'
    + 'access.log';

/** @var {String} a files-with-content example for the import help. */
export const EXAMPLE_FILES = '**src/Main.java**\n'
    + '```java\n'
    + 'public class Main {\n'
    + '    public static void main(String[] a) {}\n'
    + '}\n'
    + '```\n\n'
    + '**src/Util.java**\n'
    + '```java\n'
    + 'class Util {}\n'
    + '```';
