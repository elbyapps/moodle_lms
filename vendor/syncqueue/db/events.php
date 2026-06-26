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
 * Event observers for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    // Grade events.
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\local_syncqueue\observer::user_graded',
        'priority' => 0,
    ],

    // Assignment submission events.
    [
        'eventname' => '\mod_assign\event\submission_created',
        'callback' => '\local_syncqueue\observer::submission_created',
        'priority' => 0,
    ],
    [
        'eventname' => '\mod_assign\event\submission_updated',
        'callback' => '\local_syncqueue\observer::submission_updated',
        'priority' => 0,
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_created',
        'callback' => '\local_syncqueue\observer::file_submission_created',
        'priority' => 0,
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_updated',
        'callback' => '\local_syncqueue\observer::file_submission_updated',
        'priority' => 0,
    ],

    // Quiz events.
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_syncqueue\observer::quiz_attempt_submitted',
        'priority' => 0,
    ],

    // Forum events.
    [
        'eventname' => '\mod_forum\event\post_created',
        'callback' => '\local_syncqueue\observer::forum_post_created',
        'priority' => 0,
    ],
    [
        'eventname' => '\mod_forum\event\discussion_created',
        'callback' => '\local_syncqueue\observer::forum_discussion_created',
        'priority' => 0,
    ],

    // Enrollment events.
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_syncqueue\observer::user_enrolment_created',
        'priority' => 0,
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\local_syncqueue\observer::user_enrolment_deleted',
        'priority' => 0,
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\local_syncqueue\observer::user_enrolment_updated',
        'priority' => 0,
    ],

    // Course completion events.
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_syncqueue\observer::completion_updated',
        'priority' => 0,
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => '\local_syncqueue\observer::course_completed',
        'priority' => 0,
    ],

    // User events (for user profile sync).
    [
        'eventname' => '\core\event\user_created',
        'callback' => '\local_syncqueue\observer::user_created',
        'priority' => 0,
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback' => '\local_syncqueue\observer::user_updated',
        'priority' => 0,
    ],
];
