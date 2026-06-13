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
 * Per-argument Run inputs for a function question, shared by the practice
 * workbench (qtype_codeatelier/control) and the judge "run your own input"
 * (qtype_codeatelier/judgerun) so the two tiers behave identically.
 *
 * A function question's single "JSON array of all arguments" box confuses
 * students: a call shown as f([1, 2, 3]) (one list argument) must be typed
 * [[1, 2, 3]]. These per-argument fields each take a value directly (matching
 * the call), assemble the args array behind the scenes into the hidden scratch
 * field the run flow reads, and show a live call preview. (LeetCode uses
 * per-parameter inputs for the same reason.)
 *
 * @module qtype_codeatelier/argfields
 */

/**
 * Replace a scratch box with one field per argument. Returns true when the
 * fields were built (arity inferred from the example tests), false to keep the
 * raw box as a fallback.
 *
 * @param {HTMLElement} scratchEl the hidden scratch input the run flow reads
 * @param {Object} opts {wrapperid, entrypoint, tests, strings}
 * @return {Boolean}
 */
export const setupFunctionArgs = (scratchEl, opts) => {
    const strings = opts.strings || {};
    let arity = null;
    let prefill = null;
    (opts.tests || []).some((t) => {
        let arr;
        try {
            arr = JSON.parse(t.input);
        } catch (e) {
            return false;
        }
        if (Array.isArray(arr)) {
            arity = arr.length;
            prefill = arr;
            return true;
        }
        return false;
    });
    if (arity === null || !scratchEl) {
        return false;
    }

    scratchEl.style.display = 'none';
    const fieldsWrap = document.createElement('div');
    fieldsWrap.className = 'codeatelier-argfields';
    const inputs = [];
    for (let i = 0; i < arity; i++) {
        const id = (opts.wrapperid || 'ca') + '_arg' + i;
        const fieldrow = document.createElement('div');
        fieldrow.className = 'codeatelier-argfield';
        const label = document.createElement('label');
        label.className = 'codeatelier-block-label';
        label.setAttribute('for', id);
        label.textContent = (strings.argn || 'Argument {$a}').replace('{$a}', i + 1);
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.id = id;
        inp.className = 'codeatelier-arg-input';
        inp.spellcheck = false;
        if (prefill && prefill[i] !== undefined) {
            inp.value = JSON.stringify(prefill[i]);
        }
        inputs.push(inp);
        fieldrow.appendChild(label);
        fieldrow.appendChild(inp);
        fieldsWrap.appendChild(fieldrow);
    }

    const preview = document.createElement('p');
    preview.className = 'codeatelier-arg-callpreview';
    const assemble = () => {
        const args = inputs.map((inp) => {
            try {
                return JSON.parse(inp.value);
            } catch (e) {
                // Lenient: pass the raw text so a malformed argument surfaces as a
                // real run error rather than blocking the student.
                return inp.value;
            }
        });
        scratchEl.value = JSON.stringify(args);
        preview.textContent = (opts.entrypoint || 'solve')
            + '(' + args.map((a) => JSON.stringify(a)).join(', ') + ')';
    };
    inputs.forEach((inp) => inp.addEventListener('input', assemble));
    assemble();

    // Lets "Use this input" on an example distribute its argument array into the
    // per-argument fields (the example's input is the full args array).
    scratchEl.codeatelierSetArgs = (inputstr) => {
        try {
            const arr = JSON.parse(inputstr);
            if (Array.isArray(arr)) {
                inputs.forEach((inp, i) => {
                    inp.value = (arr[i] === undefined) ? '' : JSON.stringify(arr[i]);
                });
                assemble();
            }
        } catch (e) {
            // Leave the fields unchanged on malformed input.
        }
        if (inputs[0]) {
            inputs[0].focus();
        }
    };

    scratchEl.parentNode.insertBefore(fieldsWrap, scratchEl.nextSibling);
    fieldsWrap.parentNode.insertBefore(preview, fieldsWrap.nextSibling);
    return true;
};
