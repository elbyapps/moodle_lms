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
 * Event observers for local_elby_dashboard.
 *
 * Handles real-time metric updates for quiz submissions, assignment
 * submissions, and course/module completions.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer class.
 */
class observer {

    /**
     * Handle quiz attempt submission.
     *
     * Increments quizzes_attempted and recomputes quizzes_avg_score
     * based on all quiz attempts in the current week.
     *
     * @param \mod_quiz\event\attempt_submitted $event The event.
     */
    public static function quiz_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;

        $record = self::get_or_create_weekly_record($userid, $courseid);
        if (!$record) {
            return;
        }

        // Increment quizzes_attempted.
        $record->quizzes_attempted = (int) $record->quizzes_attempted + 1;

        // Recompute average quiz score from all attempts this week.
        $weekstart = metrics_calculator::get_current_week_start();
        $weekend = metrics_calculator::get_current_week_end();

        $avgscore = $DB->get_field_sql(
            "SELECT AVG(qg.grade / q.grade * 100)
             FROM {quiz_grades} qg
             JOIN {quiz} q ON q.id = qg.quiz
             WHERE qg.userid = :userid
               AND q.course = :courseid
               AND qg.timemodified >= :weekstart
               AND qg.timemodified < :weekend
               AND q.grade > 0",
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'weekstart' => $weekstart,
                'weekend' => $weekend,
            ]
        );

        if ($avgscore !== false) {
            $record->quizzes_avg_score = round((float) $avgscore, 2);
        }

        $DB->update_record('elby_user_metrics', $record);
    }

    /**
     * Handle assignment submission.
     *
     * Increments assignments_submitted counter.
     *
     * @param \mod_assign\event\submission_created $event The event.
     */
    public static function assignment_submitted(\mod_assign\event\submission_created $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;

        $record = self::get_or_create_weekly_record($userid, $courseid);
        if (!$record) {
            return;
        }

        $record->assignments_submitted = (int) $record->assignments_submitted + 1;
        $DB->update_record('elby_user_metrics', $record);
    }

    /**
     * Handle course module completion update.
     *
     * Increments activities_completed when state is COMPLETION_COMPLETE or
     * COMPLETION_COMPLETE_PASS.
     *
     * @param \core\event\course_module_completion_updated $event The event.
     */
    public static function course_module_completed(\core\event\course_module_completion_updated $event): void {
        global $DB;

        $data = $event->get_record_snapshot('course_modules_completion', $event->objectid);

        // Only count actual completions.
        if (!in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS])) {
            return;
        }

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;

        $record = self::get_or_create_weekly_record($userid, $courseid);
        if (!$record) {
            return;
        }

        $record->activities_completed = (int) $record->activities_completed + 1;
        $DB->update_record('elby_user_metrics', $record);
    }

    /**
     * Handle course completion.
     *
     * Sets course_progress to 100.
     *
     * @param \core\event\course_completed $event The event.
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;

        $record = self::get_or_create_weekly_record($userid, $courseid);
        if (!$record) {
            return;
        }

        $record->course_progress = 100.00;
        $DB->update_record('elby_user_metrics', $record);
    }

    /**
     * Get or create the current week's elby_user_metrics record.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Moodle course ID.
     * @return \stdClass|null The metrics record, or null on failure.
     */
    private static function get_or_create_weekly_record(int $userid, int $courseid): ?\stdClass {
        global $DB;

        $weekstart = metrics_calculator::get_current_week_start();
        $weekend = metrics_calculator::get_current_week_end();

        $record = $DB->get_record('elby_user_metrics', [
            'userid' => $userid,
            'courseid' => $courseid,
            'period_start' => $weekstart,
            'period_type' => 'weekly',
        ]);

        if ($record) {
            return $record;
        }

        // Create a new record.
        try {
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->period_start = $weekstart;
            $record->period_end = $weekend;
            $record->period_type = 'weekly';
            $record->total_actions = 0;
            $record->active_days = 0;
            $record->first_access = null;
            $record->last_access = null;
            $record->time_spent_seconds = 0;
            $record->resources_viewed = 0;
            $record->resources_unique = 0;
            $record->videos_started = 0;
            $record->videos_completed = 0;
            $record->pages_viewed = 0;
            $record->files_downloaded = 0;
            $record->forum_views = 0;
            $record->forum_posts = 0;
            $record->forum_replies = 0;
            $record->chat_messages = 0;
            $record->assignments_viewed = 0;
            $record->assignments_submitted = 0;
            $record->assignments_graded = 0;
            $record->assignments_avg_score = null;
            $record->quizzes_attempted = 0;
            $record->quizzes_completed = 0;
            $record->quizzes_avg_score = null;
            $record->quizzes_avg_duration = null;
            $record->activities_completed = 0;
            $record->activities_total = 0;
            $record->course_progress = null;
            $record->timecreated = time();

            $record->id = $DB->insert_record('elby_user_metrics', $record);
            return $record;
        } catch (\Exception $e) {
            debugging("observer: failed to create metrics record: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }
}
