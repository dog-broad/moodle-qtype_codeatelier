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

use question_state;
use test_question_maker;
use qtype_codeatelier_stub_runner;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_runner.php');

/**
 * Tests for authoritative judge-tier grading on the question's grade_response path.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier_question
 */
final class judge_question_test extends \advanced_testcase {

    /**
     * A judge-tier question with the given runner injected.
     *
     * @param qtype_codeatelier_stub_runner $runner
     * @return \qtype_codeatelier_question
     */
    private function judge_question(qtype_codeatelier_stub_runner $runner) {
        $question = test_question_maker::make_question('codeatelier', 'judge');
        $question->judgerunner = $runner;
        return $question;
    }

    /**
     * A multi-language judge question (python primary; c and cpp also allowed).
     *
     * @param qtype_codeatelier_stub_runner $runner
     * @return \qtype_codeatelier_question
     */
    private function multilang_question(qtype_codeatelier_stub_runner $runner) {
        $question = test_question_maker::make_question('codeatelier', 'multilang');
        $question->judgerunner = $runner;
        return $question;
    }

    public function test_grades_in_the_chosen_language(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->multilang_question($runner);

        // The student chose C: the runner must be asked to run C.
        $question->get_judge_result('code', 'c');
        $this->assertSame('c', $runner->last_language());
    }

    public function test_tampered_language_falls_back_to_primary(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->multilang_question($runner);

        // A language outside the allowed set can never run off-tier: it resolves
        // to the primary language (python).
        $question->get_judge_result('code', 'ruby');
        $this->assertSame('python', $runner->last_language());
    }

    public function test_different_languages_are_separate_memo_buckets(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->multilang_question($runner);

        // Same code, two languages: each computes (and caches) independently.
        $question->get_judge_result('same code', 'python');
        $question->get_judge_result('same code', 'c');
        $this->assertEquals(2, $runner->call_count());
        // The python bucket is warm; re-reading it does not run the sandbox again.
        $this->assertNotNull($question->cached_judge_result('same code', 'python'));
        $this->assertEquals(2, $runner->call_count());
    }

    public function test_judge_sql_grades_authoritatively_via_rows(): void {
        $this->resetAfterTest();
        // Judge0 SQLite CSV: visible test returns rows in order, hidden out of order.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "1,amy\n2,bo\n"),
            qtype_codeatelier_stub_runner::row('ran', "2,bo\n1,amy\n"),
        ));
        $question = test_question_maker::make_question('codeatelier', 'judge_sql');
        $question->judgerunner = $runner;

        // Warm the memo (as the scheduled task does), then grade the submission.
        $question->get_judge_result('SELECT id, name FROM users ORDER BY id');
        list($fraction, $state) = $question->grade_response(
                array('answer' => 'SELECT id, name FROM users ORDER BY id'));
        $this->assertEquals(1.0, $fraction);
        $this->assertEquals(question_state::$gradedright, $state);
        // The runner was asked to run SQL (the language resolved to sql).
        $this->assertSame('sql', $runner->last_language());
    }

    public function test_grade_response_reads_the_chosen_language(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->multilang_question($runner);

        // Warm the C bucket the way the scheduled task does, then grade the
        // response that carries language=c: it must hit that bucket (graded right).
        $question->get_judge_result('code', 'c');
        list($fraction, $state) = $question->grade_response(
                array('answer' => 'code', 'language' => 'c'));
        $this->assertEquals(1.0, $fraction);
        $this->assertEquals(question_state::$gradedright, $state);

        // A response with no warmed bucket for its language stays needs-grading.
        list(, $state) = $question->grade_response(
                array('answer' => 'code', 'language' => 'python'));
        $this->assertEquals(question_state::$needsgrading, $state);
    }

    public function test_proctor_log_is_not_a_response_var(): void {
        $this->resetAfterTest();
        // Proctor events are recorded server-side (an append-only table), never carried
        // as a response variable the browser could forge or clear.
        $question = $this->judge_question(new qtype_codeatelier_stub_runner(array()));
        $this->assertArrayNotHasKey('proctorlog', $question->get_expected_data());
        $practice = test_question_maker::make_question('codeatelier', 'function');
        $this->assertArrayNotHasKey('proctorlog', $practice->get_expected_data());
    }

    public function test_proctor_events_are_decoupled_from_grading(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->judge_question($runner);
        $question->get_judge_result('correct');
        list($fraction) = $question->grade_response(array('answer' => 'correct'));
        $this->assertEquals(1.0, $fraction);
    }

    public function test_run_examples_runs_visible_tests_only(): void {
        $this->resetAfterTest();
        // The judge fixture has one visible and one hidden test. A formative run
        // must execute only the visible one, so the stub returns a single result.
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
        ));
        $question = $this->judge_question($runner);

        $result = $question->run_examples('some code');
        $this->assertCount(1, $result['testresults']);
        $this->assertSame(1, $result['testresults'][0]['visibility']);
        // A visible example carries its diagnostics for display.
        $this->assertArrayHasKey('expected', $result['testresults'][0]);
        $this->assertTrue($result['testresults'][0]['passed']);
    }

    public function test_is_complete_response_uses_code_not_score(): void {
        $this->resetAfterTest();
        $question = $this->judge_question(new qtype_codeatelier_stub_runner(array()));
        // No client score is needed on the judge tier; non-empty code is gradable.
        $this->assertFalse($question->is_complete_response(array('answer' => '')));
        $this->assertTrue($question->is_complete_response(array('answer' => 'print(5)')));
        $this->assertTrue($question->is_complete_response(array('answer' => 'x', 'score' => '')));
    }

    public function test_grade_response_is_authoritative_and_ignores_client_score(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->judge_question($runner);

        // Grading is off-request: the first grade leaves the response awaiting grading.
        list(, $state) = $question->grade_response(array('answer' => 'correct', 'score' => '0'));
        $this->assertEquals(question_state::$needsgrading, $state);

        // The scheduled task computes and memoises the result; the regrade then
        // records the real, server-computed grade (a lying client score of 0 is ignored).
        $question->get_judge_result('correct');
        list($fraction, $state) = $question->grade_response(array('answer' => 'correct', 'score' => '0'));
        $this->assertEquals(1.0, $fraction);
        $this->assertEquals(question_state::$gradedright, $state);
    }

    public function test_grade_response_partial_credit(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "wrong\n"),
        ));
        $question = $this->judge_question($runner);

        $question->get_judge_result('half');
        list($fraction, $state) = $question->grade_response(array('answer' => 'half'));
        $this->assertEquals(0.5, $fraction);
        $this->assertEquals(question_state::$gradedpartial, $state);
    }

    public function test_infrastructure_failure_needs_grading_not_zero(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('internal', '', array('statusdesc' => 'unreachable')),
            qtype_codeatelier_stub_runner::row('internal'),
        ));
        $question = $this->judge_question($runner);

        // The task runs the sandbox but it is unreachable, so nothing is memoised...
        $result = $question->get_judge_result('code');
        $this->assertTrue($result['infrastructure']);
        // ...and the response stays awaiting grading rather than becoming a faked zero.
        list(, $state) = $question->grade_response(array('answer' => 'code'));
        $this->assertEquals(question_state::$needsgrading, $state);
    }

    public function test_results_are_memoised_across_grades(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->judge_question($runner);

        $question->get_judge_result('same code');
        $question->get_judge_result('same code');
        // The second run is served from the memo, so the sandbox ran only once.
        $this->assertEquals(1, $runner->call_count());

        // The cached result is readable without re-running the sandbox (review + grade paths).
        $cached = $question->cached_judge_result('same code');
        $this->assertNotNull($cached);
        $this->assertSame('AC', $cached['verdict']);
    }

    public function test_no_endpoint_configured_needs_grading(): void {
        $this->resetAfterTest();
        // No injected runner and no configured endpoint -> cannot grade -> needs grading.
        $question = test_question_maker::make_question('codeatelier', 'judge');
        set_config('judge0baseurl', '', 'qtype_codeatelier');

        list($fraction, $state) = $question->grade_response(array('answer' => 'code'));
        $this->assertEquals(question_state::$needsgrading, $state);
    }
}
