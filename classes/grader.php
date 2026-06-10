<?php
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

namespace qtype_codeatelier;

/**
 * Declarative matching-spec interpreter (server tier).
 *
 * The matching rule is data, not code: each test case carries a {matchmode,
 * tolerance}. This class interprets that spec to decide whether a run's output
 * matches the expected value, and aggregates weighted per-test results into an
 * overall fraction and verdict.
 *
 * It is a faithful port of the in-browser grader (amd/src/grader.js): both tiers
 * interpret the SAME declarative spec so a given answer grades identically in the
 * browser and on the server. The two implementations are pinned together by a
 * shared fixture exercised from both PHP and JS (see tests/fixtures); that parity
 * is the whole reason the spec is declarative rather than hand-coded per tier.
 *
 * One cross-language invariant lives here: JavaScript has a single number type,
 * so structured/result-set deep equality treats integer 5 and float 5.0 as equal
 * (it compares numbers numerically), otherwise JSON like 5 vs 5.0 would match in
 * the browser yet mismatch on the server.
 *
 * Pure functions only: no DB, no Moodle globals.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grader {
    /**
     * Decide whether an actual output matches the expected value under a rule.
     *
     * For text modes (exact/trimmed/regex/float) $actual is treated as a string.
     * For structured it is the function return value (a decoded value, or a JSON
     * string to be decoded). For result-set modes it is the array of rows.
     *
     * @param mixed $actual the produced output
     * @param string $expected the authored expected value
     * @param string $mode exact | trimmed | regex | float | structured | resultset | resultset_ordered
     * @param mixed $tolerance absolute tolerance, used only by float (null/'' means 0)
     * @return bool
     */
    public static function matches($actual, string $expected, string $mode, $tolerance = null): bool {
        switch ($mode) {
            case 'exact':
                return (string) $actual === (string) $expected;
            case 'trimmed':
                return self::normalise($actual) === self::normalise($expected);
            case 'regex':
                // No anchors and no flags, to mirror JavaScript's RegExp.test(). A
                // control char is the delimiter so authored patterns containing '/'
                // need no escaping; an invalid pattern yields false (as the JS catch).
                $result = @preg_match("\1" . $expected . "\1", (string) $actual);
                return $result === 1;
            case 'float':
                // Compare every number in the output positionally within tolerance.
                // A single value is just the one-element case.
                $actuals = self::extract_numbers($actual);
                $expecteds = self::extract_numbers($expected);
                if (count($expecteds) === 0 || count($actuals) !== count($expecteds)) {
                    return false;
                }
                $tol = (float) $tolerance;
                foreach ($expecteds as $i => $b) {
                    if (abs($actuals[$i] - $b) > $tol) {
                        return false;
                    }
                }
                return true;
            case 'structured':
                $expectedval = json_decode($expected);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }
                $actualval = $actual;
                if (is_string($actual)) {
                    $decoded = json_decode($actual);
                    $actualval = json_last_error() === JSON_ERROR_NONE ? $decoded : $actual;
                }
                return self::deep_equal($actualval, $expectedval);
            case 'resultset':
            case 'resultset_ordered':
                $expectedrows = json_decode($expected);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }
                if (!is_array($actual) || !is_array($expectedrows)) {
                    return false;
                }
                return $mode === 'resultset_ordered'
                    ? self::deep_equal($actual, $expectedrows)
                    : self::same_multiset($actual, $expectedrows);
            default:
                return self::normalise($actual) === self::normalise($expected);
        }
    }

    /**
     * Grade a set of run results against their test cases.
     *
     * Each result is an array shaped like the runner output:
     * {timedout?, error?, stdout?, returnvalue?, rows?}. Each test is an object
     * with {expected, matchmode, tolerance, weight, visibility}.
     *
     * @param array $results runner results, aligned with tests by index
     * @param array $tests the authored test cases
     * @param array $options {allOrNothing} — when set, a partial pass scores 0
     * @return array {fraction, verdict, testresults}
     */
    public static function grade(array $results, array $tests, array $options = []): array {
        $totalweight = 0.0;
        $testresults = [];
        // Subtask aggregation: tests are grouped by a subtask id. A positive id
        // forms an IOI-style group that earns its summed weight only if EVERY test
        // in it passes (all-or-nothing within the group); subtask 0 (or unset) is a
        // singleton group, i.e. ordinary per-test weighted partial credit. Earned
        // credit is summed over groups, so with no subtasks the result is identical
        // to plain weighted grading.
        $groups = [];

        foreach (array_values($tests) as $i => $test) {
            $result = $results[$i] ?? [];
            $weight = (float) (self::field($test, 'weight') ?? 0);
            $totalweight += $weight;

            $passed = false;
            if (!empty($result['timedout'])) {
                $status = 'timeout';
            } else if (!empty($result['error'])) {
                $status = 'error';
            } else if (array_key_exists('passed', $result)) {
                // An already-decided pass/fail (the judge-tier custom checker decides
                // AC/WA per test, replacing the declarative matching rule). Aggregation
                // — weights, all-or-nothing, subtasks — stays identical.
                $passed = (bool) $result['passed'];
                $status = $passed ? 'pass' : 'fail';
            } else {
                // Pick what to compare by mode: structured -> return value;
                // result-set -> the SQL result rows; text modes -> stdout.
                $mode = self::field($test, 'matchmode');
                if ($mode === 'structured') {
                    $actual = $result['returnvalue'] ?? null;
                } else if ($mode === 'resultset' || $mode === 'resultset_ordered') {
                    $actual = $result['rows'] ?? null;
                } else {
                    $actual = self::actual_output($result);
                }
                $passed = self::matches(
                    $actual,
                    (string) self::field($test, 'expected'),
                    (string) $mode,
                    self::field($test, 'tolerance')
                );
                $status = $passed ? 'pass' : 'fail';
            }

            $subtask = (int) (self::field($test, 'subtask') ?? 0);
            // A positive subtask id groups its tests; subtask 0 is a singleton.
            $key = $subtask > 0 ? ('g' . $subtask) : ('s' . $i);
            if (!isset($groups[$key])) {
                $groups[$key] = ['weight' => 0.0, 'allpassed' => true];
            }
            $groups[$key]['weight'] += $weight;
            if (!$passed) {
                $groups[$key]['allpassed'] = false;
            }

            $testresults[] = [
                'index' => $i,
                'status' => $status,
                'passed' => $passed,
                'visibility' => (int) self::field($test, 'visibility'),
                'subtask' => $subtask,
            ];
        }

        // A group earns its full summed weight only if every test in it passed.
        $earned = 0.0;
        foreach ($groups as $group) {
            if ($group['allpassed']) {
                $earned += $group['weight'];
            }
        }
        $fraction = $totalweight > 0 ? $earned / $totalweight : 0.0;
        // All-or-nothing: a partial pass earns nothing.
        if (!empty($options['allOrNothing']) && $fraction < 1) {
            $fraction = 0.0;
        }

        $haserror = false;
        $hastimeout = false;
        foreach ($testresults as $tr) {
            if ($tr['status'] === 'error') {
                $haserror = true;
            }
            if ($tr['status'] === 'timeout') {
                $hastimeout = true;
            }
        }
        if ($haserror) {
            $verdict = 'RE';
        } else if ($hastimeout) {
            $verdict = 'TIMEOUT';
        } else if ($fraction >= 1) {
            $verdict = 'AC';
        } else {
            $verdict = 'WA';
        }

        return ['fraction' => $fraction, 'verdict' => $verdict, 'testresults' => $testresults];
    }

    /**
     * Read a field from a test case given as either an object or an array.
     *
     * @param object|array $test the test case
     * @param string $name the field name
     * @return mixed the value, or null if absent
     */
    private static function field($test, string $name) {
        if (is_array($test)) {
            return $test[$name] ?? null;
        }
        return $test->$name ?? null;
    }

    /**
     * Pick the output to compare for a single run result.
     *
     * Defaults to captured stdout (the stdin/stdout scenario); falls back to the
     * return value when stdout is empty (a function-style answer).
     *
     * @param array $result a single runner result
     * @return mixed
     */
    private static function actual_output(array $result) {
        if (isset($result['stdout']) && $result['stdout'] !== '') {
            return $result['stdout'];
        }
        if (isset($result['returnvalue']) && $result['returnvalue'] !== null) {
            return $result['returnvalue'];
        }
        return $result['stdout'] ?? '';
    }

    /**
     * Normalise output for trimmed comparison: unify newlines, strip trailing
     * whitespace on each line, and trim the ends.
     *
     * @param mixed $value
     * @return string
     */
    private static function normalise($value): string {
        $value = str_replace("\r\n", "\n", (string) $value);
        $value = preg_replace('/[ \t]+\n/', "\n", $value);
        return trim($value);
    }

    /**
     * Structural deep equality for JSON-shaped values (primitives, arrays,
     * objects). Numbers compare numerically so int 5 equals float 5.0, matching
     * JavaScript's single number type.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private static function deep_equal($a, $b): bool {
        if (self::is_number($a) && self::is_number($b)) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            $a = array_values($a);
            $b = array_values($b);
            foreach ($a as $i => $item) {
                if (!self::deep_equal($item, $b[$i])) {
                    return false;
                }
            }
            return true;
        }
        if (is_object($a) && is_object($b)) {
            $akeys = get_object_vars($a);
            $bkeys = get_object_vars($b);
            if (count($akeys) !== count($bkeys)) {
                return false;
            }
            foreach ($akeys as $key => $value) {
                if (!array_key_exists($key, $bkeys) || !self::deep_equal($value, $bkeys[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    /**
     * Whether a value is a JSON number (int or float, not a boolean).
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_number($value): bool {
        return is_int($value) || is_float($value);
    }

    /**
     * Compare two arrays of rows ignoring order (as multisets of rows).
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function same_multiset(array $a, array $b): bool {
        if (count($a) !== count($b)) {
            return false;
        }
        $b = array_values($b);
        $used = array_fill(0, count($b), false);
        foreach ($a as $row) {
            $found = -1;
            foreach ($b as $i => $other) {
                if (!$used[$i] && self::deep_equal($other, $row)) {
                    $found = $i;
                    break;
                }
            }
            if ($found === -1) {
                return false;
            }
            $used[$found] = true;
        }
        return true;
    }

    /**
     * Extract the numeric tokens from a value, in order. Matches integers,
     * decimals and scientific notation, with an optional sign.
     *
     * @param mixed $value
     * @return array the parsed numbers, in the order they appear
     */
    private static function extract_numbers($value): array {
        if (preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', (string) $value, $m)) {
            return array_map('floatval', $m[0]);
        }
        return [];
    }
}
