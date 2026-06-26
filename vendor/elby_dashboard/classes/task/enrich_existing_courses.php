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
 * Ad-hoc task: backfill elby_course_meta for existing courses.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad-hoc task that enriches every existing course (idempotent).
 */
class enrich_existing_courses extends \core\task\adhoc_task {

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $courseids = $DB->get_fieldset_select('course', 'id', 'id > 1', []);
        $processed = 0;
        foreach ($courseids as $courseid) {
            try {
                \local_elby_dashboard\course_enricher::enrich_course((int) $courseid);
                $processed++;
            } catch (\Throwable $e) {
                mtrace('enrich_existing_courses: course ' . $courseid . ' failed: ' . $e->getMessage());
            }
        }
        mtrace('enrich_existing_courses: processed ' . $processed . ' course(s)');
    }
}
