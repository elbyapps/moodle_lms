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
 * Scheduled task to compute user metrics from logstore.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Hourly task that aggregates logstore_standard_log into elby_user_metrics.
 */
class compute_user_metrics extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_compute_user_metrics', 'local_elby_dashboard');
    }

    /**
     * Execute the task.
     *
     * Computes metrics for the current week's period.
     */
    public function execute(): void {
        $calculator = new \local_elby_dashboard\metrics_calculator();

        $weekstart = \local_elby_dashboard\metrics_calculator::get_current_week_start();
        $weekend = \local_elby_dashboard\metrics_calculator::get_current_week_end();

        mtrace("Computing user metrics for period " . date('Y-m-d', $weekstart) .
            " to " . date('Y-m-d', $weekend));

        $calculator->compute_for_period($weekstart, $weekend);

        mtrace("User metrics computation complete.");
    }
}
