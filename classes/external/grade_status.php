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

namespace qtype_codeatelier\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Report whether a submitted judge-tier attempt has finished background grading.
 *
 * The attempt review polls this while it shows "awaiting automatic grading"; once
 * the scheduled task has graded it, the page refreshes to show the real result.
 * Returns only a state flag — never anything secret.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_status extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'qubaid' => new external_value(PARAM_INT, 'The question usage id'),
            'slot' => new external_value(PARAM_INT, 'The slot within the usage'),
        ]);
    }

    /**
     * @param int $qubaid the question usage id
     * @param int $slot the slot
     * @return array {graded, state}
     */
    public static function execute(int $qubaid, int $slot): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['qubaid' => $qubaid, 'slot' => $slot]
        );

        // Gate to the attempt's owner (or a teacher who can see the quiz reports).
        $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $params['qubaid']]);
        if ($attempt) {
            $cm = get_coursemodule_from_instance('quiz', $attempt->quiz, 0, false, MUST_EXIST);
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
            if ((int) $attempt->userid !== (int) $USER->id) {
                require_capability('mod/quiz:viewreports', $context);
            }
        } else {
            // A preview or other usage: a logged-in user polling their own page.
            self::validate_context(\context_system::instance());
        }

        $quba = \question_engine::load_questions_usage_by_activity($params['qubaid']);
        $qa = $quba->get_question_attempt($params['slot']);
        $state = $qa->get_state();

        return [
            'graded' => $state != \question_state::$needsgrading,
            'state' => (string) $state,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'graded' => new external_value(PARAM_BOOL, 'Whether grading has finished'),
            'state' => new external_value(PARAM_ALPHA, 'The question state name'),
        ]);
    }
}
