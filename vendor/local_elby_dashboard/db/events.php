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
 * Event observers for local_elby_dashboard.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_elby_dashboard\observer::quiz_submitted',
    ],
    [
        'eventname' => '\mod_assign\event\submission_created',
        'callback'  => '\local_elby_dashboard\observer::assignment_submitted',
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback'  => '\local_elby_dashboard\observer::course_module_completed',
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => '\local_elby_dashboard\observer::course_completed',
    ],
];
