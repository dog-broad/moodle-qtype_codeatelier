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
 * Deliberately not an IDE: a flat file list, no tree, no terminal.
 *
 * @module qtype_codeatelier/projectworkspace
 */

import {create} from 'qtype_codeatelier/editor';

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
    filelist.setAttribute('role', 'tablist');
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
    const rowEls = () => Array.prototype.slice.call(filelist.querySelectorAll('[role="tab"]'));

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
    const validName = (name, except) => name !== '' && !/[\\/]/.test(name) && !nameTaken(name, except);

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

    /**
     * Render the file rows for the current environment.
     */
    function renderFiles() {
        filelist.textContent = '';
        files().forEach((file, index) => {
            const row = document.createElement('li');
            row.className = 'codeatelier-filerow' + (index === active ? ' codeatelier-filerow-active' : '');

            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'codeatelier-fileitem';
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', index === active ? 'true' : 'false');
            tab.tabIndex = index === active ? 0 : -1;

            const name = document.createElement('span');
            name.className = 'codeatelier-filename-label';
            name.textContent = file.name;
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

            // The entry/main file badge. In author mode it is a button that sets the
            // entry; in attempt mode it is a static marker on the author's entry file.
            const isentry = env().entry === file.name;
            if (author) {
                const badge = document.createElement('button');
                badge.type = 'button';
                badge.className = 'codeatelier-entry-badge'
                    + (isentry ? ' codeatelier-entry-active' : '');
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
                marker.title = strings.entryis || 'Entry file (runs first)';
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
            filelist.appendChild(row);
        });
    }

    const errorEl = document.createElement('span');
    errorEl.className = 'codeatelier-fileerror text-danger small';
    errorEl.setAttribute('role', 'alert');

    const addFile = () => {
        let n = files().length + 1;
        let name = 'file' + n;
        while (files().some((f) => f.name === name)) {
            n++;
            name = 'file' + n;
        }
        const blank = author ? {name: name, starter: '', solution: ''} : {name: name, content: ''};
        files().push(blank);
        active = files().length - 1;
        renderFiles();
        mountActive();
        serialise();
        // Drop straight into a rename so the author names the new file.
        const last = rowEls()[active];
        const label = last && last.querySelector('.codeatelier-filename-label');
        if (label) {
            beginRename(label, active);
        }
    };

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
        // Language switch — only when more than one language is allowed.
        if (langs.length > 1) {
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
    };

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

    // ---- explorer header (New file) -------------------------------------------
    if (!readonly) {
        const head = document.createElement('div');
        head.className = 'codeatelier-explorer-head';
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'btn btn-link btn-sm codeatelier-newfile';
        add.textContent = strings.addfile || 'New file';
        add.addEventListener('click', addFile);
        head.appendChild(add);
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
        destroy: () => {
            if (cm && cm.destroy) {
                cm.destroy();
            }
            if (wrap.parentNode) {
                wrap.parentNode.removeChild(wrap);
            }
            backingTa.style.display = '';
        },
    };
};
