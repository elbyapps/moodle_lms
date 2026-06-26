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

use core\task\adhoc_task;
use local_syncqueue\job_manager;
use local_syncqueue\sync_client;
use local_syncqueue\update_processor;

/**
 * Adhoc task (school mode): download updates from central and apply them.
 *
 * The heavy work — downloading .mbz backups and restoring courses — runs here
 * off the web request, with per-update progress tracked on the job page.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pull_updates extends adhoc_task {

    /**
     * Execute the pull.
     */
    public function execute(): void {
        global $DB;

        $data = (array) $this->get_custom_data();
        $jobid = (int) ($data['jobid'] ?? 0);
        if (!$jobid) {
            mtrace('pull_updates: missing jobid.');
            return;
        }

        $jobmgr = new job_manager();

        if (get_config('local_syncqueue', 'mode') !== 'school' || !get_config('local_syncqueue', 'enabled')) {
            $this->finish_job($jobid, 'failed', get_string('error_disabled', 'local_syncqueue'));
            return;
        }

        // 1. Download the pending update list (light JSON; backups fetched per item).
        try {
            $client = new sync_client();
            $updates = $client->download(0);
        } catch (\Throwable $e) {
            $this->finish_job($jobid, 'failed', $e->getMessage());
            mtrace('pull_updates: download failed: ' . $e->getMessage());
            return;
        }

        if (empty($updates)) {
            $this->finish_job($jobid, 'completed', null);
            mtrace('pull_updates: no updates available.');
            return;
        }

        // 2. Record one item per update so progress is visible.
        $items = [];
        foreach ($updates as $i => $update) {
            [$type, $label, $courseid] = $this->describe($update);
            $items[$i] = $jobmgr->add_item($jobid, $type, $label, $courseid);
        }
        $jobmgr->recalculate_job($jobid);

        // 3. Apply each update (course restores are the heavy ones).
        $processor = new update_processor();
        $success = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($updates as $i => $update) {
            $itemid = $items[$i];
            $jobmgr->set_item_status($itemid, 'applying');
            try {
                $ok = $processor->apply_update($update);
                $jobmgr->set_item_status($itemid, $ok ? 'done' : 'skipped');
                $ok ? $success++ : $skipped++;
            } catch (\Throwable $e) {
                $jobmgr->set_item_status($itemid, 'failed', ['error' => $e->getMessage()]);
                $failed++;
                mtrace('pull_updates: update ' . ($update['id'] ?? '?') . ' failed: ' . $e->getMessage());
            }
        }

        mtrace("pull_updates: applied {$success}, skipped {$skipped}, failed {$failed}.");

        // 4. Best-effort report back to central.
        try {
            $client->report_sync(['success' => $success, 'failed' => $failed, 'skipped' => $skipped]);
        } catch (\Throwable $e) {
            mtrace('pull_updates: report_sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Describe an update for a job item (type, label, course reference).
     *
     * @param array $update Update from download().
     * @return array{0:string,1:string,2:int} [itemtype, label, courseid]
     */
    protected function describe(array $update): array {
        $type = $update['type'] ?? 'unknown';
        $d = isset($update['data'])
            ? (is_string($update['data']) ? json_decode($update['data'], true) : $update['data'])
            : [];
        $d = is_array($d) ? $d : [];

        switch ($type) {
            case 'course':
                $label = $d['fullname'] ?? ('Course ' . ($d['id'] ?? '?'));
                return ['course', (string) $label, 0];
            case 'user':
                $name = trim(($d['firstname'] ?? '') . ' ' . ($d['lastname'] ?? ''));
                $label = $name !== '' ? $name : ($d['username'] ?? 'User');
                if (!empty($d['email'])) {
                    $label .= ' (' . $d['email'] . ')';
                }
                return ['user', $label, 0];
            case 'enrolment':
                $u = $d['user'] ?? [];
                $c = $d['course'] ?? [];
                $label = ($u['email'] ?? ($u['username'] ?? 'user')) . ' \u2192 ' . ($c['shortname'] ?? 'course');
                return ['enrolment', $label, 0];
            default:
                return [$type, ucfirst($type), 0];
        }
    }

    /**
     * Mark a job finished with a status and optional error.
     *
     * @param int $jobid Job id.
     * @param string $status completed or failed.
     * @param string|null $error Error message.
     */
    protected function finish_job(int $jobid, string $status, ?string $error): void {
        global $DB;
        $DB->update_record('local_syncqueue_jobs', (object) [
            'id' => $jobid,
            'status' => $status,
            'error' => $error,
            'timecompleted' => time(),
        ]);
    }
}
