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
 * Editing form for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CodeAtelier question editing form: a language picker and a test-case table.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_edit_form extends question_edit_form {

    protected function definition_inner($mform) {
        $mform->addElement('select', 'language', get_string('language', 'qtype_codeatelier'),
                $this->language_options());
        $mform->addHelpButton('language', 'language', 'qtype_codeatelier');
        $mform->setDefault('language', $this->get_default_value('language', 'python'));

        $mform->addElement('header', 'testcasesheader', get_string('testcases', 'qtype_codeatelier'));
        $mform->setExpanded('testcasesheader');

        $this->add_test_case_fields($mform);

        $this->add_interactive_settings();
    }

    /**
     * Add the repeated test-case rows.
     *
     * @param MoodleQuickForm $mform the form being built
     */
    protected function add_test_case_fields($mform) {
        $repeated = array();
        $repeated[] = $mform->createElement('static', 'testcaselabel', '',
                get_string('testcaseno', 'qtype_codeatelier', '{no}'));
        $repeated[] = $mform->createElement('textarea', 'testinput',
                get_string('input', 'qtype_codeatelier'), array('rows' => 3, 'cols' => 60));
        $repeated[] = $mform->createElement('textarea', 'testexpected',
                get_string('expected', 'qtype_codeatelier'), array('rows' => 3, 'cols' => 60));
        $repeated[] = $mform->createElement('select', 'matchmode',
                get_string('matchmode', 'qtype_codeatelier'), $this->matchmode_options());
        $repeated[] = $mform->createElement('text', 'tolerance',
                get_string('tolerance', 'qtype_codeatelier'), array('size' => 10));
        $repeated[] = $mform->createElement('text', 'testweight',
                get_string('weight', 'qtype_codeatelier'), array('size' => 10));
        $repeated[] = $mform->createElement('select', 'testvisibility',
                get_string('visibility', 'qtype_codeatelier'), $this->visibility_options());

        // Note: do not set a 'default' for the matchmode/testweight/testvisibility
        // elements here. For repeated elements a default overrides the values
        // loaded by data_preprocessing(), so an edited question would silently
        // reset them. The selects fall back to their first option for new rows;
        // weight is author-entered and checked in validation().
        $repeatedoptions = array();
        $repeatedoptions['testinput']['type'] = PARAM_RAW;
        $repeatedoptions['testexpected']['type'] = PARAM_RAW;
        $repeatedoptions['matchmode']['type'] = PARAM_ALPHA;
        $repeatedoptions['tolerance']['type'] = PARAM_RAW_TRIMMED;
        $repeatedoptions['tolerance']['disabledif'] = array('matchmode', 'neq', 'float');
        $repeatedoptions['testweight']['type'] = PARAM_RAW_TRIMMED;
        $repeatedoptions['testvisibility']['type'] = PARAM_INT;

        if (!empty($this->question->options->tests)) {
            $repeats = count($this->question->options->tests);
        } else {
            $repeats = 2;
        }

        $this->repeat_elements($repeated, $repeats, $repeatedoptions, 'option_repeats',
                'option_add_tests', 1, get_string('addmoretestcases', 'qtype_codeatelier'), true);
    }

    /**
     * @return array language code => display name
     */
    protected function language_options() {
        return array(
            'python' => get_string('lang_python', 'qtype_codeatelier'),
            'sql' => get_string('lang_sql', 'qtype_codeatelier'),
            'javascript' => get_string('lang_javascript', 'qtype_codeatelier'),
        );
    }

    /**
     * @return array matching-mode code => display name
     */
    protected function matchmode_options() {
        return array(
            'exact' => get_string('match_exact', 'qtype_codeatelier'),
            'trimmed' => get_string('match_trimmed', 'qtype_codeatelier'),
            'regex' => get_string('match_regex', 'qtype_codeatelier'),
            'float' => get_string('match_float', 'qtype_codeatelier'),
        );
    }

    /**
     * @return array visibility value => display name
     */
    protected function visibility_options() {
        return array(
            1 => get_string('visible_shown', 'qtype_codeatelier'),
            0 => get_string('visible_hidden', 'qtype_codeatelier'),
        );
    }

    protected function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);

        if (empty($question->options->tests)) {
            return $question;
        }

        $question->testinput = array();
        $question->testexpected = array();
        $question->matchmode = array();
        $question->tolerance = array();
        $question->testweight = array();
        $question->testvisibility = array();

        foreach (array_values($question->options->tests) as $i => $test) {
            $question->testinput[$i] = $test->input;
            $question->testexpected[$i] = $test->expected;
            $question->matchmode[$i] = $test->matchmode;
            $question->tolerance[$i] = $test->tolerance;
            $question->testweight[$i] = 0 + $test->weight;
            $question->testvisibility[$i] = $test->visibility;
        }

        return $question;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $count = 0;
        foreach ($data['testinput'] as $key => $input) {
            $expected = $data['testexpected'][$key];
            if (trim($input) === '' && trim($expected) === '') {
                continue;
            }
            $count++;

            if (trim($data['testweight'][$key]) === '' || !is_numeric($data['testweight'][$key])) {
                $errors["testweight[$key]"] = get_string('weightnumeric', 'qtype_codeatelier');
            }
            if ($data['matchmode'][$key] === 'float') {
                $tolerance = $data['tolerance'][$key];
                if (trim($tolerance) === '' || !is_numeric($tolerance)) {
                    $errors["tolerance[$key]"] = get_string('tolerancenumeric', 'qtype_codeatelier');
                }
            }
        }

        if ($count === 0) {
            $errors['testinput[0]'] = get_string('notenoughtests', 'qtype_codeatelier');
        }

        return $errors;
    }

    public function qtype() {
        return 'codeatelier';
    }
}
