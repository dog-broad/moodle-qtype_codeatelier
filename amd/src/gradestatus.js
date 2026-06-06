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
 * Live grade status for a submitted judge-tier attempt.
 *
 * A judge submission is graded off the web request by a scheduled task, so the
 * review first shows "awaiting automatic grading". This polls the server and
 * reloads the page once the grade has landed, so the student sees their result
 * without refreshing by hand.
 *
 * @module qtype_codeatelier/gradestatus
 */

import Ajax from 'core/ajax';

/** @var {Number} how often to poll, in milliseconds. */
const INTERVAL_MS = 5000;

/** @var {Number} stop after this many polls (~3 minutes), to not poll forever. */
const MAX_POLLS = 36;

/**
 * @param {Object} config
 * @param {String} config.wrapperid the question wrapper element id
 * @param {Number} config.qubaid the question usage id
 * @param {Number} config.slot the slot within the usage
 * @param {String} config.grading label shown while grading is in progress
 */
export const init = (config) => {
    const root = document.getElementById(config.wrapperid);
    if (!root) {
        return;
    }
    const el = root.querySelector('[data-role="gradestatus"]');
    if (!el) {
        return;
    }

    let polls = 0;
    const poll = () => {
        Ajax.call([{
            methodname: 'qtype_codeatelier_grade_status',
            args: {qubaid: config.qubaid, slot: config.slot},
        }])[0].then((result) => {
            if (result.graded) {
                window.location.reload();
                return result;
            }
            if (config.grading) {
                el.textContent = config.grading;
            }
            polls++;
            if (polls < MAX_POLLS) {
                window.setTimeout(poll, INTERVAL_MS);
            }
            return result;
        }).catch(() => {
            // Stop polling on error; the page still shows the static pending message.
            return null;
        });
    };
    window.setTimeout(poll, INTERVAL_MS);
};
