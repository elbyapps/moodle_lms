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
use local_syncqueue\seed_publisher;
use stdClass;

/**
 * Central history-republish drainer (ELMS Sync v2 step 5, doc §8.3 / §12).
 *
 * Processes the coalesced reseed-job queue (local_syncqueue_seedjob): for each pending
 * (learner, destination school) job, regenerates the learner's terminal grades /
 * completions FRESH from central's gradebook and publishes them as seed rows targeted
 * at the school (via seed_publisher::republish). A job that waited therefore never
 * seeds stale data. Quarantined jobs (a flapping learner, set by the enqueue guard)
 * are left for an admin and never processed here.
 *
 * Per-job outcome: success -> done; a retryable error -> attempts++ (quarantined at the
 * retry budget so a permanently-failing job can't spin forever). Central mode only.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_republish extends scheduled_task {

    /** @var int Jobs drained per run. */
    const BATCH_LIMIT = 50;

    /** @var int Retry budget before a failing job is quarantined. */
    const MAX_ATTEMPTS = 5;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_historyrepublish', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        if (get_config('local_syncqueue', 'mode') !== 'central') {
            mtrace('history_republish: not central mode; nothing to do');
            return;
        }
        if (!get_config('local_syncqueue', 'enabled')) {
            mtrace('history_republish: sync disabled; skipping');
            return;
        }
        if (!$DB->get_manager()->table_exists(seed_publisher::JOB_TABLE)) {
            return;
        }

        $jobs = $DB->get_records('local_syncqueue_seedjob', ['status' => 'pending'],
            'timecreated ASC', '*', 0, self::BATCH_LIMIT);
        if (empty($jobs)) {
            return;
        }

        $done = 0;
        $failed = 0;
        $seeded = 0;
        foreach ($jobs as $job) {
            try {
                $n = seed_publisher::republish((string) $job->sdms, (string) $job->schoolid);
                $this->settle($job, 'done', (int) $job->attempts, null);
                $seeded += $n;
                $done++;
            } catch (\Throwable $e) {
                $attempts = (int) $job->attempts + 1;
                // Quarantine (not 'pending') once the budget is spent so a permanently
                // failing job stops being re-selected every run.
                $status = ($attempts >= self::MAX_ATTEMPTS) ? 'quarantined' : 'pending';
                $this->settle($job, $status, $attempts, $e->getMessage());
                $failed++;
            }
        }

        mtrace('history_republish: ' . count($jobs) . ' job(s): ' . $done . ' done ('
            . $seeded . ' seed rows), ' . $failed . ' failed/retry');
    }

    /**
     * Persist a job's status transition.
     *
     * @param stdClass $job The job row.
     * @param string $status New status (pending|done|quarantined).
     * @param int $attempts Attempt count to store.
     * @param string|null $lasterror Diagnostic error, or null to clear it.
     */
    protected function settle(stdClass $job, string $status, int $attempts, ?string $lasterror): void {
        global $DB;

        // Optimistic concurrency: only write if the job has not been re-enqueued by a
        // concurrent home change since we read it (enqueue resets it to pending and bumps
        // timemodified). Otherwise a 'done' here would drop that re-seed. If the guard
        // skips the write, the job stays pending and is re-drained next run — republish is
        // idempotent, so re-running is safe.
        $DB->execute(
            'UPDATE {local_syncqueue_seedjob} SET status = ?, attempts = ?, lasterror = ?, timemodified = ?'
                . ' WHERE id = ? AND timemodified = ?',
            [$status, $attempts, $lasterror, time(), (int) $job->id, (int) $job->timemodified]);
    }
}
