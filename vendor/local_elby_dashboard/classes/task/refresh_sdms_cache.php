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
 * Scheduled task to refresh stale SDMS cache records.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily task that refreshes stale SDMS user and school records.
 */
class refresh_sdms_cache extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_refresh_sdms_cache', 'local_elby_dashboard');
    }

    /**
     * Execute the task.
     *
     * Finds SDMS records whose last_synced is older than the configured TTL
     * and refreshes them from the SDMS API.
     */
    public function execute(): void {
        global $DB;

        $ttl = (int) (get_config('local_elby_dashboard', 'sdms_cache_ttl') ?: 604800);
        $threshold = time() - $ttl;

        $syncservice = new \local_elby_dashboard\sync_service();

        // Refresh stale user records.
        $staleusers = $DB->get_records_select(
            'elby_sdms_users',
            'last_synced < :threshold',
            ['threshold' => $threshold],
            'last_synced ASC',
            'id, userid',
            0,
            100 // Process in batches to avoid timeouts.
        );

        $usercount = 0;
        foreach ($staleusers as $user) {
            try {
                $syncservice->refresh_user((int) $user->userid, true);
                $usercount++;
            } catch (\Exception $e) {
                mtrace("  Warning: failed to refresh user {$user->userid}: " . $e->getMessage());
            }
        }
        mtrace("Refreshed {$usercount} stale SDMS user records.");

        // Refresh stale school records.
        $staleschools = $DB->get_records_select(
            'elby_schools',
            'last_synced < :threshold',
            ['threshold' => $threshold],
            'last_synced ASC',
            'id, school_code',
            0,
            50
        );

        $schoolcount = 0;
        foreach ($staleschools as $school) {
            try {
                $syncservice->sync_school($school->school_code, true);
                $schoolcount++;
            } catch (\Exception $e) {
                mtrace("  Warning: failed to refresh school {$school->school_code}: " . $e->getMessage());
            }
        }
        mtrace("Refreshed {$schoolcount} stale SDMS school records.");
    }
}
