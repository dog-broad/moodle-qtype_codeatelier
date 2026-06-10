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

namespace qtype_codeatelier\task;

/**
 * Grade judge-tier submissions off the web request.
 *
 * A judge submission returns "needs grading" instantly; this scheduled task drains
 * those pending attempts, runs the sandbox (batched), and regrades each attempt so
 * the real grade — and, for quiz attempts, the quiz total and gradebook — land. An
 * unreachable sandbox simply leaves the attempt pending, so the next run retries.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_pending extends \core\task\scheduled_task {
    /** @var int max question usages to process per run (the rest wait for the next). */
    const MAX_USAGES_PER_RUN = 200;

    /**
     * The task's display name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_grade_pending', 'qtype_codeatelier');
    }

    /**
     * Grade needs-grading judge attempts in the sandbox and write the results back.
     */
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        // No endpoint configured → nothing this task can do.
        if (\qtype_codeatelier\judge\judge0_runner::from_config() === null) {
            return;
        }

        // Find judge-tier CodeAtelier attempts whose latest step is "needs grading".
        $sql = "SELECT qa.id AS qaid, qa.questionusageid AS qubaid, qa.slot AS slot
                  FROM {question_attempts} qa
                  JOIN {question} q ON q.id = qa.questionid
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                 WHERE q.qtype = :qtype
                   AND qas.state = :state
                   AND qas.sequencenumber = (
                       SELECT MAX(s2.sequencenumber)
                         FROM {question_attempt_steps} s2
                        WHERE s2.questionattemptid = qa.id)
              ORDER BY qa.questionusageid ASC";
        $rows = $DB->get_records_sql($sql, ['qtype' => 'codeatelier', 'state' => 'needsgrading']);
        if (!$rows) {
            return;
        }

        // Group the pending slots by their question usage.
        $byusage = [];
        foreach ($rows as $row) {
            $byusage[(int) $row->qubaid][] = (int) $row->slot;
        }

        $processed = 0;
        foreach ($byusage as $qubaid => $slots) {
            if ($processed >= self::MAX_USAGES_PER_RUN) {
                break;
            }
            $processed++;
            try {
                $this->grade_usage($qubaid, $slots);
            } catch (\Throwable $e) {
                // One bad usage must not abort the whole run; it stays pending and
                // is retried next time.
                mtrace("qtype_codeatelier: grading usage {$qubaid} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Grade the pending judge slots of one question usage and write the result back.
     *
     * @param int $qubaid the question usage id
     * @param int[] $slots the slots whose latest step is needs-grading
     */
    private function grade_usage(int $qubaid, array $slots): void {
        $quba = \question_engine::load_questions_usage_by_activity($qubaid);
        $gradedany = false;

        foreach ($slots as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $question = $qa->get_question();
            if (!($question instanceof \qtype_codeatelier_question) || !$question->is_judge()) {
                continue;
            }
            $code = $qa->get_last_qt_var('answer', '');
            // Grade in the language the student chose for this attempt. get_judge_result
            // re-validates it the same way the regrade's grade_response will, so the
            // memo bucket this warms is exactly the one the regrade then reads.
            $language = $qa->get_last_qt_var('language', null);
            // Runs the sandbox (batched) and populates the shared memo.
            $result = $question->get_judge_result($code, $language);
            if ($result === null || !empty($result['infrastructure'])) {
                // Sandbox unreachable: leave pending, retry next run.
                continue;
            }
            // The memo is now warm, so regrading re-runs grade_response, which reads
            // the memo and records the real grade.
            $quba->regrade_question($slot, true);
            $gradedany = true;
        }

        if (!$gradedany) {
            return;
        }
        \question_engine::save_questions_usage_by_activity($quba);
        $this->update_quiz_grade($qubaid, $quba);
    }

    /**
     * If this usage belongs to a quiz attempt, refresh the attempt total and the
     * gradebook. A preview or other non-quiz usage needs nothing beyond the save.
     *
     * @param int $qubaid the question usage id
     * @param \question_usage_by_activity $quba the regraded usage
     */
    private function update_quiz_grade(int $qubaid, \question_usage_by_activity $quba): void {
        global $DB, $CFG;

        $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $qubaid]);
        if (!$attempt) {
            return;
        }
        $attempt->sumgrades = $quba->get_total_mark();
        $DB->update_record('quiz_attempts', $attempt);

        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
        if ($quiz) {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            quiz_save_best_grade($quiz, $attempt->userid);
        }
    }
}
