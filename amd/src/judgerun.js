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
 * Judge-tier "run against examples": send the current code to the server, which
 * runs it in the sandbox against the question's VISIBLE example inputs and
 * returns the output. Formative only — no grade, no hidden tests. This gives
 * compiled-language students (no in-browser runtime) a feedback loop before the
 * authoritative submit.
 *
 * @module qtype_codeatelier/judgerun
 */

import Ajax from 'core/ajax';

/**
 * @param {Object} config
 * @param {String} config.wrapperid the question wrapper element id
 * @param {Number} config.questionid the question id
 * @param {String} config.language the default language (when the student has not picked one)
 * @param {Object} config.strings localised labels
 */
export const init = (config) => {
    const root = document.getElementById(config.wrapperid);
    if (!root) {
        return;
    }
    const button = root.querySelector('[data-role="runexamples"]');
    const out = root.querySelector('[data-role="runresults"]');
    const answer = root.querySelector('[data-role="answer"]');
    if (!button || !out || !answer) {
        return;
    }
    const langField = root.querySelector('[data-role="language"]');
    const strings = config.strings || {};

    const language = () => (langField && langField.value) ? langField.value : config.language;

    button.addEventListener('click', () => {
        button.disabled = true;
        out.textContent = strings.running || 'Running…';
        Ajax.call([{
            methodname: 'qtype_codeatelier_run_examples',
            args: {questionid: config.questionid, code: answer.value, language: language()},
        }])[0].then((result) => {
            render(out, result, strings);
            button.disabled = false;
            return result;
        }).catch((error) => {
            out.textContent = (error && error.message) ? error.message : String(error);
            button.disabled = false;
        });
    });

    // "Run with my own input": run the current code once against the student's own
    // input (stdin, or a JSON argument array for the function scenario). Formative,
    // no grade — the judge-tier counterpart of the practice tier's Run verb.
    const scratchBtn = root.querySelector('[data-role="runscratch"]');
    const scratchIn = root.querySelector('[data-role="scratchinput"]');
    const scratchOut = root.querySelector('[data-role="scratchoutput"]');
    if (scratchBtn && scratchIn && scratchOut) {
        scratchBtn.addEventListener('click', () => {
            scratchBtn.disabled = true;
            scratchOut.textContent = strings.running || 'Running…';
            Ajax.call([{
                methodname: 'qtype_codeatelier_run_scratch',
                args: {questionid: config.questionid, code: answer.value,
                    language: language(), input: scratchIn.value},
            }])[0].then((result) => {
                renderScratch(scratchOut, result, strings);
                scratchBtn.disabled = false;
                return result;
            }).catch((error) => {
                scratchOut.textContent = (error && error.message) ? error.message : String(error);
                scratchBtn.disabled = false;
            });
        });
    }
};

/**
 * Render a single scratch run: compile error, runtime error output, or stdout.
 *
 * @param {HTMLElement} out
 * @param {Object} result {verdict, compileoutput, stdout, stderr}
 * @param {Object} strings
 */
/**
 * Append a labelled compiler-output block.
 *
 * @param {HTMLElement} out
 * @param {String} text
 * @param {Object} strings
 */
const compileBlock = (out, text, strings) => {
    const heading = document.createElement('p');
    heading.className = 'codeatelier-block-label';
    heading.textContent = strings.compileoutput || 'What the compiler reported:';
    out.appendChild(heading);
    out.appendChild(pre('codeatelier-compileoutput', text));
};

const renderScratch = (out, result, strings) => {
    out.textContent = '';
    if (result.verdict === 'CE' && result.compileoutput) {
        compileBlock(out, result.compileoutput, strings);
        return;
    }
    if (result.stderr && result.stderr.trim() !== '') {
        out.appendChild(pre('codeatelier-test-error', result.stderr));
    }
    const text = (result.stdout && result.stdout !== '') ? result.stdout : (strings.nooutput || '(no output)');
    out.appendChild(pre('codeatelier-diff-value', text));
};

/**
 * Render the run result into the output area.
 *
 * @param {HTMLElement} out
 * @param {Object} result
 * @param {Object} strings
 */
const render = (out, result, strings) => {
    out.textContent = '';
    out.appendChild(clearBar(out, strings));
    if (result.verdict === 'CE' && result.compileoutput) {
        compileBlock(out, result.compileoutput, strings);
        return;
    }
    const list = document.createElement('ul');
    list.className = 'codeatelier-judge-tests';
    result.examples.forEach((ex) => {
        list.appendChild(renderExample(ex, strings));
    });
    out.appendChild(list);
};

/**
 * A small bar with a Clear button that empties the results, so they don't stay
 * on screen and push the "run your own input" section out of view.
 *
 * @param {HTMLElement} out the results container to clear
 * @param {Object} strings
 * @return {HTMLElement}
 */
const clearBar = (out, strings) => {
    const bar = document.createElement('div');
    bar.className = 'codeatelier-runresults-bar';
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'btn btn-link btn-sm codeatelier-runresults-clear';
    clear.textContent = strings.clear || 'Clear';
    clear.addEventListener('click', () => {
        out.textContent = '';
    });
    bar.appendChild(clear);
    return bar;
};

/**
 * @param {Object} ex one example result
 * @param {Object} strings
 * @return {HTMLElement}
 */
const renderExample = (ex, strings) => {
    const li = document.createElement('li');
    li.className = 'codeatelier-judge-test ' + (ex.passed ? 'codeatelier-pass' : 'codeatelier-fail');

    const status = document.createElement('span');
    status.className = 'codeatelier-test-status codeatelier-status-' + (ex.passed ? 'pass' : ex.status);
    status.textContent = (strings.example || 'Example') + ' ' + (ex.index + 1) + ': '
        + (ex.passed ? (strings.pass || 'passed') : statusLabel(ex.status, strings));
    li.appendChild(status);

    if (!ex.passed) {
        if ((ex.status === 're' || ex.status === 'mle' || ex.status === 'tle') && ex.stderr) {
            li.appendChild(pre('codeatelier-test-error', ex.stderr));
        } else {
            const diff = document.createElement('div');
            diff.className = 'codeatelier-test-diff';
            if (ex.input && ex.input.trim() !== '') {
                diff.appendChild(diffRow(strings.input || 'Input', ex.input));
            }
            diff.appendChild(diffRow(strings.expected || 'Expected', ex.expected));
            diff.appendChild(diffRow(strings.got || 'Got', ex.actual));
            li.appendChild(diff);
        }
    }
    return li;
};

/**
 * @param {String} status
 * @param {Object} strings
 * @return {String}
 */
const statusLabel = (status, strings) => {
    const map = {re: strings.re, tle: strings.tle, mle: strings.mle};
    return map[status] || strings.fail || 'failed';
};

/**
 * @param {String} label
 * @param {String} value
 * @return {HTMLElement}
 */
const diffRow = (label, value) => {
    const row = document.createElement('div');
    row.className = 'codeatelier-diff-row';
    const l = document.createElement('span');
    l.className = 'codeatelier-diff-label';
    l.textContent = label;
    row.appendChild(l);
    row.appendChild(pre('codeatelier-diff-value', value));
    return row;
};

/**
 * @param {String} cls
 * @param {String} text
 * @return {HTMLElement}
 */
const pre = (cls, text) => {
    const el = document.createElement('pre');
    el.className = cls;
    el.textContent = text;
    return el;
};
