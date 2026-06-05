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
        $cat = $qgen->create_question_category(array('contextid' => $context->id));

        $question = $qgen->create_question('codeatelier', 'function', array('category' => $cat->id));

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
        $cat = $qgen->create_question_category(array('contextid' => $context->id));

        // A trailing wholly-blank row must not be saved.
        $question = $qgen->create_question('codeatelier', 'function', array(
            'category' => $cat->id,
            'testinput' => array('[2, 3]', '[10, 20]', ''),
            'testexpected' => array('5', '30', ''),
            'matchmode' => array('structured', 'structured', 'structured'),
            'tolerance' => array('', '', ''),
            'testweight' => array('1', '1', '1'),
            'testvisibility' => array(1, 0, 1),
            'checkerquery' => array('', '', ''),
            'testfeedback' => array('', '', ''),
        ));

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
        $cat = $qgen->create_question_category(array('contextid' => $context->id));

        // Explicit per-question limits are stored.
        $q1 = $qgen->create_question('codeatelier', 'judge', array(
            'category' => $cat->id, 'cputimelimit' => '1.5', 'memorylimit' => '64000'));
        $d1 = question_bank::load_question_data($q1->id);
        $this->assertEquals(1.5, (float) $d1->options->cputimelimit);
        $this->assertEquals(64000, (int) $d1->options->memorylimit);

        // Blank limits are stored as null (use the site default).
        $q2 = $qgen->create_question('codeatelier', 'judge', array('category' => $cat->id));
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
        $cat = $qgen->create_question_category(array('contextid' => $context->id));

        $question = $qgen->create_question('codeatelier', 'function', array(
            'category' => $cat->id,
            'matchmode' => array('resultset_ordered', 'resultset_ordered'),
        ));

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
        $cat = $qgen->create_question_category(array('contextid' => $context->id));

        $question = $qgen->create_question('codeatelier', 'multilang', array('category' => $cat->id));
        $data = question_bank::load_question_data($question->id);

        // The allowed set is stored as a comma string including the primary.
        $stored = explode(',', $data->options->languages);
        sort($stored);
        $this->assertEquals(array('c', 'cpp', 'python'), $stored);

        // Per-language starters are a JSON map, pruned to the non-primary languages.
        $starters = json_decode($data->options->starters, true);
        $this->assertArrayHasKey('c', $starters);
        $this->assertArrayHasKey('cpp', $starters);
        // The primary language's starter lives in startercode, not the map.
        $this->assertArrayNotHasKey('python', $starters);
    }

    public function test_single_language_question_stores_just_the_primary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(array('contextid' => $context->id));

        // The function fixture has an empty languages set: save still records the
        // primary so the loaded question is unambiguously single-language.
        $question = $qgen->create_question('codeatelier', 'function', array('category' => $cat->id));
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
        $cat = $qgen->create_question_category(array('contextid' => $context->id));

        $question = $qgen->create_question('codeatelier', 'judge', array('category' => $cat->id));
        $data = question_bank::load_question_data($question->id);

        $this->assertEquals('judge', $data->options->tier);
        $this->assertEquals('python', $data->options->language);
        // The judge tier is always the stdin/stdout shape.
        $this->assertEquals('stdinstdout', $data->options->scenario);
        $this->assertCount(2, $data->options->tests);
        $this->assertEquals(0, (int) array_values($data->options->tests)[1]->visibility);
    }
}
