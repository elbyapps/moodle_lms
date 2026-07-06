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
use local_syncqueue\external\upload_file;
use local_syncqueue\queue_manager;
use local_syncqueue\sync_client;

/**
 * Ship pending submission blobs to central (ELMS Sync v2 step 7, doc §9.1, school side).
 *
 * queue_manager::queue_event_files records each submission file (by contenthash) into
 * local_syncqueue_files as 'pending' but nothing shipped the bytes — this closes that
 * write-only dead-end. Each pending blob is content-addressed, uploaded, and marked
 * 'synced' only once central confirms receipt by hash (retain-until-confirmed). A blob
 * whose local source file is gone is marked 'missing' (reported against its contenthash,
 * never silently forgotten). Time/row budgeted for 1 vCPU school boxes.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ship_files extends scheduled_task {

    /** @var int Max blobs shipped per run. */
    const MAX_ROWS = 200;

    /** @var int Wall-clock budget per run (seconds). */
    const MAX_SECONDS = 90;

    /** @var string Terminal status for a blob whose local source file is gone. */
    const STATUS_MISSING = 'missing';

    /** @var string Non-terminal status for a blob too large for the single-shot channel. */
    const STATUS_DEFERRED = 'deferred';

    /** @var int Delete synced/terminal file rows older than this (bound the table). */
    const CLEANUP_SECONDS = 30 * 86400;

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_ship_files', 'local_syncqueue');
    }

    /**
     * Ship pending blobs.
     */
    public function execute(): void {
        global $DB;

        if (get_config('local_syncqueue', 'mode') !== 'school'
                || !get_config('local_syncqueue', 'enabled')
                || !get_config('local_syncqueue', 'push_v2')) {
            mtrace('ship_files: not an enabled push_v2 school, skipping.');
            return;
        }

        $pending = $DB->get_records('local_syncqueue_files',
            ['status' => queue_manager::STATUS_PENDING], 'id ASC', '*', 0, self::MAX_ROWS);
        if (!$pending) {
            mtrace('ship_files: no pending blobs.');
            return;
        }

        $fs = get_file_storage();
        $client = $this->client();
        $deadline = time() + self::MAX_SECONDS;
        $shipped = 0;
        $dedup = 0;
        $missing = 0;
        $failed = 0;
        $deferred = 0;

        foreach ($pending as $row) {
            if (time() >= $deadline) {
                mtrace('ship_files: time budget reached; remaining blobs next run.');
                break;
            }

            // Load any stored file with this CONTENT hash (files are content-dedup'd,
            // so any non-directory record with the hash has identical bytes). Note
            // file_storage::get_file_by_hash() keys on the PATHNAME hash, not this.
            $rec = $DB->get_records_select('files',
                "contenthash = :h AND filename <> '.'", ['h' => (string) $row->contenthash], 'id ASC', 'id', 0, 1);
            $file = $rec ? $fs->get_file_by_id((int) reset($rec)->id) : false;
            if (!$file || $file->is_directory()) {
                // The evidence is gone locally: surface it against the contenthash
                // (a genuine local loss) rather than retry a file that will never appear.
                $DB->set_field('local_syncqueue_files', 'status', self::STATUS_MISSING, ['id' => $row->id]);
                $missing++;
                debugging("ship_files: local blob {$row->contenthash} is gone; marked missing", DEBUG_DEVELOPER);
                continue;
            }

            // Too large for the single-shot channel: park it 'deferred' (NOT 'failed',
            // which is for corrupt blobs) so the chunked shipper follow-up can pick it up
            // — never silently drop a legitimate large submission.
            if ($file->get_filesize() > upload_file::MAX_BYTES) {
                $DB->set_field('local_syncqueue_files', 'status', self::STATUS_DEFERRED, ['id' => $row->id]);
                $deferred++;
                continue;
            }

            try {
                $resp = $client->upload_syncfile((string) $row->contenthash, (string) $row->filename,
                    base64_encode($file->get_content()));
            } catch (\Throwable $e) {
                $failed++;
                continue; // Transport error: stay pending, retry next run.
            }

            if (!empty($resp['received'])) {
                $update = (object) ['id' => $row->id, 'status' => queue_manager::STATUS_SYNCED,
                    'timesynced' => time()];
                $DB->update_record('local_syncqueue_files', $update);
                $shipped++;
                if (!empty($resp['dedup'])) {
                    $dedup++;
                }
            } else {
                // Central rejected (bad hash / oversize): leave pending is pointless —
                // an oversize blob needs the chunked follow-up, a bad-hash blob is
                // corrupt. Mark failed so it is visible and not retried every run.
                $DB->set_field('local_syncqueue_files', 'status', queue_manager::STATUS_FAILED, ['id' => $row->id]);
                $failed++;
                debugging('ship_files: central rejected ' . $row->contenthash . ': '
                    . ($resp['error'] ?? 'unknown'), DEBUG_DEVELOPER);
            }
        }

        // Bound the table: drop long-settled terminal rows (synced/missing/failed) —
        // the fact ledger, not this queue, is the durable record of what was captured.
        $DB->delete_records_select('local_syncqueue_files',
            'status IN (:synced, :missing, :failed) AND timecreated < :cutoff',
            ['synced' => queue_manager::STATUS_SYNCED, 'missing' => self::STATUS_MISSING,
                'failed' => queue_manager::STATUS_FAILED, 'cutoff' => time() - self::CLEANUP_SECONDS]);

        mtrace("ship_files: {$shipped} shipped ({$dedup} already held), {$missing} missing, "
            . "{$deferred} deferred (oversize), {$failed} failed.");
    }

    /**
     * The sync client used to upload blobs (overridable for testing).
     *
     * @return sync_client
     */
    protected function client(): sync_client {
        return new sync_client();
    }
}
