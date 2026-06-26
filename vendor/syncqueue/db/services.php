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

    // Register - schools register with central server.
    'local_syncqueue_register' => [
        'classname' => 'local_syncqueue\external\register',
        'methodname' => 'execute',
        'description' => 'Register a new school with the central server',
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

    // TDMP roster proxy - schools pull their own full student/teacher roster.
    'local_syncqueue_tdmp_roster' => [
        'classname' => 'local_syncqueue\external\tdmp_roster',
        'methodname' => 'execute',
        'description' => 'Return the requesting school\'s full student/teacher roster from TDMP',
        'type' => 'read',
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
            'local_syncqueue_register',
            'local_syncqueue_report',
            'local_syncqueue_catalog',
            'local_syncqueue_upload_priorities',
            'local_syncqueue_tdmp_lookup',
            'local_syncqueue_tdmp_roster',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'syncqueue',
        'downloadfiles' => 1,
        'uploadfiles' => 1,
    ],
];
