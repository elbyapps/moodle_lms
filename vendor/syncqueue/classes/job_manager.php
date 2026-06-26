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

namespace local_syncqueue;

use stdClass;

/**
 * Manages async course-push jobs (central mode).
 *
 * A push job records the admin intent (which courses) and spawns one adhoc
 * task per course so the heavy backup + enrolment fan-out runs off the web
 * request. Per-course items make progress transparent and resumable.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_manager {

    /** @var string Jobs table. */
    protected const JOBS = 'local_syncqueue_jobs';

    /** @var string Job items table. */
    protected const ITEMS = 'local_syncqueue_job_items';

    /** @var string[] Item statuses that mean a course is still being processed. */
    public const INFLIGHT = ['queued', 'backing_up', 'queuing_enrolments'];

    /**
     * Create a push job from a set of course ids and queue its adhoc tasks.
     *
     * Courses already in flight in another job are skipped (idempotency).
     *
     * @param int $userid Admin triggering the push.
     * @param int[] $courseids Course ids to push.
     * @param int|null $categoryid Source category when pushing a whole category.
     * @return stdClass {id, itemcourseids[], skipped[]}
     */
    public function create_push_job(int $userid, array $courseids, ?int $categoryid = null): stdClass {
        global $DB;

        // Normalise: unique, positive, exclude the site course.
        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids), function($id) {
            return $id > 0 && $id != SITEID;
        })));

        // Skip courses that are still in flight in any job.
        $skipped = [];
        if ($courseids) {
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
            [$statussql, $statusparams] = $DB->get_in_or_equal(self::INFLIGHT, SQL_PARAMS_NAMED, 's');
            $sql = "SELECT DISTINCT courseid FROM {" . self::ITEMS . "}
                     WHERE courseid $insql AND status $statussql";
            $skipped = array_map('intval', array_keys(
                $DB->get_records_sql($sql, array_merge($inparams, $statusparams))
            ));
        }
        $tocreate = array_values(array_diff($courseids, $skipped));

        $now = time();
        $job = new stdClass();
        $job->type = 'push_courses';
        $job->userid = $userid;
        $job->categoryid = $categoryid;
        $job->status = $tocreate ? 'queued' : 'completed';
        $job->totalitems = count($tocreate);
        $job->doneitems = 0;
        $job->faileditems = 0;
        $job->usercount = 0;
        $job->enrolcount = 0;
        $job->timecreated = $now;
        $job->timestarted = null;
        $job->timecompleted = $tocreate ? null : $now;
        $job->id = $DB->insert_record(self::JOBS, $job);

        foreach ($tocreate as $courseid) {
            $item = new stdClass();
            $item->jobid = $job->id;
            $item->courseid = $courseid;
            $item->status = 'queued';
            $item->usercount = 0;
            $item->enrolcount = 0;
            $item->timecreated = $now;
            $DB->insert_record(self::ITEMS, $item);

            $task = new \local_syncqueue\task\push_courses();
            $task->set_custom_data(['jobid' => $job->id, 'courseid' => $courseid]);
            $task->set_userid($userid);
            \core\task\manager::queue_adhoc_task($task);
        }

        $job->itemcourseids = $tocreate;
        $job->skipped = $skipped;
        return $job;
    }

    /**
     * Create a school-side pull job and queue its adhoc task.
     *
     * Items are created by the task once it has downloaded the update list
     * (the school cannot know what is pending until it asks central).
     *
     * @param int $userid Admin triggering the pull.
     * @return int Job id.
     */
    public function create_pull_job(int $userid): int {
        global $DB;
        $now = time();
        $job = (object) [
            'type' => 'pull_updates',
            'userid' => $userid,
            'status' => 'queued',
            'totalitems' => 0,
            'doneitems' => 0,
            'faileditems' => 0,
            'usercount' => 0,
            'enrolcount' => 0,
            'timecreated' => $now,
        ];
        $jobid = $DB->insert_record(self::JOBS, $job);

        $task = new \local_syncqueue\task\pull_updates();
        $task->set_custom_data(['jobid' => $jobid]);
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task);

        return $jobid;
    }

    /**
     * Add an item to a job (used by pull jobs to record each update).
     *
     * @param int $jobid Job id.
     * @param string $itemtype course, user, enrolment.
     * @param string $label Display label.
     * @param int $courseid Optional course id reference.
     * @return int Item id.
     */
    public function add_item(int $jobid, string $itemtype, string $label, int $courseid = 0): int {
        global $DB;
        return $DB->insert_record(self::ITEMS, (object) [
            'jobid' => $jobid,
            'courseid' => $courseid,
            'itemtype' => $itemtype,
            'label' => $label,
            'status' => 'queued',
            'usercount' => 0,
            'enrolcount' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Expand a category into its course ids.
     *
     * @param int $categoryid Category id.
     * @param bool $recursive Include subcategories.
     * @return int[] Course ids (excluding the site course).
     */
    public function expand_category(int $categoryid, bool $recursive = true): array {
        $category = \core_course_category::get($categoryid, IGNORE_MISSING);
        if (!$category) {
            return [];
        }
        $courses = $category->get_courses(['recursive' => $recursive, 'idonly' => true]);
        return array_values(array_filter(array_map('intval', $courses), function($id) {
            return $id > 0 && $id != SITEID;
        }));
    }

    /**
     * Get a job record.
     *
     * @param int $jobid Job id.
     * @return stdClass|false
     */
    public function get_job(int $jobid) {
        global $DB;
        return $DB->get_record(self::JOBS, ['id' => $jobid]);
    }

    /**
     * Get the items of a job (with course shortnames for display).
     *
     * @param int $jobid Job id.
     * @return array
     */
    public function get_job_items(int $jobid): array {
        global $DB;
        $sql = "SELECT i.*, c.shortname, c.fullname
                  FROM {" . self::ITEMS . "} i
             LEFT JOIN {course} c ON c.id = i.courseid
                 WHERE i.jobid = :jobid
              ORDER BY i.id ASC";
        return $DB->get_records_sql($sql, ['jobid' => $jobid]);
    }

    /**
     * Mark an item with a new status; sets timing automatically.
     *
     * @param int $itemid Item id.
     * @param string $status New status.
     * @param array $extra Extra fields to set (backupfile, usercount, enrolcount, error).
     */
    public function set_item_status(int $itemid, string $status, array $extra = []): void {
        global $DB;
        $rec = (object) array_merge($extra, ['id' => $itemid, 'status' => $status]);
        if ($status === 'backing_up' && empty($extra['timestarted'])) {
            $existing = $DB->get_field(self::ITEMS, 'timestarted', ['id' => $itemid]);
            if (empty($existing)) {
                $rec->timestarted = time();
            }
        }
        if (in_array($status, ['done', 'failed', 'skipped'], true)) {
            $rec->timecompleted = time();
        }
        $DB->update_record(self::ITEMS, $rec);
        $this->recalculate_job($DB->get_field(self::ITEMS, 'jobid', ['id' => $itemid]));
    }

    /**
     * Recompute a job's rollup counters and status from its items.
     *
     * @param int $jobid Job id.
     */
    public function recalculate_job(int $jobid): void {
        global $DB;

        $job = $DB->get_record(self::JOBS, ['id' => $jobid]);
        if (!$job) {
            return;
        }

        $total = $DB->count_records(self::ITEMS, ['jobid' => $jobid]);
        $done = $DB->count_records_select(self::ITEMS, 'jobid = :j AND status = :s',
            ['j' => $jobid, 's' => 'done']);
        $failed = $DB->count_records_select(self::ITEMS, 'jobid = :j AND status = :s',
            ['j' => $jobid, 's' => 'failed']);
        $skipped = $DB->count_records_select(self::ITEMS, 'jobid = :j AND status = :s',
            ['j' => $jobid, 's' => 'skipped']);
        $usercount = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(usercount), 0) FROM {" . self::ITEMS . "} WHERE jobid = ?", [$jobid]);
        $enrolcount = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(enrolcount), 0) FROM {" . self::ITEMS . "} WHERE jobid = ?", [$jobid]);

        $finished = $done + $failed + $skipped;
        $update = (object) [
            'id' => $jobid,
            'totalitems' => $total,
            'doneitems' => $done,
            'faileditems' => $failed,
            'usercount' => $usercount,
            'enrolcount' => $enrolcount,
        ];

        if (empty($job->timestarted) && $finished < $total) {
            $update->timestarted = time();
        }

        if ($finished >= $total) {
            $update->status = $failed > 0 ? ($done > 0 ? 'partial' : 'failed') : 'completed';
            if (empty($job->timecompleted)) {
                $update->timecompleted = time();
            }
        } else {
            $update->status = 'running';
        }

        $DB->update_record(self::JOBS, $update);
    }

    /**
     * Status snapshot for a job, suitable for the UI / web service.
     *
     * @param int $jobid Job id.
     * @return array|null
     */
    public function get_status(int $jobid): ?array {
        $job = $this->get_job($jobid);
        if (!$job) {
            return null;
        }
        $items = [];
        foreach ($this->get_job_items($jobid) as $item) {
            $items[] = [
                'id' => (int) $item->id,
                'courseid' => (int) $item->courseid,
                'coursename' => !empty($item->label)
                    ? format_string($item->label)
                    : ($item->fullname !== null ? format_string($item->fullname) : ('#' . $item->courseid)),
                'status' => $item->status,
                'usercount' => (int) $item->usercount,
                'enrolcount' => (int) $item->enrolcount,
                'error' => (string) ($item->error ?? ''),
            ];
        }
        return [
            'id' => (int) $job->id,
            'status' => $job->status,
            'totalitems' => (int) $job->totalitems,
            'doneitems' => (int) $job->doneitems,
            'faileditems' => (int) $job->faileditems,
            'usercount' => (int) $job->usercount,
            'enrolcount' => (int) $job->enrolcount,
            'finished' => in_array($job->status, ['completed', 'partial', 'failed'], true),
            'items' => $items,
        ];
    }

    /**
     * Latest push state per course id (for the listing badges).
     *
     * @param int[] $courseids Course ids.
     * @return array courseid => stdClass{status, timecompleted}
     */
    public function get_latest_states(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        // Most recent item per course.
        $sql = "SELECT i.courseid, i.status, i.timecompleted, i.timecreated
                  FROM {" . self::ITEMS . "} i
                  JOIN (
                        SELECT courseid, MAX(id) AS maxid
                          FROM {" . self::ITEMS . "}
                         WHERE courseid $insql
                      GROUP BY courseid
                       ) latest ON latest.maxid = i.id";
        $rows = $DB->get_records_sql($sql, $params);
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->courseid] = $r;
        }
        return $map;
    }
}
