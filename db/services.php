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
 * External (web service / AJAX) functions for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'qtype_codeatelier_run_examples' => [
        'classname' => 'qtype_codeatelier\external\run_examples',
        'methodname' => 'execute',
        'description' => 'Run a judge-tier submission against the question\'s visible example inputs (formative, no grade).',
        'type' => 'read',
        'ajax' => true,
    ],
    'qtype_codeatelier_run_scratch' => [
        'classname' => 'qtype_codeatelier\external\run_scratch',
        'methodname' => 'execute',
        'description' => 'Run a judge-tier submission once against the student\'s own input (formative, no grade).',
        'type' => 'read',
        'ajax' => true,
    ],
    'qtype_codeatelier_run_reference' => [
        'classname' => 'qtype_codeatelier\external\run_reference',
        'methodname' => 'execute',
        'description' => 'Teacher-only: run a reference solution against all tests and return each output (run-to-capture).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/question:add',
    ],
    'qtype_codeatelier_grade_status' => [
        'classname' => 'qtype_codeatelier\external\grade_status',
        'methodname' => 'execute',
        'description' => 'Whether a submitted judge-tier attempt has finished background grading.',
        'type' => 'read',
        'ajax' => true,
    ],
    'qtype_codeatelier_log_proctor' => [
        'classname' => 'qtype_codeatelier\external\log_proctor_event',
        'methodname' => 'execute',
        'description' => 'Record a soft anti-cheat event (focus-away, outside-paste) on the caller\'s own live attempt.',
        'type' => 'write',
        'ajax' => true,
    ],
];
