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
        $mform->addElement('select', 'tier', get_string('tier', 'qtype_codeatelier'),
                $this->tier_options());
        $mform->addHelpButton('tier', 'tier', 'qtype_codeatelier');
        $mform->setDefault('tier', $this->get_default_value('tier', 'practice'));

        $mform->addElement('select', 'language', get_string('language', 'qtype_codeatelier'),
                $this->language_options());
        $mform->addHelpButton('language', 'language', 'qtype_codeatelier');
        $mform->setDefault('language', $this->get_default_value('language', 'python'));

        $mform->addElement('select', 'scenario', get_string('scenario', 'qtype_codeatelier'),
                $this->scenario_options());
        $mform->addHelpButton('scenario', 'scenario', 'qtype_codeatelier');
        $mform->setDefault('scenario', $this->get_default_value('scenario', 'stdinstdout'));
        // The judge tier is always the stdin/stdout shape — see the question type.
        $mform->hideIf('scenario', 'tier', 'eq', 'judge');

        $mform->addElement('text', 'entrypoint', get_string('entrypoint', 'qtype_codeatelier'),
                array('size' => 40));
        $mform->setType('entrypoint', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('entrypoint', 'entrypoint', 'qtype_codeatelier');
        $mform->hideIf('entrypoint', 'scenario', 'neq', 'function');

        $mform->addElement('textarea', 'startercode', get_string('startercode', 'qtype_codeatelier'),
                array('rows' => 6, 'cols' => 60, 'spellcheck' => 'false'));
        $mform->setType('startercode', PARAM_RAW);
        $mform->addHelpButton('startercode', 'startercode', 'qtype_codeatelier');

        $mform->addElement('textarea', 'schemasql', get_string('schemasql', 'qtype_codeatelier'),
                array('rows' => 6, 'cols' => 60, 'spellcheck' => 'false'));
        $mform->setType('schemasql', PARAM_RAW);
        $mform->addHelpButton('schemasql', 'schemasql', 'qtype_codeatelier');
        $mform->hideIf('schemasql', 'scenario', 'neq', 'sql');

        $mform->addElement('header', 'testcasesheader', get_string('testcases', 'qtype_codeatelier'));
        $mform->setExpanded('testcasesheader');

        $this->add_test_case_fields($mform);

        $this->add_preview_section($mform);

        $this->add_interactive_settings();
    }

    /**
     * Add the live-preview section: a refresh button and the pane the JS renders
     * the real student workbench into.
     *
     * @param MoodleQuickForm $mform the form being built
     */
    protected function add_preview_section($mform) {
        global $PAGE;

        $mform->addElement('header', 'previewheader', get_string('preview', 'qtype_codeatelier'));
        $mform->setExpanded('previewheader', false);

        $previewid = 'codeatelier_preview';
        $refresh = html_writer::tag('button', get_string('previewrefresh', 'qtype_codeatelier'),
                array('type' => 'button', 'class' => 'btn btn-secondary', 'id' => $previewid . '_btn'));
        $capture = html_writer::tag('button', get_string('captureexpected', 'qtype_codeatelier'),
                array('type' => 'button', 'class' => 'btn btn-secondary ml-2', 'id' => $previewid . '_capture'));
        $status = html_writer::tag('span', '', array('class' => 'codeatelier-status ml-2',
                'id' => $previewid . '_status', 'role' => 'status', 'aria-live' => 'polite'));
        $pane = html_writer::div('', 'codeatelier-preview', array('id' => $previewid));
        $mform->addElement('static', 'codeatelier_previewarea', '',
                html_writer::tag('p', get_string('previewintro', 'qtype_codeatelier'), array('class' => 'text-muted'))
                . html_writer::tag('p', get_string('captureexpected_help', 'qtype_codeatelier'),
                        array('class' => 'text-muted'))
                . $refresh . $capture . $status . $pane);

        $PAGE->requires->js_call_amd('qtype_codeatelier/author', 'init', array(array(
            'languageid' => 'id_language',
            'scenarioid' => 'id_scenario',
            'entrypointid' => 'id_entrypoint',
            'startercodeid' => 'id_startercode',
            'schemasqlid' => 'id_schemasql',
            'questiontextid' => 'id_questiontext',
            'previewbtnid' => $previewid . '_btn',
            'captureid' => $previewid . '_capture',
            'statusid' => $previewid . '_status',
            'previewid' => $previewid,
            'previewcontext' => qtype_codeatelier::ui_labels(),
            'strings' => qtype_codeatelier::ui_strings(),
            'pyodidebaseurl' => get_config('qtype_codeatelier', 'pyodidebaseurl'),
            'sqljsbaseurl' => get_config('qtype_codeatelier', 'sqljsbaseurl'),
            'timeoutms' => 5000,
        )));
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
        $repeated[] = $mform->createElement('textarea', 'checkerquery',
                get_string('checkerquery', 'qtype_codeatelier'), array('rows' => 2, 'cols' => 60));
        $repeated[] = $mform->createElement('textarea', 'testfeedback',
                get_string('testfeedback', 'qtype_codeatelier'), array('rows' => 2, 'cols' => 60));

        // Note: do not set a 'default' for the matchmode/testweight/testvisibility
        // elements here. For repeated elements a default overrides the values
        // loaded by data_preprocessing(), so an edited question would silently
        // reset them. The selects fall back to their first option for new rows;
        // weight is author-entered and checked in validation().
        $repeatedoptions = array();
        $repeatedoptions['testinput']['type'] = PARAM_RAW;
        $repeatedoptions['testexpected']['type'] = PARAM_RAW;
        $repeatedoptions['matchmode']['type'] = PARAM_ALPHANUMEXT;
        $repeatedoptions['tolerance']['type'] = PARAM_RAW_TRIMMED;
        $repeatedoptions['tolerance']['disabledif'] = array('matchmode', 'neq', 'float');
        $repeatedoptions['testweight']['type'] = PARAM_RAW_TRIMMED;
        $repeatedoptions['testvisibility']['type'] = PARAM_INT;
        $repeatedoptions['checkerquery']['type'] = PARAM_RAW;
        $repeatedoptions['checkerquery']['hideif'] = array('scenario', 'neq', 'sql');
        $repeatedoptions['testfeedback']['type'] = PARAM_RAW;

        if (!empty($this->question->options->tests)) {
            $repeats = count($this->question->options->tests);
        } else {
            $repeats = 2;
        }

        $this->repeat_elements($repeated, $repeats, $repeatedoptions, 'option_repeats',
                'option_add_tests', 1, get_string('addmoretestcases', 'qtype_codeatelier'), true);
    }

    /**
     * @return array scenario code => display name
     */
    protected function scenario_options() {
        return array(
            'stdinstdout' => get_string('scenario_stdinstdout', 'qtype_codeatelier'),
            'function' => get_string('scenario_function', 'qtype_codeatelier'),
            'sql' => get_string('scenario_sql', 'qtype_codeatelier'),
        );
    }

    /**
     * @return array tier code => display name
     */
    protected function tier_options() {
        return array(
            'practice' => get_string('tier_practice', 'qtype_codeatelier'),
            'judge' => get_string('tier_judge', 'qtype_codeatelier'),
        );
    }

    /**
     * The full language set. Practice supports python/sql/javascript; judge
     * supports c/cpp/java/python/javascript. The chosen tier/language pair is
     * checked in {@see validation()}.
     *
     * @return array language code => display name
     */
    protected function language_options() {
        return array(
            'python' => get_string('lang_python', 'qtype_codeatelier'),
            'sql' => get_string('lang_sql', 'qtype_codeatelier'),
            'javascript' => get_string('lang_javascript', 'qtype_codeatelier'),
            'c' => get_string('lang_c', 'qtype_codeatelier'),
            'cpp' => get_string('lang_cpp', 'qtype_codeatelier'),
            'java' => get_string('lang_java', 'qtype_codeatelier'),
        );
    }

    /** @var array languages that run in the browser (practice tier). */
    const PRACTICE_LANGUAGES = array('python', 'sql', 'javascript');

    /** @var array languages that run in the server sandbox (judge tier). */
    const JUDGE_LANGUAGES = array('c', 'cpp', 'java', 'python', 'javascript');

    /**
     * @return array matching-mode code => display name
     */
    protected function matchmode_options() {
        return array(
            'exact' => get_string('match_exact', 'qtype_codeatelier'),
            'trimmed' => get_string('match_trimmed', 'qtype_codeatelier'),
            'regex' => get_string('match_regex', 'qtype_codeatelier'),
            'float' => get_string('match_float', 'qtype_codeatelier'),
            'structured' => get_string('match_structured', 'qtype_codeatelier'),
            'resultset' => get_string('match_resultset', 'qtype_codeatelier'),
            'resultset_ordered' => get_string('match_resultset_ordered', 'qtype_codeatelier'),
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
        $question->checkerquery = array();
        $question->testfeedback = array();

        foreach (array_values($question->options->tests) as $i => $test) {
            $question->testinput[$i] = $test->input;
            $question->testexpected[$i] = $test->expected;
            $question->matchmode[$i] = $test->matchmode;
            $question->tolerance[$i] = $test->tolerance;
            $question->testweight[$i] = 0 + $test->weight;
            $question->testvisibility[$i] = $test->visibility;
            $question->checkerquery[$i] = $test->checkerquery;
            $question->testfeedback[$i] = $test->feedback;
        }

        return $question;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $tier = isset($data['tier']) ? $data['tier'] : 'practice';
        if ($tier === 'judge' && !in_array($data['language'], self::JUDGE_LANGUAGES, true)) {
            $errors['language'] = get_string('languagenotjudge', 'qtype_codeatelier');
        }
        if ($tier === 'practice' && !in_array($data['language'], self::PRACTICE_LANGUAGES, true)) {
            $errors['language'] = get_string('languagenotpractice', 'qtype_codeatelier');
        }

        // The judge tier is always stdin/stdout, so the entrypoint is only
        // required for a practice-tier function question.
        if ($tier !== 'judge' && $data['scenario'] === 'function' && trim($data['entrypoint']) === '') {
            $errors['entrypoint'] = get_string('entrypointrequired', 'qtype_codeatelier');
        }

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
