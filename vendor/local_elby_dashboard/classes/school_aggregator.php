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
 * School metrics aggregator for local_elby_dashboard.
 *
 * Rolls up elby_user_metrics into elby_school_metrics by joining
 * with elby_sdms_users to group students by school.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregates user metrics into school-level metrics.
 */
class school_aggregator {

    /**
     * Aggregate metrics for all schools for a given period.
     *
     * @param int $periodstart Unix timestamp for period start.
     * @param int $periodend Unix timestamp for period end.
     * @param string $periodtype 'weekly' or 'monthly'.
     */
    public function aggregate_all(int $periodstart, int $periodend, string $periodtype = 'weekly'): void {
        global $DB;

        // Find all schools that have linked students.
        $schools = $DB->get_records_sql(
            "SELECT DISTINCT su.schoolid
             FROM {elby_sdms_users} su
             WHERE su.schoolid IS NOT NULL
               AND su.user_type = 'student'"
        );

        foreach ($schools as $schoolrow) {
            $schoolid = (int) $schoolrow->schoolid;
            try {
                $this->aggregate_school($schoolid, $periodstart, $periodend, $periodtype);
            } catch (\Exception $e) {
                debugging("school_aggregator: error for school {$schoolid}: " .
                    $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Aggregate metrics for a single school.
     *
     * Computes both per-course and school-wide (courseid=0) aggregates.
     *
     * @param int $schoolid School ID from elby_schools.
     * @param int $periodstart Unix timestamp for period start.
     * @param int $periodend Unix timestamp for period end.
     * @param string $periodtype 'weekly' or 'monthly'.
     */
    public function aggregate_school(int $schoolid, int $periodstart, int $periodend, string $periodtype): void {
        global $DB;

        // Get all student userids for this school.
        $studentuserids = $DB->get_fieldset_sql(
            "SELECT su.userid
             FROM {elby_sdms_users} su
             WHERE su.schoolid = :schoolid
               AND su.user_type = 'student'",
            ['schoolid' => $schoolid]
        );

        if (empty($studentuserids)) {
            return;
        }

        // Find all courses these students have metrics for in this period.
        list($insql, $params) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'uid');
        $params['pstart'] = $periodstart;
        $params['ptype'] = $periodtype;

        $courses = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid
             FROM {elby_user_metrics}
             WHERE userid {$insql}
               AND period_start = :pstart
               AND period_type = :ptype",
            $params
        );

        // Aggregate per-course.
        foreach ($courses as $courseid) {
            $this->aggregate_school_course($schoolid, (int) $courseid, $periodstart, $periodend,
                $periodtype, $studentuserids);
        }

        // Aggregate school-wide (courseid = 0).
        $this->aggregate_school_wide($schoolid, $periodstart, $periodend, $periodtype, $studentuserids);
    }

    /**
     * Aggregate metrics for a school-course pair.
     *
     * @param int $schoolid School ID.
     * @param int $courseid Course ID.
     * @param int $periodstart Period start timestamp.
     * @param int $periodend Period end timestamp.
     * @param string $periodtype Period type.
     * @param int[] $studentuserids Array of student user IDs for this school.
     */
    private function aggregate_school_course(int $schoolid, int $courseid, int $periodstart,
            int $periodend, string $periodtype, array $studentuserids): void {
        global $DB;

        list($insql, $params) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'uid');
        $params['courseid'] = $courseid;
        $params['pstart'] = $periodstart;
        $params['ptype'] = $periodtype;

        // Get aggregated metrics.
        $agg = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT um.userid) AS total_active,
                    AVG(um.total_actions) AS avg_actions,
                    AVG(um.active_days) AS avg_active_days,
                    AVG(um.time_spent_seconds / 60.0) AS avg_time_spent_minutes,
                    SUM(um.resources_viewed) AS total_resource_views,
                    AVG(um.resources_viewed) AS avg_resources,
                    SUM(um.assignments_submitted) AS total_submissions,
                    SUM(um.quizzes_attempted) AS total_quiz_attempts,
                    AVG(um.assignments_avg_score) AS avg_assignment_score,
                    AVG(um.quizzes_avg_score) AS avg_quiz_score,
                    AVG(um.course_progress) AS avg_course_progress
             FROM {elby_user_metrics} um
             WHERE um.userid {$insql}
               AND um.courseid = :courseid
               AND um.period_start = :pstart
               AND um.period_type = :ptype",
            $params
        );

        // Count enrolled students in this course for this school.
        list($insql2, $params2) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'uid');
        $params2['courseid'] = $courseid;
        $totalenrolled = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
             FROM {user_enrolments} ue
             JOIN {enrol} e ON e.id = ue.enrolid
             WHERE e.courseid = :courseid
               AND ue.userid {$insql2}",
            $params2
        );

        // Compute engagement distribution.
        $distribution = $this->compute_engagement_distribution(
            $studentuserids, $courseid, $periodstart, $periodtype
        );

        // At-risk: enrolled students with no recent activity (7 days).
        $atrisk = $this->count_at_risk_students($studentuserids, $courseid);

        // Count completed students.
        list($insql3, $params3) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'uid');
        $params3['courseid'] = $courseid;
        $params3['pstart'] = $periodstart;
        $params3['ptype'] = $periodtype;
        $completedcount = $DB->count_records_sql(
            "SELECT COUNT(*)
             FROM {elby_user_metrics} um
             WHERE um.userid {$insql3}
               AND um.courseid = :courseid
               AND um.period_start = :pstart
               AND um.period_type = :ptype
               AND um.course_progress = 100",
            $params3
        );
        $completionrate = $totalenrolled > 0 ? round(($completedcount / $totalenrolled) * 100, 2) : null;
        $submissionrate = $totalenrolled > 0 && $agg->total_submissions !== null
            ? round(($agg->total_submissions / $totalenrolled) * 100, 2) : null;

        $this->upsert_school_metrics($schoolid, $courseid, $periodstart, $periodend, $periodtype, [
            'total_enrolled' => $totalenrolled,
            'total_active' => (int) ($agg->total_active ?? 0),
            'total_inactive' => $totalenrolled - (int) ($agg->total_active ?? 0),
            'new_enrollments' => 0,
            'avg_actions_per_student' => $agg->avg_actions !== null ? round((float) $agg->avg_actions, 2) : null,
            'avg_active_days' => $agg->avg_active_days !== null ? round((float) $agg->avg_active_days, 2) : null,
            'avg_time_spent_minutes' => $agg->avg_time_spent_minutes !== null
                ? round((float) $agg->avg_time_spent_minutes, 2) : null,
            'total_resource_views' => (int) ($agg->total_resource_views ?? 0),
            'avg_resources_per_student' => $agg->avg_resources !== null ? round((float) $agg->avg_resources, 2) : null,
            'total_submissions' => (int) ($agg->total_submissions ?? 0),
            'total_quiz_attempts' => (int) ($agg->total_quiz_attempts ?? 0),
            'avg_assignment_score' => $agg->avg_assignment_score !== null
                ? round((float) $agg->avg_assignment_score, 2) : null,
            'avg_quiz_score' => $agg->avg_quiz_score !== null ? round((float) $agg->avg_quiz_score, 2) : null,
            'submission_rate' => $submissionrate,
            'avg_course_progress' => $agg->avg_course_progress !== null
                ? round((float) $agg->avg_course_progress, 2) : null,
            'completion_rate' => $completionrate,
            'high_engagement_count' => $distribution['high'],
            'medium_engagement_count' => $distribution['medium'],
            'low_engagement_count' => $distribution['low'],
            'at_risk_count' => $atrisk,
        ]);
    }

    /**
     * Aggregate school-wide metrics (courseid = 0).
     *
     * @param int $schoolid School ID.
     * @param int $periodstart Period start timestamp.
     * @param int $periodend Period end timestamp.
     * @param string $periodtype Period type.
     * @param int[] $studentuserids Student user IDs for this school.
     */
    private function aggregate_school_wide(int $schoolid, int $periodstart, int $periodend,
            string $periodtype, array $studentuserids): void {
        global $DB;

        list($insql, $params) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'uid');
        $params['pstart'] = $periodstart;
        $params['ptype'] = $periodtype;

        $agg = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT um.userid) AS total_active,
                    AVG(um.total_actions) AS avg_actions,
                    AVG(um.active_days) AS avg_active_days,
                    AVG(um.time_spent_seconds / 60.0) AS avg_time_spent_minutes,
                    SUM(um.resources_viewed) AS total_resource_views,
                    AVG(um.resources_viewed) AS avg_resources,
                    SUM(um.assignments_submitted) AS total_submissions,
                    SUM(um.quizzes_attempted) AS total_quiz_attempts,
                    AVG(um.assignments_avg_score) AS avg_assignment_score,
                    AVG(um.quizzes_avg_score) AS avg_quiz_score,
                    AVG(um.course_progress) AS avg_course_progress
             FROM {elby_user_metrics} um
             WHERE um.userid {$insql}
               AND um.period_start = :pstart
               AND um.period_type = :ptype",
            $params
        );

        $totalenrolled = count($studentuserids);

        // Engagement distribution across all courses.
        $distribution = $this->compute_engagement_distribution(
            $studentuserids, 0, $periodstart, $periodtype
        );

        // At-risk school-wide.
        $atrisk = $this->count_at_risk_students($studentuserids, 0);

        $completionrate = null;
        $submissionrate = $totalenrolled > 0 && $agg->total_submissions !== null
            ? round(((float) $agg->total_submissions / $totalenrolled) * 100, 2) : null;

        $this->upsert_school_metrics($schoolid, 0, $periodstart, $periodend, $periodtype, [
            'total_enrolled' => $totalenrolled,
            'total_active' => (int) ($agg->total_active ?? 0),
            'total_inactive' => $totalenrolled - (int) ($agg->total_active ?? 0),
            'new_enrollments' => 0,
            'avg_actions_per_student' => $agg->avg_actions !== null ? round((float) $agg->avg_actions, 2) : null,
            'avg_active_days' => $agg->avg_active_days !== null ? round((float) $agg->avg_active_days, 2) : null,
            'avg_time_spent_minutes' => $agg->avg_time_spent_minutes !== null
                ? round((float) $agg->avg_time_spent_minutes, 2) : null,
            'total_resource_views' => (int) ($agg->total_resource_views ?? 0),
            'avg_resources_per_student' => $agg->avg_resources !== null
                ? round((float) $agg->avg_resources, 2) : null,
            'total_submissions' => (int) ($agg->total_submissions ?? 0),
            'total_quiz_attempts' => (int) ($agg->total_quiz_attempts ?? 0),
            'avg_assignment_score' => $agg->avg_assignment_score !== null
                ? round((float) $agg->avg_assignment_score, 2) : null,
            'avg_quiz_score' => $agg->avg_quiz_score !== null ? round((float) $agg->avg_quiz_score, 2) : null,
            'submission_rate' => $submissionrate,
            'avg_course_progress' => $agg->avg_course_progress !== null
                ? round((float) $agg->avg_course_progress, 2) : null,
            'completion_rate' => $completionrate,
            'high_engagement_count' => $distribution['high'],
            'medium_engagement_count' => $distribution['medium'],
            'low_engagement_count' => $distribution['low'],
            'at_risk_count' => $atrisk,
        ]);
    }

    /**
     * Compute engagement distribution using percentile-based thresholds.
     *
     * @param int[] $studentuserids Student user IDs.
     * @param int $courseid Course ID (0 for school-wide).
     * @param int $periodstart Period start timestamp.
     * @param string $periodtype Period type.
     * @return array{high: int, medium: int, low: int} Engagement counts.
     */
    private function compute_engagement_distribution(array $studentuserids, int $courseid,
            int $periodstart, string $periodtype): array {
        global $DB;

        list($insql, $params) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'uid');
        $params['pstart'] = $periodstart;
        $params['ptype'] = $periodtype;

        $courseclause = '';
        if ($courseid > 0) {
            $courseclause = 'AND um.courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        // Get total_actions for each student (summed across courses if school-wide).
        $actionscores = $DB->get_fieldset_sql(
            "SELECT SUM(um.total_actions) AS score
             FROM {elby_user_metrics} um
             WHERE um.userid {$insql}
               AND um.period_start = :pstart
               AND um.period_type = :ptype
               {$courseclause}
             GROUP BY um.userid
             ORDER BY score ASC",
            $params
        );

        $high = 0;
        $medium = 0;
        $low = 0;
        $count = count($actionscores);

        if ($count > 0) {
            // Compute percentile thresholds.
            $p30idx = max(0, (int) floor($count * 0.3) - 1);
            $p70idx = min($count - 1, (int) floor($count * 0.7));
            $p30 = (float) $actionscores[$p30idx];
            $p70 = (float) $actionscores[$p70idx];

            foreach ($actionscores as $score) {
                $score = (float) $score;
                if ($score > $p70) {
                    $high++;
                } else if ($score >= $p30) {
                    $medium++;
                } else {
                    $low++;
                }
            }
        }

        return ['high' => $high, 'medium' => $medium, 'low' => $low];
    }

    /**
     * Count at-risk students: enrolled but no logstore activity in 7 days.
     *
     * @param int[] $studentuserids Student user IDs.
     * @param int $courseid Course ID (0 for all courses).
     * @return int Number of at-risk students.
     */
    private function count_at_risk_students(array $studentuserids, int $courseid): int {
        global $DB;

        $threshold = time() - (7 * 86400);
        list($insql, $params) = $DB->get_in_or_equal($studentuserids, SQL_PARAMS_NAMED, 'uid');
        $params['threshold'] = $threshold;

        $courseclause = '';
        if ($courseid > 0) {
            $courseclause = 'AND l.courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        // Students with recent activity.
        $activeuserids = $DB->get_fieldset_sql(
            "SELECT DISTINCT l.userid
             FROM {logstore_standard_log} l
             WHERE l.userid {$insql}
               AND l.timecreated >= :threshold
               AND l.anonymous = 0
               {$courseclause}",
            $params
        );

        return count($studentuserids) - count($activeuserids);
    }

    /**
     * UPSERT a school metrics record.
     *
     * @param int $schoolid School ID.
     * @param int $courseid Course ID (0 for school-wide).
     * @param int $periodstart Period start.
     * @param int $periodend Period end.
     * @param string $periodtype Period type.
     * @param array $data Metrics data to write.
     */
    private function upsert_school_metrics(int $schoolid, int $courseid, int $periodstart,
            int $periodend, string $periodtype, array $data): void {
        global $DB;

        $existing = $DB->get_record('elby_school_metrics', [
            'schoolid' => $schoolid,
            'courseid' => $courseid,
            'period_start' => $periodstart,
            'period_type' => $periodtype,
        ]);

        $record = new \stdClass();
        $record->schoolid = $schoolid;
        $record->courseid = $courseid;
        $record->period_start = $periodstart;
        $record->period_end = $periodend;
        $record->period_type = $periodtype;

        foreach ($data as $key => $value) {
            $record->$key = $value;
        }

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('elby_school_metrics', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('elby_school_metrics', $record);
        }
    }
}
