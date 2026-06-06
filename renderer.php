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
 * CodeAtelier question renderer.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Generates the output for CodeAtelier questions.
 *
 * Renders the code editor, a run/check control, and a results area. The student
 * writes code (the 'answer' field); the browser runs and grades it client-side
 * and writes the fraction into the hidden 'score' field. The practice tier ships
 * the test cases to the browser, so it is for practice only — never secure
 * grading (see the integrity boundary).
 *
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_codeatelier_renderer extends qtype_renderer {

    public function formulation_and_controls(question_attempt $qa,
            question_display_options $options) {
        global $PAGE;

        $question = $qa->get_question();
        $isjudge = $question->is_judge();
        // The set of languages the student may choose from, and the one in effect
        // for this attempt (the stored choice, re-validated; primary by default).
        $allowed = $question->get_allowed_languages();
        $chosen = $question->resolve_language($qa->get_last_qt_var('language', ''));
        $startermap = $this->starter_map($question, $allowed);
        // The scenario drives the harness + the client UI on both tiers (the judge
        // tier now supports stdin/stdout and SQL).
        $scenario = !empty($question->scenario) ? $question->scenario : 'stdinstdout';

        $answername = $qa->get_qt_field_name('answer');
        $scorename = $qa->get_qt_field_name('score');
        $languagename = $qa->get_qt_field_name('language');
        $wrapperid = $answername . '_wrapper';

        $currentcode = $qa->get_last_qt_var('answer', '');
        if ($currentcode === '') {
            $currentcode = isset($startermap[$chosen]) ? $startermap[$chosen] : $this->starter_code($chosen);
        }
        $currentscore = $qa->get_last_qt_var('score', '');

        // Ship test cases to the browser. The JUDGE tier ships visible tests only:
        // hidden tests stay on the server, which is the whole integrity point. The
        // PRACTICE tier must ship all tests because the client grades them (those
        // hidden tests are only "polite-hidden").
        $tests = array();
        foreach ($question->tests as $test) {
            if ($isjudge && (int) $test->visibility !== 1) {
                continue;
            }
            $tests[] = array(
                'input' => $test->input,
                'expected' => $test->expected,
                'matchmode' => $test->matchmode,
                'tolerance' => $test->tolerance,
                'weight' => $test->weight,
                'visibility' => (int) $test->visibility,
                'checkerquery' => $test->checkerquery,
                // Ship teacher feedback only for visible tests (hidden reveals nothing client-side).
                'feedback' => ((int) $test->visibility === 1) ? $test->feedback : null,
            );
        }

        // On a judge-tier review, render the authoritative result from the memo
        // (computed at submit time) — never re-run the sandbox from a render path.
        $judgereviewhtml = '';
        $proctorreviewhtml = '';
        $gradingpending = false;
        if ($isjudge && $options->readonly) {
            $cached = $question->cached_judge_result($currentcode, $chosen);
            if ($cached === null && $qa->get_state() == question_state::$needsgrading) {
                // Graded off-request: the scheduled task has not reached this attempt
                // yet. A live indicator polls and refreshes when the grade lands.
                $gradingpending = true;
                $judgereviewhtml = html_writer::div(get_string('judgepending', 'qtype_codeatelier'),
                        'codeatelier-judge-review codeatelier-judge-pending',
                        array('data-role' => 'gradestatus'));
            } else {
                $judgereviewhtml = $this->render_judge_review($cached);
            }
            $proctorreviewhtml = $this->render_proctor_summary($qa->get_last_qt_var('proctorlog', ''));
        }

        // A judge attempt can offer "run against examples" only when an endpoint is
        // configured (and never on review).
        $canrunexamples = $isjudge && !$options->readonly
                && !empty(get_config('qtype_codeatelier', 'judge0baseurl'));
        // "Run with my own input" — a scratch console for stdin/stdout and function
        // judge questions (SQL has no stdin; it uses run-against-examples instead).
        $canrunscratch = $canrunexamples && $scenario !== 'sql';
        $scratchlabel = ($scenario === 'function')
                ? get_string('scratchargs', 'qtype_codeatelier')
                : get_string('scratchstdin', 'qtype_codeatelier');
        // Pre-fill the scratch box with the first visible example's input so a
        // student sees the expected shape (a JSON argument array for the function
        // scenario, or the stdin text) rather than guessing at a blank box.
        $scratchdefault = ($canrunscratch && !empty($tests)) ? (string) $tests[0]['input'] : '';

        $validationresponse = $isjudge ? array('answer' => $currentcode) : array('score' => $currentscore);

        // The student-facing language picker shows only when there is a real choice
        // and the attempt is live; on review we show which language was used.
        $languagechoice = count($allowed) > 1 && !$options->readonly;
        $languageoptions = array();
        foreach ($allowed as $code) {
            $languageoptions[] = array(
                'code' => $code,
                'label' => get_string('lang_' . $code, 'qtype_codeatelier'),
                'selected' => ($code === $chosen),
            );
        }

        $context = array(
            'questiontext' => $question->format_questiontext($qa),
            'wrapperid' => $wrapperid,
            'answername' => $answername,
            'answerid' => $answername,
            'answervalue' => $currentcode,
            'scorename' => $scorename,
            'scorevalue' => $currentscore,
            'languagename' => $languagename,
            'languagevalue' => $chosen,
            'languagechoice' => $languagechoice,
            'languageoptions' => $languageoptions,
            'languagechooselabel' => get_string('chooselanguage', 'qtype_codeatelier'),
            'showlanguageused' => $options->readonly && count($allowed) > 1,
            'languageusedlabel' => get_string('languageused', 'qtype_codeatelier',
                    get_string('lang_' . $chosen, 'qtype_codeatelier')),
            'readonly' => $options->readonly,
            'isjudge' => $isjudge,
            'judgenote' => $isjudge ? get_string('judgenote', 'qtype_codeatelier') : '',
            'judgereviewhtml' => $judgereviewhtml . $proctorreviewhtml,
            'proctorname' => $qa->get_qt_field_name('proctorlog'),
            'proctorvalue' => $qa->get_last_qt_var('proctorlog', ''),
            'proctornote' => $isjudge ? get_string('proctornote', 'qtype_codeatelier') : '',
            'canrunexamples' => $canrunexamples,
            'runexampleslabel' => get_string('runexamples', 'qtype_codeatelier'),
            'canrunscratch' => $canrunscratch,
            'scratchlabel' => $scratchlabel,
            'scratchdefault' => $scratchdefault,
            'hasvalidationerror' => $qa->get_state() == question_state::$invalid,
            'validationerror' => $question->get_validation_error($validationresponse),
        ) + qtype_codeatelier::ui_labels();

        $PAGE->requires->js_call_amd('qtype_codeatelier/control', 'init', array(array(
            'wrapperid' => $wrapperid,
            'language' => $chosen,
            'languages' => array_values($allowed),
            'starters' => $startermap,
            'scenario' => $scenario,
            'entrypoint' => $question->entrypoint,
            // Ship the schema for any SQL question so the client schema viewer
            // renders. A judge attempt has no Check button, so control.js never
            // wires client-side grading — the schema is the visible data, while the
            // expected rows and checker queries (hidden tests) are never shipped.
            'schemasql' => ($scenario === 'sql') ? $question->schemasql : null,
            'pyodidebaseurl' => get_config('qtype_codeatelier', 'pyodidebaseurl'),
            'sqljsbaseurl' => get_config('qtype_codeatelier', 'sqljsbaseurl'),
            'tests' => $tests,
            'timeoutms' => 5000,
            'readonly' => (bool) $options->readonly,
            'isjudge' => $isjudge,
            'allornothing' => (bool) $question->allornothing,
            'strings' => qtype_codeatelier::ui_strings(),
        )));

        // Soft anti-cheat runs on a live judge-tier attempt only (never on review,
        // never on the formative practice tier).
        if ($isjudge && !$options->readonly) {
            $PAGE->requires->js_call_amd('qtype_codeatelier/proctor', 'init',
                    array(array('wrapperid' => $wrapperid)));
        }

        // Live grade status: poll while a submitted judge attempt awaits the
        // background task, and refresh the page once the grade lands.
        if ($gradingpending) {
            $PAGE->requires->js_call_amd('qtype_codeatelier/gradestatus', 'init', array(array(
                'wrapperid' => $wrapperid,
                'qubaid' => (int) $qa->get_usage_id(),
                'slot' => (int) $qa->get_slot(),
                'grading' => get_string('judgegrading', 'qtype_codeatelier'),
            )));
        }

        // The formative "run against examples" loop for the judge tier.
        if ($canrunexamples) {
            $PAGE->requires->js_call_amd('qtype_codeatelier/judgerun', 'init', array(array(
                'wrapperid' => $wrapperid,
                'questionid' => (int) $question->id,
                'language' => $chosen,
                'strings' => array(
                    'running' => get_string('running', 'qtype_codeatelier'),
                    'example' => get_string('result_test', 'qtype_codeatelier'),
                    'input' => get_string('input', 'qtype_codeatelier'),
                    'expected' => get_string('expected', 'qtype_codeatelier'),
                    'got' => get_string('result_got', 'qtype_codeatelier'),
                    'pass' => get_string('result_pass', 'qtype_codeatelier'),
                    'fail' => get_string('result_fail', 'qtype_codeatelier'),
                    're' => get_string('verdict_RE', 'qtype_codeatelier'),
                    'tle' => get_string('verdict_TLE', 'qtype_codeatelier'),
                    'mle' => get_string('verdict_MLE', 'qtype_codeatelier'),
                    'nooutput' => get_string('scratchnooutput', 'qtype_codeatelier'),
                ),
            )));
        }

        return $this->render_from_template('qtype_codeatelier/editor_control', $context);
    }

    /**
     * Build the server-side review block for a judge-tier attempt: the verdict,
     * compile output on a compile error, and a per-test pass/fail list. Hidden
     * tests are shown as pass/fail only and never reveal their expected output.
     *
     * @param array|null $result the memoised judge result, or null on a cache miss
     * @return string HTML
     */
    protected function render_judge_review($result) {
        if ($result === null) {
            return html_writer::div(get_string('judgenoresult', 'qtype_codeatelier'),
                    'codeatelier-judge-review text-muted');
        }

        $verdict = isset($result['verdict']) ? (string) $result['verdict'] : 'WA';
        $out = html_writer::div(
                html_writer::span(get_string('judgeverdict', 'qtype_codeatelier'),
                        'codeatelier-judge-verdict-label') . ' '
                . html_writer::span($this->verdict_label($verdict),
                        'codeatelier-verdict codeatelier-verdict-' . $verdict),
                'codeatelier-judge-verdict');

        if ($verdict === 'CE' && !empty($result['compileoutput'])) {
            $out .= html_writer::tag('pre', s($result['compileoutput']),
                    array('class' => 'codeatelier-compileoutput'));
        }

        $items = '';
        foreach ($result['testresults'] as $tr) {
            $items .= $this->render_judge_test_row($tr);
        }
        $out .= html_writer::tag('ul', $items, array('class' => 'codeatelier-judge-tests'));

        return html_writer::div($out, 'codeatelier-judge-review');
    }

    /**
     * The soft anti-cheat activity summary for a judge-tier review: a count per
     * flagged event type. Empty when nothing was flagged. The underlying log is a
     * client-accumulated deterrent, not a tamper-proof record.
     *
     * @param string $raw the stored proctorlog JSON
     * @return string HTML (empty when there is nothing to show)
     */
    protected function render_proctor_summary($raw) {
        if (trim((string) $raw) === '') {
            return '';
        }
        $events = json_decode($raw, true);
        if (!is_array($events) || !$events) {
            return '';
        }

        $counts = array();
        foreach ($events as $event) {
            $type = isset($event['type']) ? $event['type'] : 'other';
            $counts[$type] = (isset($counts[$type]) ? $counts[$type] : 0) + 1;
        }

        $items = '';
        foreach ($counts as $type => $count) {
            $items .= html_writer::tag('li', s($this->proctor_label($type)) . ': ' . (int) $count);
        }

        return html_writer::div(
                html_writer::tag('h6', get_string('proctorsummary', 'qtype_codeatelier'))
                . html_writer::tag('ul', $items, array('class' => 'codeatelier-proctor-list')),
                'codeatelier-proctor-summary');
    }

    /**
     * A localised label for a flagged-event type.
     *
     * @param string $type focusaway | paste | other
     * @return string
     */
    protected function proctor_label($type) {
        switch ($type) {
            case 'focusaway':
                return get_string('proctor_focusaway', 'qtype_codeatelier');
            case 'paste':
                return get_string('proctor_paste', 'qtype_codeatelier');
            default:
                return $type;
        }
    }

    /**
     * One per-test row: a status badge with time/memory, and for a failed VISIBLE
     * test the error output or an expected-vs-actual diff. Hidden tests show only
     * a pass/fail badge — they carry no diagnostics in the result.
     *
     * @param array $tr a per-test result
     * @return string HTML
     */
    protected function render_judge_test_row(array $tr) {
        $visible = (int) $tr['visibility'] === 1;
        $passed = !empty($tr['passed']);
        $status = (string) $tr['status'];

        $label = $visible
            ? get_string('result_test', 'qtype_codeatelier') . ' ' . ($tr['index'] + 1)
            : get_string('result_hidden', 'qtype_codeatelier');
        $statuslabel = $passed
            ? get_string('result_pass', 'qtype_codeatelier')
            : $this->status_label($status);

        $head = html_writer::span(s($label), 'codeatelier-test-label')
            . ' '
            . html_writer::span(s($statuslabel),
                    'codeatelier-test-status codeatelier-status-' . ($passed ? 'pass' : $status))
            . $this->render_test_meta($tr);

        $detail = '';
        if ($visible && !$passed) {
            if (($status === 're' || $status === 'mle') && !empty($tr['stderr'])) {
                $detail = html_writer::tag('pre', s($tr['stderr']), array('class' => 'codeatelier-test-error'));
            } else if ($status === 'fail') {
                $detail = $this->render_test_diff($tr);
            }
        }

        $cls = 'codeatelier-judge-test ' . ($passed ? 'codeatelier-pass' : 'codeatelier-fail');
        return html_writer::tag('li', $head . $detail, array('class' => $cls));
    }

    /**
     * The time/memory meta for a test row, when measured.
     *
     * @param array $tr a per-test result
     * @return string HTML (empty when no measurements)
     */
    protected function render_test_meta(array $tr) {
        $parts = array();
        if (isset($tr['time']) && $tr['time'] !== null) {
            $parts[] = get_string('judgetime', 'qtype_codeatelier', format_float($tr['time'] * 1000, 0));
        }
        if (isset($tr['memory']) && $tr['memory'] !== null) {
            $parts[] = get_string('judgememory', 'qtype_codeatelier', display_size((int) $tr['memory'] * 1024));
        }
        if (!$parts) {
            return '';
        }
        return html_writer::span(implode(' · ', $parts), 'codeatelier-test-meta');
    }

    /**
     * An input / expected / got diff for a failed visible test.
     *
     * @param array $tr a per-test result
     * @return string HTML
     */
    protected function render_test_diff(array $tr) {
        $rows = '';
        if (isset($tr['input']) && trim((string) $tr['input']) !== '') {
            $rows .= $this->diff_line(get_string('input', 'qtype_codeatelier'), $tr['input'], 'input');
        }
        $rows .= $this->diff_line(get_string('expected', 'qtype_codeatelier'),
                isset($tr['expected']) ? $tr['expected'] : '', 'expected');
        $rows .= $this->diff_line(get_string('result_got', 'qtype_codeatelier'),
                isset($tr['actual']) ? $tr['actual'] : '', 'got');
        return html_writer::div($rows, 'codeatelier-test-diff');
    }

    /**
     * One labelled line of a diff.
     *
     * @param string $label the row label
     * @param string $value the (verbatim) value
     * @param string $which input | expected | got (for styling)
     * @return string HTML
     */
    protected function diff_line($label, $value, $which) {
        return html_writer::div(
                html_writer::span(s($label), 'codeatelier-diff-label')
                . html_writer::tag('pre', s((string) $value), array('class' => 'codeatelier-diff-value')),
                'codeatelier-diff-row codeatelier-diff-' . $which);
    }

    /**
     * A localised label for an overall verdict.
     *
     * @param string $verdict AC | WA | TLE | RE | CE | INTERNAL
     * @return string
     */
    protected function verdict_label($verdict) {
        $known = array('AC', 'WA', 'TLE', 'MLE', 'RE', 'CE');
        if (in_array($verdict, $known, true)) {
            return get_string('verdict_' . $verdict, 'qtype_codeatelier');
        }
        return $verdict;
    }

    /**
     * A localised label for a per-test status.
     *
     * @param string $status pass | fail | re | tle | ce
     * @return string
     */
    protected function status_label($status) {
        switch ($status) {
            case 'pass':
                return get_string('result_pass', 'qtype_codeatelier');
            case 're':
                return get_string('verdict_RE', 'qtype_codeatelier');
            case 'tle':
                return get_string('verdict_TLE', 'qtype_codeatelier');
            case 'mle':
                return get_string('verdict_MLE', 'qtype_codeatelier');
            case 'ce':
                return get_string('verdict_CE', 'qtype_codeatelier');
            case 'fail':
            default:
                return get_string('result_fail', 'qtype_codeatelier');
        }
    }

    /**
     * The starter code for each allowed language: the teacher's per-language
     * override when present, then the question's single startercode for the
     * primary language, then the built-in template. Lets the editor swap starters
     * as the student changes language without a server round-trip.
     *
     * @param qtype_codeatelier_question $question
     * @param string[] $allowed the allowed language codes
     * @return array map of language code to starter source
     */
    protected function starter_map($question, array $allowed) {
        $overrides = ($question->starters !== null && trim((string) $question->starters) !== '')
            ? json_decode((string) $question->starters, true) : null;
        if (!is_array($overrides)) {
            $overrides = array();
        }
        $map = array();
        foreach ($allowed as $code) {
            if (isset($overrides[$code]) && trim((string) $overrides[$code]) !== '') {
                $map[$code] = (string) $overrides[$code];
            } else if ($code === $question->language
                    && $question->startercode !== null && $question->startercode !== '') {
                $map[$code] = $question->startercode;
            } else {
                $map[$code] = $this->starter_code($code);
            }
        }
        return $map;
    }

    /**
     * A language-appropriate placeholder shown in a fresh editor.
     *
     * @param string $language python, sql or javascript
     * @return string the starter source
     */
    protected function starter_code($language) {
        switch ($language) {
            case 'sql':
                return "-- Write your SQL query here\n";
            case 'javascript':
                return "// Write your JavaScript here\n";
            case 'c':
                return "#include <stdio.h>\n\nint main(void) {\n    // Read input, print output\n    return 0;\n}\n";
            case 'cpp':
                return "#include <iostream>\nusing namespace std;\n\nint main() {\n    // Read input, print output\n    return 0;\n}\n";
            case 'java':
                // Judge0 compiles the public class named Main.
                return "import java.util.*;\n\npublic class Main {\n    public static void main(String[] args) {\n"
                    . "        Scanner sc = new Scanner(System.in);\n        // Read input, print output\n    }\n}\n";
            case 'go':
                return "package main\n\nimport (\n\t\"bufio\"\n\t\"fmt\"\n\t\"os\"\n)\n\n"
                    . "func main() {\n\treader := bufio.NewReader(os.Stdin)\n\t_ = reader\n\t// Read input, print output\n\t_ = fmt.Println\n}\n";
            case 'rust':
                return "use std::io::{self, Read};\n\nfn main() {\n    let mut input = String::new();\n"
                    . "    io::stdin().read_to_string(&mut input).unwrap();\n    // Read input, print output\n}\n";
            case 'php':
                return "<?php\n// Read input with fgets(STDIN); print output with echo.\n";
            case 'typescript':
                // 'declare' keeps the older tsc on Judge0 happy without @types/node.
                return "declare const require: any;\nconst input: string = require('fs').readFileSync(0, 'utf8');\n"
                    . "// Parse input; print with console.log.\n";
            case 'csharp':
                return "using System;\n\nclass Program {\n    static void Main() {\n"
                    . "        // Read input with Console.ReadLine(); print with Console.WriteLine().\n    }\n}\n";
            case 'kotlin':
                return "fun main() {\n    // Read input with readLine(); print with println().\n}\n";
            case 'ruby':
                return "# Read input with gets; print with puts.\n";
            case 'python':
            default:
                return "# Write your Python code here\n";
        }
    }

    /**
     * Render the per-question Insight report.
     *
     * @param \stdClass $stats the aggregate from question_stats::for_question
     * @param \moodle_url $baseurl the report page URL
     * @return string HTML
     */
    public function render_question_report($stats, \moodle_url $baseurl, array $recent = array()) {
        $context = $this->report_context($stats, $baseurl);
        $context['recent'] = $this->recent_rows($recent);
        $context['hasrecent'] = !empty($context['recent']);
        return $this->render_from_template('qtype_codeatelier/report', $context);
    }

    /**
     * Shape the recent-submissions rows for the template.
     *
     * @param \stdClass[] $recent rows from question_stats::recent_submissions
     * @return array
     */
    protected function recent_rows(array $recent) {
        $rows = array();
        foreach ($recent as $r) {
            $rows[] = array(
                'user' => $r->userfullname !== null ? $r->userfullname : '—',
                'language' => $r->language,
                'graded' => $r->graded,
                'scorepct' => $r->fraction !== null ? round($r->fraction * 100, 1) : null,
                'verdict' => $r->verdict,
                'verdictlabel' => $r->verdict ? $this->verdict_label($r->verdict) : '',
                'time' => userdate($r->submittedtime, get_string('strftimedatetimeshort')),
                'reviewurl' => $r->quizattemptid
                        ? (new \moodle_url('/mod/quiz/review.php', array('attempt' => $r->quizattemptid)))->out(false)
                        : null,
            );
        }
        return $rows;
    }

    /**
     * Render the per-quiz overview: each CodeAtelier question in the quiz with its
     * headline figures and a link to the full per-question report.
     *
     * @param \stdClass[] $summaries one stats object per question (from for_quiz)
     * @param int $cmid the quiz course-module id
     * @return string HTML
     */
    public function render_quiz_report(array $summaries, $cmid) {
        $rows = array();
        foreach ($summaries as $stats) {
            $rows[] = array(
                'name' => format_string($stats->name),
                'reporturl' => (new \moodle_url('/question/type/codeatelier/report.php',
                        array('id' => $stats->questionid, 'cmid' => $cmid)))->out(false),
                'tierlabel' => $stats->tier ? get_string('tier_' . $stats->tier, 'qtype_codeatelier') : '',
                'totalattempts' => $stats->totalattempts,
                'graded' => $stats->graded,
                'needsgrading' => $stats->needsgrading,
                'hasneedsgrading' => $stats->needsgrading > 0,
                'meanscorepct' => $stats->meanscore !== null ? round($stats->meanscore * 100, 1) : null,
                'hasmean' => $stats->meanscore !== null,
            );
        }
        return $this->render_from_template('qtype_codeatelier/report_quiz',
                array('questions' => $rows, 'hasquestions' => !empty($rows)));
    }

    /**
     * Build the Mustache context for the report from the raw aggregate.
     *
     * @param \stdClass $stats
     * @param \moodle_url $baseurl
     * @return array
     */
    protected function report_context($stats, \moodle_url $baseurl) {
        $graded = $stats->graded;

        $scoredist = array();
        foreach (array('zero' => 'report_score_zero', 'partial' => 'report_score_partial',
                'full' => 'report_score_full') as $key => $stringid) {
            $count = $stats->scoredist->$key;
            $scoredist[] = array(
                'label' => get_string($stringid, 'qtype_codeatelier'),
                'count' => $count,
                'pct' => $graded > 0 ? round($count / $graded * 100) : 0,
                'key' => $key,
            );
        }

        $verdicts = array();
        $seen = $stats->verdicts;
        foreach (array('AC', 'WA', 'TLE', 'MLE', 'RE', 'CE', 'INTERNAL') as $v) {
            if (!empty($seen[$v])) {
                $verdicts[] = array('code' => $v, 'label' => $this->verdict_label($v), 'count' => $seen[$v]);
                unset($seen[$v]);
            }
        }
        foreach ($seen as $v => $c) {
            $verdicts[] = array('code' => $v, 'label' => $v, 'count' => $c);
        }

        $pertest = array();
        foreach ($stats->pertest as $row) {
            $pct = $row->passrate === null ? null : round($row->passrate * 100, 1);
            $pertest[] = array(
                'num' => $row->index + 1,
                'visible' => $row->visible,
                'visiblelabel' => $row->visible ? get_string('visible_shown', 'qtype_codeatelier')
                        : get_string('visible_hidden', 'qtype_codeatelier'),
                'passes' => $row->passes,
                'total' => $row->total,
                'passratepct' => $pct,
                'haspassrate' => $pct !== null,
                // Flag a test almost everyone fails — often a wrong expected value.
                'suspect' => $row->total >= 5 && $row->passrate !== null && $row->passrate < 0.2,
            );
        }

        $languages = array();
        arsort($stats->languages);
        foreach ($stats->languages as $lang => $count) {
            $languages[] = array('language' => $lang, 'count' => $count);
        }

        return array(
            'name' => format_string($stats->name),
            'isjudge' => $stats->tier === 'judge',
            'tierlabel' => $stats->tier ? get_string('tier_' . $stats->tier, 'qtype_codeatelier') : '',
            'hasattempts' => $stats->totalattempts > 0,
            'totalattempts' => $stats->totalattempts,
            'graded' => $graded,
            'needsgrading' => $stats->needsgrading,
            'hasneedsgrading' => $stats->needsgrading > 0,
            'meanscorepct' => $stats->meanscore !== null ? round($stats->meanscore * 100, 1) : null,
            'hasmean' => $stats->meanscore !== null,
            'scoredist' => $scoredist,
            'verdicts' => $verdicts,
            'hasverdicts' => !empty($verdicts),
            'pertest' => $pertest,
            'languages' => $languages,
            'multilang' => count($languages) > 1,
            'maxtimems' => $stats->timing->maxtimems > 0 ? round($stats->timing->maxtimems) : null,
            'maxmemory' => $stats->timing->maxmemorykb > 0
                    ? display_size((int) $stats->timing->maxmemorykb * 1024) : null,
            'proctorflagged' => $stats->proctorflagged,
            'hasproctor' => $stats->proctorflagged > 0,
            'coveragepartial' => $stats->detailcoverage->withresult < $stats->detailcoverage->total,
            'coveragenote' => get_string('report_coverage', 'qtype_codeatelier', (object) array(
                    'with' => $stats->detailcoverage->withresult,
                    'total' => $stats->detailcoverage->total)),
            'csvurl' => (new \moodle_url($baseurl, array('format' => 'csv')))->out(false),
        );
    }
}
