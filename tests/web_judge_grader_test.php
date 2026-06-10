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
 * Authoritative web (frontend) grading: the grade path adapts the web-judge
 * service's per-assertion results into a weighted fraction + verdict blob, with
 * an injected fake runner standing in for the real service.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\judge\web_judge_grader
 */
final class web_judge_grader_test extends \advanced_testcase {
    /**
     * A fake web-judge runner returning canned per-assertion results (or an error).
     *
     * @param array $results per-assertion [{pass, actual}]
     * @param string|null $error a connection error message instead of results
     * @return \qtype_codeatelier\judge\web_judge_runner
     */
    protected function fake_runner(array $results, ?string $error = null) {
        $fake = new class ('http://example.test') extends \qtype_codeatelier\judge\web_judge_runner {
            /** @var array */
            public $results = [];
            /** @var string|null */
            public $error = null;
            /**
             * Return the scripted results (or error) instead of calling the service.
             *
             * @param array $files
             * @param array $assertions
             * @param int $timeoutms
             * @return array
             */
            public function grade(array $files, array $assertions, int $timeoutms = 5000): array {
                return $this->error !== null ? ['_error' => $this->error] : ['results' => $this->results];
            }
        };
        $fake->results = $results;
        $fake->error = $error;
        return $fake;
    }

    /**
     * A judge-tier web question (the helper 'web' fixture: a visible #t text check
     * weight 1, and a hidden #o text check weight 2 after a click).
     *
     * @return \qtype_codeatelier_question
     */
    protected function web_question() {
        global $CFG;
        require_once($CFG->dirroot . '/question/type/codeatelier/tests/helper.php');
        \question_bank::load_question_definition_classes('codeatelier');
        $helper = new \qtype_codeatelier_test_helper();
        $q = $helper->make_codeatelier_question_web();
        $q->tier = 'judge';
        return $q;
    }

    public function test_all_assertions_pass_is_ac(): void {
        $this->resetAfterTest();
        $q = $this->web_question();
        $q->webjudgerunner = $this->fake_runner([
            ['pass' => true, 'actual' => 'Hi'],
            ['pass' => true, 'actual' => 'ok'],
        ]);
        $result = $q->get_judge_result($q->projectfiles);
        $this->assertFalse($result['infrastructure']);
        $this->assertEqualsWithDelta(1.0, $result['fraction'], 0.0001);
        $this->assertEquals('AC', $result['verdict']);
    }

    public function test_partial_pass_is_weighted_wa(): void {
        $this->resetAfterTest();
        $q = $this->web_question();
        // The visible check (weight 1) passes; the hidden check (weight 2) fails.
        $q->webjudgerunner = $this->fake_runner([
            ['pass' => true, 'actual' => 'Hi'],
            ['pass' => false, 'actual' => ''],
        ]);
        $result = $q->get_judge_result($q->projectfiles);
        $this->assertEqualsWithDelta(1.0 / 3.0, $result['fraction'], 0.0001);
        $this->assertEquals('WA', $result['verdict']);
    }

    public function test_all_or_nothing_zeroes_a_partial(): void {
        $this->resetAfterTest();
        $q = $this->web_question();
        $q->allornothing = 1;
        $q->webjudgerunner = $this->fake_runner([
            ['pass' => true, 'actual' => 'Hi'],
            ['pass' => false, 'actual' => ''],
        ]);
        $result = $q->get_judge_result($q->projectfiles);
        $this->assertEqualsWithDelta(0.0, $result['fraction'], 0.0001);
        $this->assertEquals('WA', $result['verdict']);
    }

    public function test_hidden_assertion_is_not_revealed_in_the_blob(): void {
        $this->resetAfterTest();
        $q = $this->web_question();
        $q->webjudgerunner = $this->fake_runner([
            ['pass' => false, 'actual' => 'Goodbye'],
            ['pass' => false, 'actual' => 'secret'],
        ]);
        $result = $q->get_judge_result($q->projectfiles);
        $rows = $result['testresults'];
        // The visible row carries the check + the produced value.
        $this->assertSame(1, (int) $rows[0]['visibility']);
        $this->assertArrayHasKey('check', $rows[0]);
        $this->assertStringContainsString('#t', $rows[0]['check']);
        // The hidden row carries only pass/fail — never its check, expected or actual.
        $this->assertSame(0, (int) $rows[1]['visibility']);
        $this->assertArrayNotHasKey('check', $rows[1]);
        $this->assertArrayNotHasKey('actual', $rows[1]);
    }

    public function test_infrastructure_failure_is_not_a_zero(): void {
        $this->resetAfterTest();
        $q = $this->web_question();
        $q->webjudgerunner = $this->fake_runner([], 'connection refused');
        $result = $q->get_judge_result($q->projectfiles);
        $this->assertTrue($result['infrastructure']);
        $this->assertEquals(0.0, $result['fraction']);
    }

    public function test_uncached_submit_is_needs_grading(): void {
        $this->resetAfterTest();
        $q = $this->web_question();
        // Nothing is memoised yet, so grade_response leaves it awaiting grading
        // (the scheduled task runs the service and writes back) rather than scoring 0.
        $response = ['answer' => $q->projectfiles, 'language' => 'javascript'];
        [$fraction, $state] = $q->grade_response($response);
        $this->assertEquals(\question_state::$needsgrading, $state);
    }

    public function test_assertion_fields_change_the_cache_key(): void {
        $base = (object) ['input' => '', 'expected' => 'Hi', 'matchmode' => 'trimmed',
            'weight' => 1, 'visibility' => 1, 'selector' => '#t',
            'assertproperty' => 'text', 'assertmatcher' => 'equals', 'actions' => ''];
        $args = ['javascript', 'code', [$base], [], '', false, '', 'web', '', '', ''];
        $key = call_user_func_array(['\qtype_codeatelier\judge\result_cache', 'key'], $args);
        foreach (
            ['selector' => '#other', 'assertproperty' => 'exists',
                'assertmatcher' => 'contains', 'actions' => 'click #x'] as $field => $value
        ) {
            $changed = clone $base;
            $changed->$field = $value;
            $args[2] = [$changed];
            $other = call_user_func_array(['\qtype_codeatelier\judge\result_cache', 'key'], $args);
            $this->assertNotEquals($key, $other, "editing {$field} should change the cache key");
        }
    }
}
