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

// Student attempt UI.
$string['answerlabel'] = 'Your code';
$string['loadingeditor'] = 'Loading editor…';
$string['runlabel'] = 'Run';
$string['checklabel'] = 'Check';
$string['stoplabel'] = 'Stop';
$string['consolelabel'] = 'Output';
$string['testslabel'] = 'Tests';
$string['outputtabs'] = 'Output and test results';
$string['scratchargs'] = 'Arguments (JSON array)';
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
$string['verdict_AC'] = 'Accepted';
$string['verdict_WA'] = 'Wrong answer';
$string['verdict_TLE'] = 'Time limit exceeded';
$string['verdict_MLE'] = 'Memory limit exceeded';
$string['verdict_RE'] = 'Runtime error';
$string['verdict_CE'] = 'Compile error';
$string['judgetime'] = '{$a} ms';
$string['judgememory'] = '{$a}';

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
$string['language_help'] = 'The language the student writes in. Practice tier: Python (Pyodide), SQL (sql.js) or JavaScript, run in the browser. Judge tier: C, C++, Java, Python or JavaScript, run in the server sandbox.';
$string['lang_python'] = 'Python';
$string['lang_sql'] = 'SQL';
$string['lang_javascript'] = 'JavaScript';
$string['lang_c'] = 'C';
$string['lang_cpp'] = 'C++';
$string['lang_java'] = 'Java';

// Authoring: scenario.
$string['scenario'] = 'Scenario';
$string['scenario_help'] = 'How the student\'s code is run and graded. **Standard input/output**: the code reads input and prints output, compared as text. **Function**: the student writes a named function; each test calls it with arguments and checks the return value.';
$string['scenario_stdinstdout'] = 'Standard input / output';
$string['scenario_function'] = 'Function (call and check the return value)';
$string['scenario_sql'] = 'SQL (query a database)';
$string['schemasql'] = 'Schema and seed data (SQL)';
$string['schemasql_help'] = 'SQL run before the student\'s query to set up the database: CREATE TABLE statements and INSERTs. Runs on SQLite (sql.js) in the browser.';
$string['checkerquery'] = 'Checker query (SQL)';
$string['checkerquery_help'] = 'For questions that change data (INSERT/UPDATE/DELETE): a SELECT run after the student\'s statements; its result set is compared with the expected result. Leave empty to compare the student query\'s own result set.';
$string['testfeedback'] = 'Feedback (shown for this test)';
$string['entrypoint'] = 'Function name';
$string['entrypoint_help'] = 'The name of the function the student must define. Each test case calls this function with the arguments given in its Input field.';
$string['startercode'] = 'Starter code';
$string['startercode_help'] = 'Optional code pre-filled in the student\'s editor — for example a function signature to complete.';

// Authoring: live preview.
$string['preview'] = 'Preview';
$string['previewrefresh'] = 'Refresh preview';
$string['previewintro'] = 'See exactly what students will see. Click refresh after changing the question, code or test cases.';
$string['captureexpected'] = 'Capture expected outputs';
$string['captureexpected_help'] = 'Write a correct solution in the preview editor, then capture its output for every test case into the Expected fields. Review them before saving.';
$string['capturepreviewfirst'] = 'Refresh the preview and write a solution in its editor first.';
$string['capturenotests'] = 'Add at least one test case with an input first.';
$string['capturedone'] = 'Captured expected output for {$a} test case(s) — review them before saving.';
$string['captureerrors'] = 'Captured {$a->ok} of {$a->total} — {$a->failed} case(s) errored and were left unchanged.';

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
$string['languagenotjudge'] = 'The judge tier supports C, C++, Java, Python and JavaScript. SQL is a practice-tier language.';
$string['languagenotpractice'] = 'The practice tier supports Python, SQL and JavaScript. C, C++ and Java require the judge tier.';
