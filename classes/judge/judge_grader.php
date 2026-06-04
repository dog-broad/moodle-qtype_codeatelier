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

        // Map each execution outcome into the grader's result shape: a timeout
        // and a runtime error are signalled by flags; a clean run is compared by
        // its stdout (the judge tier is the stdin/stdout shape).
        $graderresults = [];
        foreach ($runresults as $result) {
            if ($result['outcome'] === 'tle') {
                $graderresults[] = ['timedout' => true];
            } else if ($result['outcome'] === 're') {
                $graderresults[] = ['error' => true];
            } else {
                $graderresults[] = ['stdout' => $result['stdout']];
            }
        }

        $graded = grader::grade($graderresults, $tests);

        $testresults = [];
        foreach ($graded['testresults'] as $tr) {
            $i = $tr['index'];
            $testresults[] = [
                'index' => $i,
                'status' => self::display_status($tr['status']),
                'passed' => $tr['passed'],
                'visibility' => $tr['visibility'],
                'time' => $runresults[$i]['time'] ?? null,
                'memory' => $runresults[$i]['memory'] ?? null,
            ];
        }

        return [
            'infrastructure' => false,
            'fraction' => $graded['fraction'],
            // The grader is tier-neutral and reports TIMEOUT; the judge taxonomy calls it TLE.
            'verdict' => $graded['verdict'] === 'TIMEOUT' ? 'TLE' : $graded['verdict'],
            'compileoutput' => '',
            'message' => '',
            'testresults' => $testresults,
        ];
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

    /**
     * Translate a grader per-test status into the judge taxonomy used for display.
     *
     * @param string $status pass | fail | error | timeout
     * @return string pass | fail | re | tle
     */
    private static function display_status(string $status): string {
        if ($status === 'error') {
            return 're';
        }
        if ($status === 'timeout') {
            return 'tle';
        }
        return $status;
    }
}
