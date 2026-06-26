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
 * Metrics calculator for local_elby_dashboard.
 *
 * Computes user engagement metrics from logstore_standard_log and
 * UPSERTs results into elby_user_metrics.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Computes user engagement metrics from logstore data.
 */
class metrics_calculator {

    /** @var int Session gap threshold in seconds (30 minutes). */
    private const SESSION_GAP = 1800;

    /**
     * Compute metrics for all active user-course pairs in a given period.
     *
     * Finds users with logstore activity in the period, then computes
     * and upserts metrics for each user-course pair.
     *
     * @param int $periodstart Unix timestamp for period start.
     * @param int $periodend Unix timestamp for period end.
     */
    public function compute_for_period(int $periodstart, int $periodend): void {
        global $DB;

        // Find all active user-course pairs in this period (excluding site course).
        $sql = "SELECT DISTINCT userid, courseid
                FROM {logstore_standard_log}
                WHERE timecreated >= :start
                  AND timecreated < :end
                  AND courseid > 1
                  AND userid > 0
                  AND anonymous = 0";
        $pairs = $DB->get_records_sql($sql, [
            'start' => $periodstart,
            'end' => $periodend,
        ]);

        foreach ($pairs as $pair) {
            try {
                $this->compute_user_course_metrics(
                    (int) $pair->userid,
                    (int) $pair->courseid,
                    $periodstart,
                    $periodend
                );
            } catch (\Exception $e) {
                debugging("metrics_calculator: error for user {$pair->userid} course {$pair->courseid}: " .
                    $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Compute full metrics for a user-course pair and UPSERT into elby_user_metrics.
     *
     * Does NOT overwrite observer-managed fields (quizzes_*, assignments_submitted,
     * course_progress, activities_completed).
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Moodle course ID.
     * @param int $weekstart Unix timestamp for week start.
     * @param int $weekend Unix timestamp for week end.
     */
    public function compute_user_course_metrics(int $userid, int $courseid, int $weekstart, int $weekend): void {
        global $DB;

        // Fetch all log entries for this user-course-period.
        $logs = $DB->get_records_sql(
            "SELECT id, component, action, target, objecttable, timecreated
             FROM {logstore_standard_log}
             WHERE userid = :userid
               AND courseid = :courseid
               AND timecreated >= :start
               AND timecreated < :end
               AND anonymous = 0
             ORDER BY timecreated ASC",
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'start' => $weekstart,
                'end' => $weekend,
            ]
        );

        if (empty($logs)) {
            return;
        }

        // Compute content-type counts.
        $timestamps = [];
        $totalactions = 0;
        $activedaysmap = [];
        $resourcesviewed = 0;
        $resourcesuniqueset = [];
        $pagesviewed = 0;
        $videosstarted = 0;
        $forumviews = 0;
        $forumposts = 0;
        $forumreplies = 0;
        $chatmessages = 0;
        $filesdownloaded = 0;
        $assignmentsviewed = 0;
        $firstaccess = null;
        $lastaccess = null;

        foreach ($logs as $log) {
            $ts = (int) $log->timecreated;
            $timestamps[] = $ts;
            $totalactions++;
            $activedaysmap[date('Y-m-d', $ts)] = true;

            if ($firstaccess === null || $ts < $firstaccess) {
                $firstaccess = $ts;
            }
            if ($lastaccess === null || $ts > $lastaccess) {
                $lastaccess = $ts;
            }

            $component = $log->component;
            $action = $log->action;
            $target = $log->target;

            // Content mapping.
            if ($component === 'mod_resource') {
                $resourcesviewed++;
                if (!empty($log->objecttable)) {
                    $resourcesuniqueset[$log->objecttable . '_' . ($log->id ?? '')] = true;
                }
            } else if ($component === 'mod_page') {
                $pagesviewed++;
            } else if ($component === 'mod_url') {
                $videosstarted++;
            } else if ($component === 'mod_forum') {
                if ($action === 'viewed') {
                    $forumviews++;
                } else if ($action === 'created' && $target === 'discussion') {
                    $forumposts++;
                } else if ($action === 'created' && $target === 'post') {
                    $forumreplies++;
                }
            } else if ($component === 'mod_chat') {
                if ($action === 'sent' || $action === 'created') {
                    $chatmessages++;
                }
            } else if ($component === 'mod_assign') {
                if ($action === 'viewed') {
                    $assignmentsviewed++;
                }
            }

            // File downloads.
            if ($action === 'downloaded' || ($component === 'mod_resource' && $action === 'viewed')) {
                $filesdownloaded++;
            }
        }

        $timespent = $this->estimate_time_spent($timestamps);
        $activedays = count($activedaysmap);
        $resourcesunique = count($resourcesuniqueset);

        // Get total activities in the course.
        $activitiestotal = $DB->count_records('course_modules', [
            'course' => $courseid,
            'deletioninprogress' => 0,
        ]);

        // UPSERT: find or create the record.
        $existing = $DB->get_record('elby_user_metrics', [
            'userid' => $userid,
            'courseid' => $courseid,
            'period_start' => $weekstart,
            'period_type' => 'weekly',
        ]);

        if ($existing) {
            // Update only logstore-derived fields, preserve observer-managed fields.
            $existing->period_end = $weekend;
            $existing->total_actions = $totalactions;
            $existing->active_days = $activedays;
            $existing->first_access = $firstaccess;
            $existing->last_access = $lastaccess;
            $existing->time_spent_seconds = $timespent;
            $existing->resources_viewed = $resourcesviewed;
            $existing->resources_unique = $resourcesunique;
            $existing->videos_started = $videosstarted;
            $existing->pages_viewed = $pagesviewed;
            $existing->files_downloaded = $filesdownloaded;
            $existing->forum_views = $forumviews;
            $existing->forum_posts = $forumposts;
            $existing->forum_replies = $forumreplies;
            $existing->chat_messages = $chatmessages;
            $existing->assignments_viewed = $assignmentsviewed;
            $existing->activities_total = $activitiestotal;
            $DB->update_record('elby_user_metrics', $existing);
        } else {
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->period_start = $weekstart;
            $record->period_end = $weekend;
            $record->period_type = 'weekly';
            $record->total_actions = $totalactions;
            $record->active_days = $activedays;
            $record->first_access = $firstaccess;
            $record->last_access = $lastaccess;
            $record->time_spent_seconds = $timespent;
            $record->resources_viewed = $resourcesviewed;
            $record->resources_unique = $resourcesunique;
            $record->videos_started = $videosstarted;
            $record->videos_completed = 0;
            $record->pages_viewed = $pagesviewed;
            $record->files_downloaded = $filesdownloaded;
            $record->forum_views = $forumviews;
            $record->forum_posts = $forumposts;
            $record->forum_replies = $forumreplies;
            $record->chat_messages = $chatmessages;
            $record->assignments_viewed = $assignmentsviewed;
            $record->assignments_submitted = 0;
            $record->assignments_graded = 0;
            $record->assignments_avg_score = null;
            $record->quizzes_attempted = 0;
            $record->quizzes_completed = 0;
            $record->quizzes_avg_score = null;
            $record->quizzes_avg_duration = null;
            $record->activities_completed = 0;
            $record->activities_total = $activitiestotal;
            $record->course_progress = null;
            $record->timecreated = time();
            $DB->insert_record('elby_user_metrics', $record);
        }
    }

    /**
     * Estimate time spent from a sorted list of timestamps using session-gap algorithm.
     *
     * Iterates sorted timestamps; if gap between consecutive events is < 30 minutes,
     * adds the gap to total. Otherwise starts a new session.
     *
     * @param int[] $timestamps Sorted array of Unix timestamps.
     * @return int Estimated time spent in seconds.
     */
    public function estimate_time_spent(array $timestamps): int {
        if (count($timestamps) < 2) {
            return 0;
        }

        sort($timestamps);
        $total = 0;

        for ($i = 1; $i < count($timestamps); $i++) {
            $gap = $timestamps[$i] - $timestamps[$i - 1];
            if ($gap < self::SESSION_GAP) {
                $total += $gap;
            }
        }

        return $total;
    }

    /**
     * Get the current week's start timestamp (Monday 00:00:00 UTC).
     *
     * @return int Unix timestamp.
     */
    public static function get_current_week_start(): int {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $dayofweek = (int) $now->format('N'); // 1=Monday, 7=Sunday.
        $now->modify('-' . ($dayofweek - 1) . ' days');
        $now->setTime(0, 0, 0);
        return $now->getTimestamp();
    }

    /**
     * Get the current week's end timestamp (next Monday 00:00:00 UTC).
     *
     * @return int Unix timestamp.
     */
    public static function get_current_week_end(): int {
        return self::get_current_week_start() + (7 * 86400);
    }
}
