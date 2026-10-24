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

use backup;
use backup_controller;
use restore_controller;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Course backup + restore round-trip for CodeAtelier questions.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_qtype_codeatelier_plugin
 * @covers     \restore_qtype_codeatelier_plugin
 */
final class backup_test extends \advanced_testcase {
    public function test_question_survives_backup_and_restore(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);
        $qgen->create_question('codeatelier', 'function', ['category' => $cat->id]);

        $this->assertEquals(1, $DB->count_records('qtype_codeatelier_options'));
        $this->assertEquals(2, $DB->count_records('qtype_codeatelier_tests'));

        // Back the course up (kept in a temp folder via MODE_IMPORT).
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        // Restore into a brand-new course.
        $newcourse = $generator->create_course();
        $rc = new restore_controller(
            $backupid,
            $newcourse->id,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        // The plugin rows are now duplicated: the originals plus the restored copy.
        $this->assertEquals(2, $DB->count_records('qtype_codeatelier_options'));
        $this->assertEquals(4, $DB->count_records('qtype_codeatelier_tests'));

        // The restored options carry the same field values.
        $newcontext = \context_course::instance($newcourse->id);
        $restored = $this->find_restored_options($newcontext->id);
        $this->assertNotNull($restored, 'Restored CodeAtelier options not found in the new course.');
        $this->assertEquals('javascript', $restored->language);
        $this->assertEquals('function', $restored->scenario);
        $this->assertEquals('add', $restored->entrypoint);

        $tests = $DB->get_records(
            'qtype_codeatelier_tests',
            ['questionid' => $restored->questionid],
            'sortorder ASC'
        );
        $this->assertCount(2, $tests);
        $tests = array_values($tests);
        $this->assertEquals('5', $tests[0]->expected);
        $this->assertEquals('Add the two numbers.', $tests[0]->feedback);
        $this->assertEquals(0, (int) $tests[1]->visibility);
    }

    public function test_judge_tier_survives_backup_and_restore(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);
        $qgen->create_question('codeatelier', 'judge', ['category' => $cat->id]);

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourse = $generator->create_course();
        $rc = new restore_controller(
            $backupid,
            $newcourse->id,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newcontext = \context_course::instance($newcourse->id);
        $restored = $this->find_restored_options($newcontext->id);
        $this->assertNotNull($restored);
        // The tier must survive: otherwise a secure judge question silently becomes
        // an in-browser practice question after a course copy.
        $this->assertEquals('judge', $restored->tier);
        $this->assertEquals('python', $restored->language);
    }

    public function test_languages_starters_and_limits_survive_backup_and_restore(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);
        // A multi-language judge question with explicit per-question limits,
        // all-or-nothing grading, and a support/data file.
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string(
            (object) [
                'contextid' => \context_user::instance($USER->id)->id, 'component' => 'user',
                'filearea' => 'draft', 'itemid' => $draftid, 'filepath' => '/', 'filename' => 'fixture.txt'],
            "fixture data\n"
        );
        $qgen->create_question('codeatelier', 'multilang', [
                'category' => $cat->id, 'cputimelimit' => '1.5', 'memorylimit' => '64000',
                'allornothing' => 1, 'datafiles' => $draftid]);

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourse = $generator->create_course();
        $rc = new restore_controller(
            $backupid,
            $newcourse->id,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newcontext = \context_course::instance($newcourse->id);
        $restored = $this->find_restored_options($newcontext->id);
        $this->assertNotNull($restored);

        // The allowed set and per-language starters must survive a course copy...
        $langs = explode(',', $restored->languages);
        sort($langs);
        $this->assertEquals(['c', 'cpp', 'python'], $langs);
        $starters = json_decode($restored->starters, true);
        $this->assertArrayHasKey('c', $starters);
        $this->assertArrayHasKey('cpp', $starters);
        // ...as must the per-question limits (previously dropped by the backup step)...
        $this->assertEquals(1.5, (float) $restored->cputimelimit);
        $this->assertEquals(64000, (int) $restored->memorylimit);
        // ...and the reference solution...
        $this->assertStringContainsString('input().split()', $restored->referencesolution);
        // ...and the all-or-nothing flag...
        $this->assertEquals(1, (int) $restored->allornothing);
        // ...and the support/data file (restored into the new course's context).
        $datafiles = get_file_storage()->get_area_files(
            $newcontext->id,
            'qtype_codeatelier',
            'datafiles',
            $restored->questionid,
            'filename',
            false
        );
        $this->assertCount(1, $datafiles);
        $this->assertEquals('fixture.txt', reset($datafiles)->get_filename());
    }

    public function test_project_files_survive_backup_and_restore(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);
        $qgen = $generator->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => $context->id]);
        $projectfiles = json_encode([
            'version' => 2,
            'envs' => [
                'cpp' => [
                    'entry' => 'main.cpp',
                    'files' => [
                        [
                            'name' => 'main.cpp',
                            'starter' => "#include <iostream>\n#include \"helper.h\"\n"
                                . "int main() {\n    int a, b;\n    std::cin >> a >> b;\n"
                                . "    // TODO: print the sum\n    return 0;\n}\n",
                            'solution' => "#include <iostream>\n#include \"helper.h\"\n"
                                . "int main() {\n    int a, b;\n    std::cin >> a >> b;\n"
                                . "    std::cout << add(a, b) << std::endl;\n    return 0;\n}\n",
                        ],
                        [
                            'name' => 'helper.h',
                            'starter' => "#pragma once\nint add(int a, int b);\n",
                            'solution' => "#pragma once\nint add(int a, int b);\n",
                        ],
                        [
                            'name' => 'helper.cpp',
                            'starter' => "#include \"helper.h\"\nint add(int a, int b) {\n    return 0;\n}\n",
                            'solution' => "#include \"helper.h\"\nint add(int a, int b) {\n    return a + b;\n}\n",
                        ],
                    ],
                ],
            ],
        ]);
        $qgen->create_question('codeatelier', 'judge', [
                'category' => $cat->id, 'scenario' => 'project', 'language' => 'cpp',
                'projectfiles' => $projectfiles]);

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourse = $generator->create_course();
        $rc = new restore_controller(
            $backupid,
            $newcourse->id,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newcontext = \context_course::instance($newcourse->id);
        $restored = $this->find_restored_options($newcontext->id);
        $this->assertNotNull($restored);
        $this->assertEquals('project', $restored->scenario);
        // The scalar column stays null for projects; the entry rides in the blob.
        $this->assertNull($restored->entryfile);
        $envs = \qtype_codeatelier\judge\project_packager::decode_envs($restored->projectfiles);
        $this->assertSame('main.cpp', $envs['cpp']['entry']);
        $starters = \qtype_codeatelier\judge\project_packager::starter_files($envs['cpp']);
        $this->assertSame(['main.cpp', 'helper.h', 'helper.cpp'], array_keys($starters));
        $solutions = \qtype_codeatelier\judge\project_packager::reference_files($envs['cpp']);
        $this->assertStringContainsString('return a + b;', $solutions['helper.cpp']);
    }

    /**
     * Find the CodeAtelier options row whose question lives in the given context.
     *
     * @param int $contextid the restored course context id
     * @return \stdClass|null
     */
    protected function find_restored_options($contextid) {
        global $DB;
        $sql = "SELECT o.*
                  FROM {qtype_codeatelier_options} o
                  JOIN {question} q ON q.id = o.questionid
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                 WHERE qc.contextid = :contextid";
        $records = $DB->get_records_sql($sql, ['contextid' => $contextid]);
        return $records ? reset($records) : null;
    }
}
