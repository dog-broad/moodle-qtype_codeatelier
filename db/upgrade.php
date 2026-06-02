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
 * Upgrade steps for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Apply schema upgrades for the CodeAtelier question type.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_qtype_codeatelier_upgrade($oldversion) {
    global $CFG, $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026060200) {
        // Introduce the options + test-case tables.
        $table = new xmldb_table('qtype_codeatelier_options');
        if (!$dbman->table_exists($table)) {
            $dbman->install_from_xmldb_file($CFG->dirroot . '/question/type/codeatelier/db/install.xml');
        }
        upgrade_plugin_savepoint(true, 2026060200, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026060300) {
        // Add the scenario, entrypoint and starter-code options.
        $table = new xmldb_table('qtype_codeatelier_options');

        $scenario = new xmldb_field('scenario', XMLDB_TYPE_CHAR, '32', null,
                XMLDB_NOTNULL, null, 'stdinstdout', 'language');
        if (!$dbman->field_exists($table, $scenario)) {
            $dbman->add_field($table, $scenario);
        }

        $entrypoint = new xmldb_field('entrypoint', XMLDB_TYPE_CHAR, '64', null,
                null, null, null, 'scenario');
        if (!$dbman->field_exists($table, $entrypoint)) {
            $dbman->add_field($table, $entrypoint);
        }

        $startercode = new xmldb_field('startercode', XMLDB_TYPE_TEXT, null, null,
                null, null, null, 'entrypoint');
        if (!$dbman->field_exists($table, $startercode)) {
            $dbman->add_field($table, $startercode);
        }

        upgrade_plugin_savepoint(true, 2026060300, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026060500) {
        // SQL scenario: question-level schema+seed and a per-test checker query.
        $optionstable = new xmldb_table('qtype_codeatelier_options');
        $schemasql = new xmldb_field('schemasql', XMLDB_TYPE_TEXT, null, null, null, null, null, 'startercode');
        if (!$dbman->field_exists($optionstable, $schemasql)) {
            $dbman->add_field($optionstable, $schemasql);
        }

        $teststable = new xmldb_table('qtype_codeatelier_tests');
        $checkerquery = new xmldb_field('checkerquery', XMLDB_TYPE_TEXT, null, null, null, null, null, 'visibility');
        if (!$dbman->field_exists($teststable, $checkerquery)) {
            $dbman->add_field($teststable, $checkerquery);
        }

        upgrade_plugin_savepoint(true, 2026060500, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026060900) {
        // Optional per-test teacher feedback.
        $teststable = new xmldb_table('qtype_codeatelier_tests');
        $feedback = new xmldb_field('feedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'checkerquery');
        if (!$dbman->field_exists($teststable, $feedback)) {
            $dbman->add_field($teststable, $feedback);
        }
        upgrade_plugin_savepoint(true, 2026060900, 'qtype', 'codeatelier');
    }

    return true;
}
