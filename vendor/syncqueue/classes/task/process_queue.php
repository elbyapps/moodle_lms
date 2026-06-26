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
use local_syncqueue\sync_client;

/**
 * Scheduled task to process the sync queue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_queue extends scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_processqueue', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (!get_config('local_syncqueue', 'enabled')) {
            mtrace('Sync queue is disabled.');
            return;
        }

        $queuemanager = new queue_manager();
        $stats = $queuemanager->get_stats();

        mtrace("Queue status: {$stats->pending} pending, {$stats->failed} failed");

        if ($stats->pending === 0) {
            mtrace('No pending items to sync.');
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

        // Process queue in batches.
        $batchsize = get_config('local_syncqueue', 'batchsize') ?: 100;
        $items = $queuemanager->get_pending_items($batchsize);

        if (empty($items)) {
            return;
        }

        $ids = array_column($items, 'id');
        $queuemanager->mark_processing($ids);

        mtrace("Processing " . count($items) . " items...");

        try {
            $results = $client->upload($items);

            $synced = 0;
            $failed = 0;
            $conflicts = 0;

            foreach ($results as $result) {
                $id = $result['id'];

                switch ($result['status']) {
                    case 'success':
                        $queuemanager->mark_synced($id);
                        $synced++;
                        break;

                    case 'conflict':
                        $queuemanager->mark_conflict($id, $result['message'] ?? 'Conflict detected');
                        $conflicts++;
                        break;

                    case 'error':
                    default:
                        $queuemanager->mark_failed($id, $result['message'] ?? 'Unknown error');
                        $failed++;
                        break;
                }
            }

            mtrace("Sync complete: {$synced} synced, {$failed} failed, {$conflicts} conflicts");

            // Log the sync operation.
            $this->log_sync($synced, $failed, $conflicts);

        } catch (\Exception $e) {
            mtrace('Sync failed: ' . $e->getMessage());

            // Mark all items as failed.
            foreach ($ids as $id) {
                $queuemanager->mark_failed($id, $e->getMessage());
            }
        }
    }

    /**
     * Log the sync operation.
     *
     * @param int $synced Number of synced items.
     * @param int $failed Number of failed items.
     * @param int $conflicts Number of conflicts.
     */
    protected function log_sync(int $synced, int $failed, int $conflicts): void {
        global $DB;

        $schoolid = get_config('local_syncqueue', 'schoolid') ?: 'unknown';

        $log = new \stdClass();
        $log->schoolid = $schoolid;
        $log->direction = 'upload';
        $log->itemcount = $synced + $failed + $conflicts;
        $log->successcount = $synced;
        $log->failcount = $failed;
        $log->conflictcount = $conflicts;
        $log->status = ($failed === 0 && $conflicts === 0) ? 'success' : 'partial';
        $log->timecreated = time();

        $DB->insert_record('local_syncqueue_log', $log);
    }
}
