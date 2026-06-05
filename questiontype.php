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

/**
 * The CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * The CodeAtelier question type.
 *
 * A question carries a language and a list of test cases. The test cases (input,
 * expected output, matching rule, weight, visibility) are the declarative
 * matching spec the practice-tier and judge-tier graders both interpret. The
 * single per-question option (language) is handled by the base class via
 * extra_question_fields(); the test-case rows are stored in a dedicated table.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier extends question_type {

    /** @var string[] every language code the question type knows, in picker order. */
    const ALL_LANGUAGES = array('python', 'sql', 'javascript', 'c', 'cpp', 'java',
            'go', 'rust', 'php', 'typescript', 'csharp', 'kotlin', 'ruby');

    public function extra_question_fields() {
        return array('qtype_codeatelier_options', 'language', 'tier', 'scenario', 'entrypoint',
                'startercode', 'schemasql', 'cputimelimit', 'memorylimit', 'languages', 'starters',
                'referencesolution');
    }

    public function get_question_options($question) {
        global $DB;
        parent::get_question_options($question);
        $question->options->tests = $DB->get_records('qtype_codeatelier_tests',
                array('questionid' => $question->id), 'sortorder ASC, id ASC');
        return true;
    }

    public function save_question_options($question) {
        global $DB;

        // A blank per-question limit means "use the site default" — store null.
        $question->cputimelimit = (isset($question->cputimelimit) && trim((string) $question->cputimelimit) !== '')
                ? (float) $question->cputimelimit : null;
        $question->memorylimit = (isset($question->memorylimit) && trim((string) $question->memorylimit) !== '')
                ? (int) $question->memorylimit : null;

        // The allowed-language set is stored as a comma-separated string (the form
        // sends an array); the primary language is always a member. Per-language
        // starter code is a JSON map, pruned to the languages actually allowed.
        $primary = isset($question->language) ? $question->language : 'python';
        $allowed = array();
        foreach ((array) ($question->languages ?? array()) as $lang) {
            $lang = trim((string) $lang);
            if ($lang !== '' && in_array($lang, self::ALL_LANGUAGES, true) && !in_array($lang, $allowed, true)) {
                $allowed[] = $lang;
            }
        }
        if (!in_array($primary, $allowed, true)) {
            array_unshift($allowed, $primary);
        }
        $question->languages = implode(',', $allowed);

        $decoded = isset($question->starters) && trim((string) $question->starters) !== ''
                ? json_decode((string) $question->starters, true) : null;
        $starters = array();
        if (is_array($decoded)) {
            foreach ($allowed as $lang) {
                // The primary language's starter lives in startercode, not the map.
                if ($lang !== $primary && isset($decoded[$lang]) && trim((string) $decoded[$lang]) !== '') {
                    $starters[$lang] = (string) $decoded[$lang];
                }
            }
        }
        $question->starters = $starters ? json_encode($starters) : null;

        // The reference solution is a scalar; store null when blank.
        $question->referencesolution = (isset($question->referencesolution)
                && trim((string) $question->referencesolution) !== '')
                ? (string) $question->referencesolution : null;

        // Saves the language/tier/scenario options into qtype_codeatelier_options.
        parent::save_question_options($question);

        $oldtests = $DB->get_records('qtype_codeatelier_tests',
                array('questionid' => $question->id), 'id ASC');

        $sortorder = 0;
        $inputs = isset($question->testinput) ? $question->testinput : array();
        foreach ($inputs as $key => $input) {
            $expected = isset($question->testexpected[$key]) ? $question->testexpected[$key] : '';
            // Skip a completely blank row.
            if (trim($input) === '' && trim($expected) === '') {
                continue;
            }

            $matchmode = $question->matchmode[$key];
            $tolerance = null;
            if ($matchmode === 'float' && isset($question->tolerance[$key])
                    && trim($question->tolerance[$key]) !== '') {
                $tolerance = $question->tolerance[$key];
            }

            $record = new stdClass();
            $record->questionid = $question->id;
            $record->sortorder = $sortorder++;
            $record->input = $input;
            $record->expected = $expected;
            $record->matchmode = $matchmode;
            $record->tolerance = $tolerance;
            $record->weight = $question->testweight[$key];
            $record->visibility = $question->testvisibility[$key];
            $record->checkerquery = isset($question->checkerquery[$key]) ? $question->checkerquery[$key] : null;
            $record->feedback = isset($question->testfeedback[$key]) ? $question->testfeedback[$key] : null;

            $existing = array_shift($oldtests);
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('qtype_codeatelier_tests', $record);
            } else {
                $DB->insert_record('qtype_codeatelier_tests', $record);
            }
        }

        // Remove any test rows the author deleted from the form.
        foreach ($oldtests as $leftover) {
            $DB->delete_records('qtype_codeatelier_tests', array('id' => $leftover->id));
        }
    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        // $question->language is filled by the base class from extra_question_fields().
        $question->tests = array_values($questiondata->options->tests);
    }

    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_codeatelier_tests', array('questionid' => $questionid));
        parent::delete_question($questionid, $contextid);
    }

    public function get_random_guess_score($questiondata) {
        // The result is supplied with the response, so there is no meaningful
        // random-guess baseline to report.
        return null;
    }

    /**
     * Export a question to Moodle XML. Fully custom (the base class assumes an
     * 'answers' child table this type does not have): writes every option column
     * plus a <testcase> per test case.
     *
     * @param object $question the question being exported
     * @param qformat_xml $format the export format
     * @param mixed $extra unused
     * @return string the XML fragment
     */
    public function export_to_xml($question, qformat_xml $format, $extra = null) {
        $fields = $this->extra_question_fields();
        array_shift($fields);

        $output = '';
        foreach ($fields as $field) {
            $value = isset($question->options->$field) ? $question->options->$field : '';
            $output .= "    <{$field}>" . $format->xml_escape((string) $value) . "</{$field}>\n";
        }

        foreach ($question->options->tests as $test) {
            $output .= "    <testcase>\n";
            foreach (array('input', 'expected', 'matchmode', 'tolerance', 'weight',
                    'visibility', 'checkerquery', 'feedback') as $field) {
                $value = isset($test->$field) ? $test->$field : '';
                $output .= "      <{$field}>" . $format->xml_escape((string) $value) . "</{$field}>\n";
            }
            $output .= "    </testcase>\n";
        }
        return $output;
    }

    /**
     * Import a question from Moodle XML written by {@see export_to_xml()}.
     *
     * @param array $data the xmlized question element
     * @param object $question unused (the in-progress question)
     * @param qformat_xml $format the import format
     * @param mixed $extra unused
     * @return object|bool the question object to save, or false if not our type
     */
    public function import_from_xml($data, $question, qformat_xml $format, $extra = null) {
        if (!isset($data['@']['type']) || $data['@']['type'] !== 'codeatelier') {
            return false;
        }

        $qo = $format->import_headers($data);
        $qo->qtype = 'codeatelier';

        $defaults = array(
            'language' => 'python', 'tier' => 'practice', 'scenario' => 'stdinstdout',
            'entrypoint' => '', 'startercode' => '', 'schemasql' => '',
            'cputimelimit' => '', 'memorylimit' => '', 'languages' => '',
            'starters' => '', 'referencesolution' => '',
        );
        $fields = $this->extra_question_fields();
        array_shift($fields);
        foreach ($fields as $field) {
            $default = isset($defaults[$field]) ? $defaults[$field] : '';
            $qo->$field = $format->getpath($data, array('#', $field, 0, '#'), $default);
        }
        // save_question_options expects the allowed-language SET as an array (the
        // multi-select value), not the stored comma string — casting a comma string
        // would collapse the whole set to one bad element.
        $qo->languages = ($qo->languages !== '' && $qo->languages !== null)
            ? array_values(array_filter(array_map('trim', explode(',', $qo->languages))))
            : array();

        foreach (array('testinput', 'testexpected', 'matchmode', 'tolerance',
                'testweight', 'testvisibility', 'checkerquery', 'testfeedback') as $arr) {
            $qo->$arr = array();
        }
        $testcases = isset($data['#']['testcase']) ? $data['#']['testcase'] : array();
        foreach ($testcases as $tc) {
            $qo->testinput[] = $format->getpath($tc, array('#', 'input', 0, '#'), '');
            $qo->testexpected[] = $format->getpath($tc, array('#', 'expected', 0, '#'), '');
            $qo->matchmode[] = $format->getpath($tc, array('#', 'matchmode', 0, '#'), 'trimmed');
            $qo->tolerance[] = $format->getpath($tc, array('#', 'tolerance', 0, '#'), '');
            $qo->testweight[] = $format->getpath($tc, array('#', 'weight', 0, '#'), '1');
            $qo->testvisibility[] = $format->getpath($tc, array('#', 'visibility', 0, '#'), 1);
            $qo->checkerquery[] = $format->getpath($tc, array('#', 'checkerquery', 0, '#'), '');
            $qo->testfeedback[] = $format->getpath($tc, array('#', 'feedback', 0, '#'), '');
        }
        return $qo;
    }

    /**
     * The JS string bundle passed to the workbench controller.
     *
     * Shared by the question renderer and the authoring form's live preview so
     * both drive the identical student UI from one source of truth.
     *
     * @return array string key => localised text
     */
    public static function ui_strings() {
        $keys = array(
            'running' => 'running',
            'loading' => 'preparingruntime',
            'runerror' => 'runerror',
            'pass' => 'result_pass',
            'fail' => 'result_fail',
            'test' => 'result_test',
            'hidden' => 'result_hidden',
            'score' => 'result_score',
            'schematables' => 'schematables',
            'examples' => 'examples',
            'input' => 'input',
            'expected' => 'expected',
            'call' => 'result_call',
            'got' => 'result_got',
            'argslabel' => 'scratchargs',
            'output' => 'output',
            'stopped' => 'stopped',
            'noresult' => 'noresult',
            'teacherfeedback' => 'result_feedback',
            'stale' => 'stale',
            'degraded' => 'degraded',
            'focus' => 'focus',
            'exitfocus' => 'exitfocus',
            'focusmode' => 'focusmode',
        );
        $strings = array();
        foreach ($keys as $jskey => $stringid) {
            $strings[$jskey] = get_string($stringid, 'qtype_codeatelier');
        }
        return $strings;
    }

    /**
     * The static label fields of the workbench template context (everything that
     * does not depend on a specific question's saved data).
     *
     * @return array template variable => localised text
     */
    public static function ui_labels() {
        return array(
            'answerlabel' => get_string('answerlabel', 'qtype_codeatelier'),
            'loadingeditor' => get_string('loadingeditor', 'qtype_codeatelier'),
            'runlabel' => get_string('runlabel', 'qtype_codeatelier'),
            'focuslabel' => get_string('focus', 'qtype_codeatelier'),
            'checklabel' => get_string('checklabel', 'qtype_codeatelier'),
            'stoplabel' => get_string('stoplabel', 'qtype_codeatelier'),
            'consolelabel' => get_string('consolelabel', 'qtype_codeatelier'),
            'testslabel' => get_string('testslabel', 'qtype_codeatelier'),
            'outputtabslabel' => get_string('outputtabs', 'qtype_codeatelier'),
            'practicenote' => get_string('practicenote', 'qtype_codeatelier'),
        );
    }
}
