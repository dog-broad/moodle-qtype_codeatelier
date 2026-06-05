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

namespace qtype_codeatelier\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the judge-cache prune scheduled task.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qtype_codeatelier\task\prune_judgecache
 */
final class prune_judgecache_test extends \advanced_testcase {

    /**
     * Insert a cache row aged by the given number of seconds.
     *
     * @param string $hash the (test) content hash
     * @param int $age seconds before now to backdate timecreated
     */
    private function seed(string $hash, int $age): void {
        global $DB;
        $DB->insert_record('qtype_codeatelier_judgecache', (object) [
            'contenthash' => $hash,
            'fraction' => 1.0,
            'verdict' => 'AC',
            'resultblob' => '{}',
            'timecreated' => time() - $age,
        ]);
    }

    public function test_prunes_entries_older_than_retention(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('judgecacheretention', 30, 'qtype_codeatelier');

        $this->seed('old', 31 * DAYSECS);
        $this->seed('fresh', 5 * DAYSECS);

        ob_start();
        (new prune_judgecache())->execute();
        $trace = ob_get_clean();

        $this->assertStringContainsString('pruned 1', $trace);
        $this->assertFalse($DB->record_exists('qtype_codeatelier_judgecache', ['contenthash' => 'old']));
        $this->assertTrue($DB->record_exists('qtype_codeatelier_judgecache', ['contenthash' => 'fresh']));
    }

    public function test_retention_zero_keeps_everything(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('judgecacheretention', 0, 'qtype_codeatelier');

        $this->seed('ancient', 999 * DAYSECS);

        (new prune_judgecache())->execute();

        $this->assertTrue($DB->record_exists('qtype_codeatelier_judgecache', ['contenthash' => 'ancient']));
    }
}
