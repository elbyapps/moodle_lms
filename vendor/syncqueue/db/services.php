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
 * External services and functions for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // Status check - used by schools to verify connection.
    'local_syncqueue_status' => [
        'classname' => 'local_syncqueue\external\status',
        'methodname' => 'execute',
        'description' => 'Check sync API status and school registration',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Upload - schools send their queued items to central.
    'local_syncqueue_upload' => [
        'classname' => 'local_syncqueue\external\upload',
        'methodname' => 'execute',
        'description' => 'Upload queued sync items from a school',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Download - schools fetch updates from central.
    'local_syncqueue_download' => [
        'classname' => 'local_syncqueue\external\download',
        'methodname' => 'execute',
        'description' => 'Download pending updates for a school',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Pull - schools fetch the v2 sequenced outbox stream (dual-stack with download).
    'local_syncqueue_pull' => [
        'classname' => 'local_syncqueue\external\pull',
        'methodname' => 'execute',
        'description' => 'Pull sequenced v2 outbox rows for a school',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Push - schools send the v2 sequenced upstream fact stream (dual-stack with upload).
    'local_syncqueue_push' => [
        'classname' => 'local_syncqueue\external\push',
        'methodname' => 'execute',
        'description' => 'Receive and buffer sequenced v2 upstream facts from a school',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Register - schools register with central server.
    'local_syncqueue_register' => [
        'classname' => 'local_syncqueue\external\register',
        'methodname' => 'execute',
        'description' => 'Register a new school with the central server',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Reincarnate - central issues a fresh epoch to a restored/cloned school (v2 §4.5).
    'local_syncqueue_reincarnate' => [
        'classname' => 'local_syncqueue\external\reincarnate',
        'methodname' => 'execute',
        'description' => 'Issue a new epoch and seed to a re-incarnating school',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Report - schools report sync completion status.
    'local_syncqueue_report' => [
        'classname' => 'local_syncqueue\external\report',
        'methodname' => 'execute',
        'description' => 'Report sync completion status from a school',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Catalog - schools list available courses to set pull priorities (F3).
    'local_syncqueue_catalog' => [
        'classname' => 'local_syncqueue\external\catalog',
        'methodname' => 'execute',
        'description' => 'List courses available to a school with its current pull preferences',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Upload priorities - schools store their course pull preferences (F3).
    'local_syncqueue_upload_priorities' => [
        'classname' => 'local_syncqueue\external\upload_priorities',
        'methodname' => 'execute',
        'description' => 'Store a school\'s per-course pull preferences',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // TDMP lookup proxy - schools resolve identity through central (no key on schools).
    'local_syncqueue_tdmp_lookup' => [
        'classname' => 'local_syncqueue\external\tdmp_lookup',
        'methodname' => 'execute',
        'description' => 'Proxy a single TDMP gateway lookup on behalf of a school',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Snapshot bootstrap manifest (step 6) - a fresh/re-incarnated school loads head state.
    'local_syncqueue_snapshot_manifest' => [
        'classname' => 'local_syncqueue\external\snapshot_manifest',
        'methodname' => 'execute',
        'description' => 'Return a chunk of the school\'s pinned bootstrap manifest',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Anti-entropy applied-state digest (step 6) - schools converge replicated content.
    'local_syncqueue_digest' => [
        'classname' => 'local_syncqueue\external\digest',
        'methodname' => 'execute',
        'description' => 'Exchange an applied-state digest and return entities the school is missing/stale on',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Content-addressed submission-blob receive (step 7 file channel).
    'local_syncqueue_upload_file' => [
        'classname' => 'local_syncqueue\external\upload_file',
        'methodname' => 'execute',
        'description' => 'Receive and content-address a submission blob uploaded by a school',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // Dual-validity API key rotation (step 7 credentials).
    'local_syncqueue_rotate_key' => [
        'classname' => 'local_syncqueue\external\rotate_key',
        'methodname' => 'execute',
        'description' => 'Rotate a school API key, keeping the old key valid through a grace window',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],

    // TDMP roster proxy - schools pull their own full student/teacher roster.
    'local_syncqueue_tdmp_roster' => [
        'classname' => 'local_syncqueue\external\tdmp_roster',
        'methodname' => 'execute',
        'description' => 'Return the requesting school\'s full student/teacher roster from TDMP',
        // 'write': serving a roster records the school's home tenure (Option B
        // producer), so it must run on the write DB, not a read replica.
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
    ],
];

// Define the sync service.
$services = [
    'Sync Queue Service' => [
        'functions' => [
            'local_syncqueue_status',
            'local_syncqueue_upload',
            'local_syncqueue_download',
            'local_syncqueue_pull',
            'local_syncqueue_push',
            'local_syncqueue_register',
            'local_syncqueue_reincarnate',
            'local_syncqueue_report',
            'local_syncqueue_catalog',
            'local_syncqueue_upload_priorities',
            'local_syncqueue_tdmp_lookup',
            'local_syncqueue_tdmp_roster',
            // Step 6: schools must be able to call these over the wire, not just in-process.
            'local_syncqueue_snapshot_manifest',
            'local_syncqueue_digest',
            // Step 7.
            'local_syncqueue_upload_file',
            'local_syncqueue_rotate_key',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'syncqueue',
        'downloadfiles' => 1,
        'uploadfiles' => 1,
    ],
];
