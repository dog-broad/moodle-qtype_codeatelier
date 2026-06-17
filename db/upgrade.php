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

        $scenario = new xmldb_field(
            'scenario',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'stdinstdout',
            'language'
        );
        if (!$dbman->field_exists($table, $scenario)) {
            $dbman->add_field($table, $scenario);
        }

        $entrypoint = new xmldb_field(
            'entrypoint',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'scenario'
        );
        if (!$dbman->field_exists($table, $entrypoint)) {
            $dbman->add_field($table, $entrypoint);
        }

        $startercode = new xmldb_field(
            'startercode',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'entrypoint'
        );
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

    if ($oldversion < 2026061400) {
        // Per-question execution tier: practice (in-browser) or judge (server sandbox).
        $optionstable = new xmldb_table('qtype_codeatelier_options');
        $tier = new xmldb_field(
            'tier',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'practice',
            'language'
        );
        if (!$dbman->field_exists($optionstable, $tier)) {
            $dbman->add_field($optionstable, $tier);
        }

        // Memoised judge-tier grading results (deterministic sandbox runs).
        $cache = new xmldb_table('qtype_codeatelier_judgecache');
        if (!$dbman->table_exists($cache)) {
            $cache->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $cache->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $cache->add_field('fraction', XMLDB_TYPE_NUMBER, '12, 7', null, XMLDB_NOTNULL, null, '0');
            $cache->add_field('verdict', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'WA');
            $cache->add_field('resultblob', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $cache->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $cache->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $cache->add_index('contenthash', XMLDB_INDEX_UNIQUE, ['contenthash']);
            $dbman->create_table($cache);
        }

        upgrade_plugin_savepoint(true, 2026061400, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026061800) {
        // Widen matchmode: 'resultset_ordered' is 17 chars and overflowed char(16),
        // which errored on PostgreSQL (and silently truncated on MySQL).
        $table = new xmldb_table('qtype_codeatelier_tests');
        $field = new xmldb_field(
            'matchmode',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'trimmed',
            'expected'
        );
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026061800, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062000) {
        // Per-question judge limits (null = use the site default).
        $table = new xmldb_table('qtype_codeatelier_options');
        $cputime = new xmldb_field('cputimelimit', XMLDB_TYPE_NUMBER, '10, 3', null, null, null, null, 'schemasql');
        if (!$dbman->field_exists($table, $cputime)) {
            $dbman->add_field($table, $cputime);
        }
        $memory = new xmldb_field('memorylimit', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'cputimelimit');
        if (!$dbman->field_exists($table, $memory)) {
            $dbman->add_field($table, $memory);
        }
        upgrade_plugin_savepoint(true, 2026062000, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062100) {
        // Student language choice: the allowed set, and per-language starter code.
        $table = new xmldb_table('qtype_codeatelier_options');
        $languages = new xmldb_field('languages', XMLDB_TYPE_TEXT, null, null, null, null, null, 'memorylimit');
        if (!$dbman->field_exists($table, $languages)) {
            $dbman->add_field($table, $languages);
        }
        $starters = new xmldb_field('starters', XMLDB_TYPE_TEXT, null, null, null, null, null, 'languages');
        if (!$dbman->field_exists($table, $starters)) {
            $dbman->add_field($table, $starters);
        }
        upgrade_plugin_savepoint(true, 2026062100, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062200) {
        // A reference solution for validate-on-save and run-to-capture.
        $table = new xmldb_table('qtype_codeatelier_options');
        $field = new xmldb_field('referencesolution', XMLDB_TYPE_TEXT, null, null, null, null, null, 'starters');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026062200, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062300) {
        // All-or-nothing grading toggle (0 = weighted partial credit).
        $table = new xmldb_table('qtype_codeatelier_options');
        $field = new xmldb_field('allornothing', XMLDB_TYPE_INTEGER, '2', null, null, null, '0', 'referencesolution');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026062300, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062404) {
        // Typed signature for static-language function grading (empty for dynamic).
        $table = new xmldb_table('qtype_codeatelier_options');
        $field = new xmldb_field('functionsignature', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'allornothing');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026062404, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062701) {
        // Multi-file projects: the starter file set (JSON) and the entry file name.
        $table = new xmldb_table('qtype_codeatelier_options');
        $projectfiles = new xmldb_field(
            'projectfiles',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'functionsignature'
        );
        if (!$dbman->field_exists($table, $projectfiles)) {
            $dbman->add_field($table, $projectfiles);
        }
        $entryfile = new xmldb_field('entryfile', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'projectfiles');
        if (!$dbman->field_exists($table, $entryfile)) {
            $dbman->add_field($table, $entryfile);
        }
        upgrade_plugin_savepoint(true, 2026062701, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062711) {
        // Web scenario: per-test-case DOM assertion (selector, property, matcher)
        // and optional interaction actions, on the test-case table.
        $table = new xmldb_table('qtype_codeatelier_tests');
        $fields = [
            new xmldb_field('selector', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'feedback'),
            new xmldb_field('assertproperty', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'selector'),
            new xmldb_field('assertmatcher', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'assertproperty'),
            new xmldb_field('actions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'assertmatcher'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026062711, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062716) {
        // Custom checker (special judge): the checker's language and source.
        $table = new xmldb_table('qtype_codeatelier_options');
        $checkerlang = new xmldb_field('checkerlang', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'entryfile');
        if (!$dbman->field_exists($table, $checkerlang)) {
            $dbman->add_field($table, $checkerlang);
        }
        $checkersource = new xmldb_field('checkersource', XMLDB_TYPE_TEXT, null, null, null, null, null, 'checkerlang');
        if (!$dbman->field_exists($table, $checkersource)) {
            $dbman->add_field($table, $checkersource);
        }
        upgrade_plugin_savepoint(true, 2026062716, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062717) {
        // Subtask group id on the test-case table (0 = ungrouped).
        $table = new xmldb_table('qtype_codeatelier_tests');
        $field = new xmldb_field('subtask', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'actions');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026062717, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062720) {
        // Server-side, append-only proctor event log (replaces the tamperable hidden
        // proctorlog response field). Events are written by an authenticated AJAX call
        // and stamped server-side, so they cannot be forged or altered through the page.
        $table = new xmldb_table('qtype_codeatelier_proctorlog');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('questionattemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('eventtype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('questionattemptid', XMLDB_INDEX_NOTUNIQUE, ['questionattemptid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026062720, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062747) {
        // Optional per-test SQL dataset (schema + seed). When set it replaces the
        // question schemasql for that test, so hidden tests can run against
        // different data and a hardcoded answer cannot pass them.
        $table = new xmldb_table('qtype_codeatelier_tests');
        $field = new xmldb_field('datasetsql', XMLDB_TYPE_TEXT, null, null, null, null, null, 'checkerquery');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026062747, 'qtype', 'codeatelier');
    }

    if ($oldversion < 2026062764) {
        // Project questions move from three fields (a flat projectfiles starter set,
        // a scalar entryfile, a flat referencesolution set) to one language-keyed
        // blob in projectfiles: {version, envs: {<lang>: {entry, files: [{name,
        // starter, solution}]}}}. Migrate existing single-language projects in place;
        // entry + solution then live in the blob, so the scalar columns are cleared.
        $records = $DB->get_records('qtype_codeatelier_options', ['scenario' => 'project']);
        foreach ($records as $rec) {
            $pf = trim((string) $rec->projectfiles);
            if ($pf === '') {
                continue;
            }
            $decoded = json_decode($pf, true);
            if (is_array($decoded) && isset($decoded['envs'])) {
                continue; // Already a blob.
            }
            $starter = [];
            if (is_array($decoded)) {
                foreach ($decoded as $f) {
                    if (is_array($f) && isset($f['name']) && trim((string) $f['name']) !== '') {
                        $starter[(string) $f['name']] = isset($f['content']) ? (string) $f['content'] : '';
                    }
                }
            }
            $solution = [];
            $ref = json_decode((string) $rec->referencesolution, true);
            if (is_array($ref)) {
                foreach ($ref as $f) {
                    if (is_array($f) && isset($f['name']) && trim((string) $f['name']) !== '') {
                        $solution[(string) $f['name']] = isset($f['content']) ? (string) $f['content'] : '';
                    }
                }
            }
            $byname = $starter + $solution;
            if (!$byname) {
                continue;
            }
            $files = [];
            foreach (array_keys($byname) as $name) {
                $files[] = [
                    'name' => $name,
                    'starter' => $starter[$name] ?? '',
                    'solution' => $solution[$name] ?? '',
                ];
            }
            $entry = trim((string) $rec->entryfile);
            if ($entry === '' || !array_key_exists($entry, $byname)) {
                $entry = $files[0]['name'];
            }
            $lang = (string) $rec->language;
            $rec->projectfiles = json_encode([
                'version' => 2,
                'envs' => [$lang => ['entry' => $entry, 'files' => $files]],
            ]);
            $rec->entryfile = null;
            $rec->referencesolution = null;
            $DB->update_record('qtype_codeatelier_options', $rec);
        }
        upgrade_plugin_savepoint(true, 2026062764, 'qtype', 'codeatelier');
    }

    return true;
}
