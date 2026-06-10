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
 * Teacher-only "run-to-capture": run a reference solution against all of a
 * question's tests in the judge sandbox and return each produced output, so the
 * author can fill the Expected fields.
 *
 * It takes the question's data INLINE (not a saved question id) so it works while
 * the question is still being authored. There is no integrity boundary here — the
 * teacher is authoring and sees every test — so it is gated on the authoring
 * capability (moodle/question:add) in the form's context, not the attempt path.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_reference extends external_api {
    /** @var \qtype_codeatelier\judge\judge0_runner|null test-injected runner; null builds from config. */
    protected static $runneroverride = null;

    /**
     * Inject a runner for unit testing (bypasses the configured endpoint).
     *
     * @param \qtype_codeatelier\judge\judge0_runner|null $runner
     */
    public static function set_runner($runner): void {
        self::$runneroverride = $runner;
    }

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context the question is authored in'),
            'code' => new external_value(PARAM_RAW, 'The reference solution'),
            'language' => new external_value(PARAM_ALPHANUMEXT, 'The reference language (the question primary)'),
            'scenario' => new external_value(PARAM_ALPHANUMEXT, 'The scenario', VALUE_DEFAULT, 'stdinstdout'),
            'entrypoint' => new external_value(PARAM_RAW_TRIMMED, 'The function name (function scenario)', VALUE_DEFAULT, ''),
            'functionsignature' => new external_value(PARAM_RAW_TRIMMED, 'Typed signature (static-language function)', VALUE_DEFAULT, ''),
            'entryfile' => new external_value(PARAM_RAW_TRIMMED, 'The entry file name (project scenario)', VALUE_DEFAULT, ''),
            'schemasql' => new external_value(PARAM_RAW, 'SQL scenario schema + seed', VALUE_DEFAULT, ''),
            'cputimelimit' => new external_value(PARAM_RAW_TRIMMED, 'Per-question CPU-time limit, or empty', VALUE_DEFAULT, ''),
            'memorylimit' => new external_value(PARAM_RAW_TRIMMED, 'Per-question memory limit, or empty', VALUE_DEFAULT, ''),
            'tests' => new external_multiple_structure(new external_single_structure([
                'input' => new external_value(PARAM_RAW, 'The test input'),
                'matchmode' => new external_value(PARAM_ALPHANUMEXT, 'The match mode'),
                'checkerquery' => new external_value(PARAM_RAW, 'SQL checker query', VALUE_DEFAULT, ''),
            ])),
        ]);
    }

    /**
     * Run the reference against all tests and return each produced output.
     *
     * @param int $contextid the authoring context
     * @param string $code the reference solution
     * @param string $language the reference language
     * @param string $scenario the question scenario
     * @param string $schemasql the SQL schema + seed
     * @param string $cputimelimit per-question CPU-time limit, or ''
     * @param string $memorylimit per-question memory limit, or ''
     * @param array $tests the tests ({input, matchmode, checkerquery})
     * @return array the produced outputs per test
     */
    public static function execute(
        int $contextid,
        string $code,
        string $language,
        string $scenario = 'stdinstdout',
        string $entrypoint = '',
        string $functionsignature = '',
        string $entryfile = '',
        string $schemasql = '',
        string $cputimelimit = '',
        string $memorylimit = '',
        array $tests = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid, 'code' => $code, 'language' => $language, 'scenario' => $scenario,
            'entrypoint' => $entrypoint, 'functionsignature' => $functionsignature, 'entryfile' => $entryfile,
            'schemasql' => $schemasql,
            'cputimelimit' => $cputimelimit, 'memorylimit' => $memorylimit, 'tests' => $tests,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('moodle/question:add', $context);

        $runner = self::runner($params);
        if ($runner === null) {
            throw new \moodle_exception('judgenotconfigured', 'qtype_codeatelier');
        }

        $testobjects = [];
        foreach ($params['tests'] as $test) {
            $testobjects[] = (object) [
                'input' => $test['input'],
                'matchmode' => $test['matchmode'],
                'checkerquery' => $test['checkerquery'],
            ];
        }

        $schemasql = ($params['scenario'] === 'sql') ? $params['schemasql'] : '';
        $grader = new \qtype_codeatelier\judge\judge_grader($runner);
        $outputs = $grader->run_outputs(
            $params['code'],
            $params['language'],
            $testobjects,
            $schemasql,
            (string) $params['scenario'],
            (string) $params['entrypoint'],
            (string) $params['functionsignature'],
            (string) $params['entryfile']
        );

        $results = [];
        foreach ($outputs as $output) {
            $results[] = [
                'index' => $output['index'],
                'output' => (string) $output['output'],
                'ok' => !empty($output['ok']),
                'error' => (string) $output['error'],
            ];
        }
        return ['results' => $results];
    }

    /**
     * The runner: the test-injected one, or one built from the configured endpoint
     * with the per-question limit overrides.
     *
     * @param array $params the validated parameters
     * @return \qtype_codeatelier\judge\judge0_runner|null
     */
    protected static function runner($params) {
        if (self::$runneroverride !== null) {
            return self::$runneroverride;
        }
        $cpu = trim($params['cputimelimit']) !== '' ? (float) $params['cputimelimit'] : null;
        $mem = trim($params['memorylimit']) !== '' ? (int) $params['memorylimit'] : null;
        return \qtype_codeatelier\judge\judge0_runner::from_config($cpu, $mem);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(new external_single_structure([
                'index' => new external_value(PARAM_INT, 'The test index'),
                'output' => new external_value(PARAM_RAW, 'The produced output, to write into the Expected field'),
                'ok' => new external_value(PARAM_BOOL, 'Whether the reference ran cleanly for this test'),
                'error' => new external_value(PARAM_RAW, 'Error detail when the reference did not run cleanly'),
            ])),
        ]);
    }
}
