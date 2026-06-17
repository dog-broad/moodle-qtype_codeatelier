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
    /**
     * Render the question prompt and the interactive code workbench.
     *
     * @param question_attempt $qa
     * @param question_display_options $options
     * @return string HTML
     */
    public function formulation_and_controls(
        question_attempt $qa,
        question_display_options $options
    ) {

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
            // A project or web question's starting answer is its starter file set
            // (canonical JSON); other scenarios start from the chosen language's
            // starter code.
            if ($scenario === 'project') {
                // The starting answer is the chosen language's flat starter file set,
                // extracted from the per-language environment blob.
                $currentcode = $this->project_starter_answer($question, $chosen);
            } else if ($scenario === 'web') {
                $currentcode = (string) $question->projectfiles;
            } else {
                $currentcode = isset($startermap[$chosen]) ? $startermap[$chosen] : $this->starter_code($chosen);
            }
        }
        $currentscore = $qa->get_last_qt_var('score', '');

        // Ship test cases to the browser. The JUDGE tier ships visible tests only:
        // hidden tests stay on the server, which is the whole integrity point. The
        // PRACTICE tier must ship all tests because the client grades them (those
        // hidden tests are only "polite-hidden").
        $tests = [];
        foreach ($question->tests as $test) {
            if ($isjudge && (int) $test->visibility !== 1) {
                continue;
            }
            $tests[] = [
                'input' => $test->input,
                'expected' => $test->expected,
                'matchmode' => $test->matchmode,
                'tolerance' => $test->tolerance,
                'weight' => $test->weight,
                'visibility' => (int) $test->visibility,
                'checkerquery' => $test->checkerquery,
                // SQL per-test dataset. Hidden tests are already dropped above for the
                // judge tier, so a hidden dataset never ships there; on the practice
                // tier the client grades, so every shipped test carries its dataset.
                'datasetsql' => isset($test->datasetsql) ? $test->datasetsql : null,
                // Ship teacher feedback only for visible tests (hidden reveals nothing client-side).
                'feedback' => ((int) $test->visibility === 1) ? $test->feedback : null,
                // Web scenario: the DOM assertion (selector + property + matcher) and
                // its optional interaction actions.
                'selector' => $test->selector,
                'assertproperty' => $test->assertproperty,
                'assertmatcher' => $test->assertmatcher,
                'actions' => $test->actions,
                // Web scenario: the compound assertion tree (all/any/not), parsed so
                // the client evaluates it directly; null falls back to the flat leaf.
                'assertion' => (isset($test->assertionjson) && trim((string) $test->assertionjson) !== '')
                        ? json_decode($test->assertionjson, true) : null,
            ];
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
                $judgereviewhtml = html_writer::div(
                    get_string('judgepending', 'qtype_codeatelier'),
                    'codeatelier-judge-review codeatelier-judge-pending',
                    ['data-role' => 'gradestatus']
                );
            } else {
                $judgereviewhtml = $this->render_judge_review($cached, $scenario);
            }
            $proctorreviewhtml = $this->render_proctor_summary((int) $qa->get_database_id());
        }

        // A judge attempt can offer "run against examples" only when an endpoint is
        // configured (and never on review).
        // The web scenario grades through the web-judge service, not Judge0, and has
        // no stdin/examples run — its formative feedback is the live preview.
        $canrunexamples = $isjudge && !$options->readonly && $scenario !== 'web'
                && !empty(get_config('qtype_codeatelier', 'judge0baseurl'));
        // The "Run with my own input" scratch console for stdin/stdout and function
        // judge questions (SQL has no stdin; it uses run-against-examples instead).
        $canrunscratch = $canrunexamples && $scenario !== 'sql';
        $scratchlabel = ($scenario === 'function')
                ? get_string('scratchargs', 'qtype_codeatelier')
                : get_string('scratchstdin', 'qtype_codeatelier');
        // Pre-fill the scratch box with the first visible example's input so a
        // student sees the expected shape (a JSON argument array for the function
        // scenario, or the stdin text) rather than guessing at a blank box.
        $scratchdefault = ($canrunscratch && !empty($tests)) ? (string) $tests[0]['input'] : '';

        $validationresponse = $isjudge ? ['answer' => $currentcode] : ['score' => $currentscore];

        // The student-facing language picker shows only when there is a real choice
        // and the attempt is live; on review we show which language was used.
        $languagechoice = count($allowed) > 1 && !$options->readonly;
        $languageoptions = [];
        foreach ($allowed as $code) {
            $languageoptions[] = [
                'code' => $code,
                'label' => get_string('lang_' . $code, 'qtype_codeatelier'),
                'selected' => ($code === $chosen),
            ];
        }

        $context = [
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
            'languageusedlabel' => get_string(
                'languageused',
                'qtype_codeatelier',
                get_string('lang_' . $chosen, 'qtype_codeatelier')
            ),
            'readonly' => $options->readonly,
            'isjudge' => $isjudge,
            'isweb' => ($scenario === 'web'),
            'previewlabel' => get_string('preview', 'qtype_codeatelier'),
            'judgenote' => $isjudge ? get_string('judgenote', 'qtype_codeatelier') : '',
            'judgereviewhtml' => $judgereviewhtml . $proctorreviewhtml,
            'proctornote' => $isjudge ? get_string('proctornote', 'qtype_codeatelier') : '',
            'canrunexamples' => $canrunexamples,
            'runexampleslabel' => get_string('runexamples', 'qtype_codeatelier'),
            'canrunscratch' => $canrunscratch,
            'scratchlabel' => $scratchlabel,
            'scratchdefault' => $scratchdefault,
            'hasvalidationerror' => $qa->get_state() == question_state::$invalid,
            'validationerror' => $question->get_validation_error($validationresponse),
        ] + qtype_codeatelier::ui_labels();

        // The control config is large (visible tests, every UI string, per-language
        // starters), and Moodle warns when js_call_amd arguments exceed 1024 chars.
        // Ship the config as a data attribute on the wrapper and pass only the
        // wrapper id through the AMD init call; control.js reads the rest from the DOM.
        $controlconfig = [
            'wrapperid' => $wrapperid,
            'language' => $chosen,
            'languages' => array_values($allowed),
            'starters' => $startermap,
            'scenario' => $scenario,
            'entrypoint' => $question->entrypoint,
            // The entry/main file. For a project it is the chosen language's entry
            // from the environment blob; web always renders index.html.
            'entryfile' => ($scenario === 'web') ? 'index.html'
                    : (($scenario === 'project') ? $question->project_entry($chosen) : $question->entryfile),
            // Project: the per-language flat starter file sets, so the attempt editor
            // can load the right files when the student switches language.
            'projectstarters' => ($scenario === 'project')
                    ? $this->project_starters($question, $allowed) : null,
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
            'editortheme' => get_user_preferences('qtype_codeatelier_editortheme', 'auto'),
            'strings' => qtype_codeatelier::ui_strings(),
        ];
        $context['controlconfig'] = json_encode($controlconfig);

        $this->page->requires->js_call_amd('qtype_codeatelier/control', 'init', [[
            'wrapperid' => $wrapperid,
        ]]);

        // Soft anti-cheat runs on a live judge-tier attempt only (never on review,
        // never on the formative practice tier).
        if ($isjudge && !$options->readonly) {
            $this->page->requires->js_call_amd(
                'qtype_codeatelier/proctor',
                'init',
                [[
                        'wrapperid' => $wrapperid,
                        'qubaid' => (int) $qa->get_usage_id(),
                        'slot' => (int) $qa->get_slot(),
                ]]
            );
        }

        // Live grade status: poll while a submitted judge attempt awaits the
        // background task, and refresh the page once the grade lands.
        if ($gradingpending) {
            $this->page->requires->js_call_amd('qtype_codeatelier/gradestatus', 'init', [[
                'wrapperid' => $wrapperid,
                'qubaid' => (int) $qa->get_usage_id(),
                'slot' => (int) $qa->get_slot(),
                'grading' => get_string('judgegrading', 'qtype_codeatelier'),
            ]]);
        }

        // The formative "run against examples" loop for the judge tier.
        if ($canrunexamples) {
            $this->page->requires->js_call_amd('qtype_codeatelier/judgerun', 'init', [[
                'wrapperid' => $wrapperid,
                'questionid' => (int) $question->id,
                'language' => $chosen,
                'strings' => [
                    'running' => get_string('running', 'qtype_codeatelier'),
                    'example' => get_string('result_test', 'qtype_codeatelier'),
                    'compileoutput' => get_string('compileoutputlabel', 'qtype_codeatelier'),
                    'input' => get_string('input', 'qtype_codeatelier'),
                    'expected' => get_string('expected', 'qtype_codeatelier'),
                    'got' => get_string('result_got', 'qtype_codeatelier'),
                    'pass' => get_string('result_pass', 'qtype_codeatelier'),
                    'fail' => get_string('result_fail', 'qtype_codeatelier'),
                    're' => get_string('verdict_RE', 'qtype_codeatelier'),
                    'tle' => get_string('verdict_TLE', 'qtype_codeatelier'),
                    'mle' => get_string('verdict_MLE', 'qtype_codeatelier'),
                    'nooutput' => get_string('scratchnooutput', 'qtype_codeatelier'),
                    'clear' => get_string('clear', 'qtype_codeatelier'),
                    'argn' => get_string('argn', 'qtype_codeatelier'),
                    'argsheading' => get_string('argsheading', 'qtype_codeatelier'),
                    'rowsnote' => get_string('rowsnote', 'qtype_codeatelier'),
                    'resultdifflegend' => get_string('resultdifflegend', 'qtype_codeatelier'),
                ],
            ]]);
        }

        return $this->render_from_template('qtype_codeatelier/editor_control', $context);
    }

    /**
     * Build the server-side review block for a judge-tier attempt: the verdict,
     * compile output on a compile error, and a per-test pass/fail list. Hidden
     * tests are shown as pass/fail only and never reveal their expected output.
     *
     * @param array|null $result the memoised judge result, or null on a cache miss
     * @param string $scenario the question scenario (sql renders result tables)
     * @return string HTML
     */
    protected function render_judge_review($result, $scenario = '') {
        if ($result === null) {
            return html_writer::div(
                get_string('judgenoresult', 'qtype_codeatelier'),
                'codeatelier-judge-review text-muted'
            );
        }

        $verdict = isset($result['verdict']) ? (string) $result['verdict'] : 'WA';
        $out = html_writer::div(
            html_writer::span(
                get_string('judgeverdict', 'qtype_codeatelier'),
                'codeatelier-judge-verdict-label'
            ) . ' '
                . html_writer::span(
                    $this->verdict_label($verdict),
                    'codeatelier-verdict codeatelier-verdict-' . $verdict
                ),
            'codeatelier-judge-verdict'
        );

        if ($verdict === 'CE' && !empty($result['compileoutput'])) {
            $out .= html_writer::tag(
                'p',
                get_string('compileoutputlabel', 'qtype_codeatelier'),
                ['class' => 'codeatelier-block-label']
            );
            $out .= html_writer::tag(
                'pre',
                s($result['compileoutput']),
                ['class' => 'codeatelier-compileoutput']
            );
        }

        // A custom checker decided AC/WA, so a passing answer may differ from the
        // shown Expected — note it so the comparison is not read as authoritative.
        if (!empty($result['checker'])) {
            $out .= html_writer::div(
                get_string('gradedbychecker', 'qtype_codeatelier'),
                'codeatelier-checker-note text-muted'
            );
        }

        $items = '';
        foreach ($result['testresults'] as $tr) {
            $items .= $this->render_judge_test_row($tr, $scenario);
        }
        $out .= html_writer::tag('ul', $items, ['class' => 'codeatelier-judge-tests']);

        return html_writer::div($out, 'codeatelier-judge-review');
    }

    /**
     * The soft anti-cheat activity summary for a judge-tier review: a count per
     * flagged event type, read from the server-side append-only proctor log. Empty when
     * nothing was flagged. Detection, not prevention — a student can decline to send an
     * event, but what is recorded here is server-authoritative.
     *
     * @param int $questionattemptid the question_attempts.id
     * @return string HTML (empty when there is nothing to show)
     */
    protected function render_proctor_summary($questionattemptid) {
        $counts = \qtype_codeatelier\local\integrity\proctor_log::summarise($questionattemptid);
        if (!$counts) {
            return '';
        }

        $items = '';
        foreach ($counts as $type => $count) {
            $items .= html_writer::tag('li', s($this->proctor_label($type)) . ': ' . (int) $count);
        }

        return html_writer::div(
            html_writer::tag('h6', get_string('proctorsummary', 'qtype_codeatelier'))
                . html_writer::tag('ul', $items, ['class' => 'codeatelier-proctor-list']),
            'codeatelier-proctor-summary'
        );
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
            case 'extpaste':
            case 'paste':
                return get_string('proctor_extpaste', 'qtype_codeatelier');
            case 'extdrop':
                return get_string('proctor_extdrop', 'qtype_codeatelier');
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
     * @param string $scenario the question scenario
     * @return string HTML
     */
    protected function render_judge_test_row(array $tr, $scenario = '') {
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
            . html_writer::span(
                s($statuslabel),
                'codeatelier-test-status codeatelier-status-' . ($passed ? 'pass' : $status)
            );
        // Show the subtask group so grouped (all-or-nothing) scoring is legible.
        if (!empty($tr['subtask'])) {
            $head .= ' ' . html_writer::span(
                s(get_string('subtaskgroup', 'qtype_codeatelier', (int) $tr['subtask'])),
                'codeatelier-test-subtask'
            );
        }
        $head .= $this->render_test_meta($tr);

        $detail = '';
        if ($visible && !$passed) {
            if (isset($tr['check'])) {
                // A web (DOM-assertion) row: the check and the produced value.
                $detail = $this->render_web_test_detail($tr);
            } else if (($status === 're' || $status === 'mle') && !empty($tr['stderr'])) {
                $detail = html_writer::tag('pre', s($tr['stderr']), ['class' => 'codeatelier-test-error']);
            } else if ($status === 'fail') {
                $detail = $this->render_test_diff($tr, $scenario);
            }
        }

        $cls = 'codeatelier-judge-test ' . ($passed ? 'codeatelier-pass' : 'codeatelier-fail');
        return html_writer::tag('li', $head . $detail, ['class' => $cls]);
    }

    /**
     * The time/memory meta for a test row, when measured.
     *
     * @param array $tr a per-test result
     * @return string HTML (empty when no measurements)
     */
    protected function render_test_meta(array $tr) {
        $parts = [];
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
     * @param string $scenario the question scenario
     * @return string HTML
     */
    protected function render_test_diff(array $tr, $scenario = '') {
        // SQL: expected/actual are JSON row arrays — render result tables (with the
        // differing rows highlighted) for parity with the practice tier and the
        // run-against-examples view, instead of dumping raw JSON text.
        if ($scenario === 'sql') {
            $expectedrows = json_decode(isset($tr['expected']) ? (string) $tr['expected'] : '', true);
            $actualrows = json_decode(isset($tr['actual']) ? (string) $tr['actual'] : '', true);
            if (is_array($expectedrows) && is_array($actualrows)) {
                // The actual run's column names header both tables (same query shape).
                $columns = json_decode(isset($tr['columns']) ? (string) $tr['columns'] : '', true);
                $columns = is_array($columns) ? $columns : [];
                $rows = $this->sql_result_table(
                    get_string('expected', 'qtype_codeatelier'),
                    $columns,
                    $expectedrows,
                    $this->extra_row_keys($expectedrows, $actualrows),
                    'expected'
                );
                $rows .= $this->sql_result_table(
                    get_string('result_got', 'qtype_codeatelier'),
                    $columns,
                    $actualrows,
                    $this->extra_row_keys($actualrows, $expectedrows),
                    'got'
                );
                $rows .= html_writer::tag(
                    'p',
                    s(get_string('resultdifflegend', 'qtype_codeatelier')),
                    ['class' => 'codeatelier-diff-legend']
                );
                return html_writer::div($rows, 'codeatelier-test-diff');
            }
        }

        $rows = '';
        if (isset($tr['input']) && trim((string) $tr['input']) !== '') {
            $rows .= $this->diff_line(get_string('input', 'qtype_codeatelier'), $tr['input'], 'input');
        }
        $rows .= $this->diff_line(
            get_string('expected', 'qtype_codeatelier'),
            isset($tr['expected']) ? $tr['expected'] : '',
            'expected'
        );
        $rows .= $this->diff_line(
            get_string('result_got', 'qtype_codeatelier'),
            isset($tr['actual']) ? $tr['actual'] : '',
            'got'
        );
        return html_writer::div($rows, 'codeatelier-test-diff');
    }

    /**
     * The JSON keys of rows present in $rows but not in $other (multiset), so the
     * differing rows in a SQL diff can be highlighted. Mirrors the JS extraRowKeys.
     *
     * @param array $rows
     * @param array $other
     * @return array set of json_encode(row) => true
     */
    protected function extra_row_keys(array $rows, array $other) {
        $counts = [];
        foreach ($other as $row) {
            $key = json_encode($row);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $extra = [];
        foreach ($rows as $row) {
            $key = json_encode($row);
            if (!empty($counts[$key])) {
                $counts[$key]--;
            } else {
                $extra[$key] = true;
            }
        }
        return $extra;
    }

    /**
     * A labelled SQL result-set table (one diff side), bounded + scrollable, with
     * the differing rows highlighted. Matches the markup the JS renderer emits so
     * the shared CSS styles both tiers identically. Display is capped at 200 rows
     * (a failed-test review, so generous enough never to hide a differing row).
     *
     * @param string $label the row label (Expected / Got)
     * @param array $columns the column names (header), or empty for none
     * @param array $rows the rows (each an array of cell values)
     * @param array $extrakeys json_encode(row) => true for rows to highlight
     * @param string $which expected | got (drives the per-side row marker + styling)
     * @return string HTML
     */
    protected function sql_result_table($label, array $columns, array $rows, array $extrakeys, $which) {
        $cap = 200;
        $shown = array_slice($rows, 0, $cap);
        $head = '';
        // Header only when the column count matches the rows (empty result keeps its
        // header); a wrong-arity result falls back to headerless.
        $headered = $columns && (!$rows || (isset($rows[0]) && is_array($rows[0])
            && count($rows[0]) === count($columns)));
        if ($headered) {
            $hcells = '';
            foreach ($columns as $name) {
                $hcells .= html_writer::tag('th', s((string) $name));
            }
            $head = html_writer::tag('thead', html_writer::tag('tr', $hcells));
        }
        $body = '';
        foreach ($shown as $row) {
            $cells = '';
            foreach ((array) $row as $value) {
                $cells .= html_writer::tag('td', $value === null ? 'NULL' : s((string) $value));
            }
            // codeatelier-row-diff (NOT codeatelier-diff-row, which is display:flex).
            $trclass = !empty($extrakeys[json_encode($row)]) ? 'codeatelier-row-diff' : '';
            $body .= html_writer::tag('tr', $cells, $trclass ? ['class' => $trclass] : []);
        }
        $table = html_writer::tag(
            'table',
            $head . html_writer::tag('tbody', $body),
            ['class' => 'codeatelier-resultset codeatelier-resultset-' . $which]
        );
        $block = html_writer::div(
            html_writer::div($table, 'codeatelier-table-scroll'),
            'codeatelier-resultset-block'
        );
        if (count($rows) > $cap) {
            // The string uses {shown}/{total} (so the JS renderer can substitute too),
            // not Moodle's {$a->..}, so replace by hand here.
            $note = str_replace(
                ['{shown}', '{total}'],
                [$cap, count($rows)],
                get_string('rowsnote', 'qtype_codeatelier')
            );
            $block .= html_writer::tag('p', s($note), ['class' => 'codeatelier-rows-note']);
        }
        return html_writer::div(
            html_writer::span(s($label), 'codeatelier-diff-label') . $block,
            'codeatelier-diff-row codeatelier-diff-' . $which
        );
    }

    /**
     * The check / got detail for a failed visible web (DOM-assertion) test.
     *
     * @param array $tr a per-test result carrying 'check' and 'actual'
     * @return string HTML
     */
    protected function render_web_test_detail(array $tr) {
        $rows = $this->diff_line(
            get_string('assertexpect', 'qtype_codeatelier'),
            isset($tr['check']) ? $tr['check'] : '',
            'expected'
        );
        $rows .= $this->diff_line(
            get_string('result_got', 'qtype_codeatelier'),
            isset($tr['actual']) ? $tr['actual'] : '',
            'got'
        );
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
                . html_writer::tag('pre', s((string) $value), ['class' => 'codeatelier-diff-value']),
            'codeatelier-diff-row codeatelier-diff-' . $which
        );
    }

    /**
     * A localised label for an overall verdict.
     *
     * @param string $verdict AC | WA | TLE | RE | CE | INTERNAL
     * @return string
     */
    protected function verdict_label($verdict) {
        $known = ['AC', 'WA', 'TLE', 'MLE', 'RE', 'CE'];
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
     * The flat starter file set (canonical [{name, content}] JSON) for one language
     * of a project, extracted from the per-language environment blob. Falls back to
     * the primary language's environment when the requested one is absent.
     *
     * @param qtype_codeatelier_question $question
     * @param string $language
     * @return string the canonical file-set JSON
     */
    protected function project_starter_answer($question, $language) {
        $envs = \qtype_codeatelier\judge\project_packager::decode_envs((string) $question->projectfiles);
        $lang = isset($envs[$language]) ? $language : $question->language;
        $env = isset($envs[$lang]) ? $envs[$lang] : null;
        $files = $env ? \qtype_codeatelier\judge\project_packager::starter_files($env) : [];
        return \qtype_codeatelier\judge\project_packager::encode_files($files);
    }

    /**
     * The per-language flat starter file sets for a project, keyed by language, so
     * the attempt editor can load a language's files when the student switches.
     *
     * @param qtype_codeatelier_question $question
     * @param array $allowed the allowed languages
     * @return array language => canonical file-set JSON
     */
    protected function project_starters($question, array $allowed) {
        $map = [];
        foreach ($allowed as $lang) {
            $map[$lang] = $this->project_starter_answer($question, $lang);
        }
        return $map;
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
            $overrides = [];
        }
        $map = [];
        foreach ($allowed as $code) {
            if (isset($overrides[$code]) && trim((string) $overrides[$code]) !== '') {
                $map[$code] = (string) $overrides[$code];
            } else if (
                $code === $question->language
                    && $question->startercode !== null && $question->startercode !== ''
            ) {
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
                return "#include <iostream>\nusing namespace std;\n\nint main() {\n"
                    . "    // Read input, print output\n    return 0;\n}\n";
            case 'java':
                // Judge0 compiles the public class named Main.
                return "import java.util.*;\n\npublic class Main {\n    public static void main(String[] args) {\n"
                    . "        Scanner sc = new Scanner(System.in);\n        // Read input, print output\n    }\n}\n";
            case 'go':
                return "package main\n\nimport (\n\t\"bufio\"\n\t\"fmt\"\n\t\"os\"\n)\n\n"
                    . "func main() {\n\treader := bufio.NewReader(os.Stdin)\n\t_ = reader\n"
                    . "\t// Read input, print output\n\t_ = fmt.Println\n}\n";
            case 'rust':
                return "use std::io::{self, Read};\n\nfn main() {\n    let mut input = String::new();\n"
                    . "    io::stdin().read_to_string(&mut input).unwrap();\n    // Read input, print output\n}\n";
            case 'php':
                return "<?php\n// Read input with fgets(STDIN); print output with echo.\n";
            case 'typescript':
                // The 'declare' line keeps the older tsc on Judge0 happy without @types/node.
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
    public function render_question_report(
        $stats,
        \moodle_url $baseurl,
        array $recent = [],
        array $similarity = []
    ) {
        $context = $this->report_context($stats, $baseurl);
        $context['recent'] = $this->recent_rows($recent);
        $context['hasrecent'] = !empty($context['recent']);
        $context['similarity'] = $this->similarity_context($similarity);
        $context['hassimilarity'] = !empty($context['similarity']);
        return $this->render_from_template('qtype_codeatelier/report', $context);
    }

    /**
     * Shape the per-language similarity analysis for the template.
     *
     * Each language group is either analyzable (with zero or more clusters of similar
     * submissions) or reported low-signal with the reason it can't be analysed — the
     * report never presents a bare "% similar" without that context.
     *
     * @param \stdClass[] $similarity per-language results from cohort_analyser::analyse
     * @return array
     */
    protected function similarity_context(array $similarity) {
        $groups = [];
        foreach ($similarity as $lang) {
            $clusters = [];
            foreach ($lang->clusters as $cluster) {
                $members = [];
                foreach ($cluster->members as $m) {
                    $members[] = [
                        'label' => $m->label,
                        'reviewurl' => $m->quizattemptid
                                ? (new \moodle_url(
                                    '/mod/quiz/review.php',
                                    ['attempt' => $m->quizattemptid]
                                ))->out(false)
                                : null,
                    ];
                }
                $pairs = [];
                foreach ($cluster->pairs as $p) {
                    $pairs[] = ['a' => $p->a, 'b' => $p->b, 'simpct' => round($p->sim * 100)];
                }
                $maxsimpct = round($cluster->maxsim * 100);
                $clusters[] = [
                    'members' => $members,
                    'pairs' => $pairs,
                    'clusterlabel' => get_string('report_sim_cluster', 'qtype_codeatelier', $cluster->size),
                    'simlabel' => get_string('report_sim_upto', 'qtype_codeatelier', $maxsimpct),
                ];
            }
            $note = '';
            if ($lang->reason === \qtype_codeatelier\local\integrity\cohort_analyser::REASON_TOOSHORT) {
                $note = get_string('report_sim_tooshort', 'qtype_codeatelier');
            } else if ($lang->reason === \qtype_codeatelier\local\integrity\cohort_analyser::REASON_UNIFORM) {
                $note = get_string('report_sim_uniform', 'qtype_codeatelier');
            } else if (empty($clusters)) {
                $note = get_string('report_sim_noclusters', 'qtype_codeatelier');
            }
            $groups[] = [
                'language' => $lang->language,
                'count' => $lang->count,
                'analyzable' => $lang->analyzable,
                'note' => $note,
                'hasnote' => $note !== '',
                'clusters' => $clusters,
                'hasclusters' => !empty($clusters),
            ];
        }
        return $groups;
    }

    /**
     * Shape the recent-submissions rows for the template.
     *
     * @param \stdClass[] $recent rows from question_stats::recent_submissions
     * @return array
     */
    protected function recent_rows(array $recent) {
        $rows = [];
        foreach ($recent as $r) {
            $rows[] = [
                'user' => $r->userfullname !== null ? $r->userfullname : '—',
                'language' => $r->language,
                'graded' => $r->graded,
                'scorepct' => $r->fraction !== null ? round($r->fraction * 100, 1) : null,
                'verdict' => $r->verdict,
                'verdictlabel' => $r->verdict ? $this->verdict_label($r->verdict) : '',
                'time' => userdate($r->submittedtime, get_string('strftimedatetimeshort')),
                'reviewurl' => $r->quizattemptid
                        ? (new \moodle_url('/mod/quiz/review.php', ['attempt' => $r->quizattemptid]))->out(false)
                        : null,
            ];
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
        $rows = [];
        $haspractice = false;
        foreach ($summaries as $stats) {
            $ispractice = $stats->tier === 'practice';
            $haspractice = $haspractice || $ispractice;
            $rows[] = [
                'name' => format_string($stats->name),
                'reporturl' => (new \moodle_url(
                    '/question/type/codeatelier/report.php',
                    ['id' => $stats->questionid, 'cmid' => $cmid]
                ))->out(false),
                'tierlabel' => $stats->tier ? get_string('tier_' . $stats->tier, 'qtype_codeatelier') : '',
                'ispractice' => $ispractice,
                'totalattempts' => $stats->totalattempts,
                'graded' => $stats->graded,
                'needsgrading' => $stats->needsgrading,
                'hasneedsgrading' => $stats->needsgrading > 0,
                'meanscorepct' => $stats->meanscore !== null ? round($stats->meanscore * 100, 1) : null,
                'hasmean' => $stats->meanscore !== null,
            ];
        }
        return $this->render_from_template(
            'qtype_codeatelier/report_quiz',
            ['questions' => $rows, 'hasquestions' => !empty($rows),
            'haspractice' => $haspractice]
        );
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

        $scoredist = [];
        foreach (
            ['zero' => 'report_score_zero', 'partial' => 'report_score_partial',
                'full' => 'report_score_full'] as $key => $stringid
        ) {
            $count = $stats->scoredist->$key;
            $scoredist[] = [
                'label' => get_string($stringid, 'qtype_codeatelier'),
                'count' => $count,
                'pct' => $graded > 0 ? round($count / $graded * 100) : 0,
                'key' => $key,
            ];
        }

        $verdicts = [];
        $seen = $stats->verdicts;
        foreach (['AC', 'WA', 'TLE', 'MLE', 'RE', 'CE', 'INTERNAL'] as $v) {
            if (!empty($seen[$v])) {
                $verdicts[] = ['code' => $v, 'label' => $this->verdict_label($v), 'count' => $seen[$v]];
                unset($seen[$v]);
            }
        }
        foreach ($seen as $v => $c) {
            $verdicts[] = ['code' => $v, 'label' => $v, 'count' => $c];
        }

        $pertest = [];
        foreach ($stats->pertest as $row) {
            $pct = $row->passrate === null ? null : round($row->passrate * 100, 1);
            $pertest[] = [
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
            ];
        }

        $languages = [];
        arsort($stats->languages);
        foreach ($stats->languages as $lang => $count) {
            $languages[] = ['language' => $lang, 'count' => $count];
        }

        return [
            'name' => format_string($stats->name),
            'isjudge' => $stats->tier === 'judge',
            'ispractice' => $stats->tier === 'practice',
            'tierlabel' => $stats->tier ? get_string('tier_' . $stats->tier, 'qtype_codeatelier') : '',
            'hasattempts' => $stats->totalattempts > 0,
            'totalattempts' => $stats->totalattempts,
            'graded' => $graded,
            'hasgraded' => $graded > 0,
            'needsgrading' => $stats->needsgrading,
            'hasneedsgrading' => $stats->needsgrading > 0,
            'meanscorepct' => $stats->meanscore !== null ? round($stats->meanscore * 100, 1) : null,
            'hasmean' => $stats->meanscore !== null,
            'scoredist' => $scoredist,
            'verdicts' => $verdicts,
            'hasverdicts' => !empty($verdicts),
            'pertest' => $pertest,
            // Per-test pass rates, time and memory are best-effort detail from the
            // judge memo; with no live cache entry there is nothing to show, so the
            // table is hidden rather than rendered as a column of zeros.
            'hasdetail' => $stats->detailcoverage->withresult > 0,
            'languages' => $languages,
            'multilang' => count($languages) > 1,
            'maxtimems' => $stats->timing->maxtimems > 0 ? round($stats->timing->maxtimems) : null,
            'maxmemory' => $stats->timing->maxmemorykb > 0
                    ? display_size((int) $stats->timing->maxmemorykb * 1024) : null,
            'proctorflagged' => $stats->proctorflagged,
            'hasproctor' => $stats->proctorflagged > 0,
            'coveragepartial' => $stats->detailcoverage->withresult < $stats->detailcoverage->total,
            'coveragenote' => get_string('report_coverage', 'qtype_codeatelier', (object) [
                    'with' => $stats->detailcoverage->withresult,
                    'total' => $stats->detailcoverage->total]),
            'csvurl' => (new \moodle_url($baseurl, ['format' => 'csv']))->out(false),
        ];
    }
}
