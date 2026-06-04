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
 * Test helper providing CodeAtelier question fixtures.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Test fixtures for the CodeAtelier question type.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_test_helper extends question_test_helper {

    public function get_test_questions() {
        return array('function', 'judge');
    }

    /**
     * A runtime question: a JavaScript function-scenario "add(a, b)" with one
     * visible and one hidden test case.
     *
     * @return qtype_codeatelier_question
     */
    public function make_codeatelier_question_function() {
        question_bank::load_question_definition_classes('codeatelier');
        $q = new qtype_codeatelier_question();
        test_question_maker::initialise_a_question($q);
        $q->name = 'CodeAtelier add';
        $q->questiontext = 'Write a function add(a, b) that returns their sum.';
        $q->generalfeedback = '';
        $q->qtype = question_bank::get_qtype('codeatelier');
        $q->language = 'javascript';
        $q->tier = 'practice';
        $q->scenario = 'function';
        $q->entrypoint = 'add';
        $q->startercode = "function add(a, b) {\n}\n";
        $q->schemasql = null;
        $q->tests = array(
            (object) array('sortorder' => 0, 'input' => '[2, 3]', 'expected' => '5',
                'matchmode' => 'structured', 'tolerance' => null, 'weight' => 1,
                'visibility' => 1, 'checkerquery' => null, 'feedback' => 'Add the two numbers.'),
            (object) array('sortorder' => 1, 'input' => '[10, 20]', 'expected' => '30',
                'matchmode' => 'structured', 'tolerance' => null, 'weight' => 1,
                'visibility' => 0, 'checkerquery' => null, 'feedback' => null),
        );
        return $q;
    }

    /**
     * Edit-form data for the same question, used by the core question generator
     * to save a question through the normal save path.
     *
     * @return stdClass
     */
    public function get_codeatelier_question_form_data_function() {
        $form = new stdClass();
        $form->name = 'CodeAtelier add';
        $form->questiontext = array('text' => 'Write a function add(a, b) that returns their sum.',
            'format' => FORMAT_HTML);
        $form->generalfeedback = array('text' => '', 'format' => FORMAT_HTML);
        $form->defaultmark = 1.0;
        $form->penalty = 0.0;
        $form->language = 'javascript';
        $form->tier = 'practice';
        $form->scenario = 'function';
        $form->entrypoint = 'add';
        $form->startercode = "function add(a, b) {\n}\n";
        $form->schemasql = '';
        $form->testinput = array('[2, 3]', '[10, 20]');
        $form->testexpected = array('5', '30');
        $form->matchmode = array('structured', 'structured');
        $form->tolerance = array('', '');
        $form->testweight = array('1', '1');
        $form->testvisibility = array(1, 0);
        $form->checkerquery = array('', '');
        $form->testfeedback = array('Add the two numbers.', '');
        $form->cputimelimit = '';
        $form->memorylimit = '';
        return $form;
    }

    /**
     * A judge-tier runtime question: a Python stdin/stdout "sum two integers"
     * with one visible and one hidden test. Tests must inject a runner.
     *
     * @return qtype_codeatelier_question
     */
    public function make_codeatelier_question_judge() {
        question_bank::load_question_definition_classes('codeatelier');
        $q = new qtype_codeatelier_question();
        test_question_maker::initialise_a_question($q);
        $q->name = 'CodeAtelier judge sum';
        $q->questiontext = 'Read two integers from input and print their sum.';
        $q->generalfeedback = '';
        $q->qtype = question_bank::get_qtype('codeatelier');
        $q->language = 'python';
        $q->tier = 'judge';
        $q->scenario = 'stdinstdout';
        $q->entrypoint = null;
        $q->startercode = '';
        $q->schemasql = null;
        $q->tests = array(
            (object) array('sortorder' => 0, 'input' => "2 3\n", 'expected' => '5',
                'matchmode' => 'trimmed', 'tolerance' => null, 'weight' => 1,
                'visibility' => 1, 'checkerquery' => null, 'feedback' => null),
            (object) array('sortorder' => 1, 'input' => "10 20\n", 'expected' => '30',
                'matchmode' => 'trimmed', 'tolerance' => null, 'weight' => 1,
                'visibility' => 0, 'checkerquery' => null, 'feedback' => null),
        );
        return $q;
    }

    /**
     * Edit-form data for a judge-tier question, saved through the normal path.
     *
     * @return stdClass
     */
    public function get_codeatelier_question_form_data_judge() {
        $form = new stdClass();
        $form->name = 'CodeAtelier judge sum';
        $form->questiontext = array('text' => 'Read two integers from input and print their sum.',
            'format' => FORMAT_HTML);
        $form->generalfeedback = array('text' => '', 'format' => FORMAT_HTML);
        $form->defaultmark = 1.0;
        $form->penalty = 0.0;
        $form->language = 'python';
        $form->tier = 'judge';
        $form->scenario = 'stdinstdout';
        $form->entrypoint = '';
        $form->startercode = '';
        $form->schemasql = '';
        $form->testinput = array("2 3\n", "10 20\n");
        $form->testexpected = array('5', '30');
        $form->matchmode = array('trimmed', 'trimmed');
        $form->tolerance = array('', '');
        $form->testweight = array('1', '1');
        $form->testvisibility = array(1, 0);
        $form->checkerquery = array('', '');
        $form->testfeedback = array('', '');
        $form->cputimelimit = '';
        $form->memorylimit = '';
        return $form;
    }
}
