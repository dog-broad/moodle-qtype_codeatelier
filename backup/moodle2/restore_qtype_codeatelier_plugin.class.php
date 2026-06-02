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
 * Restore support for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restores CodeAtelier questions: the per-question options row and the
 * test-case rows. Only inserts when the parent question was newly created by
 * the restore (mapped questions keep their existing data).
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_qtype_codeatelier_plugin extends restore_qtype_plugin {

    /**
     * Returns the paths to be handled by the plugin at question level.
     *
     * @return restore_path_element[]
     */
    protected function define_question_plugin_structure() {
        $paths = array();

        $paths[] = new restore_path_element('codeatelier_options',
                $this->get_pathfor('/codeatelier_options'));
        $paths[] = new restore_path_element('codeatelier_test',
                $this->get_pathfor('/codeatelier_tests/codeatelier_test'));

        return $paths;
    }

    /**
     * Process the options for a restored CodeAtelier question.
     *
     * @param array $data
     */
    public function process_codeatelier_options($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $oldquestionid = $this->get_old_parentid('question');
        $newquestionid = $this->get_new_parentid('question');

        // Only restore for questions the restore created (not mapped existing ones).
        if ($this->get_mappingid('question_created', $oldquestionid)) {
            $data->questionid = $newquestionid;
            $newitemid = $DB->insert_record('qtype_codeatelier_options', $data);
            $this->set_mapping('qtype_codeatelier_options', $oldid, $newitemid);
        }
    }

    /**
     * Process one test case for a restored CodeAtelier question.
     *
     * @param array $data
     */
    public function process_codeatelier_test($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $oldquestionid = $this->get_old_parentid('question');
        $newquestionid = $this->get_new_parentid('question');

        if ($this->get_mappingid('question_created', $oldquestionid)) {
            $data->questionid = $newquestionid;
            $newitemid = $DB->insert_record('qtype_codeatelier_tests', $data);
            $this->set_mapping('qtype_codeatelier_tests', $oldid, $newitemid);
        }
    }
}
