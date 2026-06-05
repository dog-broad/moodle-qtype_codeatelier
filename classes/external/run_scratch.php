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
 * Judge-tier "run with my own input": run the current code once against a
 * student-supplied input and return the output. Formative only — no grade, no
 * tests, no hidden data. The judge-tier counterpart of the practice tier's Run
 * verb, so compiled-language students can explore before the authoritative submit.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_scratch extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'The CodeAtelier question id'),
            'code' => new external_value(PARAM_RAW, 'The student submission to run'),
            'language' => new external_value(PARAM_ALPHANUMEXT, 'The chosen language', VALUE_DEFAULT, ''),
            'input' => new external_value(PARAM_RAW, 'The student-supplied input (stdin, or a JSON argument array)'),
        ]);
    }

    /**
     * Run the submission once against the supplied input.
     *
     * @param int $questionid the question id
     * @param string $code the student submission
     * @param string $language the chosen language (re-validated against the allowed set)
     * @param string $input the student's own input
     * @return array {verdict, stdout, stderr, compileoutput}
     */
    public static function execute(int $questionid, string $code, string $language = '', string $input = ''): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $params = self::validate_parameters(self::execute_parameters(),
                ['questionid' => $questionid, 'code' => $code, 'language' => $language, 'input' => $input]);

        $question = \question_bank::load_question($params['questionid']);
        if (!($question instanceof \qtype_codeatelier_question) || !$question->is_judge()) {
            throw new \moodle_exception('notjudgequestion', 'qtype_codeatelier');
        }
        $context = \context::instance_by_id($question->contextid);
        self::validate_context($context);

        // Share the per-user throttle with run-against-examples: both run the sandbox on demand.
        run_examples::enforce_cooldown((int) $USER->id);

        $result = $question->run_scratch($params['code'], $question->resolve_language($params['language']),
                $params['input']);
        if ($result === null) {
            throw new \moodle_exception('judgenotconfigured', 'qtype_codeatelier');
        }
        if (!empty($result['infrastructure'])) {
            throw new \moodle_exception('judgenotconfigured', 'qtype_codeatelier');
        }

        return [
            'verdict' => $result['verdict'] ?? 'OK',
            'stdout' => (string) ($result['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? ''),
            'compileoutput' => (string) ($result['compileoutput'] ?? ''),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'verdict' => new external_value(PARAM_ALPHA, 'OK, CE, RE, TLE or MLE'),
            'stdout' => new external_value(PARAM_RAW, 'The produced output'),
            'stderr' => new external_value(PARAM_RAW, 'Error output, when any'),
            'compileoutput' => new external_value(PARAM_RAW, 'Compiler output, when the code did not compile'),
        ]);
    }
}
