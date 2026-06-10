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
use qtype_codeatelier\local\integrity\proctor_log;

defined('MOODLE_INTERNAL') || die();

/**
 * Record one soft anti-cheat event against the caller's own live judge-tier attempt.
 *
 * The proctor JS calls this as events happen (leaving the question, an outside-paste
 * attempt). The event is stamped server-side with the authenticated user and the
 * server clock and appended to the {@see proctor_log} table, so the record is
 * server-authoritative rather than a client-held hidden field. A caller may only log
 * against an attempt they own; previews and non-owned usages are silently ignored.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_proctor_event extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'qubaid' => new external_value(PARAM_INT, 'The question usage id'),
            'slot' => new external_value(PARAM_INT, 'The slot within the usage'),
            'eventtype' => new external_value(PARAM_ALPHA, 'The event kind (focusaway, extpaste, extdrop, paste)'),
        ]);
    }

    /**
     * @param int $qubaid the question usage id
     * @param int $slot the slot within the usage
     * @param string $eventtype the event kind
     * @return array {logged}
     */
    public static function execute(int $qubaid, int $slot, string $eventtype): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $params = self::validate_parameters(self::execute_parameters(),
                ['qubaid' => $qubaid, 'slot' => $slot, 'eventtype' => $eventtype]);

        // Only the owner of a real quiz attempt logs events, and only against their own.
        // A preview (no quiz attempt) or a usage owned by someone else is ignored — we
        // never let one user write proctor events for another.
        $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $params['qubaid']]);
        if (!$attempt) {
            self::validate_context(\context_system::instance());
            return ['logged' => false];
        }
        $cm = get_coursemodule_from_instance('quiz', $attempt->quiz, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        if ((int) $attempt->userid !== (int) $USER->id) {
            return ['logged' => false];
        }

        // Resolve the question attempt for this slot in the usage.
        $qaid = $DB->get_field('question_attempts', 'id',
                ['questionusageid' => $params['qubaid'], 'slot' => $params['slot']]);
        if (!$qaid) {
            return ['logged' => false];
        }

        $logged = proctor_log::record((int) $qaid, (int) $USER->id, $params['eventtype']);
        return ['logged' => $logged];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'logged' => new external_value(PARAM_BOOL, 'Whether the event was recorded'),
        ]);
    }
}
