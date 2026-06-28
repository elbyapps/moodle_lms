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
 * Scheduled task to aggregate school-level metrics.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily task that rolls up elby_user_metrics into elby_school_metrics.
 */
class aggregate_school_metrics extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_aggregate_school_metrics', 'local_elby_dashboard');
    }

    /**
     * Execute the task.
     *
     * Aggregates both weekly and monthly metrics for all schools.
     */
    public function execute(): void {
        $aggregator = new \local_elby_dashboard\school_aggregator();

        // Weekly aggregation.
        $weekstart = \local_elby_dashboard\metrics_calculator::get_current_week_start();
        $weekend = \local_elby_dashboard\metrics_calculator::get_current_week_end();

        mtrace("Aggregating weekly school metrics for " . date('Y-m-d', $weekstart) .
            " to " . date('Y-m-d', $weekend));
        $aggregator->aggregate_all($weekstart, $weekend, 'weekly');

        // Monthly aggregation.
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $monthstart = (new \DateTime($now->format('Y-m-01'), new \DateTimeZone('UTC')))->getTimestamp();
        $monthend = (new \DateTime($now->format('Y-m-01'), new \DateTimeZone('UTC')))
            ->modify('+1 month')->getTimestamp();

        mtrace("Aggregating monthly school metrics for " . date('Y-m-d', $monthstart) .
            " to " . date('Y-m-d', $monthend));
        $aggregator->aggregate_all($monthstart, $monthend, 'monthly');

        mtrace("School metrics aggregation complete.");
    }
}
