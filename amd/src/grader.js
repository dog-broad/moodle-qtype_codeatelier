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
 * Structural deep equality for JSON-shaped values (primitives, arrays, objects).
 *
 * @param {*} a
 * @param {*} b
 * @return {Boolean}
 */
const deepEqual = (a, b) => {
    if (a === b) {
        return true;
    }
    if (typeof a !== typeof b || a === null || b === null || typeof a !== 'object') {
        return false;
    }
    if (Array.isArray(a) || Array.isArray(b)) {
        if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) {
            return false;
        }
        return a.every((item, i) => deepEqual(item, b[i]));
    }
    const akeys = Object.keys(a);
    const bkeys = Object.keys(b);
    if (akeys.length !== bkeys.length) {
        return false;
    }
    return akeys.every((key) => Object.prototype.hasOwnProperty.call(b, key) && deepEqual(a[key], b[key]));
};

/**
 * Compare two arrays of rows ignoring order (as multisets of rows).
 *
 * @param {Array} a
 * @param {Array} b
 * @return {Boolean}
 */
const sameMultiset = (a, b) => {
    if (a.length !== b.length) {
        return false;
    }
    const used = new Array(b.length).fill(false);
    return a.every((row) => {
        const idx = b.findIndex((other, i) => !used[i] && deepEqual(other, row));
        if (idx === -1) {
            return false;
        }
        used[idx] = true;
        return true;
    });
};

/**
 * Extract the numeric tokens from a value, in order. Matches integers, decimals
 * and scientific notation, with an optional sign.
 *
 * @param {String} value
 * @return {Array} the parsed numbers, in the order they appear
 */
const extractNumbers = (value) => {
    const tokens = String(value).match(/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/g);
    return tokens ? tokens.map(Number) : [];
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
            // Compare every number in the output positionally within tolerance.
            // A single value is just the one-element case.
            const actuals = extractNumbers(actual);
            const expecteds = extractNumbers(expected);
            if (expecteds.length === 0 || actuals.length !== expecteds.length) {
                return false;
            }
            const tol = tolerance || 0;
            return expecteds.every((b, i) => !Number.isNaN(actuals[i]) && Math.abs(actuals[i] - b) <= tol);
        }
        case 'structured': {
            let expectedval;
            try {
                expectedval = JSON.parse(expected);
            } catch (e) {
                return false;
            }
            let actualval = actual;
            if (typeof actual === 'string') {
                try {
                    actualval = JSON.parse(actual);
                } catch (e) {
                    actualval = actual;
                }
            }
            return deepEqual(actualval, expectedval);
        }
        case 'resultset':
        case 'resultset_ordered': {
            let expectedrows;
            try {
                expectedrows = JSON.parse(expected);
            } catch (e) {
                return false;
            }
            if (!Array.isArray(actual) || !Array.isArray(expectedrows)) {
                return false;
            }
            return mode === 'resultset_ordered'
                ? deepEqual(actual, expectedrows)
                : sameMultiset(actual, expectedrows);
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
 * @param {Object} [options] {allOrNothing} — when set, a partial pass scores 0
 * @return {Object} {fraction, verdict, testresults}
 */
const grade = (results, tests, options) => {
    const opts = options || {};
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
            // Pick what to compare by mode: structured -> return value;
            // result-set -> the SQL result rows; text modes -> stdout.
            let actual;
            if (test.matchmode === 'structured') {
                actual = result.returnvalue;
            } else if (test.matchmode === 'resultset' || test.matchmode === 'resultset_ordered') {
                actual = result.rows;
            } else {
                actual = actualOutput(result);
            }
            passed = matches(actual, test.expected, test.matchmode, parseFloat(test.tolerance));
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

    let fraction = totalweight > 0 ? earned / totalweight : 0;
    // All-or-nothing: a partial pass earns nothing.
    if (opts.allOrNothing && fraction < 1) {
        fraction = 0;
    }

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
