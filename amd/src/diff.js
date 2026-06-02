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
 * A small word-level inline diff for qtype_codeatelier feedback.
 *
 * Pure functions only. Tokenises into runs of whitespace and non-whitespace,
 * then computes the diff via longest-common-subsequence backtracking. The result
 * is a flat op list the controller renders into expected/actual highlighting.
 *
 * @module qtype_codeatelier/diff
 */

/**
 * Split into word/whitespace tokens.
 *
 * @param {String} value
 * @return {Array<String>}
 */
const tokenize = (value) => {
    return String(value === null || value === undefined ? '' : value).match(/\s+|\S+/g) || [];
};

/**
 * Word-level diff of two strings.
 *
 * @param {String} expected
 * @param {String} actual
 * @return {Array<Object>} ops, each {type: 'equal'|'del'|'ins', text}
 *     where 'del' is present only in expected and 'ins' only in actual.
 */
const tokenDiff = (expected, actual) => {
    const a = tokenize(expected);
    const b = tokenize(actual);
    const n = a.length;
    const m = b.length;

    const dp = Array.from({length: n + 1}, () => new Array(m + 1).fill(0));
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            dp[i][j] = (a[i] === b[j]) ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }

    const ops = [];
    let i = 0;
    let j = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) {
            ops.push({type: 'equal', text: a[i]});
            i++;
            j++;
        } else if (dp[i + 1][j] >= dp[i][j + 1]) {
            ops.push({type: 'del', text: a[i]});
            i++;
        } else {
            ops.push({type: 'ins', text: b[j]});
            j++;
        }
    }
    while (i < n) {
        ops.push({type: 'del', text: a[i++]});
    }
    while (j < m) {
        ops.push({type: 'ins', text: b[j++]});
    }
    return ops;
};

export {tokenDiff};
