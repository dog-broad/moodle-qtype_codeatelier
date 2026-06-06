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

use qtype_codeatelier\local\report\question_stats;
use qtype_codeatelier_stub_runner;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/stub_runner.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Tests for the Insight submissions data layer.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\local\report\question_stats
 */
final class question_stats_test extends \advanced_testcase {

    /** @var \context the usage context. */
    private $context;

    /** @var int the question id under test. */
    private $questionid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(array('contextid' => $this->context->id));
        $q = $qgen->create_question('codeatelier', 'judge', array('category' => $cat->id));
        $this->questionid = $q->id;
    }

    /**
     * Warm the judge memo for an answer with scripted per-case results, so a later
     * submit of that exact answer grades from the cache.
     *
     * @param string $answer
     * @param array $rows the per-case runner rows
     */
    private function warm(string $answer, array $rows): void {
        $question = \question_bank::load_question($this->questionid);
        $question->judgerunner = new qtype_codeatelier_stub_runner($rows);
        $question->get_judge_result($answer, 'python');
    }

    /**
     * Submit one attempt of the question and finish it (deferred feedback).
     *
     * @param string $answer
     */
    private function attempt(string $answer, string $component = 'mod_quiz', $context = null): void {
        $question = \question_bank::load_question($this->questionid);
        $quba = \question_engine::make_questions_usage_by_activity($component, $context ?? $this->context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question($question, 1);
        $quba->start_all_questions();
        $quba->process_action($slot, array('answer' => $answer, 'language' => 'python'));
        $quba->finish_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
    }

    public function test_aggregates_across_attempts(): void {
        $allpass = array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        );
        $partial = array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "999\n"),
        );

        // Two correct (AC, full), one partial (WA, 0.5), one never-graded.
        $this->warm('correct-a', $allpass);
        $this->warm('correct-b', $allpass);
        $this->warm('half', $partial);

        $this->attempt('correct-a');
        $this->attempt('correct-b');
        $this->attempt('half');
        $this->attempt('uncached');   // no memo -> needs grading
        // A teacher preview of the same answer must NOT count as a student attempt.
        $this->attempt('correct-a', 'core_question_preview');

        $stats = question_stats::for_question($this->questionid);

        $this->assertSame('judge', $stats->tier);
        $this->assertSame(4, $stats->totalattempts);
        $this->assertSame(3, $stats->graded);
        $this->assertSame(1, $stats->needsgrading);

        // Score distribution + mean over graded attempts.
        $this->assertSame(2, $stats->scoredist->full);
        $this->assertSame(1, $stats->scoredist->partial);
        $this->assertSame(0, $stats->scoredist->zero);
        $this->assertEqualsWithDelta((1 + 1 + 0.5) / 3, $stats->meanscore, 1e-9);

        // Verdicts from the memo (the uncached attempt contributes none).
        $this->assertSame(2, $stats->verdicts['AC']);
        $this->assertSame(1, $stats->verdicts['WA']);
        $this->assertSame(3, $stats->detailcoverage->withresult);
        $this->assertSame(4, $stats->detailcoverage->total);

        // Language breakdown counts every attempt.
        $this->assertSame(4, $stats->languages['python']);

        // Per-test pass rates: test 0 passed by all 3 graded, test 1 by 2 of 3.
        $this->assertCount(2, $stats->pertest);
        $this->assertTrue($stats->pertest[0]->visible);
        $this->assertSame(3, $stats->pertest[0]->passes);
        $this->assertEqualsWithDelta(1.0, $stats->pertest[0]->passrate, 1e-9);
        $this->assertFalse($stats->pertest[1]->visible);
        $this->assertSame(2, $stats->pertest[1]->passes);
        $this->assertEqualsWithDelta(2 / 3, $stats->pertest[1]->passrate, 1e-9);
    }

    public function test_scoped_to_usage_context(): void {
        $allpass = array(
            qtype_codeatelier_stub_runner::row('ran', "5\n"),
            qtype_codeatelier_stub_runner::row('ran', "30\n"),
        );
        $this->warm('shared', $allpass);

        // The same answer attempted in two different course contexts.
        $course2 = $this->getDataGenerator()->create_course();
        $other = \context_course::instance($course2->id);
        $this->attempt('shared', 'mod_quiz', $this->context);
        $this->attempt('shared', 'mod_quiz', $other);

        $this->assertSame(2, question_stats::for_question($this->questionid)->totalattempts);
        $this->assertSame(1, question_stats::for_question($this->questionid, $this->context->id)->totalattempts);
        $this->assertSame(1, question_stats::for_question($this->questionid, $other->id)->totalattempts);
    }

    public function test_empty_question_has_zeroed_stats(): void {
        $stats = question_stats::for_question($this->questionid);
        $this->assertSame(0, $stats->totalattempts);
        $this->assertNull($stats->meanscore);
        $this->assertSame([], $stats->verdicts);
        $this->assertSame(0, $stats->detailcoverage->withresult);
    }
}
