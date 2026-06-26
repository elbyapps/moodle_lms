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
 * Ad-hoc task to attach a newly created cohort to all matching courses.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Defers the Phase 2 course fan-out off the interactive student-link request.
 */
class link_cohort_adhoc extends \core\task\adhoc_task {

    /**
     * Execute the task.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->cohortid)) {
            return;
        }
        \local_elby_dashboard\cohort_course_linker::link_cohort((int) $data->cohortid);
    }
}
