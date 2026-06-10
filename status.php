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
 * Judge-tier status page: Judge0 health, needs-grading queue depth, cache size.
 *
 * Read-only diagnostics for an administrator. It reads the same numbers the system
 * acts on — the live sandbox probe, the queue the grading task drains, the memo the
 * prune task trims — and never touches test contents or grades.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('qtypecodeatelierstatus');

$runner = \qtype_codeatelier\judge\judge0_runner::from_config();
$endpoint = (string) get_config('qtype_codeatelier', 'judge0baseurl');

// Needs-grading queue depth: judge-tier CodeAtelier attempts whose latest step is
// "needs grading" — exactly the set the grading task drains.
$queuesql = "SELECT COUNT(1)
               FROM {question_attempts} qa
               JOIN {question} q ON q.id = qa.questionid
               JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
              WHERE q.qtype = :qtype
                AND qas.state = :state
                AND qas.sequencenumber = (
                    SELECT MAX(s2.sequencenumber)
                      FROM {question_attempt_steps} s2
                     WHERE s2.questionattemptid = qa.id)";
$queuedepth = $DB->count_records_sql($queuesql, ['qtype' => 'codeatelier', 'state' => 'needsgrading']);

$cachesize = $DB->count_records('qtype_codeatelier_judgecache');
$oldest = $DB->get_field_sql('SELECT MIN(timecreated) FROM {qtype_codeatelier_judgecache}');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('statuspagetitle', 'qtype_codeatelier'));

$rows = [];

// Endpoint + live health probe.
if ($endpoint === '') {
    $rows[] = [get_string('status_endpoint', 'qtype_codeatelier'),
            html_writer::span(get_string('status_endpointnotset', 'qtype_codeatelier'), 'badge badge-secondary')];
} else {
    $rows[] = [get_string('status_endpoint', 'qtype_codeatelier'), s($endpoint)];
    $ping = $runner->ping();
    if (!empty($ping['ok'])) {
        $rows[] = [get_string('status_health', 'qtype_codeatelier'),
                html_writer::span(get_string(
                    'status_reachable',
                    'qtype_codeatelier',
                    s($ping['version'])
                ), 'badge badge-success')];
        if (isset($ping['workers']) && is_array($ping['workers'])) {
            $summary = [];
            foreach ($ping['workers'] as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $queue = isset($w['queue']) ? s($w['queue']) : '';
                $size = isset($w['size']) ? (int) $w['size'] : 0;
                $available = isset($w['available']) ? (int) $w['available'] : 0;
                $idle = isset($w['idle']) ? (int) $w['idle'] : 0;
                $summary[] = get_string(
                    'status_workerline',
                    'qtype_codeatelier',
                    ['queue' => $queue, 'size' => $size, 'available' => $available, 'idle' => $idle]
                );
            }
            if ($summary) {
                $rows[] = [get_string('status_workers', 'qtype_codeatelier'),
                        html_writer::alist($summary)];
            }
        }
    } else {
        $rows[] = [get_string('status_health', 'qtype_codeatelier'),
                html_writer::span(get_string(
                    'status_unreachable',
                    'qtype_codeatelier',
                    s($ping['error'] ?? '')
                ), 'badge badge-danger')];
    }
}

// Web-judge service endpoint + live health probe.
$webrunner = \qtype_codeatelier\judge\web_judge_runner::from_config();
if ($webrunner === null) {
    $rows[] = [get_string('status_webendpoint', 'qtype_codeatelier'),
            html_writer::span(get_string('status_endpointnotset', 'qtype_codeatelier'), 'badge badge-secondary')];
} else {
    $rows[] = [get_string('status_webendpoint', 'qtype_codeatelier'),
            s(get_config('qtype_codeatelier', 'webjudgebaseurl'))];
    $webping = $webrunner->ping();
    if (!empty($webping['ok'])) {
        $rows[] = [get_string('status_webhealth', 'qtype_codeatelier'),
                html_writer::span(get_string(
                    'status_reachable',
                    'qtype_codeatelier',
                    s($webping['version'])
                ), 'badge badge-success')];
    } else {
        $rows[] = [get_string('status_webhealth', 'qtype_codeatelier'),
                html_writer::span(get_string(
                    'status_unreachable',
                    'qtype_codeatelier',
                    s($webping['error'] ?? '')
                ), 'badge badge-danger')];
    }
}

// Async grading queue depth.
$rows[] = [get_string('status_queuedepth', 'qtype_codeatelier'), (string) $queuedepth];

// Cache size + oldest entry age.
$rows[] = [get_string('status_cachesize', 'qtype_codeatelier'), (string) $cachesize];
if ($oldest) {
    $rows[] = [get_string('status_cacheoldest', 'qtype_codeatelier'),
            format_time(time() - (int) $oldest)];
}

$table = new html_table();
$table->head = [get_string('status_metric', 'qtype_codeatelier'), get_string('status_value', 'qtype_codeatelier')];
$table->data = $rows;
$table->attributes['class'] = 'generaltable';
echo html_writer::table($table);

echo $OUTPUT->footer();
