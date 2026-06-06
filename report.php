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
 * Insight: CodeAtelier submissions analytics — per question, and per quiz.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->libdir . '/questionlib.php');

$questionid = optional_param('id', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$format = optional_param('format', '', PARAM_ALPHA);

if (!$questionid && !$cmid) {
    throw new moodle_exception('missingparam', '', '', 'id');
}

// When a quiz is named, the report is scoped to that quiz's attempts.
$usagecontextid = null;
$cm = null;
$quizcontext = null;
if ($cmid) {
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    $quizcontext = context_module::instance($cm->id);
    require_login($cm->course, false, $cm);
    require_capability('mod/quiz:viewreports', $quizcontext);
    $usagecontextid = $quizcontext->id;
}

/** @var qtype_codeatelier_renderer $renderer */
$renderer = null;

if ($questionid) {
    $questiondata = question_bank::load_question_data($questionid);
    if (!$questiondata || $questiondata->qtype !== 'codeatelier') {
        throw new moodle_exception('notcodeatelierquestion', 'qtype_codeatelier');
    }
    if (!$cmid) {
        $qcontext = context::instance_by_id($questiondata->contextid);
        require_login();
        $PAGE->set_context($qcontext);
        question_require_capability_on($questiondata, 'view');
    }

    $stats = \qtype_codeatelier\local\report\question_stats::for_question($questionid, $usagecontextid);
    $baseurl = new moodle_url('/question/type/codeatelier/report.php',
            $cmid ? array('id' => $questionid, 'cmid' => $cmid) : array('id' => $questionid));

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
    $renderer = $PAGE->get_renderer('qtype_codeatelier');

    echo $OUTPUT->header();
    if ($cmid) {
        echo html_writer::div(html_writer::link(
                new moodle_url('/question/type/codeatelier/report.php', array('cmid' => $cmid)),
                get_string('report_backtoquiz', 'qtype_codeatelier')), 'mb-2');
    }
    echo $renderer->render_question_report($stats, $baseurl);
    echo $OUTPUT->footer();
    exit;
}

// Per-quiz overview.
$quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
$summaries = \qtype_codeatelier\local\report\question_stats::for_quiz($quiz->id, $quizcontext->id);

$PAGE->set_url(new moodle_url('/question/type/codeatelier/report.php', array('cmid' => $cmid)));
$PAGE->set_title(get_string('report_quiztitle', 'qtype_codeatelier'));
$PAGE->set_heading(format_string($quiz->name));
$renderer = $PAGE->get_renderer('qtype_codeatelier');

echo $OUTPUT->header();
echo $renderer->render_quiz_report($summaries, $cmid);
echo $OUTPUT->footer();
