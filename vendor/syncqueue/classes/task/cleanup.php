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

namespace local_syncqueue\task;

use core\task\scheduled_task;
use local_syncqueue\queue_manager;

/**
 * Scheduled task to clean up old sync records.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $queuemanager = new queue_manager();

        // Keep synced records for 30 days.
        $deleted = $queuemanager->cleanup(30);

        mtrace("Cleaned up {$deleted} old sync records.");

        // Also clean up old log entries (keep 90 days).
        $this->cleanup_logs(90);
    }

    /**
     * Clean up old log entries.
     *
     * @param int $keepdays Number of days to keep.
     */
    protected function cleanup_logs(int $keepdays): void {
        global $DB;

        $cutoff = time() - ($keepdays * DAYSECS);

        $deleted = $DB->delete_records_select(
            'local_syncqueue_log',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        mtrace("Cleaned up {$deleted} old log entries.");
    }
}
