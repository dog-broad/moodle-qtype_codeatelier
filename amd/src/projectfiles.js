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
 * Multi-file project editor for qtype_codeatelier.
 *
 * Builds a file-tab list and a single CodeMirror editor that swaps to the active
 * file. The student's whole file set is kept in the hidden `answer` field as the
 * canonical JSON the judge tier grades (see the project scenario), so submit,
 * run-against-examples and run-with-my-own-input all read the same value with no
 * extra wiring. Deliberately not an IDE: a flat file list, no tree, no terminal.
 *
 * @module qtype_codeatelier/projectfiles
 */

import {create} from 'qtype_codeatelier/editor';

/** @var {Object} file extension to CodeMirror highlighting language. */
const EXT_LANGUAGE = {
    c: 'c', h: 'cpp', cpp: 'cpp', cc: 'cpp', cxx: 'cpp', hpp: 'cpp', hh: 'cpp',
    java: 'java', py: 'python', js: 'javascript', mjs: 'javascript', ts: 'typescript',
    html: 'html', htm: 'html', css: 'css',
};

/**
 * The highlighting language for a file, by extension, falling back to the
 * question's language.
 *
 * @param {String} name the file name
 * @param {String} fallback the question's primary language
 * @return {String}
 */
const languageForFile = (name, fallback) => {
    const dot = name.lastIndexOf('.');
    const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
    return EXT_LANGUAGE[ext] || fallback || 'cpp';
};

/**
 * Parse a canonical file set from JSON. Returns null on anything malformed.
 *
 * @param {String} json
 * @return {Array|null} array of {name, content}
 */
const parseFiles = (json) => {
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
 * A small button element.
 *
 * @param {String} label
 * @param {Function} onClick
 * @return {HTMLButtonElement}
 */
const button = (label, onClick) => {
    const el = document.createElement('button');
    el.type = 'button';
    el.className = 'btn btn-link btn-sm codeatelier-fileaction';
    el.textContent = label;
    el.addEventListener('click', onClick);
    return el;
};

/**
 * Set up the multi-file editor inside the question's editor container.
 *
 * @param {HTMLElement} root the question wrapper
 * @param {HTMLTextAreaElement} answerTa the hidden answer field (the JSON store)
 * @param {Object} config the controller config
 * @param {Object} strings localised labels
 * @param {Function} [onChange] called after every edit (the web scenario uses it
 *     to refresh the live preview); receives no arguments.
 * @return {Object} {getValue, destroy} — getValue returns the canonical JSON
 */
export const setup = (root, answerTa, config, strings, onChange) => {
    const readonly = !!config.readonly;
    // Secure tier: each file-tab editor uses the same sandboxed clipboard as the
    // single-file editor; external attempts flag through the question wrapper.
    const sandbox = !!config.isjudge && !readonly;
    const onExternal = sandbox
        ? (type) => root.dispatchEvent(new CustomEvent('codeatelier:clip-external', {detail: {type: type}}))
        : null;
    let files = parseFiles(answerTa.value);
    if (!files || !files.length) {
        files = [{name: config.entryfile || 'main', content: ''}];
    }
    let active = 0;
    let cm = null;
    // The rename input and the actions bar are created below only on a live
    // attempt (both null on review). Tracked so destroy() can remove them.
    let nameInput = null;
    let actions = null;

    const container = answerTa.closest('.codeatelier-editor') || answerTa.parentNode;
    answerTa.style.display = 'none';
    const skeleton = container.querySelector('[data-role="skeleton"]');
    if (skeleton) {
        skeleton.style.display = 'none';
    }

    const serialise = () => {
        answerTa.value = JSON.stringify(files.map((f) => ({name: f.name, content: f.content})));
        if (typeof onChange === 'function') {
            onChange();
        }
    };

    const tablist = document.createElement('div');
    tablist.className = 'codeatelier-filetabs';
    tablist.setAttribute('role', 'tablist');
    tablist.setAttribute('aria-label', strings.projectfiles || 'Project files');

    const fileEditor = document.createElement('textarea');
    fileEditor.className = 'codeatelier-answer codeatelier-file-editor';
    fileEditor.setAttribute('spellcheck', 'false');
    fileEditor.rows = 14;

    container.appendChild(tablist);

    // Mount (or re-mount) the single editor on the active file's content.
    const mountActive = () => {
        if (cm && cm.destroy) {
            cm.destroy();
        }
        fileEditor.value = files[active].content;
        try {
            cm = create(fileEditor, languageForFile(files[active].name, config.language), {
                readonly: readonly,
                ariaLabel: files[active].name,
                sandbox: sandbox,
                onExternal: onExternal,
                onChange: () => {
                    files[active].content = fileEditor.value;
                    serialise();
                },
            });
        } catch (e) {
            fileEditor.style.display = 'block';
            cm = {getValue: () => fileEditor.value};
            fileEditor.addEventListener('input', () => {
                files[active].content = fileEditor.value;
                serialise();
            });
        }
    };

    const tabEls = () => Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));

    // selectFile and renderTabs are mutually recursive (a tab click selects; a
    // select re-renders the tabs), so they are function declarations (hoisted).
    function selectFile(index) {
        if (index === active) {
            return;
        }
        if (cm && cm.getValue) {
            files[active].content = cm.getValue();
        }
        active = index;
        renderTabs();
        mountActive();
        serialise();
        if (nameInput) {
            nameInput.value = files[active].name;
        }
    }

    function renderTabs() {
        tablist.textContent = '';
        files.forEach((file, index) => {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'codeatelier-filetab' + (index === active ? ' codeatelier-filetab-active' : '');
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', index === active ? 'true' : 'false');
            tab.tabIndex = index === active ? 0 : -1;
            tab.textContent = file.name;
            tab.addEventListener('click', () => selectFile(index));
            tab.addEventListener('keydown', (e) => {
                const tabs = tabEls();
                const at = tabs.indexOf(tab);
                let target = null;
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    target = tabs[(at + 1) % tabs.length];
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    target = tabs[(at - 1 + tabs.length) % tabs.length];
                } else if (e.key === 'Home') {
                    target = tabs[0];
                } else if (e.key === 'End') {
                    target = tabs[tabs.length - 1];
                }
                if (target) {
                    e.preventDefault();
                    selectFile(tabs.indexOf(target));
                    target.focus();
                }
            });
            tablist.appendChild(tab);
        });
    }

    // A name is valid if non-empty, free of path separators, and unique.
    const nameTaken = (name) => files.some((f, i) => i !== active && f.name === name);
    const validName = (name) => name !== '' && !/[\\/]/.test(name) && !nameTaken(name);

    if (!readonly) {
        actions = document.createElement('div');
        actions.className = 'codeatelier-fileactions';

        const nameLabel = document.createElement('label');
        nameLabel.className = 'codeatelier-block-label';
        nameLabel.textContent = strings.filename || 'File name';
        const inputId = (answerTa.id || 'codeatelier') + '_filename';
        nameLabel.setAttribute('for', inputId);

        nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.id = inputId;
        nameInput.className = 'form-control codeatelier-filename';
        nameInput.value = files[active].name;
        nameInput.setAttribute('spellcheck', 'false');

        const message = document.createElement('span');
        message.className = 'codeatelier-fileerror text-danger small';
        message.setAttribute('role', 'alert');

        // Commit a rename on blur/Enter; revert and warn on an invalid name.
        nameInput.addEventListener('change', () => {
            const next = nameInput.value.trim();
            if (next === files[active].name) {
                message.textContent = '';
                nameInput.removeAttribute('aria-invalid');
                return;
            }
            if (!validName(next)) {
                message.textContent = strings.badfilename || 'Enter a unique file name without slashes.';
                nameInput.setAttribute('aria-invalid', 'true');
                nameInput.value = files[active].name;
                return;
            }
            message.textContent = '';
            nameInput.removeAttribute('aria-invalid');
            files[active].name = next;
            renderTabs();
            // The extension may have changed, so re-mount for the right highlighting.
            mountActive();
            serialise();
        });

        let deleteButton = null;
        const addButton = button(strings.addfile || 'Add file', () => {
            let n = files.length + 1;
            let name = 'file' + n;
            while (files.some((f) => f.name === name)) {
                n++;
                name = 'file' + n;
            }
            files.push({name: name, content: ''});
            active = files.length - 1;
            renderTabs();
            mountActive();
            serialise();
            nameInput.value = name;
            nameInput.focus();
            nameInput.select();
            deleteButton.disabled = false;
        });

        deleteButton = button(strings.deletefile || 'Delete file', () => {
            if (files.length <= 1) {
                return;
            }
            files.splice(active, 1);
            if (active >= files.length) {
                active = files.length - 1;
            }
            renderTabs();
            mountActive();
            serialise();
            nameInput.value = files[active].name;
            deleteButton.disabled = files.length <= 1;
        });
        deleteButton.disabled = files.length <= 1;

        const namegroup = document.createElement('div');
        namegroup.className = 'codeatelier-filename-group';
        namegroup.appendChild(nameLabel);
        namegroup.appendChild(nameInput);
        actions.appendChild(namegroup);
        actions.appendChild(addButton);
        actions.appendChild(deleteButton);
        actions.appendChild(message);
        container.appendChild(actions);
    }

    container.appendChild(fileEditor);

    renderTabs();
    mountActive();
    serialise();

    return {
        getValue: () => answerTa.value,
        openSearch: () => {
            if (cm && typeof cm.openSearch === 'function') {
                cm.openSearch();
            }
        },
        destroy: () => {
            if (cm && cm.destroy) {
                cm.destroy();
            }
            // Remove the UI this widget built so it can be re-mounted cleanly
            // (the authoring form re-mounts on a scenario/language change).
            [tablist, actions, fileEditor].forEach((el) => {
                if (el && el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            });
            answerTa.style.display = '';
        },
    };
};
