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
 * Shared SQL result-set table renderer.
 *
 * Both tiers render a SQL result the same way: the practice tier (in-browser
 * sql.js) and the secure tier (server CSV parsed to rows) each hand this module a
 * rows array, so the student sees one consistent, bounded, scrollable table rather
 * than a table on one tier and raw text on the other.
 *
 * @module qtype_codeatelier/resulttable
 */

const ROWCAP = 50;

/**
 * Safely parse a JSON string of rows into an array (e.g. '[["Alice",230]]'),
 * returning null when it is not parseable as an array.
 *
 * @param {String} value
 * @return {Array|null}
 */
export const parseRows = (value) => {
    try {
        const parsed = JSON.parse(value);
        return Array.isArray(parsed) ? parsed : null;
    } catch (e) {
        return null;
    }
};

/**
 * Rows present in `rows` but not in `other` (as a multiset by JSON key), so a
 * diff can highlight the rows that differ between expected and actual.
 *
 * @param {Array} rows
 * @param {Array} other
 * @return {Object} set of JSON keys
 */
export const extraRowKeys = (rows, other) => {
    const counts = {};
    (other || []).forEach((row) => {
        const key = JSON.stringify(row);
        counts[key] = (counts[key] || 0) + 1;
    });
    const extra = {};
    (rows || []).forEach((row) => {
        const key = JSON.stringify(row);
        if (counts[key]) {
            counts[key]--;
        } else {
            extra[key] = true;
        }
    });
    return extra;
};

/**
 * Build a result-set table.
 *
 * The table is wrapped in a scroll container (bounded height + both-axis scroll)
 * so a wide or tall result set never breaks the pane, and display is capped so a
 * pathological result set does not render thousands of rows; the cap is noted.
 *
 * @param {Array} columns optional column names
 * @param {Array} rows array of row arrays
 * @param {Object} extrakeys set of JSON-stringified rows to highlight
 * @param {Object} opts {cap: max rows (default 50), strings, diffside: 'expected'|'got'}
 * @return {HTMLElement}
 */
export const resultSetTable = (columns, rows, extrakeys, opts) => {
    opts = opts || {};
    const cap = (typeof opts.cap === 'number') ? opts.cap : ROWCAP;
    const allrows = rows || [];
    const capped = allrows.length > cap;
    const shown = capped ? allrows.slice(0, cap) : allrows;

    const table = document.createElement('table');
    table.className = 'codeatelier-resultset';
    // On a diff side, a modifier class drives the per-side row marker (− expected,
    // + got) so a highlighted row is distinguishable without relying on colour.
    if (opts.diffside === 'expected' || opts.diffside === 'got') {
        table.classList.add('codeatelier-resultset-' + opts.diffside);
    }
    if (columns && columns.length) {
        const thead = document.createElement('thead');
        const tr = document.createElement('tr');
        columns.forEach((col) => {
            const th = document.createElement('th');
            th.textContent = col;
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        table.appendChild(thead);
    }
    const tbody = document.createElement('tbody');
    shown.forEach((row) => {
        const tr = document.createElement('tr');
        if (extrakeys && extrakeys[JSON.stringify(row)]) {
            tr.className = 'codeatelier-row-diff';
        }
        row.forEach((value) => {
            const td = document.createElement('td');
            td.textContent = (value === null) ? 'NULL' : String(value);
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);

    const block = document.createElement('div');
    block.className = 'codeatelier-resultset-block';
    const scroll = document.createElement('div');
    scroll.className = 'codeatelier-table-scroll';
    scroll.appendChild(table);
    block.appendChild(scroll);
    if (capped) {
        const note = document.createElement('p');
        note.className = 'codeatelier-rows-note';
        const tpl = (opts.strings && opts.strings.rowsnote) || 'Showing {shown} of {total} rows';
        note.textContent = tpl.replace('{shown}', String(cap)).replace('{total}', String(allrows.length));
        block.appendChild(note);
    }
    return block;
};
