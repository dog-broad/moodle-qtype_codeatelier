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
        return ['function', 'judge', 'multilang', 'judge_sql', 'web'];
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
        $q->languages = null;
        $q->starters = null;
        $q->tests = [
            (object) ['sortorder' => 0, 'input' => '[2, 3]', 'expected' => '5',
                'matchmode' => 'structured', 'tolerance' => null, 'weight' => 1,
                'visibility' => 1, 'checkerquery' => null, 'feedback' => 'Add the two numbers.'],
            (object) ['sortorder' => 1, 'input' => '[10, 20]', 'expected' => '30',
                'matchmode' => 'structured', 'tolerance' => null, 'weight' => 1,
                'visibility' => 0, 'checkerquery' => null, 'feedback' => null],
        ];
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
        $form->questiontext = ['text' => 'Write a function add(a, b) that returns their sum.',
            'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->defaultmark = 1.0;
        $form->penalty = 0.0;
        $form->language = 'javascript';
        $form->tier = 'practice';
        $form->scenario = 'function';
        $form->entrypoint = 'add';
        $form->startercode = "function add(a, b) {\n}\n";
        $form->schemasql = '';
        $form->testinput = ['[2, 3]', '[10, 20]'];
        $form->testexpected = ['5', '30'];
        $form->matchmode = ['structured', 'structured'];
        $form->tolerance = ['', ''];
        $form->testweight = ['1', '1'];
        $form->testvisibility = [1, 0];
        $form->checkerquery = ['', ''];
        $form->testfeedback = ['Add the two numbers.', ''];
        $form->cputimelimit = '';
        $form->memorylimit = '';
        $form->languages = [];
        $form->starters = '';
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
        $q->languages = null;
        $q->starters = null;
        $q->tests = [
            (object) ['sortorder' => 0, 'input' => "2 3\n", 'expected' => '5',
                'matchmode' => 'trimmed', 'tolerance' => null, 'weight' => 1,
                'visibility' => 1, 'checkerquery' => null, 'feedback' => null],
            (object) ['sortorder' => 1, 'input' => "10 20\n", 'expected' => '30',
                'matchmode' => 'trimmed', 'tolerance' => null, 'weight' => 1,
                'visibility' => 0, 'checkerquery' => null, 'feedback' => null],
        ];
        return $q;
    }

    /**
     * A multi-language judge question: the same "sum two integers" problem with an
     * allowed set of Python, C and C++, a per-language starter for C and C++, and
     * Python as the primary/default. Tests must inject a runner to grade.
     *
     * @return qtype_codeatelier_question
     */
    public function make_codeatelier_question_multilang() {
        $q = $this->make_codeatelier_question_judge();
        $q->name = 'CodeAtelier judge sum (multi-language)';
        $q->languages = 'python,c,cpp';
        $q->starters = json_encode([
            'c' => "#include <stdio.h>\nint main(void){return 0;}\n",
            'cpp' => "#include <iostream>\nint main(){return 0;}\n",
        ]);
        $q->referencesolution = "print(sum(map(int, input().split())))\n";
        return $q;
    }

    /**
     * Edit-form data for the multi-language judge question.
     *
     * @return stdClass
     */
    public function get_codeatelier_question_form_data_multilang() {
        $form = $this->get_codeatelier_question_form_data_judge();
        $form->name = 'CodeAtelier judge sum (multi-language)';
        $form->languages = ['python', 'c', 'cpp'];
        $form->starters = json_encode([
            'c' => "#include <stdio.h>\nint main(void){return 0;}\n",
            'cpp' => "#include <iostream>\nint main(){return 0;}\n",
        ]);
        $form->referencesolution = "print(sum(map(int, input().split())))\n";
        return $form;
    }

    /**
     * Edit-form data for a judge-tier question, saved through the normal path.
     *
     * @return stdClass
     */
    public function get_codeatelier_question_form_data_judge() {
        $form = new stdClass();
        $form->name = 'CodeAtelier judge sum';
        $form->questiontext = ['text' => 'Read two integers from input and print their sum.',
            'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->defaultmark = 1.0;
        $form->penalty = 0.0;
        $form->language = 'python';
        $form->tier = 'judge';
        $form->scenario = 'stdinstdout';
        $form->entrypoint = '';
        $form->startercode = '';
        $form->schemasql = '';
        $form->testinput = ["2 3\n", "10 20\n"];
        $form->testexpected = ['5', '30'];
        $form->matchmode = ['trimmed', 'trimmed'];
        $form->tolerance = ['', ''];
        $form->testweight = ['1', '1'];
        $form->testvisibility = [1, 0];
        $form->checkerquery = ['', ''];
        $form->testfeedback = ['', ''];
        $form->cputimelimit = '';
        $form->memorylimit = '';
        $form->languages = [];
        $form->starters = '';
        return $form;
    }

    /**
     * A judge-tier SQL question: a schema+seed and a SELECT, with one ordered
     * (visible) and one unordered (hidden) result-set test. Tests must inject a
     * runner whose canned stdout is Judge0 SQLite CSV.
     *
     * @return qtype_codeatelier_question
     */
    public function make_codeatelier_question_judge_sql() {
        question_bank::load_question_definition_classes('codeatelier');
        $q = new qtype_codeatelier_question();
        test_question_maker::initialise_a_question($q);
        $q->name = 'CodeAtelier judge SQL';
        $q->questiontext = 'Select id and name from users, ordered by id.';
        $q->generalfeedback = '';
        $q->qtype = question_bank::get_qtype('codeatelier');
        $q->language = 'sql';
        $q->tier = 'judge';
        $q->scenario = 'sql';
        $q->entrypoint = null;
        $q->startercode = "SELECT id, name FROM users ORDER BY id;\n";
        $q->schemasql = "CREATE TABLE users(id INT, name TEXT);\nINSERT INTO users VALUES (1,'amy'),(2,'bo');";
        $q->languages = null;
        $q->starters = null;
        $q->tests = [
            (object) ['sortorder' => 0, 'input' => '', 'expected' => '[[1,"amy"],[2,"bo"]]',
                'matchmode' => 'resultset_ordered', 'tolerance' => null, 'weight' => 1,
                'visibility' => 1, 'checkerquery' => null, 'feedback' => null],
            (object) ['sortorder' => 1, 'input' => '', 'expected' => '[[1,"amy"],[2,"bo"]]',
                'matchmode' => 'resultset', 'tolerance' => null, 'weight' => 1,
                'visibility' => 0, 'checkerquery' => null, 'feedback' => null],
        ];
        return $q;
    }

    /**
     * Edit-form data for the judge-tier SQL question.
     *
     * @return stdClass
     */
    public function get_codeatelier_question_form_data_judge_sql() {
        $form = new stdClass();
        $form->name = 'CodeAtelier judge SQL';
        $form->questiontext = ['text' => 'Select id and name from users, ordered by id.',
            'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->defaultmark = 1.0;
        $form->penalty = 0.0;
        $form->language = 'sql';
        $form->tier = 'judge';
        $form->scenario = 'sql';
        $form->entrypoint = '';
        $form->startercode = "SELECT id, name FROM users ORDER BY id;\n";
        $form->schemasql = "CREATE TABLE users(id INT, name TEXT);\nINSERT INTO users VALUES (1,'amy'),(2,'bo');";
        $form->testinput = ['', ''];
        $form->testexpected = ['[[1,"amy"],[2,"bo"]]', '[[1,"amy"],[2,"bo"]]'];
        $form->matchmode = ['resultset_ordered', 'resultset'];
        $form->tolerance = ['', ''];
        $form->testweight = ['1', '1'];
        $form->testvisibility = [1, 0];
        $form->checkerquery = ['', ''];
        $form->testfeedback = ['', ''];
        $form->cputimelimit = '';
        $form->memorylimit = '';
        $form->languages = [];
        $form->starters = '';
        return $form;
    }

    /**
     * A web (frontend) practice question instance with two DOM assertions.
     *
     * @return qtype_codeatelier_question
     */
    public function make_codeatelier_question_web() {
        question_bank::load_question_definition_classes('codeatelier');
        $q = new qtype_codeatelier_question();
        test_question_maker::initialise_a_question($q);
        $q->name = 'CodeAtelier web greeting';
        $q->questiontext = 'Make the heading say Hi and the button reveal a message.';
        $q->generalfeedback = '';
        $q->qtype = question_bank::get_qtype('codeatelier');
        $q->language = 'javascript';
        $q->tier = 'practice';
        $q->scenario = 'web';
        $q->entrypoint = null;
        $q->startercode = '';
        $q->schemasql = null;
        $q->languages = null;
        $q->starters = null;
        $q->projectfiles = json_encode([
            ['name' => 'index.html', 'content' => "<h1 id=\"t\">Hi</h1>\n"],
        ]);
        $q->tests = [
            (object) ['sortorder' => 0, 'input' => '', 'expected' => 'Hi',
                'matchmode' => 'trimmed', 'tolerance' => null, 'weight' => 1, 'visibility' => 1,
                'checkerquery' => null, 'feedback' => null, 'selector' => '#t',
                'assertproperty' => 'text', 'assertmatcher' => 'equals', 'actions' => null],
            (object) ['sortorder' => 1, 'input' => '', 'expected' => 'ok',
                'matchmode' => 'trimmed', 'tolerance' => null, 'weight' => 2, 'visibility' => 0,
                'checkerquery' => null, 'feedback' => null, 'selector' => '#o',
                'assertproperty' => 'text', 'assertmatcher' => 'equals', 'actions' => 'click #b'],
        ];
        return $q;
    }

    /**
     * A web (frontend) practice question: an HTML/CSS/JS starter file set and two
     * DOM assertions (a visible text check and a hidden click-then-check).
     *
     * @return stdClass form data for the generator
     */
    public function get_codeatelier_question_form_data_web() {
        $form = new stdClass();
        $form->name = 'CodeAtelier web greeting';
        $form->questiontext = ['text' => 'Make the heading say Hi and the button reveal a message.',
            'format' => FORMAT_HTML];
        $form->generalfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $form->defaultmark = 1.0;
        $form->penalty = 0.0;
        $form->language = 'javascript';
        $form->tier = 'practice';
        $form->scenario = 'web';
        $form->entrypoint = '';
        $form->startercode = '';
        $form->schemasql = '';
        $form->projectfiles = json_encode([
            ['name' => 'index.html', 'content' => "<h1 id=\"t\">Hi</h1>\n<button id=\"b\">Go</button>\n<p id=\"o\"></p>\n"],
            ['name' => 'script.js', 'content' => "document.getElementById('b').onclick=function(){document.getElementById('o').textContent='ok';};\n"],
        ]);
        $form->entryfile = '';
        $form->selector = ['#t', '#o'];
        $form->assertproperty = ['text', 'text'];
        $form->assertmatcher = ['equals', 'equals'];
        $form->testexpected = ['Hi', 'ok'];
        $form->actions = ['', 'click #b'];
        $form->testweight = ['1', '2'];
        $form->testvisibility = [1, 0];
        $form->testfeedback = ['', ''];
        $form->testinput = ['', ''];
        $form->matchmode = ['trimmed', 'trimmed'];
        $form->tolerance = ['', ''];
        $form->checkerquery = ['', ''];
        $form->cputimelimit = '';
        $form->memorylimit = '';
        $form->languages = [];
        $form->starters = '';
        return $form;
    }
}
