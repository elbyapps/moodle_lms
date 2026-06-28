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
 * Scheduled task definitions for local_elby_dashboard.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    // Hourly at :15 — aggregate logstore into user metrics.
    [
        'classname' => 'local_elby_dashboard\task\compute_user_metrics',
        'blocking'  => 0,
        'minute'    => '15',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    // Daily at 2:00 AM — roll up user metrics to school metrics.
    [
        'classname' => 'local_elby_dashboard\task\aggregate_school_metrics',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '2',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    // Daily at 3:00 AM — refresh stale SDMS cache records.
    [
        'classname' => 'local_elby_dashboard\task\refresh_sdms_cache',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '3',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    // Daily at 4:30 AM — auto-link unlinked users by email.
    [
        'classname' => 'local_elby_dashboard\task\auto_link_by_email',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '4',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    // Weekly Sunday at 4:00 AM — purge old metrics and logs.
    [
        'classname' => 'local_elby_dashboard\task\cleanup_old_metrics',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '4',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '0',
    ],
];
