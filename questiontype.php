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
    const ALL_LANGUAGES = array('python', 'sql', 'javascript', 'c', 'cpp', 'java');

    public function extra_question_fields() {
        return array('qtype_codeatelier_options', 'language', 'tier', 'scenario', 'entrypoint',
                'startercode', 'schemasql', 'cputimelimit', 'memorylimit', 'languages', 'starters');
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

        // The judge tier is always the stdin/stdout shape, whatever the (hidden)
        // scenario field last held in the form.
        if (isset($question->tier) && $question->tier === 'judge') {
            $question->scenario = 'stdinstdout';
        }

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
                if (isset($decoded[$lang]) && trim((string) $decoded[$lang]) !== '') {
                    $starters[$lang] = (string) $decoded[$lang];
                }
            }
        }
        $question->starters = $starters ? json_encode($starters) : null;

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
