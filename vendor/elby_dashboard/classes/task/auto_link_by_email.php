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
 * Scheduled task to auto-link users to SDMS by email.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily task that links unlinked users whose email contains their SDMS code.
 */
class auto_link_by_email extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_auto_link_by_email', 'local_elby_dashboard');
    }

    /**
     * Execute the task.
     *
     * Finds users with RTB emails who are not yet linked to SDMS,
     * extracts the SDMS code from the email prefix, and links them
     * via sync_service->link_user().
     */
    public function execute(): void {
        global $DB;

        $syncservice = new \local_elby_dashboard\sync_service();

        // Find unlinked users with RTB emails (numeric prefix only).
        $regex = $DB->sql_regex();
        $sql = "SELECT u.id, u.email
                FROM {user} u
                LEFT JOIN {elby_sdms_users} su ON su.userid = u.id
                WHERE su.id IS NULL AND u.deleted = 0
                  AND (u.email LIKE :email1 OR u.email LIKE :email2)
                  AND u.email {$regex} :emailpattern
                ORDER BY u.id ASC";
        $users = $DB->get_records_sql($sql, [
            'email1' => '%@rtb.ac.rw',
            'email2' => '%@rtb.gov.rw',
            'emailpattern' => '^[0-9]+@',
        ], 0, 200); // Process up to 200 per run.

        $linked = 0;
        $failed = 0;
        $skippedduplicates = 0;
        $flagged = 0;
        foreach ($users as $user) {
            $sdmscode = explode('@', $user->email)[0];
            $allowreassign = false;

            // If this TDMP code is already linked elsewhere, the Moodle account
            // whose email is exactly based on the code wins. If the current
            // linked user also has that email pattern, keep the existing link.
            $existingbycode = $DB->get_record('elby_sdms_users', ['sdms_id' => $sdmscode], 'id, userid');
            if ($existingbycode && (int) $existingbycode->userid !== (int) $user->id) {
                $existinguser = $DB->get_record('user', ['id' => $existingbycode->userid], 'id, email, deleted');
                $existingprefix = '';
                if ($existinguser && strpos($existinguser->email, '@') !== false) {
                    $existingprefix = explode('@', $existinguser->email)[0];
                }

                if ($existinguser && empty($existinguser->deleted) && $existingprefix === $sdmscode) {
                    $skippedduplicates++;
                    mtrace("  Skipped user {$user->id} ({$user->email}): TDMP code already linked to user {$existingbycode->userid} with matching email.");
                    continue;
                }

                $allowreassign = true;
                mtrace("  Reassigning TDMP code {$sdmscode} from user {$existingbycode->userid} to user {$user->id} ({$user->email}).");
            }

            try {
                // Try student first.
                $ok = $syncservice->link_user($user->id, $sdmscode, 'student', $allowreassign);
                if (!$ok) {
                    // Try teacher/staff.
                    $ok = $syncservice->link_user($user->id, $sdmscode, 'staff', $allowreassign);
                }
                if ($ok) {
                    $linked++;
                } else {
                    $failed++;
                    $this->flag_failed_user($user->id, $sdmscode, 'Not found in TDMP as student or teacher');
                    $flagged++;
                }
            } catch (\Exception $e) {
                if ($e instanceof \moodle_exception && $e->errorcode === 'sdms_code_taken') {
                    $skippedduplicates++;
                    mtrace("  Skipped user {$user->id} ({$user->email}): TDMP code already linked to another account.");
                    continue;
                }

                $failed++;
                $detail = $e->getMessage();
                if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
                    $detail .= ' [debug: ' . $e->debuginfo . ']';
                }
                mtrace("  Failed to link user {$user->id} ({$user->email}): " . $detail);

                // Flag users that got HTTP 500 so they're not retried.
                if ($e instanceof \moodle_exception && !empty($e->debuginfo)
                        && strpos($e->debuginfo, 'HTTP 500') !== false) {
                    $this->flag_failed_user($user->id, $sdmscode, $detail);
                    $flagged++;
                }
            }
        }
        mtrace("Auto-link by email: {$linked} linked, {$failed} failed, {$skippedduplicates} duplicate-code skipped, {$flagged} flagged out of " . count($users) . " unlinked users.");
    }

    /**
     * Create a failed elby_sdms_users record so the user is excluded from future runs.
     *
     * @param int $userid Moodle user ID.
     * @param string $sdmscode SDMS code from email prefix.
     * @param string $error Error detail to store.
     */
    private function flag_failed_user(int $userid, string $sdmscode, string $error): void {
        global $DB;

        if ($DB->record_exists('elby_sdms_users', ['userid' => $userid])) {
            return;
        }

        $now = time();
        $record = new \stdClass();
        $record->userid = $userid;
        $record->sdms_id = $sdmscode;
        $record->user_type = '';
        $record->sync_status = 0;
        $record->sync_error = $error;
        $record->last_synced = $now;
        $record->timecreated = $now;
        $record->timemodified = $now;

        try {
            $DB->insert_record('elby_sdms_users', $record);
            mtrace("  Flagged user {$userid} ({$sdmscode}) — will not retry.");
        } catch (\Exception $e) {
            mtrace("  Could not flag user {$userid}: " . $e->getMessage());
        }
    }
}
