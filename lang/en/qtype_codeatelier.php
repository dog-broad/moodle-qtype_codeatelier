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
$string['pluginnamesummary'] = 'A code question for writing, running, and grading student code inside the quiz engine.';
$string['pluginnameadding'] = 'Adding a CodeAtelier question';
$string['pluginnameediting'] = 'Editing a CodeAtelier question';

$string['privacy:metadata'] = 'The CodeAtelier question type does not store any personal data; submitted responses are held by the Moodle question engine.';

// Student attempt UI.
$string['answerlabel'] = 'Your code';
$string['runlabel'] = 'Run / Check';
$string['practicenote'] = 'Your code runs in your browser for instant practice feedback. This is not a secure exam mode.';
$string['running'] = 'Running…';
$string['runerror'] = 'Your code could not be run';
$string['result_score'] = 'Score';
$string['result_test'] = 'Test';
$string['result_hidden'] = 'Hidden test';
$string['result_pass'] = 'passed';
$string['result_fail'] = 'failed';
$string['pleaserun'] = 'Please run your code before submitting.';

// Authoring: language.
$string['language'] = 'Language';
$string['language_help'] = 'The language the student writes in. In the practice tier this selects the in-browser runtime and the editor\'s syntax highlighting: Python (Pyodide), SQL (sql.js) or JavaScript.';
$string['lang_python'] = 'Python';
$string['lang_sql'] = 'SQL';
$string['lang_javascript'] = 'JavaScript';

// Authoring: scenario.
$string['scenario'] = 'Scenario';
$string['scenario_help'] = 'How the student\'s code is run and graded. **Standard input/output**: the code reads input and prints output, compared as text. **Function**: the student writes a named function; each test calls it with arguments and checks the return value.';
$string['scenario_stdinstdout'] = 'Standard input / output';
$string['scenario_function'] = 'Function (call and check the return value)';
$string['entrypoint'] = 'Function name';
$string['entrypoint_help'] = 'The name of the function the student must define. Each test case calls this function with the arguments given in its Input field.';
$string['startercode'] = 'Starter code';
$string['startercode_help'] = 'Optional code pre-filled in the student\'s editor — for example a function signature to complete.';

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
