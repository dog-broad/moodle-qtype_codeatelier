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
$string['pluginname_help'] = 'A code question that runs and grades student code. This early build records a client-supplied result through the quiz engine to validate the grading round-trip.';
$string['pluginnamesummary'] = 'A code question for writing, running, and grading student code inside the quiz engine.';
$string['pluginnameadding'] = 'Adding a CodeAtelier question';
$string['pluginnameediting'] = 'Editing a CodeAtelier question';

$string['privacy:metadata'] = 'The CodeAtelier question type does not store any personal data; submitted responses are held by the Moodle question engine.';

$string['spikeprompt'] = 'Select the result your code produced. This stands in for the in-browser run while the grading round-trip is being validated.';
$string['optionaccepted'] = 'Accepted — full marks (100%)';
$string['optionpartial'] = 'Partial — half marks (50%)';
$string['optionwrong'] = 'Wrong answer — no marks (0%)';
$string['summariseresponse'] = 'Recorded result: {$a}%';
$string['pleaserun'] = 'Please select a result before submitting.';

// Authoring: language.
$string['language'] = 'Language';
$string['language_help'] = 'The language the student writes in. In the practice tier this selects the in-browser runtime and the editor\'s syntax highlighting: Python (Pyodide), SQL (sql.js) or JavaScript.';
$string['lang_python'] = 'Python';
$string['lang_sql'] = 'SQL';
$string['lang_javascript'] = 'JavaScript';

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
