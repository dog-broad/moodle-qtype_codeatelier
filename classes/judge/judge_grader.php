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
     * @param string $code the student submission (a complete stdin/stdout program, or a SQL query)
     * @param string $language a judge-tier language key (c, cpp, java, python, javascript, sql)
     * @param array $tests the authored test cases (objects with input/expected/matchmode/tolerance/weight/visibility)
     * @param string $schemasql the SQL scenario's schema + seed, prepended to each SQL submission
     * @return array {
     *     infrastructure: bool — true if the sandbox could not be reached (caller should mark needs-grading),
     *     fraction: float, verdict: string (AC|WA|TLE|RE|CE|INTERNAL),
     *     compileoutput: string, message: string,
     *     testresults: array of {index, status (pass|fail|re|tle|ce), passed, visibility, time, memory}
     * }
     */
    public function grade(string $code, string $language, array $tests, string $schemasql = ''): array {
        $tests = array_values($tests);
        $issql = ($language === 'sql');
        $cases = [];
        foreach ($tests as $test) {
            // SQL varies the whole source per test (schema + query + checker); other
            // languages feed the case input on stdin against one shared program.
            $cases[] = $issql
                ? ['source' => self::sql_source($schemasql, $code, $test)]
                : ['input' => $test->input];
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
        // stdout, or — for a SQL result-set test — by the rows parsed from Judge0's
        // CSV output, the same {rows} shape the in-browser sql.js tier produces.
        $graderresults = [];
        foreach ($runresults as $i => $result) {
            if ($result['outcome'] === 'tle') {
                $graderresults[] = ['timedout' => true];
            } else if ($result['outcome'] === 're' || $result['outcome'] === 'mle') {
                $graderresults[] = ['error' => true];
            } else if ($issql && self::is_resultset_mode($tests[$i]->matchmode)) {
                $graderresults[] = ['rows' => self::parse_csv_rows($result['stdout'])];
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
                if ($outcome === 'ran' && $issql && self::is_resultset_mode($tests[$i]->matchmode)) {
                    // Show the parsed rows as JSON so the diff matches the JSON expected.
                    $row['actual'] = json_encode(self::parse_csv_rows($run['stdout']));
                } else {
                    $row['actual'] = $outcome === 'ran' ? (string) $run['stdout'] : '';
                }
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

    /**
     * Whether a match mode compares SQL result-set rows.
     *
     * @param string $mode the test's match mode
     * @return bool
     */
    private static function is_resultset_mode(string $mode): bool {
        return $mode === 'resultset' || $mode === 'resultset_ordered';
    }

    /**
     * Build the Judge0 SQLite source for one SQL test: pin the output format, run
     * the schema + seed, then the student's query — or, for a mutation, the
     * student's statements followed by the test's checker query (whose SELECT
     * produces the rows we compare). The student answer should be a non-SELECT
     * when a checker query is present, so only the checker's rows reach stdout.
     *
     * @param string $schemasql the question's schema + seed
     * @param string $answer the student's SQL
     * @param object $test the test case (may carry a checkerquery)
     * @return string the SQL script submitted to Judge0
     */
    private static function sql_source(string $schemasql, string $answer, $test): string {
        // CSV output quotes embedded separators/quotes/newlines and distinguishes
        // NULL (empty field) from '' (a quoted empty string) — unlike the default
        // pipe-separated list mode. Judge0's SQLite rejects '.mode json'.
        $parts = ['.mode csv', '.headers off'];
        if (trim($schemasql) !== '') {
            $parts[] = $schemasql;
        }
        $parts[] = $answer;
        $checker = isset($test->checkerquery) ? (string) $test->checkerquery : '';
        if (trim($checker) !== '') {
            $parts[] = $checker;
        }
        return implode("\n", $parts) . "\n";
    }

    /**
     * Parse Judge0 SQLite CSV output into rows of typed cells, mirroring the typed
     * {rows} the in-browser sql.js tier returns so a given expected JSON grades the
     * same on both tiers: an empty field is NULL, an integer/decimal is a number,
     * anything else is a string. (Known limit: a value indistinguishable from a
     * number, or an embedded newline inside a quoted cell, is ambiguous — resolved
     * by run-to-capture authoring the expected against real output.)
     *
     * @param string $stdout the Judge0 stdout (CSV, headers off)
     * @return array the rows, each an array of cell values (int|float|string|null)
     */
    public static function parse_csv_rows(string $stdout): array {
        $stdout = str_replace("\r\n", "\n", $stdout);
        // Empty output is an empty result set (no rows) — checked before stripping
        // the trailing newline, so a single empty row ("\n") is not lost.
        if ($stdout === '') {
            return [];
        }
        // Drop exactly one trailing newline (the row terminator), so the normal
        // trailing newline does not add a phantom empty row.
        if (substr($stdout, -1) === "\n") {
            $stdout = substr($stdout, 0, -1);
        }
        $rows = [];
        foreach (explode("\n", $stdout) as $line) {
            // Empty escape -> RFC-4180 parsing (no backslash escaping).
            $cells = str_getcsv($line, ',', '"', '');
            $rows[] = array_map([self::class, 'coerce_cell'], $cells);
        }
        return $rows;
    }

    /**
     * Coerce one CSV cell to the typed value the grader compares: NULL, an integer,
     * a float, or the raw string.
     *
     * @param string|null $cell the raw cell text
     * @return int|float|string|null
     */
    private static function coerce_cell($cell) {
        if ($cell === null || $cell === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $cell)) {
            return (int) $cell;
        }
        if (is_numeric($cell)) {
            return (float) $cell;
        }
        return $cell;
    }
}
