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
        $language = !empty($question->language) ? $question->language : 'python';
        // The judge tier is always the stdin/stdout shape (see the question type).
        $scenario = $isjudge ? 'stdinstdout'
            : (!empty($question->scenario) ? $question->scenario : 'stdinstdout');

        $answername = $qa->get_qt_field_name('answer');
        $scorename = $qa->get_qt_field_name('score');
        $wrapperid = $answername . '_wrapper';

        $currentcode = $qa->get_last_qt_var('answer', '');
        if ($currentcode === '') {
            $currentcode = ($question->startercode !== null && $question->startercode !== '')
                ? $question->startercode
                : $this->starter_code($language);
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
        if ($isjudge && $options->readonly) {
            $judgereviewhtml = $this->render_judge_review($question->cached_judge_result($currentcode));
        }

        $validationresponse = $isjudge ? array('answer' => $currentcode) : array('score' => $currentscore);

        $context = array(
            'questiontext' => $question->format_questiontext($qa),
            'wrapperid' => $wrapperid,
            'answername' => $answername,
            'answerid' => $answername,
            'answervalue' => $currentcode,
            'scorename' => $scorename,
            'scorevalue' => $currentscore,
            'readonly' => $options->readonly,
            'isjudge' => $isjudge,
            'judgenote' => $isjudge ? get_string('judgenote', 'qtype_codeatelier') : '',
            'judgereviewhtml' => $judgereviewhtml,
            'hasvalidationerror' => $qa->get_state() == question_state::$invalid,
            'validationerror' => $question->get_validation_error($validationresponse),
        ) + qtype_codeatelier::ui_labels();

        $PAGE->requires->js_call_amd('qtype_codeatelier/control', 'init', array(array(
            'wrapperid' => $wrapperid,
            'language' => $language,
            'scenario' => $scenario,
            'entrypoint' => $question->entrypoint,
            'schemasql' => $isjudge ? null : $question->schemasql,
            'pyodidebaseurl' => get_config('qtype_codeatelier', 'pyodidebaseurl'),
            'sqljsbaseurl' => get_config('qtype_codeatelier', 'sqljsbaseurl'),
            'tests' => $tests,
            'timeoutms' => 5000,
            'readonly' => (bool) $options->readonly,
            'isjudge' => $isjudge,
            'strings' => qtype_codeatelier::ui_strings(),
        )));

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
                get_string('judgeverdict', 'qtype_codeatelier') . ' '
                . html_writer::tag('span', $this->verdict_label($verdict),
                        array('class' => 'codeatelier-verdict codeatelier-verdict-' . $verdict)),
                'codeatelier-judge-verdict');

        if ($verdict === 'CE' && !empty($result['compileoutput'])) {
            $out .= html_writer::tag('pre', s($result['compileoutput']),
                    array('class' => 'codeatelier-compileoutput'));
        }

        $items = '';
        foreach ($result['testresults'] as $tr) {
            $visible = (int) $tr['visibility'] === 1;
            $label = $visible
                ? get_string('result_test', 'qtype_codeatelier', $tr['index'] + 1)
                : get_string('result_hidden', 'qtype_codeatelier');
            $status = !empty($tr['passed'])
                ? get_string('result_pass', 'qtype_codeatelier')
                : $this->status_label((string) $tr['status']);
            $cls = !empty($tr['passed']) ? 'codeatelier-pass' : 'codeatelier-fail';
            $items .= html_writer::tag('li',
                    html_writer::span(s($label), 'codeatelier-test-label') . ' '
                    . html_writer::span(s($status), 'codeatelier-test-status'),
                    array('class' => $cls));
        }
        $out .= html_writer::tag('ul', $items, array('class' => 'codeatelier-judge-tests'));

        return html_writer::div($out, 'codeatelier-judge-review');
    }

    /**
     * A localised label for an overall verdict.
     *
     * @param string $verdict AC | WA | TLE | RE | CE | INTERNAL
     * @return string
     */
    protected function verdict_label($verdict) {
        $known = array('AC', 'WA', 'TLE', 'RE', 'CE');
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
            case 'ce':
                return get_string('verdict_CE', 'qtype_codeatelier');
            case 'fail':
            default:
                return get_string('result_fail', 'qtype_codeatelier');
        }
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
            case 'python':
            default:
                return "# Write your Python code here\n";
        }
    }
}
