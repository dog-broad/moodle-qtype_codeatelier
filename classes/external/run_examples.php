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
use core_external\external_multiple_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Run a judge-tier submission against the question's visible example inputs.
 *
 * Formative only: it returns the produced output for the VISIBLE tests, never a
 * grade and never a hidden test. The authoritative grade stays on the normal
 * grade_response() path at submit. It exists so compiled-language students, who
 * have no in-browser runtime, can try their code before submitting.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_examples extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'The CodeAtelier question id'),
            'code' => new external_value(PARAM_RAW, 'The student submission to run'),
            'language' => new external_value(PARAM_ALPHANUMEXT, 'The chosen language (re-validated server-side)',
                    VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Run the submission against the visible examples.
     *
     * @param int $questionid the question id
     * @param string $code the student submission
     * @param string $language the chosen language (re-validated against the question's allowed set)
     * @return array the run result (verdict over visible examples, compile output, per-example results)
     */
    public static function execute(int $questionid, string $code, string $language = ''): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $params = self::validate_parameters(self::execute_parameters(),
                ['questionid' => $questionid, 'code' => $code, 'language' => $language]);

        $question = \question_bank::load_question($params['questionid']);
        if (!($question instanceof \qtype_codeatelier_question) || !$question->is_judge()) {
            throw new \moodle_exception('notjudgequestion', 'qtype_codeatelier');
        }

        // Tie the run to the question's own context: a logged-in user with access
        // to that context only, and only the visible inputs ever execute.
        $context = \context::instance_by_id($question->contextid);
        self::validate_context($context);

        // Re-validate the chosen language server-side; a disallowed value falls back
        // to the question's primary language and can never run something off-tier.
        $result = $question->run_examples($params['code'], $question->resolve_language($params['language']));
        if ($result === null) {
            throw new \moodle_exception('judgenotconfigured', 'qtype_codeatelier');
        }

        $examples = [];
        foreach ($result['testresults'] as $tr) {
            $examples[] = [
                'index' => $tr['index'],
                'status' => $tr['status'],
                'passed' => !empty($tr['passed']),
                'input' => isset($tr['input']) ? (string) $tr['input'] : '',
                'expected' => isset($tr['expected']) ? (string) $tr['expected'] : '',
                'actual' => isset($tr['actual']) ? (string) $tr['actual'] : '',
                'stderr' => isset($tr['stderr']) ? (string) $tr['stderr'] : '',
                'time' => isset($tr['time']) && $tr['time'] !== null ? (float) $tr['time'] : 0.0,
                'memory' => isset($tr['memory']) && $tr['memory'] !== null ? (int) $tr['memory'] : 0,
            ];
        }

        return [
            'verdict' => $result['verdict'] ?? 'WA',
            'compileoutput' => $result['compileoutput'] ?? '',
            'examples' => $examples,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'verdict' => new external_value(PARAM_ALPHA, 'Formative verdict over the visible examples'),
            'compileoutput' => new external_value(PARAM_RAW, 'Compiler output, when the code did not compile'),
            'examples' => new external_multiple_structure(new external_single_structure([
                'index' => new external_value(PARAM_INT, 'The example index'),
                'status' => new external_value(PARAM_ALPHA, 'pass, fail, re, tle, mle or ce'),
                'passed' => new external_value(PARAM_BOOL, 'Whether the output matched'),
                'input' => new external_value(PARAM_RAW, 'The example input'),
                'expected' => new external_value(PARAM_RAW, 'The expected output'),
                'actual' => new external_value(PARAM_RAW, 'The produced output'),
                'stderr' => new external_value(PARAM_RAW, 'Error output, when any'),
                'time' => new external_value(PARAM_FLOAT, 'CPU time in seconds'),
                'memory' => new external_value(PARAM_INT, 'Peak memory in kilobytes'),
            ])),
        ]);
    }
}
