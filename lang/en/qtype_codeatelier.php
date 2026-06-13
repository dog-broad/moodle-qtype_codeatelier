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
 * Strings for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addmoretestcases'] = 'Add another test case';
$string['allornothing'] = 'All-or-nothing grading';
$string['allornothing_help'] = 'When ticked, a submission scores full marks only if it passes every test case; otherwise it scores zero. When unticked, marks are the weighted fraction of tests passed (partial credit).';
$string['allornothing_label'] = 'Award full marks only if every test passes';
$string['answerlabel'] = 'Your code';
$string['assertabsent'] = 'is absent';
$string['assertactions'] = 'Actions before checking (optional)';
$string['assertactions_help'] = 'Interaction steps run before the check, one per line: `click SELECTOR` or `type SELECTOR VALUE`. For example `click #add` then check that a new item appeared. Each check starts from a fresh render of the page.';
$string['assertafter'] = 'After doing';
$string['assertexists'] = 'exists';
$string['assertexpect'] = 'Expect';
$string['assertmatch_contains'] = 'contains';
$string['assertmatch_equals'] = 'equals';
$string['assertmatch_regex'] = 'matches regex';
$string['assertmatcher'] = 'Compare';
$string['assertnotfound'] = '(element not found)';
$string['assertproperty'] = 'Property';
$string['assertproperty_help'] = 'What to check on the matched element: `text` (its visible text), `exists` (the element is present — Expected `true` or `false`), `count` (how many elements match — Expected a number), `attr:NAME` (an attribute, e.g. `attr:href`), or `style:PROP` (a computed style, e.g. `style:color`, compared as the browser reports it such as `rgb(0, 0, 255)`).';
$string['assertpropertyinvalid'] = 'Use text, exists, count, attr:NAME or style:PROP.';
$string['argn'] = 'Argument {$a}';
$string['argsheading'] = 'Run with your own arguments';
$string['assertselector'] = 'CSS selector';
$string['callpreview'] = 'Call preview';
$string['codeblockhelp'] = 'Adding code to the question text';
$string['codeblockhelp_body'] = 'To show students syntax-highlighted code, switch the question text editor to its HTML (source) view and wrap the code in a language-tagged block like the one below — it is highlighted automatically when the student opens the question. Supported languages: {$a}.';
$string['callpreviewhint'] = 'Enter the arguments as a JSON array, e.g. [3, 4]. A function taking one list needs it wrapped: [[1, 2, 3]].';
$string['capturedone'] = 'Captured expected output for {$a} test case(s) — review them before saving.';
$string['captureerrors'] = 'Captured {$a->ok} of {$a->total} — {$a->failed} case(s) errored and were left unchanged.';
$string['capturefirsterror'] = 'First error: {$a}';
$string['captureexpected'] = 'Capture expected outputs';
$string['captureexpected_help'] = 'Write a Reference solution above, then capture its output for every test case into the Expected fields (Secure mode runs it on the server; Practice mode runs it in your browser). Review the captured values before saving.';
$string['capturenoreference'] = 'Write a reference solution first.';
$string['capturenotests'] = 'Add at least one test case with an input first.';
$string['capturepreviewfirst'] = 'Refresh the preview and write a solution in its editor first.';
$string['checkerinvalid'] = 'The custom checker could not be verified: {$a}. Fix the checker before saving.';
$string['checkerlang'] = 'Checker language';
$string['checkerlang_help'] = 'The language the custom checker is written in. It can differ from the student\'s language (for example a Python checker grading C++ answers).';
$string['checkerlangrequired'] = 'Choose a language for the custom checker.';
$string['checkerneedsreference'] = 'Provide a reference solution so the custom checker can be verified on save.';
$string['checkerquery'] = 'Checker query (SQL)';
$string['checkerquery_help'] = 'For questions that change data (INSERT/UPDATE/DELETE): a SELECT run after the student\'s statements; its result set is compared with the expected result. Leave empty to compare the student query\'s own result set. In Secure mode the student answer should be a mutation (non-SELECT) when a checker query is set, so only the checker\'s rows are compared.';
$string['checkerscenario'] = 'A custom checker is only available for Secure mode with the stdin/stdout question format.';
$string['checkersource'] = 'Custom checker (special judge)';
$string['checkersource_help'] = 'Secure mode, stdin/stdout only. A program that decides whether each answer is correct — for problems with many valid answers (any valid path, any order, a tolerance). Leave blank to compare against the Expected output with the matching rule.

The checker is given three files in its working directory, named on its command line in this order: **input** (the test input), **expected** (the Expected output), **output** (the student\'s output). Open argv[1], argv[2], argv[3]. Print **AC** on the first line to accept the answer, or **WA** to reject it.

A reference solution is required so the checker is verified on save (its output for every test must be accepted).';
$string['checklabel'] = 'Check';
$string['chooselanguage'] = 'Language';
$string['compileoutputlabel'] = 'What the compiler reported:';
$string['consolelabel'] = 'Output';
$string['cputimelimit'] = 'CPU-time limit (seconds)';
$string['cputimelimit_help'] = 'Secure mode only. The CPU-time limit for each run of this question, in seconds. Leave blank to use the site default.';
$string['datafiles'] = 'Support / data files';
$string['datafiles_help'] = 'Secure mode only. Files you upload here are placed in the working directory next to the student\'s program, so it can read them at run time (for example a CSV the program opens). Practice mode (in the browser) does not provide these files.';
$string['degraded'] = 'The code runtime could not be loaded. Check your connection, or ask your teacher.';
$string['editor_addtest'] = 'Add test case';
$string['editor_append'] = 'Append to existing';
$string['editor_count'] = '{$a} test cases';
$string['editor_delete'] = 'Delete';
$string['editor_done'] = 'Done';
$string['editor_edit'] = 'Edit';
$string['editor_empty'] = 'No test cases yet — add one, or import a set below.';
$string['editor_export'] = 'Export';
$string['editor_format_csv'] = 'CSV';
$string['editor_format_json'] = 'JSON';
$string['editor_format_tsv'] = 'Spreadsheet (tab-separated)';
$string['editor_hidden'] = 'Hidden';
$string['editor_import'] = 'Bulk add / import';
$string['editor_importerror'] = 'Could not parse the input: {$a}';
$string['editor_importformat'] = 'Format';
$string['editor_importhelp'] = 'The first row names the columns. Download a template to see the columns and an example for this question format. Columns you omit use their defaults (match mode "trimmed", weight 1, visible); unrecognised columns are ignored.';
$string['editor_importok'] = 'Imported {$a} test case(s).';
$string['editor_movedown'] = 'Move down';
$string['editor_moveup'] = 'Move up';
$string['editor_page'] = 'Page {$a->page} of {$a->pages}';
$string['editor_paste'] = 'Paste data here, or upload a file';
$string['editor_replace'] = 'Replace all';
$string['editor_search'] = 'Search test cases';
$string['editor_template'] = 'Download template';
$string['editor_upload'] = 'Upload file';
$string['editor_visible'] = 'Visible';
$string['editorfind'] = 'Find / replace';
$string['editorshortcuts'] = '<ul class="codeatelier-shortcut-list">
<li><kbd>Ctrl/Cmd</kbd>+<kbd>F</kbd> — find and replace</li>
<li><kbd>Ctrl/Cmd</kbd>+<kbd>D</kbd> — select the next occurrence (multiple cursors)</li>
<li><kbd>Ctrl/Cmd</kbd>+<kbd>/</kbd> — toggle line comment</li>
<li><kbd>Alt</kbd>+<kbd>↑</kbd> / <kbd>↓</kbd> — move the current line up or down</li>
<li><kbd>Shift</kbd>+<kbd>Alt</kbd>+<kbd>↓</kbd> — duplicate the current line</li>
<li><kbd>Ctrl/Cmd</kbd>+<kbd>Z</kbd> / <kbd>Y</kbd> — undo / redo</li>
<li><kbd>Tab</kbd> / <kbd>Shift</kbd>+<kbd>Tab</kbd> — indent or outdent (press <kbd>Esc</kbd> first to tab out of the editor)</li>
</ul>';
$string['editorshortcutstitle'] = 'Editor keyboard shortcuts';
$string['editortheme'] = 'Editor theme';
$string['editortheme_auto'] = 'Theme: Auto';
$string['editortheme_dark'] = 'Theme: Dark';
$string['editortheme_hc'] = 'Theme: High contrast';
$string['editortheme_light'] = 'Theme: Light';
$string['entryfile'] = 'Entry file';
$string['entryfile_help'] = 'The name of the file the server compiles and runs as the program\'s entry point (for example `main.cpp`, or `Main.java` whose class is run). It must be one of the project files below.';
$string['entryfilemissing'] = 'The entry file must be one of the project files.';
$string['entryfilerequired'] = 'Enter the entry file name (the file the program runs from).';
$string['entrypoint'] = 'Function name';
$string['entrypoint_help'] = 'The name of the function the student must define. Each test\'s Input is a **JSON array of the arguments** passed to the call: `[2, 3]` calls `f(2, 3)`, while a function taking a single list needs it wrapped, `[[1, 2, 3]]`, to call `f([1, 2, 3])`. The function\'s return value is compared with Expected.';
$string['entrypointrequired'] = 'Enter the function name for a function question.';
$string['examples'] = 'Examples';
$string['examplesregion'] = 'Worked examples (scrollable)';
$string['exitfocus'] = 'Exit focus';
$string['expected'] = 'Expected output';
$string['focus'] = 'Focus';
$string['focusmode'] = 'Code workbench (focus mode)';
$string['functionlanguageset'] = 'A function question supports Python and JavaScript in Practice mode. In Secure mode it supports Python, JavaScript, Ruby, PHP, TypeScript (no signature), and Java, C++, C, Go, Rust, C#, Kotlin (with a typed signature; C is scalar-only).';
$string['functionsignature'] = 'Function signature';
$string['functionsignature_help'] = 'Required for compiled/static languages (Java, C++, Go, Rust, C#, Kotlin), which cannot be called without knowing the types. Declare the parameter types and return type, for example `int, int -> int`, `string -> bool`, or `list<int> -> list<int>`.

Types: int, long, double, bool, string; one-dimensional lists list<int>, list<double>, list<bool>, list<string>; and string-keyed maps map<string, int>, map<string, double>, map<string, bool>, map<string, string>. (C supports scalar types only.) Dynamic languages (Python, JavaScript, Ruby, PHP, TypeScript) ignore this field.';
$string['gradedbychecker'] = 'This question is graded by a custom checker, so a correct answer may differ from the Expected output shown.';
$string['input'] = 'Input';
$string['judge0apikey'] = 'Judge0 auth token';
$string['judge0apikey_desc'] = 'Optional authentication token sent with each submission (a self-hosted Judge0 auth token, or a hosted-gateway API key). Leave empty if the instance needs no authentication.';
$string['judge0baseurl'] = 'Judge0 base URL';
$string['judge0baseurl_desc'] = 'Base URL of the Judge0 instance the server submits code to, for example http://judge.example.org:2358. Judge0 must run on a host configured for cgroup v1. Leave empty until a Judge0 instance is available.';
$string['judge0cputimelimit'] = 'Default CPU-time limit (seconds)';
$string['judge0cputimelimit_desc'] = 'The default per-run CPU-time limit for Secure-mode questions, in seconds. Individual questions may override this.';
$string['judge0memorylimit'] = 'Default memory limit (KB)';
$string['judge0memorylimit_desc'] = 'The default per-run memory limit for Secure-mode questions, in kilobytes. Individual questions may override this.';
$string['judgecacheretention'] = 'Grading cache retention (days)';
$string['judgecacheretention_desc'] = 'How long saved Secure-mode grading results are kept before a daily cleanup task removes them. The cache only speeds up review and regrade; a removed entry is recomputed when next needed. Set to 0 to keep entries forever.';
$string['judgegrading'] = 'Grading your submission…';
$string['judgeheading'] = 'Secure mode (server-side grading)';
$string['judgeheading_desc'] = 'Secure mode runs and grades submissions on the server (Judge0) with enforced time and memory limits and hidden tests that never reach the browser. This is the official grading path. Leave the base URL empty to disable it.';
$string['judgememory'] = '{$a}';
$string['judgenoresult'] = 'No graded result is available for this attempt.';
$string['judgenotconfigured'] = 'No grading server is configured. Ask your administrator to set the Judge0 base URL.';
$string['judgenote'] = 'This question is graded on the server against hidden tests when you submit. The editor is for writing and reviewing your code.';
$string['judgepending'] = 'Awaiting automatic grading. Your submission has been received and will be graded shortly — refresh in a moment.';
$string['judgetime'] = '{$a} ms';
$string['judgeverdict'] = 'Result:';
$string['lang_c'] = 'C';
$string['lang_cpp'] = 'C++';
$string['lang_csharp'] = 'C#';
$string['lang_go'] = 'Go';
$string['lang_java'] = 'Java';
$string['lang_javascript'] = 'JavaScript';
$string['lang_kotlin'] = 'Kotlin';
$string['lang_php'] = 'PHP';
$string['lang_python'] = 'Python';
$string['lang_ruby'] = 'Ruby';
$string['lang_rust'] = 'Rust';
$string['lang_sql'] = 'SQL';
$string['lang_typescript'] = 'TypeScript';
$string['language'] = 'Language';
$string['language_help'] = 'The primary (default) language the student writes in. Practice mode: Python (Pyodide), SQL (sql.js) or JavaScript, run in the browser. Secure mode: C, C++, Java, Python, JavaScript, SQL (SQLite), Go, Rust, PHP, TypeScript, C#, Kotlin or Ruby, run on the server. When you allow several languages below, this is the one selected by default.';
$string['languagenotjudge'] = 'Secure mode supports C, C++, Java, Python and JavaScript. SQL is a Practice-mode language.';
$string['languagenotpractice'] = 'Practice mode supports Python, SQL and JavaScript. C, C++ and Java require Secure mode.';
$string['languages'] = 'Allowed languages';
$string['languages_help'] = 'The set of languages the student may choose from when attempting the question. Leave empty for a single-language question (the Language above). All chosen languages must belong to the same grading mode. SQL questions are single-language; function questions support Python and JavaScript.';
$string['languageused'] = 'Language used: {$a}';
$string['limitpositive'] = 'Enter a positive number, or leave blank for the site default.';
$string['loadingeditor'] = 'Loading editor…';
$string['match_exact'] = 'Exact match';
$string['match_float'] = 'Numeric (within tolerance)';
$string['match_regex'] = 'Regular expression';
$string['match_resultset'] = 'Result set (rows, any order)';
$string['match_resultset_ordered'] = 'Result set (rows, in order)';
$string['match_structured'] = 'Structured (deep-equal JSON value)';
$string['match_trimmed'] = 'Trimmed (ignore leading/trailing whitespace)';
$string['matchmode'] = 'Matching rule';
$string['memorylimit'] = 'Memory limit (KB)';
$string['memorylimit_help'] = 'Secure mode only. The memory limit for each run of this question, in kilobytes. Leave blank to use the site default.';
$string['noassertions'] = 'Add at least one check (a CSS selector and what to check).';
$string['noresult'] = 'No rows returned.';
$string['notcodeatelierquestion'] = 'That is not a CodeAtelier question.';
$string['notenoughtests'] = 'You must provide at least one test case.';
$string['notjudgequestion'] = 'This is not a Secure-mode CodeAtelier question.';
$string['output'] = 'Output';
$string['outputempty'] = 'Run your code to see output and test results.';
$string['outputtabs'] = 'Output and test results';
$string['pickaddrow'] = 'Add another check row below, then pick again.';
$string['pickcancel'] = 'Click an element in the preview…';
$string['pickelement'] = 'Pick element';
$string['pickfilled'] = 'Added a check for';
$string['pleaserun'] = 'Please run your code before submitting.';
$string['pleasewritecode'] = 'Please write some code before submitting.';
$string['pluginname'] = 'CodeAtelier';
$string['pluginname_help'] = 'A code question where students write code in an in-browser editor, run it against your test cases, and get instant feedback. In Practice mode the grade is worked out in the browser — good for instant practice, but not for marks. Secure mode runs and grades the code on the server with hidden tests, for work that counts.';
$string['pluginnameadding'] = 'Adding a CodeAtelier question';
$string['pluginnameediting'] = 'Editing a CodeAtelier question';
$string['pluginnamesummary'] = 'An in-browser code question for instant, run-it-yourself coding practice (Python, SQL, JavaScript). Practice mode — for practice, not for grades that count.';
$string['practicenote'] = 'Your code runs in your browser for instant practice feedback — this is not a graded exam.';
$string['practicewarning'] = 'Practice mode: the grade is worked out in the student\'s browser and can be changed by the student, so it can\'t be trusted. Use Practice-mode questions for practice only — never for work that counts towards a grade. For grades that count, choose Secure mode.';
$string['preparingruntime'] = 'Preparing the runtime… (first run only)';
$string['preview'] = 'Preview';
$string['previewintro'] = 'See exactly what students will see. Click refresh after changing the question, code or test cases.';
$string['previewpick'] = 'Preview — pick an element to add a check';
$string['previewrefresh'] = 'Refresh preview';
$string['privacy:metadata:judge0'] = 'When a question is graded in Secure mode, the student\'s submission is sent to the site-configured code sandbox (Judge0) to be run and graded.';
$string['privacy:metadata:judge0:code'] = 'The student\'s submitted code.';
$string['privacy:metadata:judge0:language'] = 'The programming language the submission is run as.';
$string['privacy:metadata:preference:editortheme'] = 'The student\'s chosen code-editor theme (auto, light, dark or high contrast).';
$string['privacy:metadata:proctorlog'] = 'A server-side, append-only record of soft anti-cheat events (leaving the question, outside-paste attempts) on secure-mode attempts.';
$string['privacy:metadata:proctorlog:eventtype'] = 'The kind of event (leaving the question, an outside-paste attempt).';
$string['privacy:metadata:proctorlog:questionattemptid'] = 'The question attempt the event was recorded against.';
$string['privacy:metadata:proctorlog:timecreated'] = 'The server time the event was recorded.';
$string['privacy:metadata:proctorlog:userid'] = 'The user who generated the event.';
$string['privacy:metadata:webjudge'] = 'When a Secure-mode frontend question is graded, the student\'s HTML/CSS/JavaScript files are sent to the site-configured headless browser service to be rendered and assessed.';
$string['privacy:metadata:webjudge:files'] = 'The student\'s submitted frontend files.';
$string['privacy:proctorpath'] = 'CodeAtelier activity flags';
$string['proctor_extdrop'] = 'Outside text drop blocked';
$string['proctor_extpaste'] = 'Outside paste blocked';
$string['proctor_focusaway'] = 'Switched away from the question';
$string['proctor_paste'] = 'Paste blocked';
$string['proctornote'] = 'Switching away from this question and paste attempts are recorded for your teacher.';
$string['proctorsummary'] = 'Activity flags';
$string['projectaddfile'] = 'Add file';
$string['projectbadfilename'] = 'Enter a unique file name without slashes.';
$string['projectdeletefile'] = 'Delete file';
$string['projectfilename'] = 'File name';
$string['projectfiles'] = 'Project files';
$string['projectfilesempty'] = 'Add at least one project file.';
$string['projectfilesform'] = 'Project files';
$string['projectfilesform_help'] = 'The multi-file starter the student begins from. Add, rename and delete files with the tabs; each file is edited in the code editor. The whole set is what the student edits and the server compiles together. Project questions run in Secure mode in a single language.';
$string['projectjudgeonly'] = 'A multi-file project runs in Secure mode. Set the grading mode to Secure.';
$string['projectlanguageset'] = 'A project is single-language: set Language to one of C, C++, Java, Python, JavaScript, Go, Rust, PHP, TypeScript, C#, Kotlin or Ruby, and leave the allowed languages empty.';
$string['pyodidebaseurl'] = 'Pyodide base URL';
$string['pyodidebaseurl_desc'] = 'Where the browser loads the Python (Pyodide) runtime from for Practice-mode Python questions. The default is a pinned public CDN; point it at a local mirror for offline or locked-down sites. Must end with a trailing slash.';
$string['referencecompileerror'] = 'The reference solution did not compile: {$a}';
$string['referencefailed'] = 'The reference solution does not pass test {$a}. Fix the solution or the expected output before saving.';
$string['referencesolution'] = 'Reference solution';
$string['referencesolution_help'] = 'A correct solution in the primary language. Use **Capture expected outputs** to fill the Expected fields by running it against every test. In Secure mode the reference is run on save and the save is blocked if it does not pass every test; in Practice mode a failing reference only warns.';
$string['referenceverifyfail'] = 'Could not verify the reference solution (the runtime did not load). Saving anyway.';
$string['referencewarning'] = 'Warning: your reference solution does not pass test {$a}. Students may not be able to score full marks.';
$string['report_attempts'] = 'Submissions';
$string['report_backtoquiz'] = '« Back to the quiz overview';
$string['report_coverage'] = 'Full detail (result, per-test pass rates, time and memory) is available for {$a->with} of {$a->total} submissions; the rest were graded against an earlier version of the question or have aged out of the result cache.';
$string['report_exportcsv'] = 'Export per-test table (CSV)';
$string['report_graded'] = 'Graded';
$string['report_languages'] = 'Languages used';
$string['report_maxmemory'] = 'Peak memory';
$string['report_maxtime'] = 'Slowest run';
$string['report_meanscore'] = 'Mean score';
$string['report_needsgrading'] = 'Awaiting grading';
$string['report_nosubmissions'] = 'No student submissions yet. (Teacher previews are not counted.)';
$string['report_outof'] = 'Out of';
$string['report_passes'] = 'Passes';
$string['report_passrate'] = 'Pass rate';
$string['report_pertest'] = 'Per-test pass rates';
$string['report_pertest_help'] = 'How many graded submissions passed each test. A test almost everyone fails often means a wrong expected output.';
$string['report_practiceflag'] = 'Practice mode — the scores below are worked out in each student\'s browser and sent back to Moodle. They are self-reported and can be changed by the student, so treat them as practice, not as official marks. Use Secure mode for grades that count.';
$string['report_proctor'] = 'Submissions with activity flags';
$string['report_question'] = 'Question';
$string['report_quiz_noquestions'] = 'This quiz has no CodeAtelier questions added directly. (Randomly-selected questions are not listed here.)';
$string['report_quiztitle'] = 'CodeAtelier submissions';
$string['report_recent'] = 'Recent submissions';
$string['report_review'] = 'Review';
$string['report_score_full'] = 'Full marks';
$string['report_score_partial'] = 'Partial';
$string['report_score_zero'] = 'Scored nothing';
$string['report_scoredist'] = 'Score distribution';
$string['report_selfreported'] = 'Self-reported';
$string['report_selfreported_note'] = 'Questions marked "Self-reported" run in Practice mode: their means are worked out in students\' browsers and cannot be trusted for marks.';
$string['report_sim_cluster'] = 'Group of {$a} similar submissions';
$string['report_sim_noclusters'] = 'No submissions stand out as more similar to each other than the cohort as a whole.';
$string['report_sim_tooshort'] = 'These submissions are too short to compare reliably — there is essentially one obvious answer, so similarity here is not a meaningful signal.';
$string['report_sim_uniform'] = 'Almost every submission is similar, so there is one dominant way to solve this. Similarity cannot tell copying from coincidence here, so nothing is singled out.';
$string['report_sim_upto'] = 'up to {$a}% similar';
$string['report_similarity'] = 'Code similarity';
$string['report_similarity_help'] = 'Submissions in the same language are compared for structural similarity, ignoring renamed variables and reformatting. Similar groups are shown for you to review — this is a prompt to look, not a judgement. High similarity is only flagged when the rest of the cohort is varied; on a question with one obvious answer, similarity is expected and nothing is flagged.';
$string['report_suspect'] = 'check this test';
$string['report_test'] = 'Test';
$string['report_title'] = 'Submissions report';
$string['report_unknownuser'] = 'Unknown student';
$string['report_user'] = 'Student';
$string['report_verdict'] = 'Result';
$string['report_verdicts'] = 'Results';
$string['report_when'] = 'Submitted';
$string['result_call'] = 'Call';
$string['result_check'] = 'Check';
$string['result_fail'] = 'failed';
$string['result_feedback'] = 'Feedback';
$string['result_got'] = 'Got';
$string['result_hidden'] = 'Hidden test';
$string['result_pass'] = 'passed';
$string['result_score'] = 'Score';
$string['result_test'] = 'Test';
$string['runerror'] = 'Your code could not be run';
$string['runexamples'] = 'Run examples';
$string['runexamplescooldown'] = 'Run-examples cooldown (seconds)';
$string['runexamplescooldown_desc'] = 'The minimum number of seconds a student must wait between "run against examples" requests, which run on the server. Throttles on-demand server load; the official grading at submit is unaffected. Set to 0 to disable the cooldown.';
$string['runexamplestoofast'] = 'Please wait {$a} second(s) before running the examples again.';
$string['runlabel'] = 'Run';
$string['running'] = 'Running…';
$string['scenario'] = 'Question format';
$string['scenario_function'] = 'Function (call and check the return value)';
$string['scenario_help'] = 'How the student\'s code is run and graded. **Standard input/output**: the code reads input and prints output, compared as text. **Function**: the student writes a named function; each test\'s Input is a JSON array of the call arguments (so a function taking one list needs that list wrapped, e.g. `[[1,2,3]]`), and the returned value is compared with Expected.';
$string['scenario_project'] = 'Project (multi-file program)';
$string['scenario_sql'] = 'SQL (query a database)';
$string['scenario_stdinstdout'] = 'Standard input / output';
$string['scenario_web'] = 'Web page (HTML / CSS / JavaScript)';
$string['schemasql'] = 'Schema and seed data (SQL)';
$string['schemasql_help'] = 'SQL run before the student\'s query to set up the database: CREATE TABLE statements and INSERTs. Runs on SQLite (sql.js) in the browser.';
$string['schematables'] = 'Tables and sample data';
$string['scratchargs'] = 'Arguments (JSON array)';
$string['scratchnooutput'] = '(no output)';
$string['scratchplaceholderfn'] = 'e.g. [[1, 2, 3]] — or click "Use this input" on a worked example below';
$string['scratchplaceholderio'] = 'Type the program input here, or click "Use this input" on a worked example below';
$string['scratchstdin'] = 'Run with your own input';
$string['showinpreview'] = 'Show in preview';
$string['sigbuilder_addparam'] = 'Add parameter';
$string['sigbuilder_dynamicnote'] = 'Python and JavaScript receive the arguments as JSON, so this function can take any shape — numbers, strings, lists, and objects (including a list of records, like {"member":"Alice","daysLate":3}). No signature is needed. In each test case, the Input is a JSON array of the arguments — for example [[{"member":"Alice","daysLate":3}]] for a single list-of-objects argument; the Expected value is the return as JSON.';
$string['sigbuilder_noparams'] = 'No parameters';
$string['sigbuilder_param'] = 'Parameter';
$string['sigbuilder_removeparam'] = 'Remove parameter';
$string['sigbuilder_returns'] = 'Returns';
$string['sigbuilder_staticnote'] = 'Compiled languages (C, C++, Java, Go, Rust, C#, Kotlin) need a typed signature using the types below: scalars, list<scalar>, and map<string,scalar> — one level only (and C grades scalars only). Nested or mixed-type arguments, such as a list of objects, are not available on compiled languages; remove them to run this question on Python or JavaScript, which accept arguments of any shape.';
$string['signatureinvalid'] = 'Invalid signature. Use a form like "int, int -> int" with the supported types.';
$string['signaturenested'] = 'Nested types like list<map<...>> cannot be graded on a compiled language. Remove the compiled language(s) to run this question on Python or JavaScript, which accept arguments of any shape (no signature needed).';
$string['signaturerequired'] = 'A function signature is required when a compiled/static language is allowed.';
$string['signaturescalaronly'] = 'C functions support scalar parameters and returns only (int, long, double, bool, string) — not list or map types.';
$string['sqljsbaseurl'] = 'sql.js base URL';
$string['sqljsbaseurl_desc'] = 'Where the browser loads the SQL (sql.js / SQLite) runtime from for Practice-mode SQL questions. The default is a pinned public CDN; point it at a local mirror for offline or locked-down sites. Must end with a trailing slash.';
$string['sqlsinglelanguage'] = 'A SQL question is single-language: set Language to SQL and leave the allowed languages empty.';
$string['stale'] = 'You have edited your code since the last check — Run or Check again.';
$string['startercode'] = 'Starter code';
$string['startercode_help'] = 'Optional code pre-filled in the student\'s editor for the primary language — for example a function signature to complete.';
$string['starterlang'] = 'Starter for language';
$string['starterother'] = 'Starter code for this language';
$string['startersinvalidjson'] = 'The per-language starter code could not be saved. Please reload the form and try again.';
$string['status_cacheoldest'] = 'Oldest cached result';
$string['status_cachesize'] = 'Cached grading results';
$string['status_endpoint'] = 'Judge0 endpoint';
$string['status_endpointnotset'] = 'Not configured';
$string['status_health'] = 'Judge0 health';
$string['status_metric'] = 'Metric';
$string['status_queuedepth'] = 'Submissions awaiting grading';
$string['status_reachable'] = 'Reachable (version {$a})';
$string['status_unreachable'] = 'Unreachable: {$a}';
$string['status_value'] = 'Value';
$string['status_webendpoint'] = 'Web-judge endpoint';
$string['status_webhealth'] = 'Web-judge health';
$string['status_workerline'] = 'Queue "{$a->queue}": {$a->size} worker(s), {$a->available} available, {$a->idle} idle';
$string['status_workers'] = 'Server workers';
$string['statuspagetitle'] = 'Secure grading status';
$string['stoplabel'] = 'Stop';
$string['stopped'] = 'Stopped.';
$string['subtask'] = 'Subtask group';
$string['subtask_help'] = 'Group tests into subtasks (IOI-style). Tests sharing the same positive group number are scored all-or-nothing together: the group is worth the sum of its tests\' weights, awarded only if every test in it passes. Leave 0 (the default) for an ungrouped test scored on its own weight (ordinary partial credit).';
$string['subtaskgroup'] = 'Subtask {$a}';
$string['task_grade_pending'] = 'Grade pending Secure-mode submissions';
$string['task_prune_judgecache'] = 'Prune old Secure-mode grading cache entries';
$string['testcaseno'] = 'Test case {$a}';
$string['testcases'] = 'Test cases';
$string['testcasesjsoninvalid'] = 'The test cases could not be read. Re-import them, or fix the raw data in the field.';
$string['testfeedback'] = 'Feedback (shown for this test)';
$string['testslabel'] = 'Tests';
$string['tier'] = 'Grading mode';
$string['tier_help'] = 'Where the student\'s code runs and how it is graded.

**Practice mode (in the browser)**: code runs in the student\'s browser for instant feedback. Test cases are sent to the browser, so a determined student can read them — this mode is for practice, not for marks.

**Secure mode (on the server)**: code is compiled and run on the server with enforced time and memory limits, and graded against tests that never leave the server. This is the official grade students can\'t change; it adds compiled languages (C, C++, Java) and is always the standard input/output shape. Requires a configured Judge0 endpoint.';
$string['tier_judge'] = 'Secure mode (graded on the server)';
$string['tier_practice'] = 'Practice mode (in the browser)';
$string['tierbadge_judge'] = 'Secure mode — the official grade, hidden tests stay on the server';
$string['tierbadge_practice'] = 'Practice mode — instant feedback, not for marks';
$string['tolerance'] = 'Tolerance';
$string['tolerancenumeric'] = 'A numeric tolerance is required when the matching rule is numeric.';
$string['useinput'] = 'Use this input';
$string['verdict_AC'] = 'Accepted';
$string['verdict_CE'] = 'Compile error';
$string['verdict_MLE'] = 'Memory limit exceeded';
$string['verdict_RE'] = 'Runtime error';
$string['verdict_TLE'] = 'Time limit exceeded';
$string['verdict_WA'] = 'Wrong answer';
$string['viewreport'] = 'View the submissions report for this question';
$string['visibility'] = 'Visibility';
$string['visible_hidden'] = 'Hidden';
$string['visible_shown'] = 'Shown to students';
$string['webchecksheading'] = 'What is checked';
$string['webjudgebaseurl'] = 'Web-judge service URL';
$string['webjudgebaseurl_desc'] = 'Base URL of the web-judge service the server sends web-page submissions to, for example http://webjudge.example.org:4400. Run the codeatelier-webjudge service on a private host. Leave empty until it is available.';
$string['webjudgeheading'] = 'Secure mode for web pages (headless browser)';
$string['webjudgeheading_desc'] = 'Web-page questions in Secure mode are graded by a self-hosted headless-browser service that renders the student\'s page and checks it where the student cannot see or change the result. Leave the URL empty to disable Secure mode for web-page questions.';
$string['webjudgetoken'] = 'Web-judge auth token';
$string['webjudgetoken_desc'] = 'Optional shared token sent with each grading request (matches the service\'s WEBJUDGE_TOKEN). Leave empty if the service needs no authentication.';
$string['webnohtml'] = 'Add an HTML file (for example index.html) — it is the page the preview renders.';
$string['weight'] = 'Weight';
$string['weightnumeric'] = 'The weight must be a number.';
