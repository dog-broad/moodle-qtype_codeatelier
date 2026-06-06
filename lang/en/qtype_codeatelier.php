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

$string['pluginname'] = 'CodeAtelier';
$string['pluginname_help'] = 'A code question where students write code in an in-browser editor, run it against your test cases, and get instant feedback. Practice-tier grading runs in the browser and is for formative practice, not secure assessment.';
$string['pluginnamesummary'] = 'An in-browser code question for instant, run-it-yourself coding practice (Python, SQL, JavaScript). Practice tier — for formative use, not secure assessment.';
$string['pluginnameadding'] = 'Adding a CodeAtelier question';
$string['pluginnameediting'] = 'Editing a CodeAtelier question';

$string['privacy:metadata'] = 'The CodeAtelier question type does not store any personal data; submitted responses are held by the Moodle question engine.';

// Admin settings.
$string['pyodidebaseurl'] = 'Pyodide base URL';
$string['pyodidebaseurl_desc'] = 'Where the browser loads the Python (Pyodide) runtime from for practice-tier Python questions. The default is a pinned public CDN; point it at a local mirror for offline or locked-down sites. Must end with a trailing slash.';
$string['sqljsbaseurl'] = 'sql.js base URL';
$string['sqljsbaseurl_desc'] = 'Where the browser loads the SQL (sql.js / SQLite) runtime from for practice-tier SQL questions. The default is a pinned public CDN; point it at a local mirror for offline or locked-down sites. Must end with a trailing slash.';
$string['schematables'] = 'Tables and sample data';
$string['judgeheading'] = 'Judge tier (server-side grading)';
$string['judgeheading_desc'] = 'The judge tier runs and grades submissions in a server sandbox (Judge0) with enforced time and memory limits and hidden tests that never reach the browser. This is the authoritative, secure grading path. Leave the base URL empty to disable it.';
$string['judge0baseurl'] = 'Judge0 base URL';
$string['judge0baseurl_desc'] = 'Base URL of the Judge0 instance the server submits code to, for example http://judge.example.org:2358. Judge0 must run on a host configured for cgroup v1. Leave empty until a Judge0 instance is available.';
$string['judge0apikey'] = 'Judge0 auth token';
$string['judge0apikey_desc'] = 'Optional authentication token sent with each submission (a self-hosted Judge0 auth token, or a hosted-gateway API key). Leave empty if the instance needs no authentication.';
$string['judge0cputimelimit'] = 'Default CPU-time limit (seconds)';
$string['judge0cputimelimit_desc'] = 'The default per-run CPU-time limit for judge-tier questions, in seconds. Individual questions may override this.';
$string['judge0memorylimit'] = 'Default memory limit (KB)';
$string['judge0memorylimit_desc'] = 'The default per-run memory limit for judge-tier questions, in kilobytes. Individual questions may override this.';
$string['runexamplescooldown'] = 'Run-examples cooldown (seconds)';
$string['runexamplescooldown_desc'] = 'The minimum number of seconds a student must wait between formative "run against examples" requests, which run on the server sandbox. Throttles on-demand sandbox load; the authoritative grading at submit is unaffected. Set to 0 to disable the cooldown.';
$string['judgecacheretention'] = 'Grading cache retention (days)';
$string['judgecacheretention_desc'] = 'How long memoised judge-tier grading results are kept before a daily cleanup task removes them. The cache only speeds up review and regrade; a removed entry is recomputed when next needed. Set to 0 to keep entries forever.';

// Student attempt UI.
$string['answerlabel'] = 'Your code';
$string['chooselanguage'] = 'Language';
$string['languageused'] = 'Language used: {$a}';
$string['loadingeditor'] = 'Loading editor…';
$string['runlabel'] = 'Run';
$string['checklabel'] = 'Check';
$string['stoplabel'] = 'Stop';
$string['consolelabel'] = 'Output';
$string['testslabel'] = 'Tests';
$string['outputtabs'] = 'Output and test results';
$string['scratchargs'] = 'Arguments (JSON array)';
$string['scratchstdin'] = 'Run with your own input';
$string['scratchnooutput'] = '(no output)';
$string['useinput'] = 'Use this input';
$string['output'] = 'Output';
$string['stopped'] = 'Stopped.';
$string['noresult'] = 'No rows returned.';
$string['practicenote'] = 'Your code runs in your browser for instant practice feedback. This is not a secure exam mode.';
$string['running'] = 'Running…';
$string['preparingruntime'] = 'Preparing the runtime… (first run only)';
$string['runerror'] = 'Your code could not be run';
$string['result_score'] = 'Score';
$string['result_call'] = 'Call';
$string['result_got'] = 'Got';
$string['result_feedback'] = 'Feedback';
$string['examples'] = 'Examples';
$string['stale'] = 'You have edited your code since the last check — Run or Check again.';
$string['degraded'] = 'The code runtime could not be loaded. Check your connection, or ask your teacher.';
$string['focus'] = 'Focus';
$string['exitfocus'] = 'Exit focus';
$string['focusmode'] = 'Code workbench (focus mode)';
$string['result_test'] = 'Test';
$string['result_hidden'] = 'Hidden test';
$string['result_pass'] = 'passed';
$string['result_fail'] = 'failed';
$string['pleaserun'] = 'Please run your code before submitting.';
$string['pleasewritecode'] = 'Please write some code before submitting.';

// Judge-tier student UI.
$string['judgenote'] = 'This question is graded on the server against hidden tests when you submit. The editor is for writing and reviewing your code.';
$string['judgeverdict'] = 'Verdict:';
$string['judgenoresult'] = 'No graded result is available for this attempt.';
$string['judgepending'] = 'Awaiting automatic grading. Your submission has been received and will be graded shortly — refresh in a moment.';
$string['task_grade_pending'] = 'Grade pending judge-tier submissions';
$string['task_prune_judgecache'] = 'Prune old judge-tier grading cache entries';
$string['runexamplestoofast'] = 'Please wait {$a} second(s) before running the examples again.';
$string['verdict_AC'] = 'Accepted';
$string['verdict_WA'] = 'Wrong answer';
$string['verdict_TLE'] = 'Time limit exceeded';
$string['verdict_MLE'] = 'Memory limit exceeded';
$string['verdict_RE'] = 'Runtime error';
$string['verdict_CE'] = 'Compile error';
$string['judgetime'] = '{$a} ms';
$string['judgememory'] = '{$a}';

// Judge-tier "run against examples".
$string['runexamples'] = 'Run examples';
$string['notjudgequestion'] = 'This is not a judge-tier CodeAtelier question.';
$string['judgenotconfigured'] = 'No judge endpoint is configured. Ask your administrator to set the Judge0 base URL.';

// Admin status page.
$string['statuspagetitle'] = 'Judge tier status';
$string['status_metric'] = 'Metric';
$string['status_value'] = 'Value';
$string['status_endpoint'] = 'Judge0 endpoint';
$string['status_endpointnotset'] = 'Not configured';
$string['status_health'] = 'Judge0 health';
$string['status_reachable'] = 'Reachable (version {$a})';
$string['status_unreachable'] = 'Unreachable: {$a}';
$string['status_workers'] = 'Sandbox workers';
$string['status_workerline'] = 'Queue "{$a->queue}": {$a->size} worker(s), {$a->available} available, {$a->idle} idle';
$string['status_queuedepth'] = 'Submissions awaiting grading';
$string['status_cachesize'] = 'Cached grading results';
$string['status_cacheoldest'] = 'Oldest cached result';

// Insight: the per-question submissions report.
$string['report_title'] = 'Submissions report';
$string['viewreport'] = 'View the submissions report for this question';
$string['notcodeatelierquestion'] = 'That is not a CodeAtelier question.';
$string['report_nosubmissions'] = 'No student submissions yet. (Teacher previews are not counted.)';
$string['report_attempts'] = 'Submissions';
$string['report_graded'] = 'Graded';
$string['report_needsgrading'] = 'Awaiting grading';
$string['report_meanscore'] = 'Mean score';
$string['report_scoredist'] = 'Score distribution';
$string['report_score_zero'] = 'Scored nothing';
$string['report_score_partial'] = 'Partial';
$string['report_score_full'] = 'Full marks';
$string['report_verdicts'] = 'Verdicts';
$string['report_pertest'] = 'Per-test pass rates';
$string['report_pertest_help'] = 'How many graded submissions passed each test. A test almost everyone fails often means a wrong expected output.';
$string['report_test'] = 'Test';
$string['report_passrate'] = 'Pass rate';
$string['report_passes'] = 'Passes';
$string['report_outof'] = 'Out of';
$string['report_suspect'] = 'check this test';
$string['report_languages'] = 'Languages used';
$string['report_maxtime'] = 'Slowest run';
$string['report_maxmemory'] = 'Peak memory';
$string['report_proctor'] = 'Submissions with activity flags';
$string['report_coverage'] = 'Detailed results (verdicts, per-test pass rates, time and memory) are available for {$a->with} of {$a->total} submissions; the rest were graded against an earlier version of the question or have aged out of the result cache.';
$string['report_exportcsv'] = 'Export per-test table (CSV)';
$string['report_quiztitle'] = 'CodeAtelier submissions';
$string['report_quiz_noquestions'] = 'This quiz has no CodeAtelier questions added directly. (Randomly-selected questions are not listed here.)';
$string['report_question'] = 'Question';
$string['report_backtoquiz'] = '« Back to the quiz overview';
$string['judgegrading'] = 'Grading your submission…';

// Soft anti-cheat (judge tier).
$string['proctornote'] = 'Switching away from this question and paste attempts are recorded for your teacher.';
$string['proctorsummary'] = 'Activity flags';
$string['proctor_focusaway'] = 'Switched away from the question';
$string['proctor_paste'] = 'Paste blocked';

// Authoring: tier.
$string['tier'] = 'Grading tier';
$string['tier_help'] = 'Where the student\'s code runs and how it is graded.

**Practice (in-browser)**: code runs in the student\'s browser for instant feedback. Test cases are sent to the browser, so a determined student can read them — this tier is for formative practice, never secure assessment.

**Judge (server sandbox)**: code is compiled and run on the server with enforced time and memory limits, and graded against tests that never leave the server. This is the authoritative, secure grading path; it adds compiled languages (C, C++, Java) and is always the standard input/output shape. Requires a configured Judge0 endpoint.';
$string['tier_practice'] = 'Practice (in-browser, formative)';
$string['tier_judge'] = 'Judge (server sandbox, authoritative)';

// Authoring: language.
$string['language'] = 'Language';
$string['language_help'] = 'The primary (default) language the student writes in. Practice tier: Python (Pyodide), SQL (sql.js) or JavaScript, run in the browser. Judge tier: C, C++, Java, Python, JavaScript, SQL (SQLite), Go, Rust, PHP, TypeScript, C#, Kotlin or Ruby, run in the server sandbox. When you allow several languages below, this is the one selected by default.';
$string['languages'] = 'Allowed languages';
$string['languages_help'] = 'The set of languages the student may choose from when attempting the question. Leave empty for a single-language question (the Language above). All chosen languages must belong to the same tier. SQL questions are single-language; function-scenario questions support Python and JavaScript.';
$string['lang_python'] = 'Python';
$string['lang_sql'] = 'SQL';
$string['lang_javascript'] = 'JavaScript';
$string['lang_c'] = 'C';
$string['lang_cpp'] = 'C++';
$string['lang_java'] = 'Java';
$string['lang_go'] = 'Go';
$string['lang_rust'] = 'Rust';
$string['lang_php'] = 'PHP';
$string['lang_typescript'] = 'TypeScript';
$string['lang_csharp'] = 'C#';
$string['lang_kotlin'] = 'Kotlin';
$string['lang_ruby'] = 'Ruby';

// Authoring: scenario.
$string['scenario'] = 'Scenario';
$string['scenario_help'] = 'How the student\'s code is run and graded. **Standard input/output**: the code reads input and prints output, compared as text. **Function**: the student writes a named function; each test\'s Input is a JSON array of the call arguments (so a function taking one list needs that list wrapped, e.g. `[[1,2,3]]`), and the returned value is compared with Expected.';
$string['scenario_stdinstdout'] = 'Standard input / output';
$string['scenario_function'] = 'Function (call and check the return value)';
$string['scenario_sql'] = 'SQL (query a database)';
$string['schemasql'] = 'Schema and seed data (SQL)';
$string['schemasql_help'] = 'SQL run before the student\'s query to set up the database: CREATE TABLE statements and INSERTs. Runs on SQLite (sql.js) in the browser.';
$string['checkerquery'] = 'Checker query (SQL)';
$string['checkerquery_help'] = 'For questions that change data (INSERT/UPDATE/DELETE): a SELECT run after the student\'s statements; its result set is compared with the expected result. Leave empty to compare the student query\'s own result set. On the judge tier the student answer should be a mutation (non-SELECT) when a checker query is set, so only the checker\'s rows are compared.';
$string['testfeedback'] = 'Feedback (shown for this test)';
$string['entrypoint'] = 'Function name';
$string['entrypoint_help'] = 'The name of the function the student must define. Each test\'s Input is a **JSON array of the arguments** passed to the call: `[2, 3]` calls `f(2, 3)`, while a function taking a single list needs it wrapped, `[[1, 2, 3]]`, to call `f([1, 2, 3])`. The function\'s return value is compared with Expected.';
$string['functionsignature'] = 'Function signature';
$string['functionsignature_help'] = 'Required for compiled/static languages (Java, C++, Go, Rust, C#, Kotlin), which cannot be called without knowing the types. Declare the parameter types and return type, for example `int, int -> int`, `string -> bool`, or `list<int> -> list<int>`.

Types: `int`, `long`, `double`, `bool`, `string`, and one-dimensional lists `list<int>`, `list<double>`, `list<bool>`, `list<string>`. Dynamic languages (Python, JavaScript, Ruby, PHP, TypeScript) ignore this field.';
$string['signaturerequired'] = 'A function signature is required when a compiled/static language is allowed.';
$string['signatureinvalid'] = 'Invalid signature. Use a form like "int, int -> int" with the supported types.';
$string['startercode'] = 'Starter code';
$string['startercode_help'] = 'Optional code pre-filled in the student\'s editor for the primary language — for example a function signature to complete.';
$string['referencesolution'] = 'Reference solution';
$string['referencesolution_help'] = 'A correct solution in the primary language. Use **Capture expected outputs** to fill the Expected fields by running it against every test. On the judge tier the reference is run on save and the save is blocked if it does not pass every test; on the practice tier a failing reference only warns.';
$string['datafiles'] = 'Support / data files';
$string['datafiles_help'] = 'Judge tier only. Files you upload here are placed in the working directory next to the student\'s program, so it can read them at run time (for example a CSV the program opens). The practice tier (in-browser) does not provide these files.';
$string['starterlang'] = 'Starter for language';
$string['starterother'] = 'Starter code for this language';
$string['cputimelimit'] = 'CPU-time limit (seconds)';
$string['cputimelimit_help'] = 'Judge tier only. The CPU-time limit for each run of this question, in seconds. Leave blank to use the site default.';
$string['memorylimit'] = 'Memory limit (KB)';
$string['memorylimit_help'] = 'Judge tier only. The memory limit for each run of this question, in kilobytes. Leave blank to use the site default.';
$string['allornothing'] = 'All-or-nothing grading';
$string['allornothing_label'] = 'Award full marks only if every test passes';
$string['allornothing_help'] = 'When ticked, a submission scores full marks only if it passes every test case; otherwise it scores zero. When unticked, marks are the weighted fraction of tests passed (partial credit).';

// Authoring: live preview.
$string['preview'] = 'Preview';
$string['previewrefresh'] = 'Refresh preview';
$string['previewintro'] = 'See exactly what students will see. Click refresh after changing the question, code or test cases.';
$string['captureexpected'] = 'Capture expected outputs';
$string['captureexpected_help'] = 'Write a Reference solution above, then capture its output for every test case into the Expected fields (the judge tier runs it in the sandbox; the practice tier runs it in your browser). Review the captured values before saving.';
$string['capturepreviewfirst'] = 'Refresh the preview and write a solution in its editor first.';
$string['capturenotests'] = 'Add at least one test case with an input first.';
$string['capturedone'] = 'Captured expected output for {$a} test case(s) — review them before saving.';
$string['captureerrors'] = 'Captured {$a->ok} of {$a->total} — {$a->failed} case(s) errored and were left unchanged.';
$string['capturenoreference'] = 'Write a reference solution first.';
$string['referencewarning'] = 'Warning: your reference solution does not pass test {$a}. Students may not be able to score full marks.';
$string['referenceverifyfail'] = 'Could not verify the reference solution (the runtime did not load). Saving anyway.';

// Authoring: test cases.
$string['testcases'] = 'Test cases';
$string['testcaseno'] = 'Test case {$a}';
$string['input'] = 'Input';
$string['expected'] = 'Expected output';
$string['matchmode'] = 'Matching rule';
$string['match_exact'] = 'Exact match';
$string['match_trimmed'] = 'Trimmed (ignore leading/trailing whitespace)';
$string['match_regex'] = 'Regular expression';
$string['match_float'] = 'Numeric (within tolerance)';
$string['match_structured'] = 'Structured (deep-equal JSON value)';
$string['match_resultset'] = 'Result set (rows, any order)';
$string['match_resultset_ordered'] = 'Result set (rows, in order)';
$string['tolerance'] = 'Tolerance';
$string['weight'] = 'Weight';
$string['visibility'] = 'Visibility';
$string['visible_shown'] = 'Shown to students';
$string['visible_hidden'] = 'Hidden';
$string['addmoretestcases'] = 'Add another test case';

// Authoring: validation.
$string['weightnumeric'] = 'The weight must be a number.';
$string['tolerancenumeric'] = 'A numeric tolerance is required when the matching rule is numeric.';
$string['notenoughtests'] = 'You must provide at least one test case.';
$string['entrypointrequired'] = 'Enter the function name for a function question.';
$string['limitpositive'] = 'Enter a positive number, or leave blank for the site default.';
$string['languagenotjudge'] = 'The judge tier supports C, C++, Java, Python and JavaScript. SQL is a practice-tier language.';
$string['languagenotpractice'] = 'The practice tier supports Python, SQL and JavaScript. C, C++ and Java require the judge tier.';
$string['sqlsinglelanguage'] = 'A SQL question is single-language: set Language to SQL and leave the allowed languages empty.';
$string['functionlanguageset'] = 'A function-scenario question supports Python and JavaScript on the practice tier. On the judge tier it supports Python, JavaScript, Ruby, PHP, TypeScript (no signature), and Java, C++, Go, Rust, C#, Kotlin (with a typed signature).';
$string['referencefailed'] = 'The reference solution does not pass test {$a}. Fix the solution or the expected output before saving.';
$string['referencecompileerror'] = 'The reference solution did not compile: {$a}';
$string['startersinvalidjson'] = 'The per-language starter code could not be saved. Please reload the form and try again.';
