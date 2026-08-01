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
 * Project (multi-file) workspace for qtype_codeatelier.
 *
 * A two-pane workspace: a file explorer beside one CodeMirror editor. It runs in
 * two modes against the same UI:
 *
 *  - AUTHOR mode edits the question's language-keyed environment blob
 *    `{envs: {<lang>: {entry, files: [{name, starter, solution}]}}}` stored in the
 *    `projectfiles` field. A language switch picks the environment; a Starter|
 *    Solution toggle picks which content layer the editor binds to; the entry/main
 *    file is a designation set on a file (the run badge), not a separate field.
 *
 *  - ATTEMPT mode edits the student's flat file set `[{name, content}]` stored in
 *    the `answer` field (the canonical JSON the judge grades) — explorer only, no
 *    layers, entry read-only.
 *
 * Deliberately not an IDE: a folder-tree file list, no terminal.
 *
 * @module qtype_codeatelier/projectworkspace
 */

import {create} from 'qtype_codeatelier/editor';
import {parseTree, parseFilesWithContent, detectPasteFormat, sanitisePath,
    EXAMPLE_TREE, EXAMPLE_FILES} from './projectimport.js';
import {unzip} from './unzip.js';

/** @var {Object} file extension to CodeMirror highlighting language. */
const EXT_LANGUAGE = {
    c: 'c', h: 'cpp', cpp: 'cpp', cc: 'cpp', cxx: 'cpp', hpp: 'cpp', hh: 'cpp',
    java: 'java', py: 'python', js: 'javascript', mjs: 'javascript', ts: 'typescript',
    go: 'go', rs: 'rust', php: 'php', cs: 'csharp', kt: 'kotlin', rb: 'ruby',
    html: 'html', htm: 'html', css: 'css',
};

/** @var {Object} the conventional entry/main file name for each language. */
const DEFAULT_ENTRY = {
    python: 'main.py', javascript: 'main.js', typescript: 'main.ts', java: 'Main.java',
    c: 'main.c', cpp: 'main.cpp', go: 'main.go', rust: 'main.rs', php: 'main.php',
    csharp: 'Main.cs', kotlin: 'Main.kt', ruby: 'main.rb',
};

/**
 * The highlighting language for a file, by extension, falling back to the
 * environment's language.
 *
 * @param {String} name the file name
 * @param {String} fallback the environment language
 * @return {String}
 */
const languageForFile = (name, fallback) => {
    const dot = name.lastIndexOf('.');
    const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
    return EXT_LANGUAGE[ext] || fallback || 'python';
};

/**
 * A fresh single-file environment for a language (used to seed a newly allowed
 * language, optionally copying the file structure of another environment).
 *
 * @param {String} lang
 * @param {Object|null} copyfrom an environment to copy file names from, or null
 * @return {Object} {entry, files}
 */
const seedEnv = (lang, copyfrom) => {
    const entry = DEFAULT_ENTRY[lang] || 'main';
    if (copyfrom && Array.isArray(copyfrom.files) && copyfrom.files.length) {
        // Copy the structure (file names + starter/solution) so the author starts
        // from the primary language's shape rather than a blank file.
        return {
            entry: copyfrom.entry || entry,
            files: copyfrom.files.map((f) => ({name: f.name, starter: f.starter, solution: f.solution})),
        };
    }
    return {entry: entry, files: [{name: entry, starter: '', solution: ''}]};
};

/**
 * Normalise a per-file object to {name, starter, solution} (author) shape.
 *
 * @param {Object} f
 * @return {Object|null}
 */
const cleanFile = (f) => {
    if (!f || typeof f.name !== 'string' || f.name.trim() === '') {
        return null;
    }
    return {
        name: String(f.name),
        starter: typeof f.starter === 'string' ? f.starter
            : (typeof f.content === 'string' ? f.content : ''),
        solution: typeof f.solution === 'string' ? f.solution : '',
    };
};

/**
 * Parse the author-mode environment blob, guaranteeing an environment for every
 * allowed language (seeding a missing one from the primary or a blank file).
 *
 * @param {String} json the projectfiles blob JSON
 * @param {String[]} langs the allowed languages
 * @param {String} primary the primary language
 * @return {Object} {envs: {<lang>: {entry, files}}}
 */
const parseBlob = (json, langs, primary) => {
    let decoded = null;
    try {
        decoded = JSON.parse(json);
    } catch (e) {
        decoded = null;
    }
    const rawenvs = (decoded && decoded.envs && typeof decoded.envs === 'object') ? decoded.envs : {};
    const out = {envs: {}};
    // Build the primary first so other languages can copy its structure.
    const order = [primary].concat(langs.filter((l) => l !== primary));
    order.forEach((lang) => {
        const env = rawenvs[lang];
        const files = (env && Array.isArray(env.files))
            ? env.files.map(cleanFile).filter(Boolean) : [];
        if (files.length) {
            const entry = (env && typeof env.entry === 'string'
                && files.some((f) => f.name === env.entry)) ? env.entry : files[0].name;
            out.envs[lang] = {entry: entry, files: files};
        } else {
            out.envs[lang] = seedEnv(lang, out.envs[primary] || null);
        }
    });
    return out;
};

/**
 * Parse an attempt-mode flat file set `[{name, content}]`.
 *
 * @param {String} json
 * @return {Array|null}
 */
const parseFlat = (json) => {
    let decoded;
    try {
        decoded = JSON.parse(json);
    } catch (e) {
        return null;
    }
    if (!Array.isArray(decoded)) {
        return null;
    }
    return decoded
        .filter((f) => f && typeof f.name === 'string' && f.name.trim() !== '')
        .map((f) => ({name: String(f.name), content: typeof f.content === 'string' ? f.content : ''}));
};

/**
 * Set up the project workspace inside the editor container.
 *
 * @param {HTMLElement} root the question/preview wrapper
 * @param {HTMLTextAreaElement} backingTa the hidden field (blob in author mode,
 *     flat set in attempt mode)
 * @param {Object} config controller config; uses {readonly, isjudge, language,
 *     languages[], author (bool), strings}
 * @param {Object} strings localised labels
 * @param {Function} [onChange] called after every edit
 * @param {Function} [getTheme] returns the current effective editor theme
 * @return {Object} {getValue, openSearch, setTheme, destroy}
 */
export const setup = (root, backingTa, config, strings, onChange, getTheme) => {
    const readonly = !!config.readonly;
    const author = !!config.author && !readonly;
    const isweb = !!config.web || config.scenario === 'web';
    const sandbox = !!config.isjudge && !readonly;
    const onExternal = sandbox
        ? (type) => root.dispatchEvent(new CustomEvent('codeatelier:clip-external', {detail: {type: type}}))
        : null;
    strings = strings || {};

    const primary = config.language || 'python';
    const langs = (Array.isArray(config.languages) && config.languages.length)
        ? config.languages.slice() : [primary];

    // ---- model ----------------------------------------------------------------
    // Author: the language-keyed blob. Attempt: a single env wrapping the flat set
    // so the explorer/editor code is shared (layer is always 'content').
    let model;
    let layer; // 'starter' | 'solution' (author) | 'content' (attempt)
    if (author) {
        model = parseBlob(backingTa.value, langs, primary);
        layer = 'starter';
    } else {
        let flat = parseFlat(backingTa.value);
        if (!flat || !flat.length) {
            flat = [{name: config.entryfile || DEFAULT_ENTRY[primary] || 'main', content: ''}];
        }
        model = {envs: {}};
        model.envs[primary] = {
            entry: config.entryfile || flat[0].name,
            files: flat.map((f) => ({name: f.name, content: f.content})),
        };
        layer = 'content';
    }
    let currentLang = primary;
    let active = 0;
    let cm = null;

    const env = () => model.envs[currentLang];
    const files = () => env().files;
    const layerOf = (f) => (layer === 'content' ? f.content : f[layer]);
    const setLayerOf = (f, value) => {
        if (layer === 'content') {
            f.content = value;
        } else {
            f[layer] = value;
        }
    };

    const serialise = () => {
        if (author) {
            backingTa.value = JSON.stringify({version: 2, envs: model.envs});
        } else {
            backingTa.value = JSON.stringify(files().map((f) => ({name: f.name, content: f.content})));
        }
        if (typeof onChange === 'function') {
            onChange();
        }
    };

    // ---- DOM scaffold ---------------------------------------------------------
    const container = backingTa.closest('.codeatelier-editor') || backingTa.parentNode;
    backingTa.style.display = 'none';
    const skeleton = container.querySelector('[data-role="skeleton"]');
    if (skeleton) {
        skeleton.style.display = 'none';
    }

    const wrap = document.createElement('div');
    wrap.className = 'codeatelier-project' + (author ? ' codeatelier-project-author' : '');

    const toolbar = document.createElement('div');
    toolbar.className = 'codeatelier-project-toolbar';
    wrap.appendChild(toolbar);

    const body = document.createElement('div');
    body.className = 'codeatelier-project-body';
    const explorer = document.createElement('div');
    explorer.className = 'codeatelier-project-explorer';
    const filelist = document.createElement('ul');
    filelist.className = 'codeatelier-filelist';
    filelist.setAttribute('aria-label', strings.projectfiles || 'Project files');
    const editorpane = document.createElement('div');
    editorpane.className = 'codeatelier-project-editorpane';
    const fileEditor = document.createElement('textarea');
    fileEditor.className = 'codeatelier-answer codeatelier-file-editor';
    fileEditor.setAttribute('spellcheck', 'false');
    fileEditor.rows = 16;
    editorpane.appendChild(fileEditor);
    body.appendChild(explorer);
    body.appendChild(editorpane);
    wrap.appendChild(body);
    container.appendChild(wrap);

    // ---- editor mount ---------------------------------------------------------
    const mountActive = () => {
        if (cm && cm.destroy) {
            cm.destroy();
        }
        const f = files()[active];
        fileEditor.value = layerOf(f);
        try {
            cm = create(fileEditor, languageForFile(f.name, currentLang), {
                readonly: readonly,
                ariaLabel: f.name,
                sandbox: sandbox,
                onExternal: onExternal,
                theme: typeof getTheme === 'function' ? getTheme() : 'light',
                onChange: () => {
                    setLayerOf(files()[active], fileEditor.value);
                    serialise();
                },
            });
        } catch (e) {
            fileEditor.style.display = 'block';
            cm = {getValue: () => fileEditor.value};
            fileEditor.addEventListener('input', () => {
                setLayerOf(files()[active], fileEditor.value);
                serialise();
            });
        }
    };

    // ---- file explorer --------------------------------------------------------
    const rowEls = () => Array.prototype.slice.call(filelist.querySelectorAll('.codeatelier-fileitem'));

    /**
     * Activate a file by index (saving the outgoing editor content first).
     *
     * @param {Number} index
     */
    function selectFile(index) {
        if (index === active) {
            return;
        }
        if (cm && cm.getValue) {
            setLayerOf(files()[active], cm.getValue());
        }
        active = index;
        renderFiles();
        mountActive();
    }

    const nameTaken = (name, except) => files().some((f, i) => i !== except && f.name === name);
    // A name is one or more `/`-separated path segments. Each segment is non-empty
    // and not `.`/`..`; no backslash, no leading/trailing slash, no `//`. So
    // `src/Main.java` is valid; `/x`, `x/`, `a//b`, `../x`, `a\b` are not.
    const validPath = (name) => {
        if (name === '' || /\\/.test(name) || name.indexOf('/') === 0 || /\/$/.test(name)) {
            return false;
        }
        return name.split('/').every((seg) => seg !== '' && seg !== '.' && seg !== '..');
    };
    const validName = (name, except) => validPath(name) && !nameTaken(name, except);

    /**
     * Begin an inline rename on a file row's label.
     *
     * @param {HTMLElement} label the name span
     * @param {Number} index the file index
     */
    const beginRename = (label, index) => {
        if (readonly) {
            return;
        }
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm codeatelier-filename-inline';
        input.value = files()[index].name;
        input.setAttribute('spellcheck', 'false');
        label.replaceWith(input);
        input.focus();
        input.select();
        const commit = (keep) => {
            const next = input.value.trim();
            if (keep && next !== files()[index].name && validName(next, index)) {
                // Keep the entry designation pointing at the file if it is renamed.
                if (env().entry === files()[index].name) {
                    env().entry = next;
                }
                files()[index].name = next;
                serialise();
            }
            renderFiles();
            if (index === active) {
                mountActive();
            }
        };
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                commit(true);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                commit(false);
            }
        });
        input.addEventListener('blur', () => commit(true));
    };

    // Collapsed folder paths (persist across renders); folders default to expanded.
    const collapsed = {};

    /**
     * Build one file row <li>: the file button (label = its basename within the
     * tree), the entry badge, and delete. Operations key off the full file.name.
     *
     * @param {Object} file the file object
     * @param {Number} index its index in files()
     * @param {String} label the basename to display
     * @param {Number} depth the tree depth, for indentation
     * @return {HTMLElement}
     */
    function buildFileRow(file, index, label, depth) {
        const row = document.createElement('li');
        row.className = 'codeatelier-filerow' + (index === active ? ' codeatelier-filerow-active' : '');
        row.style.paddingLeft = (depth * 0.9) + 'rem';

        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'codeatelier-fileitem';
        // File buttons carry roving tabindex + arrow-key nav (not an ARIA tablist —
        // the buttons sit inside <li>s); aria-current marks the open file.
        if (index === active) {
            tab.setAttribute('aria-current', 'true');
        }
        tab.tabIndex = index === active ? 0 : -1;

        const name = document.createElement('span');
        name.className = 'codeatelier-filename-label';
        name.textContent = label;
        tab.appendChild(name);
        tab.addEventListener('click', () => selectFile(index));
        tab.addEventListener('dblclick', () => beginRename(name, index));
        tab.addEventListener('keydown', (e) => {
            const rows = rowEls();
            const at = rows.indexOf(tab);
            let target = null;
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
                target = rows[(at + 1) % rows.length];
            } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
                target = rows[(at - 1 + rows.length) % rows.length];
            } else if (e.key === 'Home') {
                target = rows[0];
            } else if (e.key === 'End') {
                target = rows[rows.length - 1];
            } else if ((e.key === 'F2') && !readonly) {
                e.preventDefault();
                beginRename(name, index);
                return;
            }
            if (target) {
                e.preventDefault();
                selectFile(rows.indexOf(target));
                target.focus();
            }
        });
        row.appendChild(tab);

        // The entry/main file badge. In author mode it sets the entry; in attempt
        // mode it is a static marker on the author's entry file.
        const isentry = env().entry === file.name;
        if (author) {
            const badge = document.createElement('button');
            badge.type = 'button';
            badge.className = 'codeatelier-entry-badge' + (isentry ? ' codeatelier-entry-active' : '');
            badge.title = isentry
                ? (strings.entryis || 'Entry file (runs first)')
                : (strings.setentry || 'Set as the entry file');
            badge.setAttribute('aria-label', badge.title);
            badge.setAttribute('aria-pressed', isentry ? 'true' : 'false');
            badge.textContent = '▸︎';
            badge.addEventListener('click', () => {
                env().entry = file.name;
                renderFiles();
                serialise();
            });
            row.appendChild(badge);
        } else if (isentry) {
            const marker = document.createElement('span');
            marker.className = 'codeatelier-entry-badge codeatelier-entry-active';
            marker.textContent = '▸︎';
            marker.title = isweb
                ? (strings.servedpage || 'Served page (loads in the browser)')
                : (strings.entryis || 'Entry file (runs first)');
            marker.setAttribute('aria-label', marker.title);
            row.appendChild(marker);
        }

        if (!readonly) {
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'codeatelier-filedelete';
            del.textContent = '×';
            del.title = strings.deletefile || 'Delete file';
            del.setAttribute('aria-label', (strings.deletefile || 'Delete file') + ': ' + file.name);
            del.disabled = files().length <= 1 || isentry;
            del.addEventListener('click', (e) => {
                e.stopPropagation();
                deleteFile(index);
            });
            row.appendChild(del);
        }
        return row;
    }

    /**
     * Render the current environment as a folder tree: folders are derived from the
     * `/`-separated file paths (never stored) and are collapsible; files render
     * under their folder with their basename as the label.
     */
    function renderFiles() {
        filelist.textContent = '';
        const root = {folders: {}, files: []};
        files().forEach((file, index) => {
            const parts = String(file.name).split('/');
            let node = root;
            for (let i = 0; i < parts.length - 1; i++) {
                node.folders[parts[i]] = node.folders[parts[i]] || {folders: {}, files: []};
                node = node.folders[parts[i]];
            }
            node.files.push({file: file, index: index, base: parts[parts.length - 1]});
        });

        const renderNode = (node, depth, prefix) => {
            Object.keys(node.folders).sort().forEach((folder) => {
                const path = prefix + folder + '/';
                const open = !collapsed[path];
                const frow = document.createElement('li');
                frow.className = 'codeatelier-folderrow';
                frow.style.paddingLeft = (depth * 0.9) + 'rem';
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'codeatelier-folder-toggle';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.textContent = (open ? '▾ ' : '▸ ') + folder + '/';
                toggle.addEventListener('click', () => {
                    if (collapsed[path]) {
                        delete collapsed[path];
                    } else {
                        collapsed[path] = true;
                    }
                    renderFiles();
                });
                frow.appendChild(toggle);
                filelist.appendChild(frow);
                if (open) {
                    renderNode(node.folders[folder], depth + 1, path);
                }
            });
            node.files.slice().sort((a, b) => a.base.localeCompare(b.base)).forEach((f) => {
                filelist.appendChild(buildFileRow(f.file, f.index, f.base, depth));
            });
        };
        renderNode(root, 0, '');
    }

    const errorEl = document.createElement('span');
    errorEl.className = 'codeatelier-fileerror text-danger small';
    errorEl.setAttribute('role', 'alert');

    const addFile = () => {
        // Seed the new file into the active file's folder, so "New file" inside
        // src/ starts at src/. The author edits the full path in the rename.
        const cur = files()[active];
        const dir = cur ? String(cur.name).replace(/[^/]*$/, '') : '';
        const taken = (candidate) => files().some((f) => f.name === candidate);
        let n = files().length + 1;
        let name = dir + 'file' + n;
        while (taken(name)) {
            n++;
            name = dir + 'file' + n;
        }
        const blank = author ? {name: name, starter: '', solution: ''} : {name: name, content: ''};
        files().push(blank);
        active = files().length - 1;
        renderFiles();
        mountActive();
        serialise();
        // Drop straight into a rename so the author names the new file. The active
        // row is found by class (tree order is not files() order).
        const label = filelist.querySelector('.codeatelier-filerow-active .codeatelier-filename-label');
        if (label) {
            beginRename(label, active);
        }
    };

    /**
     * Small DOM builder (text/html/class attrs + children).
     *
     * @param {String} tag the element tag name
     * @param {Object} attrs attributes; `text` and `html` set content, the rest are set as attributes
     * @param {Array} children child nodes to append
     * @returns {Element} the built element
     */
    const el = (tag, attrs, children) => {
        const node = document.createElement(tag);
        Object.keys(attrs || {}).forEach((k) => {
            if (k === 'text') {
                node.textContent = attrs[k];
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
     * Add many files to the current env + current layer. Tree entries carry no
     * content (empty files); content entries fill the active layer. Replace clears
     * the env's files first. Returns the number of files added/updated.
     *
     * @param {Array<{name: String, content?: String}>} entries
     * @param {Boolean} replace
     * @return {Number}
     */
    const importFiles = (entries, replace) => {
        const list = files();
        if (replace) {
            list.length = 0;
        }
        let added = 0;
        entries.forEach((e) => {
            const path = sanitisePath(e.name);
            if (path === '') {
                return;
            }
            let f = list.find((x) => x.name === path);
            if (!f) {
                f = author ? {name: path, starter: '', solution: ''} : {name: path, content: ''};
                list.push(f);
            }
            if (typeof e.content === 'string') {
                setLayerOf(f, e.content);
            }
            added++;
        });
        if (!list.length) {
            // Never leave an env with no files.
            list.push(author ? {name: 'main', starter: '', solution: ''} : {name: 'main', content: ''});
        }
        if (!env().entry || !list.some((f) => f.name === env().entry)) {
            const main = list.find((f) => /(^|\/)(main|index|program)\.\w+$/i.test(f.name));
            env().entry = (main || list[0]).name;
        }
        active = 0;
        renderFiles();
        mountActive();
        serialise();
        return added;
    };

    /**
     * Build the import disclosure: a paste box that auto-detects a directory tree
     * vs filename+code blocks, a pick-file input (.zip or text files), Add/Replace,
     * inline examples, and a status line. Imports into the current env + layer.
     *
     * @param {Function} readPicked reads a File[] into [{name, content}]
     * @return {HTMLElement} the <details> panel
     */
    function buildImportPanel(readPicked) {
        const paste = el('textarea', {class: 'form-control codeatelier-import-paste', rows: '6',
            spellcheck: 'false',
            placeholder: strings.importplaceholder || 'Paste a directory tree, or filename + code blocks…'}, []);
        const status = el('span', {class: 'codeatelier-import-status small', 'aria-live': 'polite'}, []);
        const radio = (val, label, checked) => {
            const r = el('input', {type: 'radio', name: 'caimp_' + currentLang, value: val}, []);
            r.checked = checked;
            return el('label', {class: 'codeatelier-import-mode'}, [r, el('span', {text: ' ' + label}, [])]);
        };
        const appendR = radio('append', strings.importadd || 'Add to files', true);
        const replaceR = radio('replace', strings.importreplace || 'Replace all', false);
        const isReplace = () => replaceR.querySelector('input').checked;

        const apply = (entries, skipped) => {
            const valid = entries.filter((e) => sanitisePath(e.name) !== '');
            if (!valid.length) {
                status.textContent = strings.importempty || 'Nothing recognised to import.';
                return;
            }
            const n = importFiles(valid, isReplace());
            let msg = (strings.importok || 'Imported {$a} file(s).').replace('{$a}', String(n));
            if (skipped) {
                msg += ' ' + (strings.importskipped || '{$a} line(s) skipped.').replace('{$a}', String(skipped));
            }
            status.textContent = msg;
            paste.value = '';
        };

        const importBtn = el('button', {type: 'button', class: 'btn btn-primary btn-sm',
            text: strings.importdo || 'Import'}, []);
        importBtn.addEventListener('click', () => {
            const text = paste.value;
            if (text.trim() === '') {
                status.textContent = strings.importempty || 'Nothing recognised to import.';
                return;
            }
            if (detectPasteFormat(text) === 'files') {
                apply(parseFilesWithContent(text), 0);
            } else {
                const r = parseTree(text);
                apply(r.files.map((name) => ({name: name})), r.skipped);
            }
        });

        const pick = el('input', {type: 'file', class: 'form-control form-control-sm codeatelier-import-pick',
            accept: '.zip,.txt,.java,.cs,.py,.js,.mjs,.ts,.c,.cpp,.cc,.h,.hpp,.go,.rs,.php,.rb,.kt,.html,.css,.json,.xml,.md,.sql',
            multiple: 'multiple'}, []);
        pick.addEventListener('change', () => {
            if (!pick.files || !pick.files.length) {
                return;
            }
            status.textContent = strings.importreading || 'Reading…';
            readPicked(Array.prototype.slice.call(pick.files)).then((entries) => {
                apply(entries, 0);
                pick.value = '';
            }).catch((err) => {
                status.textContent = String((err && err.message) || err);
            });
        });

        const example = (label, sample) => {
            const a = el('button', {type: 'button', class: 'btn btn-link btn-sm', text: label}, []);
            a.addEventListener('click', () => {
                paste.value = sample;
                paste.focus();
            });
            return a;
        };

        return el('details', {class: 'codeatelier-import'}, [
            el('summary', {text: strings.import || 'Import files…'}, []),
            el('div', {class: 'codeatelier-import-body'}, [
                el('p', {class: 'codeatelier-import-help small text-muted',
                    text: strings.importhelp || 'Paste a directory tree (creates empty files) or filenames with '
                        + 'code blocks (files with content); or pick / drop a .zip or files onto the file list. '
                        + 'Everything imports into the current language.'}, []),
                paste,
                el('div', {class: 'codeatelier-import-examples'}, [
                    el('span', {class: 'small text-muted', text: (strings.importexamples || 'Insert example:') + ' '}, []),
                    example(strings.importextree || 'directory tree', EXAMPLE_TREE),
                    example(strings.importexfiles || 'files with code', EXAMPLE_FILES),
                ]),
                el('div', {class: 'codeatelier-import-actions'}, [appendR, replaceR, importBtn,
                    el('span', {class: 'codeatelier-import-or small text-muted',
                        text: strings.importorpick || 'or pick:'}, []),
                    pick, status]),
            ]),
        ]);
    }

    /**
     * Remove a file from the current environment. The last remaining file and the
     * entry file are never deleted — the environment must stay runnable.
     *
     * @param {Number} index position in the current environment's file list
     */
    function deleteFile(index) {
        if (files().length <= 1 || env().entry === files()[index].name) {
            return;
        }
        files().splice(index, 1);
        if (active >= files().length) {
            active = files().length - 1;
        } else if (active > index) {
            active--;
        }
        renderFiles();
        mountActive();
        serialise();
    }

    // ---- toolbar (language switch + layer toggle) -----------------------------
    const buildToolbar = () => {
        toolbar.textContent = '';
        // Language switch — author only, and only when more than one language is
        // allowed. In an attempt the student's language is chosen by the question's
        // own language picker (which re-mounts this workspace with that env), so an
        // in-workspace switch here would be a dead, duplicate control.
        if (author && langs.length > 1) {
            const langwrap = document.createElement('div');
            langwrap.className = 'codeatelier-lang-switch';
            langwrap.setAttribute('role', 'group');
            langwrap.setAttribute('aria-label', strings.language || 'Language');
            langs.forEach((lang) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'codeatelier-lang-btn'
                    + (lang === currentLang ? ' codeatelier-lang-active' : '');
                b.textContent = (strings['lang_' + lang]) || lang;
                b.setAttribute('aria-pressed', lang === currentLang ? 'true' : 'false');
                b.addEventListener('click', () => switchLanguage(lang));
                langwrap.appendChild(b);
            });
            toolbar.appendChild(langwrap);
        }
        // Starter|Solution layer toggle — author mode only.
        if (author) {
            const toggle = document.createElement('div');
            toggle.className = 'codeatelier-layer-toggle';
            toggle.setAttribute('role', 'group');
            toggle.setAttribute('aria-label', strings.layer || 'Editing layer');
            [['starter', strings.starterlayer || 'Starter'], ['solution', strings.solutionlayer || 'Solution']]
                .forEach(([key, label]) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'codeatelier-layer-btn'
                        + (layer === key ? ' codeatelier-layer-active' : '');
                    b.textContent = label;
                    b.setAttribute('aria-pressed', layer === key ? 'true' : 'false');
                    b.addEventListener('click', () => switchLayer(key));
                    toggle.appendChild(b);
                });
            toolbar.appendChild(toggle);
        }
        // No switch and no toggle (a single-language attempt / web): drop the empty
        // toolbar bar so the explorer + editor sit flush.
        toolbar.style.display = toolbar.children.length ? '' : 'none';
    };

    /**
     * Switch to another language's file environment, flushing the open editor
     * into the model first so nothing typed is lost.
     *
     * @param {String} lang the language code to switch to
     */
    function switchLanguage(lang) {
        if (lang === currentLang || !model.envs[lang]) {
            return;
        }
        if (cm && cm.getValue) {
            setLayerOf(files()[active], cm.getValue());
        }
        currentLang = lang;
        active = 0;
        buildToolbar();
        renderFiles();
        mountActive();
    }

    /**
     * Switch between the Starter and Solution editing layers, flushing the open
     * editor into the current layer first.
     *
     * @param {String} key either 'starter' or 'solution'
     */
    function switchLayer(key) {
        if (key === layer) {
            return;
        }
        if (cm && cm.getValue) {
            setLayerOf(files()[active], cm.getValue());
        }
        layer = key;
        buildToolbar();
        mountActive();
    }

    // ---- file reading + import panel (author only) ----------------------------
    const readFileText = (file) => new Promise((res, rej) => {
        const r = new FileReader();
        r.onload = () => res(String(r.result || ''));
        r.onerror = () => rej(r.error);
        r.readAsText(file);
    });
    const readFileBuf = (file) => new Promise((res, rej) => {
        const r = new FileReader();
        r.onload = () => res(r.result);
        r.onerror = () => rej(r.error);
        r.readAsArrayBuffer(file);
    });
    const readPicked = async (fileList) => {
        const out = [];
        for (const file of fileList) {
            if (/\.zip$/i.test(file.name)) {
                (await unzip(await readFileBuf(file))).forEach((z) => out.push(z));
            } else {
                out.push({name: file.webkitRelativePath || file.name, content: await readFileText(file)});
            }
        }
        return out;
    };
    // Walk a dropped directory entry (HTML5 file-system API) into {file, path}.
    const walkEntry = (entry, prefix) => new Promise((res) => {
        if (entry.isFile) {
            entry.file((f) => res([{file: f, path: prefix + entry.name}]), () => res([]));
        } else if (entry.isDirectory) {
            const reader = entry.createReader();
            const acc = [];
            const batch = () => reader.readEntries((items) => {
                if (!items.length) {
                    Promise.all(acc.map((e) => walkEntry(e, prefix + entry.name + '/')))
                        .then((arrs) => res([].concat.apply([], arrs)));
                    return;
                }
                acc.push.apply(acc, items);
                batch();
            }, () => res([]));
            batch();
        } else {
            res([]);
        }
    });
    const readDataTransfer = async (dt) => {
        const items = dt.items ? Array.prototype.slice.call(dt.items) : [];
        const entries = items.map((it) => (it.webkitGetAsEntry ? it.webkitGetAsEntry() : null)).filter(Boolean);
        if (entries.length) {
            const arrs = await Promise.all(entries.map((e) => walkEntry(e, '')));
            const out = [];
            for (const it of [].concat.apply([], arrs)) {
                if (/\.zip$/i.test(it.file.name)) {
                    (await unzip(await readFileBuf(it.file))).forEach((z) => out.push(z));
                } else {
                    out.push({name: it.path, content: await readFileText(it.file)});
                }
            }
            return out;
        }
        return readPicked(Array.prototype.slice.call(dt.files || []));
    };

    let importPanel = null;
    if (author) {
        importPanel = buildImportPanel(readPicked);
        // Mount the panel above the workspace box rather than inside it: the box is
        // `overflow: hidden` (for its rounded two-pane frame), which would clip the
        // panel's lower controls (the file picker, status) once the disclosure opens.
        wrap.parentNode.insertBefore(importPanel, wrap);
        // The explorer is a drop target: drop files / a folder / a .zip onto the tree.
        explorer.addEventListener('dragover', (e) => {
            e.preventDefault();
            explorer.classList.add('codeatelier-dropping');
        });
        explorer.addEventListener('dragleave', () => explorer.classList.remove('codeatelier-dropping'));
        explorer.addEventListener('drop', (e) => {
            e.preventDefault();
            explorer.classList.remove('codeatelier-dropping');
            readDataTransfer(e.dataTransfer).then((entries) => {
                const valid = entries.filter((x) => sanitisePath(x.name) !== '');
                if (valid.length) {
                    importFiles(valid, false);
                }
            }).catch(() => {});
        });
    }

    // ---- explorer header (New file + Import) ----------------------------------
    if (!readonly) {
        const head = document.createElement('div');
        head.className = 'codeatelier-explorer-head';
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'btn btn-link btn-sm codeatelier-newfile';
        add.textContent = strings.addfile || 'New file';
        add.addEventListener('click', addFile);
        head.appendChild(add);
        if (author && importPanel) {
            const imp = document.createElement('button');
            imp.type = 'button';
            imp.className = 'btn btn-link btn-sm codeatelier-import-open';
            imp.textContent = strings.import || 'Import…';
            imp.addEventListener('click', () => {
                importPanel.open = true;
                importPanel.scrollIntoView({block: 'nearest'});
                const ta = importPanel.querySelector('.codeatelier-import-paste');
                if (ta) {
                    ta.focus();
                }
            });
            head.appendChild(imp);
        }
        explorer.appendChild(head);
    }
    explorer.appendChild(filelist);
    if (!readonly) {
        explorer.appendChild(errorEl);
    }

    buildToolbar();
    renderFiles();
    mountActive();
    serialise();

    return {
        getValue: () => backingTa.value,
        openSearch: () => {
            if (cm && typeof cm.openSearch === 'function') {
                cm.openSearch();
            }
        },
        setTheme: (mode) => {
            if (cm && typeof cm.setTheme === 'function') {
                cm.setTheme(mode);
            }
        },
        reindent: () => {
            if (cm && typeof cm.reindent === 'function') {
                cm.reindent();
            }
        },
        destroy: () => {
            if (cm && cm.destroy) {
                cm.destroy();
            }
            if (importPanel && importPanel.parentNode) {
                importPanel.parentNode.removeChild(importPanel);
            }
            if (wrap.parentNode) {
                wrap.parentNode.removeChild(wrap);
            }
            backingTa.style.display = '';
        },
    };
};
