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

namespace local_elby_dashboard\task;

use core\task\scheduled_task;
use local_elby_dashboard\desired_state_reconciler;

/**
 * Hourly desired-state enrolment reconcile (ELMS Sync v2 §6).
 *
 * Converges cohorts, cohort membership and cohort-sync enrol instances to the
 * state the persisted roster + links + course metadata imply. Offline and
 * idempotent, so it is safe to run on every school box regardless of tier.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_desired_state extends scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_reconciledesiredstate', 'local_elby_dashboard');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $report = desired_state_reconciler::reconcile([
            'trace' => function (string $m): void {
                mtrace('reconcile: ' . $m);
            },
        ]);

        mtrace(sprintf(
            'reconcile: cohorts +%d; members +%d added, %d suspended, %d held; '
            . 'instances +%d created, %d re-enabled, %d disabled',
            $report->cohorts_created, $report->members_added, $report->members_suspended,
            $report->suspend_skipped, $report->instances_created, $report->instances_reenabled,
            $report->instances_disabled));

        foreach ($report->warnings as $w) {
            mtrace('reconcile WARNING: ' . $w);
        }
    }
}
