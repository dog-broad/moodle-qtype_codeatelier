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
 * Declarative matching-spec interpreter for qtype_codeatelier.
 *
 * The matching rule is data, not code: each test case carries a {matchmode,
 * tolerance}. This module interprets that spec to decide whether a run's output
 * matches the expected value, and aggregates weighted per-test results into an
 * overall fraction and verdict. The judge tier (M2) interprets the SAME spec
 * shape so a given answer grades identically on both tiers — that parity is the
 * whole reason the spec is declarative.
 *
 * Pure functions only: no DOM, no worker, no Moodle globals.
 *
 * @module qtype_codeatelier/grader
 */

/**
 * Normalise output for trimmed comparison: unify newlines, strip trailing
 * whitespace on each line, and trim the ends.
 *
 * @param {String} value
 * @return {String}
 */
const normalise = (value) => {
    return String(value).replace(/\r\n/g, '\n').replace(/[ \t]+\n/g, '\n').trim();
};

/**
 * Decide whether an actual output matches the expected value under a rule.
 *
 * @param {String} actual the produced output
 * @param {String} expected the authored expected value
 * @param {String} mode exact | trimmed | regex | float
 * @param {Number} tolerance absolute tolerance, used only by float
 * @return {Boolean}
 */
const matches = (actual, expected, mode, tolerance) => {
    switch (mode) {
        case 'exact':
            return String(actual) === String(expected);
        case 'trimmed':
            return normalise(actual) === normalise(expected);
        case 'regex':
            try {
                return new RegExp(expected).test(String(actual));
            } catch (e) {
                return false;
            }
        case 'float': {
            const a = parseFloat(actual);
            const b = parseFloat(expected);
            if (Number.isNaN(a) || Number.isNaN(b)) {
                return false;
            }
            return Math.abs(a - b) <= (tolerance || 0);
        }
        default:
            return normalise(actual) === normalise(expected);
    }
};

/**
 * Pick the output to compare for a single run result.
 *
 * Defaults to captured stdout (the stdin/stdout scenario); falls back to the
 * return value when stdout is empty (a function-style answer). The explicit
 * per-scenario comparison target is refined in the scenarios task.
 *
 * @param {Object} result a single runner result
 * @return {String}
 */
const actualOutput = (result) => {
    if (result.stdout !== undefined && result.stdout !== '') {
        return result.stdout;
    }
    if (result.returnvalue !== undefined && result.returnvalue !== null) {
        return result.returnvalue;
    }
    return result.stdout || '';
};

/**
 * Grade a set of run results against their test cases.
 *
 * @param {Array} results runner results, aligned with tests by index
 * @param {Array} tests the authored test cases ({expected, matchmode, tolerance, weight, visibility})
 * @return {Object} {fraction, verdict, testresults}
 */
const grade = (results, tests) => {
    let totalweight = 0;
    let earned = 0;

    const testresults = tests.map((test, i) => {
        const result = results[i] || {};
        const weight = parseFloat(test.weight) || 0;
        totalweight += weight;

        let status;
        let passed = false;
        if (result.timedout) {
            status = 'timeout';
        } else if (result.error) {
            status = 'error';
        } else {
            passed = matches(actualOutput(result), test.expected, test.matchmode,
                    parseFloat(test.tolerance));
            status = passed ? 'pass' : 'fail';
        }

        if (passed) {
            earned += weight;
        }

        return {
            index: i,
            status: status,
            passed: passed,
            visibility: Number(test.visibility),
        };
    });

    const fraction = totalweight > 0 ? earned / totalweight : 0;

    let verdict;
    if (testresults.some((tr) => tr.status === 'error')) {
        verdict = 'RE';
    } else if (testresults.some((tr) => tr.status === 'timeout')) {
        verdict = 'TIMEOUT';
    } else if (fraction >= 1) {
        verdict = 'AC';
    } else {
        verdict = 'WA';
    }

    return {fraction: fraction, verdict: verdict, testresults: testresults};
};

export {matches, grade};
