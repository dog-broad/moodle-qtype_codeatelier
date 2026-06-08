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

use qtype_codeatelier\judge\judge_grader;
use qtype_codeatelier\judge\result_cache;
use qtype_codeatelier_stub_runner;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_runner.php');

/**
 * Tests for the authoritative judge-tier grading orchestration.
 *
 * Pure logic against a scripted runner: no network, no Judge0. Verifies that
 * execution outcomes are lifted into the AC/WA/TLE/RE/CE taxonomy and that the
 * matching spec decides correctness.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\judge\judge_grader
 */
final class judge_grader_test extends \advanced_testcase {

    /**
     * Two trimmed-match tests: visible "5", hidden "30".
     *
     * @return array
     */
    private function sum_tests(): array {
        return array(
            (object) array('input' => "2 3\n", 'expected' => '5', 'matchmode' => 'trimmed',
                'tolerance' => null, 'weight' => 1, 'visibility' => 1),
            (object) array('input' => "10 20\n", 'expected' => '30', 'matchmode' => 'trimmed',
                'tolerance' => null, 'weight' => 1, 'visibility' => 0),
        );
    }

    public function test_cache_key_depends_on_limits(): void {
        // Changing a per-question limit must change the memo key, so an answer is
        // re-graded under the new limit rather than served a stale result.
        $tests = $this->sum_tests();
        $a = result_cache::key('cpp', 'code', $tests, array('cputime' => 2.0, 'memory' => 128000));
        $b = result_cache::key('cpp', 'code', $tests, array('cputime' => 1.0, 'memory' => 128000));
        $c = result_cache::key('cpp', 'code', $tests, array('cputime' => 2.0, 'memory' => 64000));
        $this->assertNotEquals($a, $b);
        $this->assertNotEquals($a, $c);
    }

    public function test_cache_key_depends_on_entryfile(): void {
        // The entry file drives the generated run script, so two projects that differ
        // only in their entry file must not share a memo entry.
        $tests = $this->sum_tests();
        $limits = array('cputime' => 2.0, 'memory' => 128000);
        $a = result_cache::key('cpp', 'code', $tests, $limits, '', false, '', 'project', '', '', 'main.cpp');
        $b = result_cache::key('cpp', 'code', $tests, $limits, '', false, '', 'project', '', '', 'app.cpp');
        $this->assertNotEquals($a, $b);
    }

    /**
     * A canonical two-file C++ project as the student answer.
     *
     * @return string the canonical project JSON
     */
    private function project_answer(): string {
        return json_encode(array(
            array('name' => 'main.cpp', 'content' => "#include \"h.h\"\nint main(){}\n"),
            array('name' => 'h.h', 'content' => "int add(int,int);\n"),
        ));
    }

    public function test_project_scenario_grades_stdout_via_multifile(): void {
        // A project routes through run_multifile (not run) and is graded on stdout.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade($this->project_answer(), 'cpp',
                $this->sum_tests(), '', false, 'project', '', '', 'main.cpp');

        $this->assertFalse($result['infrastructure']);
        $this->assertEquals(1.0, $result['fraction']);
        $this->assertSame('AC', $result['verdict']);
        // The project package (not a single source) reached the runner.
        $this->assertNotNull($runner->last_multifile_zip());
    }

    public function test_project_scenario_wrong_output_is_wa(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "999\n"),
        ));
        $result = (new judge_grader($runner))->grade($this->project_answer(), 'cpp',
                $this->sum_tests(), '', false, 'project', '', '', 'main.cpp');

        $this->assertEquals(0.5, $result['fraction']);
        $this->assertSame('WA', $result['verdict']);
    }

    public function test_run_outputs_project_captures_via_multifile(): void {
        // Run-to-capture for a project routes through run_multifile and returns each
        // test's produced stdout, for filling the Expected fields.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $outputs = (new judge_grader($runner))->run_outputs($this->project_answer(), 'cpp',
                $this->sum_tests(), '', 'project', '', '', 'main.cpp');

        $this->assertNotNull($runner->last_multifile_zip());
        $this->assertCount(2, $outputs);
        $this->assertTrue($outputs[0]['ok']);
        $this->assertSame("5\n", $outputs[0]['output']);
    }

    public function test_project_scenario_compile_error_fails_all(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'error: expected ;')),
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'error: expected ;')),
        ));
        $result = (new judge_grader($runner))->grade($this->project_answer(), 'cpp',
                $this->sum_tests(), '', false, 'project', '', '', 'main.cpp');

        $this->assertEquals(0.0, $result['fraction']);
        $this->assertSame('CE', $result['verdict']);
        $this->assertStringContainsString('expected ;', $result['compileoutput']);
    }

    public function test_all_correct_is_ac(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertFalse($result['infrastructure']);
        $this->assertEquals(1.0, $result['fraction']);
        $this->assertSame('AC', $result['verdict']);
        $this->assertCount(2, $result['testresults']);
        $this->assertTrue($result['testresults'][0]['passed']);
        $this->assertTrue($result['testresults'][1]['passed']);
    }

    /**
     * Two structured (function-return) tests: visible [2,3]->5, hidden [10,20]->30.
     *
     * @return array
     */
    private function function_tests(): array {
        return array(
            (object) array('input' => '[2, 3]', 'expected' => '5', 'matchmode' => 'structured',
                'tolerance' => null, 'weight' => 1, 'visibility' => 1),
            (object) array('input' => '[10, 20]', 'expected' => '30', 'matchmode' => 'structured',
                'tolerance' => null, 'weight' => 1, 'visibility' => 0),
        );
    }

    public function test_function_scenario_grades_by_return_value(): void {
        // The harness prints the JSON return on stdout; the grader must compare it as
        // the return value under the structured matcher, so "5" == 5.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', '5'),
            qtype_codeatelier_stub_runner::row('ran', '30'),
        ));
        $result = (new judge_grader($runner))->grade('def add(a, b): return a + b', 'python',
                $this->function_tests(), '', false, 'function', 'add');

        $this->assertEquals(1.0, $result['fraction']);
        $this->assertSame('AC', $result['verdict']);
    }

    public function test_function_scenario_structured_deep_equality(): void {
        // A JSON-array return compares by deep equality (order-sensitive), and the
        // cross-language number invariant holds (int 3 vs float 3.0).
        $tests = array(
            (object) array('input' => '[[3, 1, 2]]', 'expected' => '[1, 2, 3]', 'matchmode' => 'structured',
                'tolerance' => null, 'weight' => 1, 'visibility' => 1),
        );
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', '[1.0, 2, 3]'),
        ));
        $result = (new judge_grader($runner))->grade('code', 'javascript', $tests, '', false, 'function', 'sortit');

        $this->assertEquals(1.0, $result['fraction']);
        $this->assertSame('AC', $result['verdict']);
    }

    public function test_function_scenario_wrong_return_is_wa(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', '5'),
            qtype_codeatelier_stub_runner::row('ran', '999'),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->function_tests(),
                '', false, 'function', 'add');

        $this->assertEquals(0.5, $result['fraction']);
        $this->assertSame('WA', $result['verdict']);
    }

    public function test_cache_key_depends_on_scenario_and_entrypoint(): void {
        $tests = $this->function_tests();
        $limits = array('cputime' => 2.0, 'memory' => 128000);
        $base = result_cache::key('python', 'code', $tests, $limits, '', false, '', 'stdinstdout', '');
        $func = result_cache::key('python', 'code', $tests, $limits, '', false, '', 'function', 'add');
        $func2 = result_cache::key('python', 'code', $tests, $limits, '', false, '', 'function', 'other');
        $this->assertNotEquals($base, $func);
        $this->assertNotEquals($func, $func2);
    }

    public function test_one_wrong_is_partial_wa(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "999\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertEquals(0.5, $result['fraction']);
        $this->assertSame('WA', $result['verdict']);
        $this->assertTrue($result['testresults'][0]['passed']);
        $this->assertFalse($result['testresults'][1]['passed']);
        // Visibility is preserved so the renderer can keep hidden tests secret.
        $this->assertSame(1, $result['testresults'][0]['visibility']);
        $this->assertSame(0, $result['testresults'][1]['visibility']);
    }

    public function test_timeout_is_tle(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('tle'),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertSame('TLE', $result['verdict']);
        $this->assertSame('tle', $result['testresults'][1]['status']);
        $this->assertEquals(0.5, $result['fraction']);
    }

    public function test_memory_limit_exceeded_is_mle(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('mle', '', array('memory' => 128000)),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertSame('MLE', $result['verdict']);
        $this->assertSame('mle', $result['testresults'][1]['status']);
        $this->assertEquals(0.5, $result['fraction']);
    }

    public function test_runtime_error_precedes_mle_in_verdict(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('re'),
            qtype_codeatelier_stub_runner::row('mle'),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());
        $this->assertSame('RE', $result['verdict']);
    }

    public function test_visible_tests_carry_diagnostics_hidden_do_not(): void {
        // Visible test fails (wrong output); hidden test passes.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "999\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $visible = $result['testresults'][0];
        $hidden = $result['testresults'][1];
        // The visible failure carries input/expected/actual for the diff.
        $this->assertArrayHasKey('expected', $visible);
        $this->assertArrayHasKey('actual', $visible);
        $this->assertSame('5', $visible['expected']);
        $this->assertSame("999\n", $visible['actual']);
        // The hidden test never carries its input, expected or produced output.
        $this->assertArrayNotHasKey('expected', $hidden);
        $this->assertArrayNotHasKey('actual', $hidden);
        $this->assertArrayNotHasKey('input', $hidden);
    }

    public function test_runtime_error_is_re(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('re'),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertSame('RE', $result['verdict']);
        $this->assertSame('re', $result['testresults'][0]['status']);
    }

    public function test_compile_error_fails_whole_submission(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'error: expected ;')),
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'error: expected ;')),
        ));
        $result = (new judge_grader($runner))->grade('code', 'cpp', $this->sum_tests());

        $this->assertSame('CE', $result['verdict']);
        $this->assertEquals(0.0, $result['fraction']);
        $this->assertStringContainsString('expected ;', $result['compileoutput']);
        $this->assertFalse($result['testresults'][0]['passed']);
        $this->assertFalse($result['testresults'][1]['passed']);
    }

    public function test_infrastructure_failure_is_flagged(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('internal', '', array('statusdesc' => 'judge0 unreachable')),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());

        $this->assertTrue($result['infrastructure']);
        $this->assertSame('INTERNAL', $result['verdict']);
        $this->assertEquals(0.0, $result['fraction']);
    }

    public function test_cache_key_depends_on_schema_and_checker(): void {
        // The SQL source includes the schema and (per test) the checker query, so
        // editing either must change the memo key.
        $base = array((object) array('input' => '', 'expected' => '[[1]]', 'matchmode' => 'resultset',
            'tolerance' => null, 'weight' => 1, 'visibility' => 1, 'checkerquery' => null));
        $withchecker = array((object) array('input' => '', 'expected' => '[[1]]', 'matchmode' => 'resultset',
            'tolerance' => null, 'weight' => 1, 'visibility' => 1, 'checkerquery' => 'SELECT count(*) FROM t'));
        $limits = array('cputime' => 2.0, 'memory' => 128000);

        $a = result_cache::key('sql', 'SELECT 1', $base, $limits, 'CREATE TABLE t(a INT);');
        $b = result_cache::key('sql', 'SELECT 1', $base, $limits, 'CREATE TABLE t(a INT, b INT);');
        $c = result_cache::key('sql', 'SELECT 1', $withchecker, $limits, 'CREATE TABLE t(a INT);');
        $this->assertNotEquals($a, $b, 'schema change must change the key');
        $this->assertNotEquals($a, $c, 'checker-query change must change the key');
    }

    public function test_parse_csv_rows_types_cells(): void {
        $rows = judge_grader::parse_csv_rows("1,alice,5.5\n2,bob,\n3,\"a,b\",10\n");
        $this->assertSame(array(
            array(1, 'alice', 5.5),
            array(2, 'bob', null),
            array(3, 'a,b', 10),
        ), $rows);
        // An empty result set is no rows; a single all-NULL row is one row.
        $this->assertSame(array(), judge_grader::parse_csv_rows(''));
        $this->assertSame(array(array(null)), judge_grader::parse_csv_rows("\n"));
    }

    /**
     * One SQL result-set test with the given expected JSON and match mode.
     *
     * @param string $expected the expected rows as JSON
     * @param string $mode resultset | resultset_ordered
     * @param string|null $checker an optional checker query
     * @return array
     */
    private function sql_test(string $expected, string $mode, ?string $checker = null): array {
        return array((object) array('input' => '', 'expected' => $expected, 'matchmode' => $mode,
            'tolerance' => null, 'weight' => 1, 'visibility' => 1, 'checkerquery' => $checker));
    }

    public function test_sql_resultset_unordered_ac(): void {
        // Judge0 returns the rows in a different order; the unordered match passes.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "2,y\n1,x\n"),
        ));
        $result = (new judge_grader($runner))->grade('SELECT a, b FROM t', 'sql',
                $this->sql_test('[[1,"x"],[2,"y"]]', 'resultset'), 'CREATE TABLE t(a INT, b TEXT);');

        $this->assertSame('AC', $result['verdict']);
        $this->assertEquals(1.0, $result['fraction']);
        // The visible diagnostic shows the parsed rows as JSON (symmetric with expected).
        $this->assertSame('[[2,"y"],[1,"x"]]', $result['testresults'][0]['actual']);
    }

    public function test_sql_resultset_ordered_is_order_sensitive(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "2,y\n1,x\n"),
        ));
        $result = (new judge_grader($runner))->grade('SELECT a, b FROM t ORDER BY a', 'sql',
                $this->sql_test('[[1,"x"],[2,"y"]]', 'resultset_ordered'), 'CREATE TABLE t(a INT, b TEXT);');

        $this->assertSame('WA', $result['verdict']);
        $this->assertEquals(0.0, $result['fraction']);
    }

    public function test_sql_mutation_with_checker_ac(): void {
        // A mutation graded by its checker SELECT: Judge0 prints only the checker rows.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "11\n12\n"),
        ));
        $result = (new judge_grader($runner))->grade('UPDATE t SET a = a + 10', 'sql',
                $this->sql_test('[[11],[12]]', 'resultset_ordered', 'SELECT a FROM t ORDER BY a'),
                'CREATE TABLE t(a INT); INSERT INTO t VALUES (1),(2);');

        $this->assertSame('AC', $result['verdict']);
        $this->assertEquals(1.0, $result['fraction']);
        $this->assertSame('sql', $runner->last_language());
    }

    public function test_sql_syntax_error_is_re(): void {
        // A bad query surfaces as a Judge0 runtime error -> RE verdict.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('re', '', array('stderr' => 'Error: no such column: zzz')),
        ));
        $result = (new judge_grader($runner))->grade('SELECT zzz FROM t', 'sql',
                $this->sql_test('[[1]]', 'resultset'), 'CREATE TABLE t(a INT);');

        $this->assertSame('RE', $result['verdict']);
        $this->assertSame('re', $result['testresults'][0]['status']);
    }

    public function test_run_outputs_captures_every_test(): void {
        // Run-to-capture: the produced output of ALL tests is returned (visible AND
        // hidden), so the author can fill every Expected field.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $outputs = (new judge_grader($runner))->run_outputs('code', 'python', $this->sum_tests());

        $this->assertCount(2, $outputs);
        $this->assertTrue($outputs[0]['ok']);
        $this->assertSame("5\n", $outputs[0]['output']);
        $this->assertSame(0, $outputs[0]['index']);
        // The hidden test's output is captured too (no visibility filter here).
        $this->assertTrue($outputs[1]['ok']);
        $this->assertSame("30\n", $outputs[1]['output']);
    }

    public function test_run_outputs_sql_returns_parsed_rows_json(): void {
        // The captured value for a SQL result-set test is the parsed rows as JSON,
        // the same shape grading compares against.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "1,amy\n2,bo\n"),
        ));
        $outputs = (new judge_grader($runner))->run_outputs('SELECT id, name FROM users', 'sql',
                $this->sql_test('[]', 'resultset'), 'CREATE TABLE users(id INT, name TEXT);');

        $this->assertSame('[[1,"amy"],[2,"bo"]]', $outputs[0]['output']);
    }

    public function test_all_or_nothing_zeroes_a_partial(): void {
        // One test passes, one fails -> partial 0.5 normally, but 0 all-or-nothing.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "999\n"),
        ));
        $partial = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests());
        $this->assertEquals(0.5, $partial['fraction']);

        $aon = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests(), '', true);
        $this->assertEquals(0.0, $aon['fraction']);
        $this->assertSame('WA', $aon['verdict']);
    }

    public function test_all_or_nothing_keeps_full_marks_when_all_pass(): void {
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $aon = (new judge_grader($runner))->grade('code', 'python', $this->sum_tests(), '', true);
        $this->assertEquals(1.0, $aon['fraction']);
        $this->assertSame('AC', $aon['verdict']);
    }

    public function test_cache_key_depends_on_all_or_nothing(): void {
        $tests = $this->sum_tests();
        $limits = array('cputime' => 2.0, 'memory' => 128000);
        $partial = result_cache::key('python', 'code', $tests, $limits, '', false);
        $aon = result_cache::key('python', 'code', $tests, $limits, '', true);
        $this->assertNotEquals($partial, $aon);
    }

    public function test_cache_key_depends_on_data_files(): void {
        // Editing a support/data file changes its hash, so the memo is invalidated
        // and the answer re-grades against the new data.
        $tests = $this->sum_tests();
        $limits = array('cputime' => 2.0, 'memory' => 128000);
        $none = result_cache::key('python', 'code', $tests, $limits, '', false, '');
        $withfiles = result_cache::key('python', 'code', $tests, $limits, '', false, 'filehash-v1');
        $changed = result_cache::key('python', 'code', $tests, $limits, '', false, 'filehash-v2');
        $this->assertNotEquals($none, $withfiles);
        $this->assertNotEquals($withfiles, $changed);
    }

    public function test_run_outputs_reports_a_failing_reference(): void {
        // A reference that errors yields no output and surfaces why.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('re', '', array('stderr' => 'Traceback: boom')),
        ));
        $tests = array((object) array('input' => 'x', 'expected' => '', 'matchmode' => 'trimmed',
            'tolerance' => null, 'weight' => 1, 'visibility' => 1, 'checkerquery' => null));
        $outputs = (new judge_grader($runner))->run_outputs('code', 'python', $tests);

        $this->assertFalse($outputs[0]['ok']);
        $this->assertSame('', $outputs[0]['output']);
        $this->assertStringContainsString('boom', $outputs[0]['error']);
    }

    public function test_checker_verdict_overrides_the_matching_rule(): void {
        // The student output ("0 1" / "1 0") does NOT trimmed-match the expected
        // ("5" / "30"), but the custom checker accepts both — so the answer is AC.
        // This is the many-valid-answers case the checker exists for.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "0 1\n"),
            qtype_codeatelier_stub_runner::row('ran', "1 0\n"),
        ));
        $runner->set_checker_rows(array(
            qtype_codeatelier_stub_runner::row('ran', "AC\n"),
            qtype_codeatelier_stub_runner::row('ran', "AC\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'cpp', $this->sum_tests(),
                '', false, 'stdinstdout', '', '', '', 'print("AC")', 'python');

        $this->assertFalse($result['infrastructure']);
        $this->assertEquals(1.0, $result['fraction']);
        $this->assertSame('AC', $result['verdict']);
        $this->assertTrue($result['checker']);
        // The checker received (input, expected, output) for each completed test.
        $cases = $runner->last_checker_cases();
        $this->assertCount(2, $cases);
        $this->assertSame("2 3\n", $cases[0]['input']);
        $this->assertSame('5', $cases[0]['expected']);
        $this->assertSame("0 1\n", $cases[0]['output']);
    }

    public function test_checker_rejection_is_wa_with_partial_credit(): void {
        // The checker accepts the visible test but rejects the hidden one: weighted
        // partial credit (0.5) and a WA verdict, exactly as a matching-rule miss.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "ok\n"),
            qtype_codeatelier_stub_runner::row('ran', "nope\n"),
        ));
        $runner->set_checker_rows(array(
            qtype_codeatelier_stub_runner::row('ran', "AC\n"),
            qtype_codeatelier_stub_runner::row('ran', "WA reason\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'cpp', $this->sum_tests(),
                '', false, 'stdinstdout', '', '', '', 'checker', 'python');

        $this->assertEqualsWithDelta(0.5, $result['fraction'], 0.0001);
        $this->assertSame('WA', $result['verdict']);
    }

    public function test_checker_only_runs_on_completed_tests(): void {
        // The student program crashes on the hidden test; that is already a failure,
        // so the checker is consulted only for the completed (visible) test.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "out\n"),
            qtype_codeatelier_stub_runner::row('re', '', array('stderr' => 'boom')),
        ));
        $runner->set_checker_rows(array(
            qtype_codeatelier_stub_runner::row('ran', "AC\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'cpp', $this->sum_tests(),
                '', false, 'stdinstdout', '', '', '', 'checker', 'python');

        $this->assertCount(1, $runner->last_checker_cases());
        $this->assertEqualsWithDelta(0.5, $result['fraction'], 0.0001);
        // A runtime error still drives the overall verdict.
        $this->assertSame('RE', $result['verdict']);
    }

    public function test_broken_checker_is_needs_grading_not_a_wrong_answer(): void {
        // A checker that fails to run (compile error here) is a JUDGE failure, never
        // a student WA: the submission is routed to needs-grading with a message
        // that names the checker (so validate-on-save can block it).
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "out\n"),
            qtype_codeatelier_stub_runner::row('ran', "out\n"),
        ));
        $runner->set_checker_rows(array(
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'bad checker')),
            qtype_codeatelier_stub_runner::row('ce', '', array('compileoutput' => 'bad checker')),
        ));
        $result = (new judge_grader($runner))->grade('code', 'cpp', $this->sum_tests(),
                '', false, 'stdinstdout', '', '', '', 'not valid', 'python');

        $this->assertTrue($result['infrastructure']);
        $this->assertSame('INTERNAL', $result['verdict']);
        $this->assertStringContainsStringIgnoringCase('checker', $result['message']);
    }

    public function test_checker_with_no_verdict_token_is_needs_grading(): void {
        // A checker that ran but printed nothing recognizable did not speak the
        // protocol — a judge failure, not a silent WA.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "out\n"),
            qtype_codeatelier_stub_runner::row('ran', "out\n"),
        ));
        $runner->set_checker_rows(array(
            qtype_codeatelier_stub_runner::row('ran', "\n"),
            qtype_codeatelier_stub_runner::row('ran', "\n"),
        ));
        $result = (new judge_grader($runner))->grade('code', 'cpp', $this->sum_tests(),
                '', false, 'stdinstdout', '', '', '', 'checker', 'python');

        $this->assertTrue($result['infrastructure']);
        $this->assertSame('INTERNAL', $result['verdict']);
    }

    public function test_cache_key_depends_on_checker(): void {
        // A checker edit must change the memo key so answers re-grade under it.
        $tests = $this->sum_tests();
        $limits = array('cputime' => 2.0, 'memory' => 128000);
        $none = result_cache::key('cpp', 'code', $tests, $limits, '', false, '',
                'stdinstdout', '', '', '');
        $withchecker = result_cache::key('cpp', 'code', $tests, $limits, '', false, '',
                'stdinstdout', '', '', '', 'print("AC")', 'python');
        $changed = result_cache::key('cpp', 'code', $tests, $limits, '', false, '',
                'stdinstdout', '', '', '', 'print("WA")', 'python');
        $this->assertNotEquals($none, $withchecker);
        $this->assertNotEquals($withchecker, $changed);
    }
}
