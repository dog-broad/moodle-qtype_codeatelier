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
 * Backup support for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the backup structure for CodeAtelier questions: the per-question
 * options row and the test-case rows.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_qtype_codeatelier_plugin extends backup_qtype_plugin {

    /**
     * Returns the qtype information to attach to the question element.
     *
     * @return backup_plugin_element
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '../../qtype', 'codeatelier');

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $options = new backup_nested_element('codeatelier_options', array('id'), array(
            'language', 'tier', 'scenario', 'entrypoint', 'startercode', 'schemasql',
            'cputimelimit', 'memorylimit', 'languages', 'starters', 'referencesolution', 'allornothing',
            'functionsignature', 'projectfiles', 'entryfile', 'checkerlang', 'checkersource'));
        $pluginwrapper->add_child($options);
        $options->set_source_table('qtype_codeatelier_options',
                array('questionid' => backup::VAR_PARENTID));

        $tests = new backup_nested_element('codeatelier_tests');
        $test = new backup_nested_element('codeatelier_test', array('id'), array(
            'sortorder', 'input', 'expected', 'matchmode', 'tolerance',
            'weight', 'visibility', 'checkerquery', 'feedback',
            'selector', 'assertproperty', 'assertmatcher', 'actions', 'subtask'));
        $pluginwrapper->add_child($tests);
        $tests->add_child($test);
        $test->set_source_table('qtype_codeatelier_tests',
                array('questionid' => backup::VAR_PARENTID), 'sortorder ASC, id ASC');

        return $plugin;
    }

    /**
     * The file areas this qtype owns, so backup/restore carries them (the
     * support/data files are mapped to the restored question id).
     *
     * @return array filearea => mapping name
     */
    public static function get_qtype_fileareas() {
        return array('datafiles' => 'question_created');
    }
}
