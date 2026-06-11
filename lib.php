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
 * Library callbacks for the CodeAtelier question type.
 *
 * @package    qtype_codeatelier
 * @copyright  2026 CodeAtelier
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Register the user preferences this plugin stores.
 *
 * The editor theme picker persists the student's chosen theme here so it can be
 * set through the core user-preference web service and is discoverable by the
 * privacy subsystem. A user may only set their own preference.
 *
 * @return array the preference definitions, keyed by preference name
 */
function qtype_codeatelier_user_preferences() {
    return [
        'qtype_codeatelier_editortheme' => [
            'type' => PARAM_ALPHA,
            'null' => NULL_NOT_ALLOWED,
            'default' => 'auto',
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}
