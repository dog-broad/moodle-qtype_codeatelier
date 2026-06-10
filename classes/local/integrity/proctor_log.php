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

namespace qtype_codeatelier\local\integrity;

defined('MOODLE_INTERNAL') || die();

/**
 * The server-side, append-only proctor event log for judge-tier attempts.
 *
 * Replaces the former hidden response field, which the student's browser wrote and so
 * could edit or clear. Events are appended here through an authenticated AJAX call
 * during the attempt and stamped with the server's clock and the authenticated user, so
 * the record cannot be forged or altered through the page. This remains detection, not
 * prevention (a determined student can still decline to send an event — the honest
 * boundary of any browser-side measure); the gain is that what *is* recorded is
 * server-authoritative and append-only.
 *
 * This is the single read/write point for the log: the external writes via
 * {@see record()}, review and the report read via {@see summarise()} / {@see counts_for()}.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proctor_log {
    /** @var string the table holding the events. */
    const TABLE = 'qtype_codeatelier_proctorlog';

    /** @var string[] the event kinds we accept; anything else is rejected. */
    const TYPES = ['focusaway', 'extpaste', 'extdrop', 'paste'];

    /** @var int hard cap on events stored per attempt, so the log cannot grow without bound. */
    const MAX_PER_ATTEMPT = 200;

    /**
     * Append one event, server-stamped. No-op for an unknown type or once the
     * per-attempt cap is reached.
     *
     * @param int $questionattemptid the question_attempts.id
     * @param int $userid the authenticated user (caller-supplied from $USER, never the client)
     * @param string $eventtype one of TYPES
     * @param int|null $time server time (defaults to now)
     * @return bool whether a row was written
     */
    public static function record(
        int $questionattemptid,
        int $userid,
        string $eventtype,
        ?int $time = null
    ): bool {
        global $DB;
        if (!in_array($eventtype, self::TYPES, true)) {
            return false;
        }
        if (
            $DB->count_records(self::TABLE, ['questionattemptid' => $questionattemptid])
                >= self::MAX_PER_ATTEMPT
        ) {
            return false;
        }
        $DB->insert_record(self::TABLE, (object) [
            'questionattemptid' => $questionattemptid,
            'userid' => $userid,
            'eventtype' => $eventtype,
            'timecreated' => $time ?? time(),
        ]);
        return true;
    }

    /**
     * Count of events per type for one attempt, e.g. ['focusaway' => 2, 'extpaste' => 1].
     *
     * @param int $questionattemptid
     * @return array map of eventtype => count (only types that occurred)
     */
    public static function summarise(int $questionattemptid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT eventtype, COUNT(*) AS n
                   FROM {" . self::TABLE . "}
                  WHERE questionattemptid = :qaid
               GROUP BY eventtype",
            ['qaid' => $questionattemptid]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row->eventtype] = (int) $row->n;
        }
        return $out;
    }

    /**
     * Total event count for each of several attempts, for the analytics report.
     *
     * @param int[] $questionattemptids
     * @return array map of questionattemptid => total event count (only flagged attempts)
     */
    public static function counts_for(array $questionattemptids): array {
        global $DB;
        if (!$questionattemptids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($questionattemptids, SQL_PARAMS_NAMED, 'qa');
        $rows = $DB->get_records_sql(
            "SELECT questionattemptid, COUNT(*) AS n
                   FROM {" . self::TABLE . "}
                  WHERE questionattemptid $insql
               GROUP BY questionattemptid",
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->questionattemptid] = (int) $row->n;
        }
        return $out;
    }
}
