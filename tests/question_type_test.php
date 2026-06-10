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

namespace qtype_codeatelier;

use question_bank;

/**
 * Save/load tests for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier
 */
final class question_type_test extends \advanced_testcase {
    public function test_save_and_load_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $question = $qgen->create_question('codeatelier', 'function', ['category' => $cat->id]);

        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('javascript', $data->options->language);
        $this->assertEquals('practice', $data->options->tier);
        $this->assertEquals('function', $data->options->scenario);
        $this->assertEquals('add', $data->options->entrypoint);
        $this->assertStringContainsString('function add', $data->options->startercode);

        $tests = array_values($data->options->tests);
        $this->assertCount(2, $tests);

        $this->assertEquals('[2, 3]', $tests[0]->input);
        $this->assertEquals('5', $tests[0]->expected);
        $this->assertEquals('structured', $tests[0]->matchmode);
        $this->assertEquals(1, (int) $tests[0]->visibility);
        $this->assertEquals('Add the two numbers.', $tests[0]->feedback);

        $this->assertEquals('[10, 20]', $tests[1]->input);
        $this->assertEquals(0, (int) $tests[1]->visibility);
    }

    public function test_blank_test_rows_are_skipped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // A trailing wholly-blank row must not be saved.
        $question = $qgen->create_question('codeatelier', 'function', [
            'category' => $cat->id,
            'testinput' => ['[2, 3]', '[10, 20]', ''],
            'testexpected' => ['5', '30', ''],
            'matchmode' => ['structured', 'structured', 'structured'],
            'tolerance' => ['', '', ''],
            'testweight' => ['1', '1', '1'],
            'testvisibility' => [1, 0, 1],
            'checkerquery' => ['', '', ''],
            'testfeedback' => ['', '', ''],
        ]);

        $data = question_bank::load_question_data($question->id);
        $this->assertCount(2, $data->options->tests);
    }

    public function test_per_question_limits_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // Explicit per-question limits are stored.
        $q1 = $qgen->create_question('codeatelier', 'judge', [
            'category' => $cat->id, 'cputimelimit' => '1.5', 'memorylimit' => '64000']);
        $d1 = question_bank::load_question_data($q1->id);
        $this->assertEquals(1.5, (float) $d1->options->cputimelimit);
        $this->assertEquals(64000, (int) $d1->options->memorylimit);

        // Blank limits are stored as null (use the site default).
        $q2 = $qgen->create_question('codeatelier', 'judge', ['category' => $cat->id]);
        $d2 = question_bank::load_question_data($q2->id);
        $this->assertNull($d2->options->cputimelimit);
        $this->assertNull($d2->options->memorylimit);
    }

    public function test_resultset_ordered_matchmode_round_trips(): void {
        // Regression: 'resultset_ordered' is 17 chars and overflowed the original
        // char(16) matchmode column (a DB error on PostgreSQL, truncation on MySQL).
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $question = $qgen->create_question('codeatelier', 'function', [
            'category' => $cat->id,
            'matchmode' => ['resultset_ordered', 'resultset_ordered'],
        ]);

        $data = question_bank::load_question_data($question->id);
        $this->assertEquals('resultset_ordered', array_values($data->options->tests)[0]->matchmode);
    }

    public function test_languages_and_starters_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $question = $qgen->create_question('codeatelier', 'multilang', ['category' => $cat->id]);
        $data = question_bank::load_question_data($question->id);

        // The allowed set is stored as a comma string including the primary.
        $stored = explode(',', $data->options->languages);
        sort($stored);
        $this->assertEquals(['c', 'cpp', 'python'], $stored);

        // Per-language starters are a JSON map, pruned to the non-primary languages.
        $starters = json_decode($data->options->starters, true);
        $this->assertArrayHasKey('c', $starters);
        $this->assertArrayHasKey('cpp', $starters);
        // The primary language's starter lives in startercode, not the map.
        $this->assertArrayNotHasKey('python', $starters);
    }

    public function test_xml_export_import_round_trip(): void {
        global $CFG;
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // A multi-language judge question carries the awkward fields: a language set,
        // a per-language starter map, and a reference solution.
        $question = $qgen->create_question('codeatelier', 'multilang', ['category' => $cat->id]);
        $questiondata = question_bank::load_question_data($question->id);

        $qtype = question_bank::get_qtype('codeatelier');
        $format = new \qformat_xml();
        $fragment = $qtype->export_to_xml($questiondata, $format);
        $this->assertStringContainsString('<referencesolution>', $fragment);
        $this->assertStringContainsString('<testcase>', $fragment);

        // Re-import the exported fragment (wrapped as a question element).
        $xml = '<question type="codeatelier">'
                . '<name><text>RT</text></name>'
                . '<questiontext format="html"><text>q</text></questiontext>'
                . $fragment . '</question>';
        $data = \xmlize($xml);
        $qo = $qtype->import_from_xml($data['question'], null, $format);

        $this->assertEquals('judge', $qo->tier);
        $this->assertEquals('python', $qo->language);
        $this->assertEquals('stdinstdout', $qo->scenario);
        // The allowed set survives as an ARRAY (not a collapsed comma string).
        $langs = $qo->languages;
        sort($langs);
        $this->assertEquals(['c', 'cpp', 'python'], $langs);
        // The per-language starters and the reference solution survive.
        $starters = json_decode($qo->starters, true);
        $this->assertArrayHasKey('c', $starters);
        $this->assertArrayHasKey('cpp', $starters);
        $this->assertStringContainsString('input().split()', $qo->referencesolution);
        // The test cases round-trip.
        $this->assertCount(2, $qo->testinput);
        $this->assertEquals('5', $qo->testexpected[0]);
        $this->assertEquals('trimmed', $qo->matchmode[0]);
        $this->assertEquals(0, (int) $qo->testvisibility[1]);
    }

    /**
     * Create a draft file area containing one file, returning its item id.
     *
     * @param string $filename
     * @param string $content
     * @return int the draft item id
     */
    private function draft_with_file(string $filename, string $content): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        $record = (object) [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftid,
            'filepath' => '/', 'filename' => $filename,
        ];
        get_file_storage()->create_file_from_string($record, $content);
        return $draftid;
    }

    public function test_data_files_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $draftid = $this->draft_with_file('data.txt', "hello world\n");
        $question = $qgen->create_question(
            'codeatelier',
            'judge',
            ['category' => $cat->id, 'datafiles' => $draftid]
        );

        // The uploaded file lands in the question's datafiles area.
        $files = get_file_storage()->get_area_files(
            $context->id,
            'qtype_codeatelier',
            'datafiles',
            $question->id,
            'filename',
            false
        );
        $this->assertCount(1, $files);
        $file = reset($files);
        $this->assertEquals('data.txt', $file->get_filename());
        $this->assertEquals("hello world\n", $file->get_content());
    }

    public function test_new_language_and_all_or_nothing_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // A judge question in Go with an additional Rust option and all-or-nothing on.
        $question = $qgen->create_question('codeatelier', 'judge', [
            'category' => $cat->id, 'language' => 'go',
            'languages' => ['go', 'rust'], 'allornothing' => 1]);
        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('go', $data->options->language);
        $this->assertEquals(1, (int) $data->options->allornothing);
        $langs = explode(',', $data->options->languages);
        sort($langs);
        $this->assertEquals(['go', 'rust'], $langs);
    }

    public function test_checker_columns_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // A judge stdin/stdout question with a Python custom checker.
        $checker = "import sys\nprint('AC')\n";
        $question = $qgen->create_question('codeatelier', 'judge', [
            'category' => $cat->id, 'language' => 'cpp',
            'checkersource' => $checker, 'checkerlang' => 'python']);
        $data = question_bank::load_question_data($question->id);

        $this->assertSame($checker, $data->options->checkersource);
        $this->assertSame('python', $data->options->checkerlang);
    }

    public function test_subtask_column_round_trips(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // The judge fixture has two tests; group them into subtask 1.
        $question = $qgen->create_question('codeatelier', 'judge', [
            'category' => $cat->id, 'subtask' => [1, 1]]);
        $data = question_bank::load_question_data($question->id);

        $tests = array_values($data->options->tests);
        $this->assertSame(1, (int) $tests[0]->subtask);
        $this->assertSame(1, (int) $tests[1]->subtask);
    }

    public function test_blank_checker_source_clears_both_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // A stray checker language with no source must not look like a checker.
        $question = $qgen->create_question('codeatelier', 'judge', [
            'category' => $cat->id, 'language' => 'cpp',
            'checkersource' => '', 'checkerlang' => 'python']);
        $data = question_bank::load_question_data($question->id);

        $this->assertNull($data->options->checkersource);
        $this->assertNull($data->options->checkerlang);
    }

    public function test_single_language_question_stores_just_the_primary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        // The function fixture has an empty languages set: save still records the
        // primary so the loaded question is unambiguously single-language.
        $question = $qgen->create_question('codeatelier', 'function', ['category' => $cat->id]);
        $data = question_bank::load_question_data($question->id);
        $this->assertEquals('javascript', $data->options->languages);
        $this->assertEmpty($data->options->starters);
    }

    public function test_judge_question_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $question = $qgen->create_question('codeatelier', 'judge', ['category' => $cat->id]);
        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('judge', $data->options->tier);
        $this->assertEquals('python', $data->options->language);
        // A judge stdin/stdout question keeps its scenario.
        $this->assertEquals('stdinstdout', $data->options->scenario);
        $this->assertCount(2, $data->options->tests);
        $this->assertEquals(0, (int) array_values($data->options->tests)[1]->visibility);
    }

    public function test_judge_sql_scenario_round_trips(): void {
        // The judge tier no longer forces stdin/stdout: a judge SQL question keeps
        // scenario=sql and its schema+seed through save/load.
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $question = $qgen->create_question('codeatelier', 'judge_sql', ['category' => $cat->id]);
        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('judge', $data->options->tier);
        $this->assertEquals('sql', $data->options->language);
        $this->assertEquals('sql', $data->options->scenario);
        $this->assertStringContainsString('CREATE TABLE users', $data->options->schemasql);
        $tests = array_values($data->options->tests);
        $this->assertCount(2, $tests);
        $this->assertEquals('resultset_ordered', $tests[0]->matchmode);
        $this->assertEquals(0, (int) $tests[1]->visibility);
    }

    public function test_project_scenario_round_trips(): void {
        // A multi-file project stores its scenario, entry file and the starter file
        // set (canonical JSON) through save/load.
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $projectfiles = json_encode([
            ['name' => 'main.cpp', 'content' => "#include \"helper.h\"\nint main(){}\n"],
            ['name' => 'helper.h', 'content' => "int add(int,int);\n"],
        ]);
        $question = $qgen->create_question('codeatelier', 'judge', [
            'category' => $cat->id, 'scenario' => 'project', 'language' => 'cpp',
            'projectfiles' => $projectfiles, 'entryfile' => 'main.cpp',
        ]);
        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('judge', $data->options->tier);
        $this->assertEquals('project', $data->options->scenario);
        $this->assertEquals('cpp', $data->options->language);
        $this->assertEquals('main.cpp', $data->options->entryfile);
        // The file set round-trips as the same canonical set.
        $files = \qtype_codeatelier\judge\project_packager::decode_files($data->options->projectfiles);
        $this->assertSame(['main.cpp', 'helper.h'], array_keys($files));
        $this->assertStringContainsString('int add(int,int);', $files['helper.h']);
    }

    public function test_web_scenario_round_trips(): void {
        // A web (frontend) practice question stores its scenario and the HTML/CSS/JS
        // starter file set (canonical JSON) through save/load, with no test cases.
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $webfiles = json_encode([
            ['name' => 'index.html', 'content' => "<h1 id=\"t\">Hi</h1>\n"],
            ['name' => 'style.css', 'content' => "#t { color: red; }\n"],
            ['name' => 'script.js', 'content' => "document.title = 'hi';\n"],
        ]);
        $question = $qgen->create_question('codeatelier', 'judge', [
            'category' => $cat->id, 'tier' => 'practice', 'scenario' => 'web',
            'language' => 'javascript', 'projectfiles' => $webfiles,
        ]);
        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('practice', $data->options->tier);
        $this->assertEquals('web', $data->options->scenario);
        // The file set round-trips as the same canonical set.
        $files = \qtype_codeatelier\judge\project_packager::decode_files($data->options->projectfiles);
        $this->assertSame(['index.html', 'style.css', 'script.js'], array_keys($files));
        $this->assertStringContainsString('color: red', $files['style.css']);
    }

    public function test_web_assertion_round_trips(): void {
        // A web question's DOM assertions (selector, property, matcher, actions)
        // round-trip through save/load on the test-case table.
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);

        $question = $qgen->create_question('codeatelier', 'web', ['category' => $cat->id]);
        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('web', $data->options->scenario);
        $tests = array_values($data->options->tests);
        $this->assertCount(2, $tests);
        // A visible text assertion.
        $this->assertEquals('#t', $tests[0]->selector);
        $this->assertEquals('text', $tests[0]->assertproperty);
        $this->assertEquals('equals', $tests[0]->assertmatcher);
        $this->assertEquals('Hi', $tests[0]->expected);
        $this->assertEquals(1, (int) $tests[0]->visibility);
        // A hidden assertion that runs a click action first.
        $this->assertEquals('#o', $tests[1]->selector);
        $this->assertEquals('click #b', trim((string) $tests[1]->actions));
        $this->assertEquals(0, (int) $tests[1]->visibility);
    }
}
