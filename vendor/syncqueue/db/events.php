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
 * Learner-fact and account observers are declared internal => true so that,
 * under push_v2, capture (ledger + outbox insert) commits atomically with the
 * business write. Core dispatches internal observers synchronously inside the
 * triggering transaction (lib/classes/event/manager.php process_buffers), rather
 * than deferring default observers to after commit — where a rolled-back write
 * would drop the fact before it was ever captured (doc 4.3). Observer callbacks
 * swallow their own exceptions and core additionally swallows observer
 * exceptions, so an internal handler can never break the business operation.
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
        'internal' => true,
    ],

    // Assignment submission events.
    [
        'eventname' => '\mod_assign\event\submission_created',
        'callback' => '\local_syncqueue\observer::submission_created',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\mod_assign\event\submission_updated',
        'callback' => '\local_syncqueue\observer::submission_updated',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_created',
        'callback' => '\local_syncqueue\observer::file_submission_created',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_updated',
        'callback' => '\local_syncqueue\observer::file_submission_updated',
        'priority' => 0,
        'internal' => true,
    ],

    // Quiz events.
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_syncqueue\observer::quiz_attempt_submitted',
        'priority' => 0,
        'internal' => true,
    ],

    // Forum events (not v2 facts; default post-commit dispatch is fine).
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
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\local_syncqueue\observer::user_enrolment_deleted',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\user_enrolment_updated',
        'callback' => '\local_syncqueue\observer::user_enrolment_updated',
        'priority' => 0,
        'internal' => true,
    ],

    // Course completion events.
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_syncqueue\observer::completion_updated',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => '\local_syncqueue\observer::course_completed',
        'priority' => 0,
        'internal' => true,
    ],

    // User events (for user profile sync). user_created carries no v2 fact so it
    // stays on default dispatch; user_updated/password can capture an account
    // fact, so they are internal for atomic capture.
    [
        'eventname' => '\core\event\user_created',
        'callback' => '\local_syncqueue\observer::user_created',
        'priority' => 0,
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback' => '\local_syncqueue\observer::user_updated',
        'priority' => 0,
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\user_password_updated',
        'callback' => '\local_syncqueue\observer::user_password_updated',
        'priority' => 0,
        'internal' => true,
    ],
];
