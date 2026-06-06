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
 * Insight: the per-question submissions analytics report for a CodeAtelier question.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->libdir . '/questionlib.php');

$questionid = required_param('id', PARAM_INT);
$format = optional_param('format', '', PARAM_ALPHA);

$questiondata = question_bank::load_question_data($questionid);
if (!$questiondata || $questiondata->qtype !== 'codeatelier') {
    throw new moodle_exception('notcodeatelierquestion', 'qtype_codeatelier');
}

require_login();
$context = context::instance_by_id($questiondata->contextid);
$PAGE->set_context($context);
// A teacher who can view this question in the bank can see its analytics.
question_require_capability_on($questiondata, 'view');

$stats = \qtype_codeatelier\local\report\question_stats::for_question($questionid);

$baseurl = new moodle_url('/question/type/codeatelier/report.php', array('id' => $questionid));

if ($format === 'csv') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer();
    $csv->set_filename('codeatelier-question-' . $questionid . '-pertest');
    $csv->add_data(array(
        get_string('report_test', 'qtype_codeatelier'),
        get_string('visibility', 'qtype_codeatelier'),
        get_string('report_passes', 'qtype_codeatelier'),
        get_string('report_outof', 'qtype_codeatelier'),
        get_string('report_passrate', 'qtype_codeatelier'),
    ));
    foreach ($stats->pertest as $row) {
        $csv->add_data(array(
            $row->index + 1,
            $row->visible ? get_string('visible_shown', 'qtype_codeatelier')
                    : get_string('visible_hidden', 'qtype_codeatelier'),
            $row->passes,
            $row->total,
            $row->passrate === null ? '' : round($row->passrate * 100, 1) . '%',
        ));
    }
    $csv->download_file();
    exit;
}

$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('report_title', 'qtype_codeatelier'));
$PAGE->set_heading(format_string($questiondata->name));

/** @var qtype_codeatelier_renderer $renderer */
$renderer = $PAGE->get_renderer('qtype_codeatelier');

echo $OUTPUT->header();
echo $renderer->render_question_report($stats, $baseurl);
echo $OUTPUT->footer();
