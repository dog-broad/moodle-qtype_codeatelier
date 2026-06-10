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
 * Privacy provider for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_codeatelier\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The CodeAtelier question type stores the soft anti-cheat (proctor) event log of its
 * own — focus-away and outside-paste events recorded against a student's judge-tier
 * attempt. Question content (language, test cases) is authored, not personal; student
 * code responses are held and exported by the Moodle question engine, not here.
 *
 * The proctor log links to a question attempt, which lives in a question usage's
 * context, so all of this provider's queries reach the context through
 * question_attempts -> question_usages.
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string the proctor-log table. */
    private const TABLE = 'qtype_codeatelier_proctorlog';

    /**
     * Describe the personal data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, [
            'questionattemptid' => 'privacy:metadata:proctorlog:questionattemptid',
            'userid' => 'privacy:metadata:proctorlog:userid',
            'eventtype' => 'privacy:metadata:proctorlog:eventtype',
            'timecreated' => 'privacy:metadata:proctorlog:timecreated',
        ], 'privacy:metadata:proctorlog');
        return $collection;
    }

    /**
     * The contexts in which a user has proctor-log data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT qu.contextid
                  FROM {" . self::TABLE . "} p
                  JOIN {question_attempts} qa ON qa.id = p.questionattemptid
                  JOIN {question_usages} qu ON qu.id = qa.questionusageid
                 WHERE p.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);
        return $contextlist;
    }

    /**
     * The users who have proctor-log data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $sql = "SELECT p.userid
                  FROM {" . self::TABLE . "} p
                  JOIN {question_attempts} qa ON qa.id = p.questionattemptid
                  JOIN {question_usages} qu ON qu.id = qa.questionusageid
                 WHERE qu.contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
    }

    /**
     * Export one user's proctor-log data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $events = $DB->get_records_sql(
                "SELECT p.id, p.eventtype, p.timecreated
                       FROM {" . self::TABLE . "} p
                       JOIN {question_attempts} qa ON qa.id = p.questionattemptid
                       JOIN {question_usages} qu ON qu.id = qa.questionusageid
                      WHERE qu.contextid = :contextid AND p.userid = :userid
                   ORDER BY p.timecreated ASC",
                ['contextid' => $context->id, 'userid' => $userid]
            );
            if (!$events) {
                continue;
            }
            $data = [];
            foreach ($events as $event) {
                $data[] = [
                    'event' => $event->eventtype,
                    'time' => transform::datetime($event->timecreated),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:proctorpath', 'qtype_codeatelier')],
                (object) ['events' => $data]
            );
        }
    }

    /**
     * Delete all proctor-log data for all users in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        $DB->delete_records_select(
            self::TABLE,
            "questionattemptid IN (
                     SELECT qa.id
                       FROM {question_attempts} qa
                       JOIN {question_usages} qu ON qu.id = qa.questionusageid
                      WHERE qu.contextid = :contextid)",
            ['contextid' => $context->id]
        );
    }

    /**
     * Delete one user's proctor-log data across the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $contextids = $contextlist->get_contextids();
        if (!$contextids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $DB->delete_records_select(
            self::TABLE,
            "userid = :userid AND questionattemptid IN (
                     SELECT qa.id
                       FROM {question_attempts} qa
                       JOIN {question_usages} qu ON qu.id = qa.questionusageid
                      WHERE qu.contextid $insql)",
            $params
        );
    }

    /**
     * Delete the listed users' proctor-log data in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $userlist->get_context()->id;
        $DB->delete_records_select(
            self::TABLE,
            "userid $insql AND questionattemptid IN (
                     SELECT qa.id
                       FROM {question_attempts} qa
                       JOIN {question_usages} qu ON qu.id = qa.questionusageid
                      WHERE qu.contextid = :contextid)",
            $params
        );
    }
}
