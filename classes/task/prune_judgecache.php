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
 * Prune old judge-tier grading cache entries.
 *
 * The judgecache is a pure content-addressed memo: every distinct submission adds
 * a row that is never otherwise removed. This task deletes entries older than the
 * configured retention so the table does not grow without bound. A pruned entry
 * that is needed again is simply recomputed on the next grade — losing it is never
 * wrong, only a little slower.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_judgecache extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_prune_judgecache', 'qtype_codeatelier');
    }

    public function execute() {
        global $DB;

        $days = (int) get_config('qtype_codeatelier', 'judgecacheretention');
        if ($days <= 0) {
            // Retention disabled: keep entries forever.
            return;
        }

        $cutoff = time() - ($days * DAYSECS);
        $count = $DB->count_records_select('qtype_codeatelier_judgecache', 'timecreated < ?', [$cutoff]);
        if ($count) {
            $DB->delete_records_select('qtype_codeatelier_judgecache', 'timecreated < ?', [$cutoff]);
            mtrace("qtype_codeatelier: pruned {$count} judge cache entr(y/ies) older than {$days} day(s).");
        }
    }
}
