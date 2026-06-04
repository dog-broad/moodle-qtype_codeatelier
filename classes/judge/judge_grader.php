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

namespace qtype_codeatelier\judge;

use qtype_codeatelier\grader;

defined('MOODLE_INTERNAL') || die();

/**
 * Authoritative judge-tier grading.
 *
 * Runs a whole submission through the sandbox runner over every test case
 * (visible and hidden), then decides correctness with the declarative matching
 * spec — the same {@see grader} the in-browser tier uses, so a given answer
 * grades identically on both tiers. Execution is the runner's job; AC/WA is the
 * grader's; this class joins them and lifts the result into the judge verdict
 * taxonomy (AC / WA / TLE / RE / CE).
 *
 * Pure orchestration: a runner in, a result out. No DB, no caching, no Moodle
 * globals — so it unit-tests deterministically against a stub runner.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class judge_grader {

    /** @var judge0_runner the sandbox runner. */
    private $runner;

    /**
     * @param judge0_runner $runner the sandbox runner to execute submissions with
     */
    public function __construct(judge0_runner $runner) {
        $this->runner = $runner;
    }

    /**
     * Grade student code against a question's test cases.
     *
     * @param string $code the student submission (a complete stdin/stdout program)
     * @param string $language a judge-tier language key (c, cpp, java, python, javascript)
     * @param array $tests the authored test cases (objects with input/expected/matchmode/tolerance/weight/visibility)
     * @return array {
     *     infrastructure: bool — true if the sandbox could not be reached (caller should mark needs-grading),
     *     fraction: float, verdict: string (AC|WA|TLE|RE|CE|INTERNAL),
     *     compileoutput: string, message: string,
     *     testresults: array of {index, status (pass|fail|re|tle|ce), passed, visibility, time, memory}
     * }
     */
    public function grade(string $code, string $language, array $tests): array {
        $tests = array_values($tests);
        $cases = [];
        foreach ($tests as $test) {
            $cases[] = ['input' => $test->input];
        }

        $runresults = $this->runner->run($code, $language, $cases);

        // An infrastructure failure on any case means we cannot trust a grade at
        // all — never fake a zero; the caller routes this to needs-grading.
        foreach ($runresults as $result) {
            if ($result['outcome'] === 'internal') {
                return [
                    'infrastructure' => true,
                    'fraction' => 0.0,
                    'verdict' => 'INTERNAL',
                    'compileoutput' => '',
                    'message' => (string) ($result['statusdesc'] ?? ''),
                    'testresults' => [],
                ];
            }
        }

        // A compile error is a property of the code, not a single case, so it
        // fails the whole submission. Judge0 reports it identically per case.
        foreach ($runresults as $result) {
            if ($result['outcome'] === 'ce') {
                return [
                    'infrastructure' => false,
                    'fraction' => 0.0,
                    'verdict' => 'CE',
                    'compileoutput' => (string) $result['compileoutput'],
                    'message' => '',
                    'testresults' => $this->blanket_fail($tests, 'ce'),
                ];
            }
        }

        // Map each execution outcome into the grader's result shape so the SHARED
        // matcher decides the fraction (tier parity): a timeout, a runtime error
        // and an OOM are all zero-credit failures; a clean run is compared by its
        // stdout (the judge tier is the stdin/stdout shape).
        $graderresults = [];
        foreach ($runresults as $result) {
            if ($result['outcome'] === 'tle') {
                $graderresults[] = ['timedout' => true];
            } else if ($result['outcome'] === 're' || $result['outcome'] === 'mle') {
                $graderresults[] = ['error' => true];
            } else {
                $graderresults[] = ['stdout' => $result['stdout']];
            }
        }

        $graded = grader::grade($graderresults, $tests);

        $testresults = [];
        $anyre = false;
        $anymle = false;
        $anytle = false;
        foreach ($graded['testresults'] as $tr) {
            $i = $tr['index'];
            $run = $runresults[$i];
            $outcome = $run['outcome'];
            $visible = (int) $tests[$i]->visibility === 1;

            if ($outcome === 'mle') {
                $status = 'mle';
                $anymle = true;
            } else if ($outcome === 'tle') {
                $status = 'tle';
                $anytle = true;
            } else if ($outcome === 're') {
                $status = 're';
                $anyre = true;
            } else {
                $status = $tr['passed'] ? 'pass' : 'fail';
            }

            $row = [
                'index' => $i,
                'status' => $status,
                'passed' => $tr['passed'],
                'visibility' => $tr['visibility'],
                'time' => $run['time'] ?? null,
                'memory' => $run['memory'] ?? null,
            ];
            // Diagnostics for VISIBLE tests only — a hidden test never carries its
            // input, expected output or the produced output into the result blob
            // (the integrity boundary holds in storage as well as on the wire).
            if ($visible) {
                $row['input'] = (string) $tests[$i]->input;
                $row['expected'] = (string) $tests[$i]->expected;
                $row['actual'] = $outcome === 'ran' ? (string) $run['stdout'] : '';
                $row['stderr'] = ($outcome === 're' || $outcome === 'mle') ? (string) $run['stderr'] : '';
            }
            $testresults[] = $row;
        }

        return [
            'infrastructure' => false,
            'fraction' => $graded['fraction'],
            'verdict' => self::verdict($graded['fraction'], $anyre, $anymle, $anytle),
            'compileoutput' => '',
            'message' => '',
            'testresults' => $testresults,
        ];
    }

    /**
     * The overall verdict by precedence: RE > MLE > TLE > WA > AC. A compile
     * error is handled separately as a whole-submission failure.
     *
     * @param float $fraction the graded fraction
     * @param bool $anyre any runtime error
     * @param bool $anymle any memory-limit-exceeded
     * @param bool $anytle any time-limit-exceeded
     * @return string AC | WA | TLE | MLE | RE
     */
    private static function verdict(float $fraction, bool $anyre, bool $anymle, bool $anytle): string {
        if ($anyre) {
            return 'RE';
        }
        if ($anymle) {
            return 'MLE';
        }
        if ($anytle) {
            return 'TLE';
        }
        return $fraction >= 1 ? 'AC' : 'WA';
    }

    /**
     * Per-test rows for a whole-submission failure (e.g. a compile error), all failed.
     *
     * @param array $tests the authored test cases
     * @param string $status the per-test status to record
     * @return array
     */
    private function blanket_fail(array $tests, string $status): array {
        $rows = [];
        foreach ($tests as $i => $test) {
            $rows[] = [
                'index' => $i,
                'status' => $status,
                'passed' => false,
                'visibility' => (int) $test->visibility,
                'time' => null,
                'memory' => null,
            ];
        }
        return $rows;
    }
}
