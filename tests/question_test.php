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

/**
 * Runtime grading tests for the CodeAtelier question.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier_question
 */
final class question_test extends \advanced_testcase {
    /**
     * A sample CodeAtelier question for the tests.
     *
     * @return \qtype_codeatelier_question
     */
    protected function make_question() {
        return test_question_maker::make_question('codeatelier', 'function');
    }

    public function test_get_expected_data(): void {
        $question = $this->make_question();
        $this->assertEquals(
            ['answer' => PARAM_RAW, 'score' => PARAM_RAW_TRIMMED, 'language' => PARAM_ALPHANUMEXT],
            $question->get_expected_data()
        );
    }

    public function test_get_expected_data_judge_has_language_not_proctorlog(): void {
        $question = test_question_maker::make_question('codeatelier', 'judge');
        $expected = $question->get_expected_data();
        $this->assertArrayHasKey('language', $expected);
        // Proctor events are now a server-side log, not a forgeable response variable.
        $this->assertArrayNotHasKey('proctorlog', $expected);
    }

    public function test_get_allowed_languages_single_and_set(): void {
        $question = $this->make_question();
        // Empty languages column means single-language (the primary).
        $question->languages = null;
        $this->assertEquals(['javascript'], $question->get_allowed_languages());

        // A populated set always includes the primary, primary first, deduped.
        $question->languages = 'python,javascript,python';
        $this->assertEquals(['javascript', 'python'], $question->get_allowed_languages());
    }

    public function test_resolve_language_guards_the_choice(): void {
        $question = $this->make_question();
        $question->languages = 'javascript,python';

        // A language in the allowed set passes through.
        $this->assertEquals('python', $question->resolve_language('python'));
        // Anything else (tamper, blank, unknown) falls back to the primary.
        $this->assertEquals('javascript', $question->resolve_language('c'));
        $this->assertEquals('javascript', $question->resolve_language(''));
        $this->assertEquals('javascript', $question->resolve_language(null));
    }

    public function test_is_same_response_accounts_for_language(): void {
        $question = test_question_maker::make_question('codeatelier', 'multilang');

        $base = ['answer' => 'print(1)', 'score' => '', 'language' => 'python'];
        $samelang = ['answer' => 'print(1)', 'score' => '', 'language' => 'python'];
        $difflang = ['answer' => 'print(1)', 'score' => '', 'language' => 'c'];

        $this->assertTrue($question->is_same_response($base, $samelang));
        // Same code, different language is a different gradable response (it regrades).
        $this->assertFalse($question->is_same_response($base, $difflang));
    }

    public function test_is_complete_response(): void {
        $question = $this->make_question();
        $this->assertFalse($question->is_complete_response([]));
        $this->assertFalse($question->is_complete_response(['answer' => 'x', 'score' => '']));
        $this->assertFalse($question->is_complete_response(['answer' => 'x', 'score' => 'abc']));
        $this->assertTrue($question->is_complete_response(['answer' => 'x', 'score' => '0.5']));
    }

    public function test_grade_response_reads_and_clamps_score(): void {
        $question = $this->make_question();

        [$fraction, $state] = $question->grade_response(['answer' => 'x', 'score' => '1']);
        $this->assertEquals(1.0, $fraction);
        $this->assertEquals(question_state::$gradedright, $state);

        [$fraction] = $question->grade_response(['answer' => 'x', 'score' => '0.5']);
        $this->assertEquals(0.5, $fraction);

        // Out-of-range and non-numeric scores are clamped / rejected.
        [$fraction] = $question->grade_response(['answer' => 'x', 'score' => '2']);
        $this->assertEquals(1.0, $fraction);
        [$fraction] = $question->grade_response(['answer' => 'x', 'score' => '-3']);
        $this->assertEquals(0.0, $fraction);
        [$fraction] = $question->grade_response(['answer' => 'x', 'score' => 'abc']);
        $this->assertEquals(0.0, $fraction);
    }

    public function test_regrade_is_idempotent(): void {
        $question = $this->make_question();
        $response = ['answer' => 'whatever', 'score' => '0.75'];
        [$first] = $question->grade_response($response);
        [$second] = $question->grade_response($response);
        $this->assertEquals($first, $second);
        $this->assertEquals(0.75, $second);
    }
}
