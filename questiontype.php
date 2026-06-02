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

    public function extra_question_fields() {
        return array('qtype_codeatelier_options', 'language', 'scenario', 'entrypoint', 'startercode', 'schemasql');
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

        // Saves the language option into qtype_codeatelier_options.
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
}
