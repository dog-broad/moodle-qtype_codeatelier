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
 * Soft anti-cheat for judge-tier attempts.
 *
 * Deters clipboard and context-menu use on the editor, and logs focus-away
 * (tab/window switch) and blocked-paste events into a hidden response field that
 * is submitted and stored with the attempt. This is deterrence + detection, never
 * prevention: the log is client-accumulated and so tamperable by a determined
 * student. The real integrity guarantees are the authoritative server grade and
 * Safe Exam Browser; this raises the cost and surfaces the obvious cases.
 *
 * @module qtype_codeatelier/proctor
 */

/** @var {Number} cap on stored events, so the log cannot grow without bound. */
const MAX_EVENTS = 100;

/** @var {Number} a tab switch can fire both blur and visibilitychange; collapse them. */
const FOCUS_DEBOUNCE_MS = 1500;

/**
 * Wire up the deterrents and the event log for one question.
 *
 * @param {Object} config
 * @param {String} config.wrapperid the question wrapper element id
 */
export const init = (config) => {
    const root = document.getElementById(config.wrapperid);
    if (!root) {
        return;
    }
    const field = root.querySelector('[data-role="proctorlog"]');
    if (!field) {
        return;
    }

    let events;
    try {
        events = JSON.parse(field.value || '[]');
    } catch (e) {
        events = [];
    }
    if (!Array.isArray(events)) {
        events = [];
    }

    const start = Date.now();
    let lastfocus = 0;

    const record = (type) => {
        if (events.length >= MAX_EVENTS) {
            return;
        }
        events.push({type: type, t: Math.round((Date.now() - start) / 1000)});
        field.value = JSON.stringify(events);
    };

    // Deter clipboard and context-menu use on the work pane (the editor lives here).
    // Capture phase so CodeMirror's own handlers do not run first.
    const pane = root.querySelector('[data-role="pane-work"]') || root;
    ['copy', 'cut', 'contextmenu'].forEach((name) => {
        pane.addEventListener(name, (e) => {
            e.preventDefault();
        }, true);
    });
    pane.addEventListener('paste', (e) => {
        e.preventDefault();
        record('paste');
    }, true);

    // Log leaving the question: a window blur, or the tab being hidden.
    const focusaway = () => {
        const now = Date.now();
        if (now - lastfocus < FOCUS_DEBOUNCE_MS) {
            return;
        }
        lastfocus = now;
        record('focusaway');
    };
    window.addEventListener('blur', focusaway);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            focusaway();
        }
    });
};
