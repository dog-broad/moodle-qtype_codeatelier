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

use qtype_codeatelier\external\grade_status;
use qtype_codeatelier_stub_runner;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/stub_runner.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Tests for the live grade-status endpoint.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\external\grade_status
 */
final class grade_status_test extends \advanced_testcase {

    /** @var \context */
    private $context;

    /** @var int */
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
        $this->questionid = $qgen->create_question('codeatelier', 'judge', array('category' => $cat->id))->id;
    }

    /**
     * Submit an attempt; optionally warm the memo first so it grades on finish.
     *
     * @param string $answer
     * @param bool $warm
     * @return array [qubaid, slot]
     */
    private function submit(string $answer, bool $warm): array {
        if ($warm) {
            $q = \question_bank::load_question($this->questionid);
            $q->judgerunner = new qtype_codeatelier_stub_runner(array(
                qtype_codeatelier_stub_runner::row('ran', "5\n"),
                qtype_codeatelier_stub_runner::row('ran', "30\n"),
            ));
            $q->get_judge_result($answer, 'python');
        }
        $question = \question_bank::load_question($this->questionid);
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $this->context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question($question, 1);
        $quba->start_all_questions();
        $quba->process_action($slot, array('answer' => $answer, 'language' => 'python'));
        $quba->finish_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);
        return array($quba->get_id(), $slot);
    }

    public function test_status_reflects_grading(): void {
        list($gradedqubaid, $gradedslot) = $this->submit('warm-answer', true);
        list($pendingqubaid, $pendingslot) = $this->submit('cold-answer', false);

        $graded = grade_status::execute($gradedqubaid, $gradedslot);
        $this->assertTrue($graded['graded']);

        $pending = grade_status::execute($pendingqubaid, $pendingslot);
        $this->assertFalse($pending['graded']);
        $this->assertSame('needsgrading', $pending['state']);
    }
}
