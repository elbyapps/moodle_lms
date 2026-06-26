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
use local_syncqueue\sync_client;
use local_syncqueue\update_processor;

/**
 * Scheduled task to download updates from the central server.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_updates extends scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_downloadupdates', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (!get_config('local_syncqueue', 'enabled')) {
            mtrace('Sync queue is disabled.');
            return;
        }

        // Check connectivity.
        try {
            $client = new sync_client();
            if (!$client->check_connection()) {
                mtrace('Cannot connect to central server. Will retry later.');
                return;
            }
        } catch (\Exception $e) {
            mtrace('Sync client error: ' . $e->getMessage());
            return;
        }

        // Get last download timestamp.
        $lastdownload = get_config('local_syncqueue', 'lastdownload') ?: 0;

        mtrace("Downloading updates since " . userdate($lastdownload) . "...");

        try {
            $updates = $client->download($lastdownload);

            if (empty($updates)) {
                mtrace('No updates available.');
                return;
            }

            mtrace("Received " . count($updates) . " updates.");

            $processor = new update_processor();
            $results = $processor->process($updates);

            mtrace("Processed: {$results['success']} success, {$results['failed']} failed, {$results['skipped']} skipped");

            // Update last download timestamp.
            set_config('lastdownload', time(), 'local_syncqueue');

            // Log the operation.
            $this->log_download(count($updates), $results);

        } catch (\Exception $e) {
            mtrace('Download failed: ' . $e->getMessage());
        }
    }

    /**
     * Log the download operation.
     *
     * @param int $count Number of updates received.
     * @param array $results Processing results.
     */
    protected function log_download(int $count, array $results): void {
        global $DB;

        $schoolid = get_config('local_syncqueue', 'schoolid') ?: 'unknown';

        $log = new \stdClass();
        $log->schoolid = $schoolid;
        $log->direction = 'download';
        $log->itemcount = $count;
        $log->successcount = $results['success'];
        $log->failcount = $results['failed'];
        $log->conflictcount = 0;
        $log->status = ($results['failed'] === 0) ? 'success' : 'partial';
        $log->timecreated = time();

        $DB->insert_record('local_syncqueue_log', $log);
    }
}
