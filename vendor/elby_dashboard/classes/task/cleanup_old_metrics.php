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
 * Scheduled task to clean up old metrics data.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Weekly task that purges old metrics and sync log entries.
 */
class cleanup_old_metrics extends \core\task\scheduled_task {

    /** @var int Default retention for weekly metrics: 90 days. */
    private const WEEKLY_RETENTION = 90 * 86400;

    /** @var int Default retention for monthly metrics: 365 days. */
    private const MONTHLY_RETENTION = 365 * 86400;

    /** @var int Default retention for sync logs: 30 days. */
    private const LOG_RETENTION = 30 * 86400;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_old_metrics', 'local_elby_dashboard');
    }

    /**
     * Execute the task.
     *
     * Purges old weekly/monthly user and school metrics, and old sync logs.
     */
    public function execute(): void {
        global $DB;

        $now = time();

        // Purge old weekly user metrics.
        $weeklythreshold = $now - self::WEEKLY_RETENTION;
        $deleted = $DB->delete_records_select(
            'elby_user_metrics',
            "period_type = 'weekly' AND period_start < :threshold",
            ['threshold' => $weeklythreshold]
        );
        mtrace("Deleted {$deleted} old weekly user metrics records.");

        // Purge old monthly user metrics.
        $monthlythreshold = $now - self::MONTHLY_RETENTION;
        $deleted = $DB->delete_records_select(
            'elby_user_metrics',
            "period_type = 'monthly' AND period_start < :threshold",
            ['threshold' => $monthlythreshold]
        );
        mtrace("Deleted {$deleted} old monthly user metrics records.");

        // Purge old weekly school metrics.
        $deleted = $DB->delete_records_select(
            'elby_school_metrics',
            "period_type = 'weekly' AND period_start < :threshold",
            ['threshold' => $weeklythreshold]
        );
        mtrace("Deleted {$deleted} old weekly school metrics records.");

        // Purge old monthly school metrics.
        $deleted = $DB->delete_records_select(
            'elby_school_metrics',
            "period_type = 'monthly' AND period_start < :threshold",
            ['threshold' => $monthlythreshold]
        );
        mtrace("Deleted {$deleted} old monthly school metrics records.");

        // Purge old sync log entries.
        $logthreshold = $now - self::LOG_RETENTION;
        $deleted = $DB->delete_records_select(
            'elby_sync_log',
            'timecreated < :threshold',
            ['threshold' => $logthreshold]
        );
        mtrace("Deleted {$deleted} old sync log entries.");
    }
}
