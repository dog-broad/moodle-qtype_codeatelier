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
 * Reports focus-away (tab/window switch) and outside-clipboard attempts to the server
 * as they happen, through an authenticated AJAX call that appends them to a server-side,
 * append-only log stamped with the server clock and the authenticated user. The
 * clipboard itself is governed by the editor's sandboxed in-memory clipboard (see
 * editor.js): internal copy/cut/paste work, the OS clipboard is never touched, and an
 * attempt to paste/drop outside content is reported here.
 *
 * This is deterrence + detection, never prevention: a student can still decline to send
 * an event (the honest boundary of any browser-side measure). The gain over the former
 * hidden field is that what *is* recorded is server-authoritative and cannot be forged
 * or cleared through the page. The real integrity guarantees remain the authoritative
 * server grade and Safe Exam Browser.
 *
 * @module qtype_codeatelier/proctor
 */

import Ajax from 'core/ajax';

/** @var {Number} client-side cap on events sent, so we never flood the endpoint. */
const MAX_EVENTS = 200;

/** @var {Number} a tab switch can fire both blur and visibilitychange; collapse them. */
const FOCUS_DEBOUNCE_MS = 1500;

/**
 * Wire up the deterrents and the server-side event log for one question.
 *
 * @param {Object} config
 * @param {String} config.wrapperid the question wrapper element id
 * @param {Number} config.qubaid the question usage id
 * @param {Number} config.slot the slot within the usage
 */
export const init = (config) => {
    const root = document.getElementById(config.wrapperid);
    if (!root) {
        return;
    }

    let sent = 0;
    let lastfocus = 0;

    const record = (type) => {
        if (sent >= MAX_EVENTS) {
            return;
        }
        sent++;
        Ajax.call([{
            methodname: 'qtype_codeatelier_log_proctor',
            args: {qubaid: config.qubaid, slot: config.slot, eventtype: type},
        }])[0].catch(() => {
            // A dropped event is no worse than a suppressed one; never disrupt the attempt.
            return null;
        });
    };

    // The editor governs the clipboard itself on the secure tier — a sandboxed
    // in-memory clipboard (internal copy/cut/paste work; the OS clipboard is never
    // touched, so external paste is impossible and copy-out is prevented). It reports an
    // external-origin attempt (paste/drop carrying outside content) here so this module
    // stays the single reporter of proctor events.
    root.addEventListener('codeatelier:clip-external', (e) => {
        record((e.detail && e.detail.type) || 'extpaste');
    });

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
