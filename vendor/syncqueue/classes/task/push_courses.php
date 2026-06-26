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
use local_syncqueue\backup_manager;
use local_syncqueue\job_manager;
use local_syncqueue\update_manager;

/**
 * Adhoc task: back up one course and queue it (+ its school enrolments) for schools.
 *
 * One task per course keeps the heavy work off the web request, isolates
 * failures, and lets Moodle's adhoc runner parallelise/retry.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_courses extends adhoc_task {

    /**
     * Execute the task for a single course.
     */
    public function execute(): void {
        global $DB;

        $data = (array) $this->get_custom_data();
        $jobid = (int) ($data['jobid'] ?? 0);
        $courseid = (int) ($data['courseid'] ?? 0);
        $userid = (int) ($this->get_userid() ?: get_admin()->id);

        if (!$jobid || !$courseid) {
            mtrace('push_courses: missing jobid/courseid, skipping.');
            return;
        }

        $jobmgr = new job_manager();
        $item = $DB->get_record('local_syncqueue_job_items', ['jobid' => $jobid, 'courseid' => $courseid]);
        if (!$item) {
            mtrace("push_courses: no job item for job $jobid course $courseid.");
            return;
        }

        // Already terminal (e.g. task retried after success) — nothing to do.
        if (in_array($item->status, ['done', 'failed', 'skipped'], true)) {
            mtrace("push_courses: item {$item->id} already {$item->status}.");
            return;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || $course->id == SITEID) {
            $jobmgr->set_item_status($item->id, 'skipped', ['error' => 'Course not found']);
            return;
        }

        try {
            $jobmgr->set_item_status($item->id, 'backing_up');

            $backupmanager = new backup_manager();
            $updatemanager = new update_manager();

            // 1. Backup (heavy) — now off the web request.
            $backupfile = $backupmanager->create_course_backup($courseid, $userid);
            if ($backupfile) {
                $updatemanager->queue_course_update_with_backup($course, $backupfile, 'create');
            } else {
                // Metadata-only fallback; still a usable update.
                $updatemanager->queue_course_update($course, 'create');
                mtrace("push_courses: backup failed for course $courseid, queued metadata only.");
            }

            // 2. School enrolment fan-out.
            $jobmgr->set_item_status($item->id, 'queuing_enrolments', ['backupfile' => (string) $backupfile]);
            [$usercount, $enrolcount] = $this->queue_school_enrolments($courseid, $updatemanager);

            $jobmgr->set_item_status($item->id, 'done', [
                'backupfile' => (string) $backupfile,
                'usercount' => $usercount,
                'enrolcount' => $enrolcount,
            ]);
            mtrace("push_courses: course $courseid done (users=$usercount, enrolments=$enrolcount).");

        } catch (\Throwable $e) {
            $jobmgr->set_item_status($item->id, 'failed', ['error' => $e->getMessage()]);
            mtrace('push_courses: course ' . $courseid . ' failed: ' . $e->getMessage());
            // Do not rethrow: a single bad course must not abort sibling tasks.
        }
    }

    /**
     * Queue user + enrolment updates for school-specific students of a course.
     *
     * One query per course resolves all active-school students at once.
     *
     * @param int $courseid Course id.
     * @param update_manager $updatemanager Update manager.
     * @return array{0:int,1:int} [usercount, enrolcount]
     */
    protected function queue_school_enrolments(int $courseid, update_manager $updatemanager): array {
        global $DB;

        // Students enrolled in this course who belong to an active registered school.
        $sql = "SELECT DISTINCT u.id, u.username, u.email, u.firstname, u.lastname, u.idnumber, u.password
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                  JOIN {elby_sdms_users} su ON su.userid = u.id AND su.user_type = 'student'
                  JOIN {elby_schools} sch ON sch.id = su.schoolid
                  JOIN {local_syncqueue_schools} ls ON ls.schoolid = sch.school_code AND ls.status = 'active'
                 WHERE ue.status = 0 AND u.deleted = 0";
        $students = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $usercount = 0;
        $enrolcount = 0;
        foreach ($students as $student) {
            $updatemanager->queue_user_update($student, 'create');
            $updatemanager->queue_enrolment_update($student->id, $courseid, 'create');
            $usercount++;
            $enrolcount++;
        }

        return [$usercount, $enrolcount];
    }
}
