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
 * Administration settings for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'qtype_codeatelier/pyodidebaseurl',
        get_string('pyodidebaseurl', 'qtype_codeatelier'),
        get_string('pyodidebaseurl_desc', 'qtype_codeatelier'),
        'https://cdn.jsdelivr.net/pyodide/v0.29.4/full/',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_codeatelier/sqljsbaseurl',
        get_string('sqljsbaseurl', 'qtype_codeatelier'),
        get_string('sqljsbaseurl_desc', 'qtype_codeatelier'),
        'https://cdn.jsdelivr.net/npm/sql.js@1.13.0/dist/',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'qtype_codeatelier/judgeheading',
        get_string('judgeheading', 'qtype_codeatelier'),
        get_string('judgeheading_desc', 'qtype_codeatelier')
    ));

    $settings->add(new admin_setting_configtext(
        'qtype_codeatelier/judge0baseurl',
        get_string('judge0baseurl', 'qtype_codeatelier'),
        get_string('judge0baseurl_desc', 'qtype_codeatelier'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'qtype_codeatelier/judge0apikey',
        get_string('judge0apikey', 'qtype_codeatelier'),
        get_string('judge0apikey_desc', 'qtype_codeatelier'),
        ''
    ));
}
