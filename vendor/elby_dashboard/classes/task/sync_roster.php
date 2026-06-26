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
 * Scheduled task: refresh the school's offline roster cache from TDMP.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

use core\task\scheduled_task;

/**
 * Pull the school's own student/teacher roster into the local cache.
 */
class sync_roster extends scheduled_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_roster', 'local_elby_dashboard');
    }

    /**
     * Run the roster refresh (school instances only).
     */
    public function execute(): void {
        if (!get_config('local_elby_dashboard', 'tdmp_proxy_mode')) {
            mtrace('Roster sync skipped: not a school instance (TDMP proxy mode off).');
            return;
        }

        try {
            $result = (new \local_elby_dashboard\roster_manager())->sync_roster();
            mtrace("Roster sync complete: {$result['students']} students, {$result['teachers']} teachers cached.");
        } catch (\Exception $e) {
            mtrace('Roster sync failed: ' . $e->getMessage());
        }
    }
}
