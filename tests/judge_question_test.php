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

    public function test_judge_expects_a_proctor_log_field(): void {
        $this->resetAfterTest();
        $question = $this->judge_question(new qtype_codeatelier_stub_runner(array()));
        $expected = $question->get_expected_data();
        $this->assertArrayHasKey('proctorlog', $expected);

        // A practice question carries no proctor log.
        $practice = test_question_maker::make_question('codeatelier', 'function');
        $this->assertArrayNotHasKey('proctorlog', $practice->get_expected_data());
    }

    public function test_proctor_log_does_not_affect_the_grade(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->judge_question($runner);
        list($fraction) = $question->grade_response(array(
            'answer' => 'correct',
            'proctorlog' => '[{"type":"focusaway","t":3}]',
        ));
        $this->assertEquals(1.0, $fraction);
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

        // A lying client score of 0 must not lower the server-computed grade.
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

        list($fraction, $state) = $question->grade_response(array('answer' => 'code'));
        $this->assertEquals(question_state::$needsgrading, $state);
    }

    public function test_results_are_memoised_across_grades(): void {
        $this->resetAfterTest();
        $runner = new qtype_codeatelier_stub_runner(array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        ));
        $question = $this->judge_question($runner);

        $question->grade_response(array('answer' => 'same code'));
        $question->grade_response(array('answer' => 'same code'));
        // The second grade is served from the memo, so the sandbox ran only once.
        $this->assertEquals(1, $runner->call_count());

        // The cached result is readable without re-running the sandbox (review path).
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
