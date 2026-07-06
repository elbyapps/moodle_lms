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
 * Scheduled tasks for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    // Process upload queue - runs every 5 minutes.
    [
        'classname' => 'local_syncqueue\task\process_queue',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // Download updates from central - runs every 10 minutes.
    [
        'classname' => 'local_syncqueue\task\download_updates',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // Cleanup old synced records - runs daily at 3am.
    [
        'classname' => 'local_syncqueue\task\cleanup',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // Refresh per-school trade/level priorities (central mode) - hourly.
    [
        'classname' => 'local_syncqueue\task\derive_school_trades',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2: assign dense sequence numbers to committed outbox rows - every minute.
    [
        'classname' => 'local_syncqueue\task\sequencer_task',
        'blocking' => 0,
        'minute' => '*',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2: pull the sequenced stream from central (school mode) - every 10 minutes.
    [
        'classname' => 'local_syncqueue\task\pull_stream',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2: push the sequenced upstream fact stream to central (school mode) - every 5 minutes.
    [
        'classname' => 'local_syncqueue\task\push_stream',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2: apply the buffered upstream ingest facts (central mode) - every 5 minutes.
    [
        'classname' => 'local_syncqueue\task\apply_ingest',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2 step 5: drain the history-reseed job queue and publish seeds down (central) - every 5 minutes.
    [
        'classname' => 'local_syncqueue\task\history_republish',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2 step 5: release seeded overrides to local evidence / human edits (school) - every 15 minutes.
    [
        'classname' => 'local_syncqueue\task\seed_handover',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2 step 6: downstream anti-entropy digest (school) - weekly, Sunday 04:20.
    [
        'classname' => 'local_syncqueue\task\anti_entropy',
        'blocking' => 0,
        'minute' => '20',
        'hour' => '4',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '0',
    ],

    // v2 step 6: capture-scan for never-captured learner facts (school) - weekly, Sunday 04:50.
    [
        'classname' => 'local_syncqueue\task\capture_scan',
        'blocking' => 0,
        'minute' => '50',
        'hour' => '4',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '0',
    ],

    // v2 step 6: upstream anti-entropy - re-queue facts central lost (school) - weekly, Sunday 05:20.
    [
        'classname' => 'local_syncqueue\task\upstream_anti_entropy',
        'blocking' => 0,
        'minute' => '20',
        'hour' => '5',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '0',
    ],

    // v2 step 7: content change-scan - flag drifted published courses (central) - weekly, Sunday 03:20.
    [
        'classname' => 'local_syncqueue\task\content_change_scan',
        'blocking' => 0,
        'minute' => '20',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '0',
    ],

    // v2 step 7: ship pending submission blobs to central (school) - every 30 minutes.
    [
        'classname' => 'local_syncqueue\task\ship_files',
        'blocking' => 0,
        'minute' => '*/30',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],

    // v2 step 7: prune superseded content outbox rows + GC expired manifests (central) - daily 02:30.
    [
        'classname' => 'local_syncqueue\task\prune_outbox',
        'blocking' => 0,
        'minute' => '30',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
