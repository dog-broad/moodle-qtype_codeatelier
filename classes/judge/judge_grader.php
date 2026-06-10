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
     * Create the grader with the sandbox runner it executes submissions through.
     *
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
     * @param bool $allornothing when true, a partial pass scores 0
     * @param string $scenario the scenario: stdinstdout, function, sql or project
     * @param string $entrypoint the function name to call (function scenario)
     * @param string $signature the typed signature (static-language function scenario)
     * @param string $entryfile the entry/main file name (project scenario)
     * @param string $checkersource a custom-checker program (stdin/stdout scenario); empty uses the matching rule
     * @param string $checkerlang the checker's language key
     * @return array {
     *     infrastructure: bool — true if the sandbox could not be reached (caller should mark needs-grading),
     *     fraction: float, verdict: string (AC|WA|TLE|RE|CE|INTERNAL),
     *     compileoutput: string, message: string,
     *     testresults: array of {index, status (pass|fail|re|tle|ce), passed, visibility, time, memory}
     * }
     */
    public function grade(
        string $code,
        string $language,
        array $tests,
        string $schemasql = '',
        bool $allornothing = false,
        string $scenario = 'stdinstdout',
        string $entrypoint = '',
        string $signature = '',
        string $entryfile = '',
        string $checkersource = '',
        string $checkerlang = ''
    ): array {
        $tests = array_values($tests);
        $issql = ($language === 'sql');
        $isfunction = ($scenario === 'function');
        // A project is a multi-file source set graded like stdin/stdout (run the
        // program, compare stdout); only the run path differs — see below.
        $isproject = ($scenario === 'project');
        // Static-language functions carry a typed signature that drives a generated
        // harness and a typed stdin/stdout protocol; dynamic ones pass JSON directly.
        $isstaticfn = $isfunction && function_harness::needs_signature($language);
        $sig = $isstaticfn ? signature::parse($signature) : null;
        // The function harness wraps the student code once; only the per-case args vary.
        $functionsource = $isfunction ? function_harness::source($language, $code, $entrypoint, $sig) : '';
        $cases = [];
        foreach ($tests as $test) {
            // SQL varies the whole source per test (schema + query + checker); the
            // function scenario shares one harness and feeds the args on stdin (JSON
            // for dynamic languages, the typed line protocol for static ones).
            if ($issql) {
                $cases[] = ['source' => self::sql_source($schemasql, $code, $test)];
            } else if ($isfunction) {
                $input = $isstaticfn
                    ? $sig->encode_args(self::decode_args($test->input))
                    : (string) $test->input;
                $cases[] = ['source' => $functionsource, 'input' => $input];
            } else {
                $cases[] = ['input' => $test->input];
            }
        }

        if ($isproject) {
            // Package the project's files once (sources + a generated compile/run
            // script) and run that package against each case's stdin via Judge0's
            // multi-file language; the per-case stdout is compared like any program.
            $zip = project_packager::package(
                $language,
                project_packager::decode_files($code),
                $entryfile
            );
            $runresults = $this->runner->run_multifile($zip, $cases);
        } else {
            $runresults = $this->runner->run($code, $language, $cases);
        }

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
            } else if ($isfunction) {
                // Dynamic: the harness prints JSON; feed the string and let the
                // structured matcher decode it. Static: decode the typed protocol
                // into the native return value the matcher compares.
                $graderresults[] = ['returnvalue' => $isstaticfn
                    ? $sig->decode_return($result['stdout'])
                    : $result['stdout']];
            } else {
                $graderresults[] = ['stdout' => $result['stdout']];
            }
        }

        // Custom checker (special judge): for the stdin/stdout scenario a teacher
        // checker decides AC/WA per test instead of the declarative matching rule
        // (problems with many valid answers, float tolerance, any order). It runs
        // only on tests whose student program completed — a CE/TLE/RE is already a
        // failure — and each completed test's pass becomes an explicit decision fed
        // to the SHARED grader, so weights / all-or-nothing / subtasks aggregate
        // identically. A checker that cannot deliver a verdict (it failed to run, or
        // emitted no AC/WA token) is a JUDGE failure, never a student WA: the whole
        // submission is routed to needs-grading rather than scored from a broken
        // checker (validate-on-save is the primary guard against that ever shipping).
        $usedchecker = ($scenario === 'stdinstdout') && trim($checkersource) !== '' && $checkerlang !== '';
        if ($usedchecker) {
            $checkercases = [];
            $checkerindexes = [];
            foreach ($runresults as $i => $result) {
                if ($result['outcome'] === 'ran') {
                    $checkerindexes[] = $i;
                    $checkercases[] = [
                        'input' => (string) $tests[$i]->input,
                        'expected' => (string) $tests[$i]->expected,
                        'output' => (string) $result['stdout'],
                    ];
                }
            }
            if ($checkercases) {
                $checkerresults = $this->runner->run_checker($checkerlang, $checkersource, $checkercases);
                foreach ($checkerindexes as $k => $i) {
                    $cr = $checkerresults[$k] ?? null;
                    $verdict = self::checker_verdict($cr);
                    if ($verdict === null) {
                        return [
                            'infrastructure' => true,
                            'fraction' => 0.0,
                            'verdict' => 'INTERNAL',
                            'compileoutput' => is_array($cr) ? (string) ($cr['compileoutput'] ?? '') : '',
                            'message' => self::checker_failure_message($cr),
                            'testresults' => [],
                        ];
                    }
                    $graderresults[$i] = ['passed' => $verdict];
                }
            }
        }

        $graded = grader::grade($graderresults, $tests, ['allOrNothing' => $allornothing]);

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
                // The subtask group id (from the shared grader) so review can show grouping.
                'subtask' => $tr['subtask'] ?? 0,
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
            'checker' => $usedchecker,
            'testresults' => $testresults,
        ];
    }

    /**
     * Run code against every test and return the PRODUCED OUTPUT of each — the
     * teacher-only "run-to-capture" primitive that fills Expected fields from a
     * reference solution. Unlike {@see grade()} there is no comparison and no
     * visibility filtering: the author sees every test's output (visible and
     * hidden) and it never touches the grade memo. The output is the same shape
     * the grader compares — stdout for text scenarios, the parsed result rows as
     * JSON for SQL result-set tests.
     *
     * @param string $code the reference solution
     * @param string $language a judge-tier language key
     * @param array $tests the authored test cases
     * @param string $schemasql the SQL scenario's schema + seed
     * @param string $scenario the scenario: stdinstdout, function, sql or project
     * @param string $entrypoint the function name to call (function scenario)
     * @param string $signature the typed signature (static-language function scenario)
     * @param string $entryfile the entry/main file name (project scenario)
     * @return array per-test [{index, output, ok, error}], aligned with the tests
     */
    public function run_outputs(
        string $code,
        string $language,
        array $tests,
        string $schemasql = '',
        string $scenario = 'stdinstdout',
        string $entrypoint = '',
        string $signature = '',
        string $entryfile = ''
    ): array {
        $tests = array_values($tests);
        $issql = ($language === 'sql');
        $isfunction = ($scenario === 'function');
        $isproject = ($scenario === 'project');
        $isstaticfn = $isfunction && function_harness::needs_signature($language);
        $sig = $isstaticfn ? signature::parse($signature) : null;
        $functionsource = $isfunction ? function_harness::source($language, $code, $entrypoint, $sig) : '';
        $cases = [];
        foreach ($tests as $test) {
            if ($issql) {
                $cases[] = ['source' => self::sql_source($schemasql, $code, $test)];
            } else if ($isfunction) {
                $input = $isstaticfn
                    ? $sig->encode_args(self::decode_args($test->input))
                    : (string) $test->input;
                $cases[] = ['source' => $functionsource, 'input' => $input];
            } else {
                $cases[] = ['input' => $test->input];
            }
        }

        if ($isproject) {
            $zip = project_packager::package(
                $language,
                project_packager::decode_files($code),
                $entryfile
            );
            $runresults = $this->runner->run_multifile($zip, $cases);
        } else {
            $runresults = $this->runner->run($code, $language, $cases);
        }

        $outputs = [];
        foreach ($runresults as $i => $result) {
            $outcome = $result['outcome'];
            if ($outcome === 'ran') {
                if ($issql && self::is_resultset_mode($tests[$i]->matchmode)) {
                    $output = json_encode(self::parse_csv_rows($result['stdout']));
                } else if ($isstaticfn) {
                    // Capture the canonical JSON of the typed return for the Expected field.
                    $output = json_encode($sig->decode_return($result['stdout']));
                } else {
                    $output = (string) $result['stdout'];
                }
                $outputs[] = ['index' => $i, 'output' => $output, 'ok' => true, 'error' => ''];
            } else {
                // A compile error, timeout, runtime error or unreachable sandbox
                // yields no usable output; surface why so the author can fix it.
                $error = $outcome === 'ce' ? (string) $result['compileoutput']
                    : (trim((string) ($result['stderr'] ?? '')) !== '' ? (string) $result['stderr']
                        : (string) ($result['statusdesc'] ?? $outcome));
                $outputs[] = ['index' => $i, 'output' => '', 'ok' => false,
                    'error' => $error !== '' ? $error : $outcome];
            }
        }
        return $outputs;
    }

    /**
     * Run the code once against a student-supplied input — the judge-tier "run with
     * my own input" scratch console. Formative: no grade, no tests, no hidden data.
     * The input is stdin (stdin/stdout scenario) or a JSON argument array (function).
     *
     * @param string $code the student submission
     * @param string $language a judge-tier language key
     * @param string $scenario the scenario (stdinstdout or function; SQL has no scratch)
     * @param string $entrypoint the function name (function scenario)
     * @param string $signature the typed signature (static-language function)
     * @param string $schemasql the SQL schema + seed (unused for scratch)
     * @param string $input the student's own input
     * @param string $entryfile the entry/main file name (project scenario)
     * @return array {infrastructure, verdict, stdout, stderr, compileoutput}
     */
    public function run_scratch(
        string $code,
        string $language,
        string $scenario,
        string $entrypoint,
        string $signature,
        string $schemasql,
        string $input,
        string $entryfile = ''
    ): array {
        $isfunction = ($scenario === 'function');
        $isproject = ($scenario === 'project');
        $isstaticfn = $isfunction && function_harness::needs_signature($language);
        $sig = $isstaticfn ? signature::parse($signature) : null;
        if ($isfunction) {
            $src = function_harness::source($language, $code, $entrypoint, $sig);
            $stdin = $isstaticfn ? $sig->encode_args(self::decode_args($input)) : $input;
            $case = ['source' => $src, 'input' => $stdin];
        } else {
            $case = ['input' => $input];
        }

        if ($isproject) {
            $zip = project_packager::package(
                $language,
                project_packager::decode_files($code),
                $entryfile
            );
            $results = $this->runner->run_multifile($zip, [$case]);
        } else {
            $results = $this->runner->run($code, $language, [$case]);
        }
        $r = $results[0] ?? null;
        if ($r === null || $r['outcome'] === 'internal') {
            return ['infrastructure' => true, 'verdict' => 'INTERNAL',
                'stdout' => '', 'stderr' => '', 'compileoutput' => ''];
        }
        $outcome = $r['outcome'];
        $stdout = (string) $r['stdout'];
        if ($outcome === 'ran' && $isstaticfn) {
            $stdout = (string) json_encode($sig->decode_return($r['stdout']));
        }
        $verdicts = ['ce' => 'CE', 'tle' => 'TLE', 'mle' => 'MLE', 're' => 'RE', 'ran' => 'OK'];
        return [
            'infrastructure' => false,
            'verdict' => $verdicts[$outcome] ?? 'OK',
            'stdout' => $stdout,
            'stderr' => (string) $r['stderr'],
            'compileoutput' => (string) $r['compileoutput'],
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
     * Interpret a checker run into a per-test verdict: true (AC/OK), false (WA), or
     * null when the checker did not run cleanly or printed no recognized verdict
     * token — a judge failure, deliberately distinct from a student WA so a broken
     * checker never silently grades a student wrong.
     *
     * @param array|null $result a single run_checker per-case result
     * @return bool|null accept | reject | judge-failure
     */
    private static function checker_verdict($result): ?bool {
        if (!is_array($result) || ($result['outcome'] ?? '') !== 'ran') {
            return null;
        }
        // The verdict is the first whitespace-delimited token on stdout.
        $token = strtok(trim((string) ($result['stdout'] ?? '')), " \t\r\n");
        $token = $token === false ? '' : strtoupper($token);
        if ($token === 'AC' || $token === 'OK') {
            return true;
        }
        if ($token === 'WA' || $token === 'WRONG') {
            return false;
        }
        return null;
    }

    /**
     * A human-readable reason a checker failed to deliver a verdict, for the
     * needs-grading message a teacher sees.
     *
     * @param array|null $result a single run_checker per-case result
     * @return string
     */
    private static function checker_failure_message($result): string {
        if (!is_array($result)) {
            return 'the custom checker produced no result';
        }
        $outcome = $result['outcome'] ?? '';
        if ($outcome === 'ce') {
            return 'the custom checker failed to compile';
        }
        if ($outcome === 'internal') {
            return (string) ($result['statusdesc'] ?? 'the custom checker could not be run');
        }
        if ($outcome !== 'ran') {
            return 'the custom checker did not run to completion (' . (string) $outcome . ')';
        }
        return 'the custom checker did not print an AC or WA verdict';
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
     * Decode a function test's authored input (a JSON array of arguments) into a
     * PHP array of argument values. A bare non-array value is a single argument.
     *
     * @param string $input the authored input
     * @return array the arguments
     */
    private static function decode_args(string $input): array {
        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }
        return ($decoded === null && trim($input) === '') ? [] : [$decoded];
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
        // pipe-separated list mode. Judge0's SQLite rejects '.mode json'. Each SQL
        // fragment is statement-terminated so a non-terminated student query does
        // not merge with the checker into one malformed statement.
        $parts = ['.mode csv', '.headers off'];
        if (trim($schemasql) !== '') {
            $parts[] = self::terminate($schemasql);
        }
        $parts[] = self::terminate($answer);
        $checker = isset($test->checkerquery) ? (string) $test->checkerquery : '';
        if (trim($checker) !== '') {
            $parts[] = self::terminate($checker);
        }
        return implode("\n", $parts) . "\n";
    }

    /**
     * Ensure a SQL fragment ends with a statement terminator, putting the semicolon
     * on its own line so a trailing line comment cannot swallow it.
     *
     * @param string $sql the fragment
     * @return string
     */
    private static function terminate(string $sql): string {
        $sql = rtrim($sql);
        if ($sql === '' || substr($sql, -1) === ';') {
            return $sql;
        }
        return $sql . "\n;";
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
