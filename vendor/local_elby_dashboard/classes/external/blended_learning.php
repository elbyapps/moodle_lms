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
 * External API for blended learning metrics.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use context_system;
use context_course;
use core_course_category;

/**
 * External API for blended learning metrics.
 */
class blended_learning extends external_api {

    /**
     * Get the configured category ID and course IDs, or throw if not configured.
     *
     * @return array [category_id, category_name, courseids[]]
     */
    private static function get_category_courses(): array {
        $categoryid = (int) get_config('local_elby_dashboard', 'blended_learning_category');
        if (!$categoryid) {
            throw new \moodle_exception('blended_learning_category_not_set', 'local_elby_dashboard');
        }

        $category = core_course_category::get($categoryid, MUST_EXIST);
        $courses = $category->get_courses(['recursive' => true, 'idonly' => true]);
        $courseids = array_values(array_map('intval', $courses));

        return [$categoryid, $category->get_formatted_name(), $courseids];
    }

    /**
     * Resolve the time range from parameters.
     *
     * @param array $params Validated parameters with days_back, from_date, to_date.
     * @return array [starttime, endtime]
     */
    private static function resolve_time_range(array $params): array {
        if ($params['from_date'] > 0) {
            $starttime = $params['from_date'];
            $endtime = $params['to_date'] > 0 ? $params['to_date'] : time();
        } else {
            $daysback = $params['days_back'];
            if ($daysback <= 0) {
                // All time: go back 10 years.
                $starttime = time() - (3650 * 86400);
            } else {
                $daysback = min(365, $daysback);
                $starttime = time() - ($daysback * 86400);
            }
            $endtime = time();
        }
        return [$starttime, $endtime];
    }

    /**
     * Build IN clause for course IDs.
     *
     * @param array $courseids
     * @param string $prefix Parameter name prefix.
     * @return array [sql_fragment, params]
     */
    private static function get_course_in_sql(array $courseids, string $prefix = 'cid'): array {
        global $DB;
        return $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, $prefix);
    }

    /**
     * Count enrolled users with a specific role in the given courses.
     *
     * @param array $courseids
     * @param string $roletype 'student' or 'teacher'
     * @return int
     */
    private static function count_enrolled_by_role(array $courseids, string $roletype): int {
        global $DB;

        if (empty($courseids)) {
            return 0;
        }

        [$insql, $params] = self::get_course_in_sql($courseids, 'erc');

        if ($roletype === 'student') {
            $rolewhere = "AND r.shortname = 'student'";
        } else {
            $rolewhere = "AND r.shortname IN ('editingteacher', 'teacher')";
        }

        $sql = "SELECT COUNT(DISTINCT ue.userid)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid {$rolewhere}
                 WHERE e.courseid {$insql}
                   AND ue.status = 0";

        return (int) $DB->count_records_sql($sql, $params);
    }

    // =========================================================================
    // get_blended_learning_metrics — All 12 indicators.
    // =========================================================================

    public static function get_blended_learning_metrics_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back (0 = all time)', VALUE_DEFAULT, 30),
            'from_date' => new external_value(PARAM_INT, 'Start timestamp (overrides days_back when > 0)', VALUE_DEFAULT, 0),
            'to_date' => new external_value(PARAM_INT, 'End timestamp (defaults to now)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_blended_learning_metrics(
        int $daysback = 30,
        int $fromdate = 0,
        int $todate = 0
    ): array {
        global $DB;

        $params = self::validate_parameters(self::get_blended_learning_metrics_parameters(), [
            'days_back' => $daysback,
            'from_date' => $fromdate,
            'to_date' => $todate,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        [$categoryid, $categoryname, $courseids] = self::get_category_courses();
        [$starttime, $endtime] = self::resolve_time_range($params);

        $totalcourses = count($courseids);

        // Return zeroed metrics if no courses.
        if ($totalcourses === 0) {
            return self::zeroed_metrics($categoryid, $categoryname, $starttime, $endtime);
        }

        [$insql, $inparams] = self::get_course_in_sql($courseids);

        $totalstudents = self::count_enrolled_by_role($courseids, 'student');
        $totalteachers = self::count_enrolled_by_role($courseids, 'teacher');

        // Metric 1: Active Student Rate — students with ≥1 log entry in period.
        $sqlparams = array_merge($inparams, ['starttime' => $starttime, 'endtime' => $endtime]);
        $activestudents = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT log.userid)
               FROM {logstore_standard_log} log
               JOIN {enrol} e ON e.courseid = log.courseid
               JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = log.userid AND ue.status = 0
               JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = log.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
              WHERE log.courseid {$insql}
                AND log.timecreated >= :starttime AND log.timecreated <= :endtime
                AND log.userid > 0",
            $sqlparams
        );
        $activestudentrate = $totalstudents > 0 ? round($activestudents / $totalstudents * 100, 1) : 0;

        // Metric 2a: Activity Participation Rate.
        $sqlparams = array_merge($inparams, ['starttime2' => $starttime, 'endtime2' => $endtime]);
        $studentswithactivity = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cmc.userid)
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
               JOIN {enrol} e ON e.courseid = cm.course
               JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = cmc.userid AND ue.status = 0
               JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = cm.course
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = cmc.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
              WHERE cm.course {$insql}
                AND cmc.completionstate > 0
                AND cmc.timemodified >= :starttime2 AND cmc.timemodified <= :endtime2",
            $sqlparams
        );
        $activityparticipationrate = $totalstudents > 0
            ? round($studentswithactivity / $totalstudents * 100, 1) : 0;

        // Metric 2b: Average login frequency.
        $sqlparams = array_merge($inparams, ['starttime3' => $starttime, 'endtime3' => $endtime]);
        $totallogins = (int) $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {logstore_standard_log} log
              WHERE log.courseid {$insql}
                AND log.action = 'viewed' AND log.target = 'course'
                AND log.timecreated >= :starttime3 AND log.timecreated <= :endtime3
                AND log.userid > 0",
            $sqlparams
        );
        $avgloginfrequency = $activestudents > 0 ? round($totallogins / $activestudents, 1) : 0;

        // Metric 2c: Average learning time (approximate via log gaps, 30-min cap).
        // For performance, use a simpler heuristic: count log entries per active student × 2 min.
        $sqlparams = array_merge($inparams, ['starttime4' => $starttime, 'endtime4' => $endtime]);
        $totallogentries = (int) $DB->count_records_sql(
            "SELECT COUNT(*)
               FROM {logstore_standard_log} log
               JOIN {enrol} e ON e.courseid = log.courseid
               JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = log.userid AND ue.status = 0
               JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = log.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
              WHERE log.courseid {$insql}
                AND log.timecreated >= :starttime4 AND log.timecreated <= :endtime4
                AND log.userid > 0",
            $sqlparams
        );
        // Approximate: each log entry ~ 2 minutes of activity.
        $avglearningtimeminutes = $activestudents > 0
            ? round(($totallogentries * 2) / $activestudents, 1) : 0;

        // Metric 3: Digital Assessment Performance.
        [$insql3, $inparams3] = self::get_course_in_sql($courseids, 'qc');
        $sqlparams = array_merge($inparams3, ['starttime5' => $starttime, 'endtime5' => $endtime]);
        $avgquizscore = $DB->get_field_sql(
            "SELECT AVG(CASE WHEN q.sumgrades > 0 THEN (qa.sumgrades / q.sumgrades * 100) ELSE 0 END)
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course {$insql3}
                AND qa.state = 'finished'
                AND qa.timefinish >= :starttime5 AND qa.timefinish <= :endtime5",
            $sqlparams
        );
        $digitalassessmentperformance = $avgquizscore !== false ? round((float) $avgquizscore, 1) : 0;

        // Metric 4: Assignment Submission Rate.
        [$insql4, $inparams4] = self::get_course_in_sql($courseids, 'ac');
        $sqlparams = array_merge($inparams4, ['starttime6' => $starttime, 'endtime6' => $endtime]);
        $studentssubmitted = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT s.userid)
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment
              WHERE a.course {$insql4}
                AND s.status = 'submitted'
                AND s.timemodified >= :starttime6 AND s.timemodified <= :endtime6",
            $sqlparams
        );
        $assignmentsubmissionrate = $totalstudents > 0
            ? round($studentssubmitted / $totalstudents * 100, 1) : 0;

        // Metric 5: Quiz Participation Rate.
        [$insql5, $inparams5] = self::get_course_in_sql($courseids, 'qpc');
        $sqlparams = array_merge($inparams5, ['starttime7' => $starttime, 'endtime7' => $endtime]);
        $studentsquiz = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT qa.userid)
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course {$insql5}
                AND qa.timefinish >= :starttime7 AND qa.timefinish <= :endtime7",
            $sqlparams
        );
        $quizparticipationrate = $totalstudents > 0
            ? round($studentsquiz / $totalstudents * 100, 1) : 0;

        // Metric 6: Course Completion Rate.
        [$insql6, $inparams6] = self::get_course_in_sql($courseids, 'cc');
        $studentscompleted = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cc.userid)
               FROM {course_completions} cc
              WHERE cc.course {$insql6}
                AND cc.timecompleted IS NOT NULL",
            $inparams6
        );
        $coursecompletionrate = $totalstudents > 0
            ? round($studentscompleted / $totalstudents * 100, 1) : 0;

        // Metric 7: Forum Interaction Rate.
        [$insql7, $inparams7] = self::get_course_in_sql($courseids, 'fc');
        $sqlparams = array_merge($inparams7, ['starttime8' => $starttime, 'endtime8' => $endtime]);
        $studentsforum = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT fp.userid)
               FROM {forum_posts} fp
               JOIN {forum_discussions} fd ON fd.id = fp.discussion
               JOIN {forum} f ON f.id = fd.forum
              WHERE f.course {$insql7}
                AND fp.created >= :starttime8 AND fp.created <= :endtime8",
            $sqlparams
        );
        $foruminteractionrate = $totalstudents > 0
            ? round($studentsforum / $totalstudents * 100, 1) : 0;

        // Metric 8: Teacher LMS Usage Rate (teachers creating/updating content).
        [$insql8, $inparams8] = self::get_course_in_sql($courseids, 'tc');
        $sqlparams = array_merge($inparams8, ['starttime9' => $starttime, 'endtime9' => $endtime]);
        $teachersuploading = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT log.userid)
               FROM {logstore_standard_log} log
               JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = log.courseid
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = log.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
              WHERE log.courseid {$insql8}
                AND log.timecreated >= :starttime9 AND log.timecreated <= :endtime9
                AND (log.action = 'created' OR log.action = 'updated')
                AND log.target IN ('course_module', 'course_content', 'course_section')",
            $sqlparams
        );
        $teacherlmsusagerate = $totalteachers > 0
            ? round($teachersuploading / $totalteachers * 100, 1) : 0;

        // Metric 9: Teachers with active courses.
        [$insql9, $inparams9] = self::get_course_in_sql($courseids, 'tac');
        $sqlparams = array_merge($inparams9, ['starttime10' => $starttime, 'endtime10' => $endtime]);
        $teacherswithactivecourses = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT log.userid)
               FROM {logstore_standard_log} log
               JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = log.courseid
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = log.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
              WHERE log.courseid {$insql9}
                AND log.timecreated >= :starttime10 AND log.timecreated <= :endtime10
                AND log.userid > 0",
            $sqlparams
        );

        // Metric 10: Teacher LMS Activity Rate (any activity, broader than metric 8).
        $teacherswithactivity = $teacherswithactivecourses; // Same query — any log activity.
        $teacherlmsactivityrate = $totalteachers > 0
            ? round($teacherswithactivity / $totalteachers * 100, 1) : 0;

        // Metric 11: Digital Course Availability.
        [$insql11, $inparams11] = self::get_course_in_sql($courseids, 'dc');
        $courseswithmaterials = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT cm.course)
               FROM {course_modules} cm
              WHERE cm.course {$insql11}
                AND cm.deletioninprogress = 0",
            $inparams11
        );
        $digitalcourseavailability = $totalcourses > 0
            ? round($courseswithmaterials / $totalcourses * 100, 1) : 0;

        return [
            'category_id' => $categoryid,
            'category_name' => $categoryname,
            'total_courses' => $totalcourses,
            'total_enrolled_students' => $totalstudents,
            'total_teachers' => $totalteachers,
            'active_student_rate' => $activestudentrate,
            'active_students' => $activestudents,
            'activity_participation_rate' => $activityparticipationrate,
            'students_with_activity' => $studentswithactivity,
            'avg_login_frequency' => $avgloginfrequency,
            'avg_learning_time_minutes' => $avglearningtimeminutes,
            'digital_assessment_performance' => $digitalassessmentperformance,
            'assignment_submission_rate' => $assignmentsubmissionrate,
            'students_submitted_assignments' => $studentssubmitted,
            'quiz_participation_rate' => $quizparticipationrate,
            'students_attempted_quizzes' => $studentsquiz,
            'course_completion_rate' => $coursecompletionrate,
            'students_completed_courses' => $studentscompleted,
            'forum_interaction_rate' => $foruminteractionrate,
            'students_with_forum_posts' => $studentsforum,
            'teacher_lms_usage_rate' => $teacherlmsusagerate,
            'teachers_uploading_content' => $teachersuploading,
            'teachers_with_active_courses' => $teacherswithactivecourses,
            'teacher_lms_activity_rate' => $teacherlmsactivityrate,
            'teachers_with_activity' => $teacherswithactivity,
            'digital_course_availability' => $digitalcourseavailability,
            'courses_with_materials' => $courseswithmaterials,
            'period_start' => $starttime,
            'period_end' => $endtime,
        ];
    }

    public static function get_blended_learning_metrics_returns(): external_single_structure {
        return new external_single_structure([
            'category_id' => new external_value(PARAM_INT, 'Configured parent category ID'),
            'category_name' => new external_value(PARAM_TEXT, 'Category name'),
            'total_courses' => new external_value(PARAM_INT, 'Total courses under category'),
            'total_enrolled_students' => new external_value(PARAM_INT, 'Total enrolled students'),
            'total_teachers' => new external_value(PARAM_INT, 'Total teachers'),
            'active_student_rate' => new external_value(PARAM_FLOAT, 'Active Student Rate %'),
            'active_students' => new external_value(PARAM_INT, 'Students who logged in'),
            'activity_participation_rate' => new external_value(PARAM_FLOAT, 'Activity Participation Rate %'),
            'students_with_activity' => new external_value(PARAM_INT, 'Students with ≥1 completed activity'),
            'avg_login_frequency' => new external_value(PARAM_FLOAT, 'Average login frequency per active student'),
            'avg_learning_time_minutes' => new external_value(PARAM_FLOAT, 'Average learning time in minutes'),
            'digital_assessment_performance' => new external_value(PARAM_FLOAT, 'Avg quiz score %'),
            'assignment_submission_rate' => new external_value(PARAM_FLOAT, 'Assignment Submission Rate %'),
            'students_submitted_assignments' => new external_value(PARAM_INT, 'Students who submitted assignments'),
            'quiz_participation_rate' => new external_value(PARAM_FLOAT, 'Quiz Participation Rate %'),
            'students_attempted_quizzes' => new external_value(PARAM_INT, 'Students who attempted quizzes'),
            'course_completion_rate' => new external_value(PARAM_FLOAT, 'Course Completion Rate %'),
            'students_completed_courses' => new external_value(PARAM_INT, 'Students who completed courses'),
            'forum_interaction_rate' => new external_value(PARAM_FLOAT, 'Forum Interaction Rate %'),
            'students_with_forum_posts' => new external_value(PARAM_INT, 'Students with forum posts'),
            'teacher_lms_usage_rate' => new external_value(PARAM_FLOAT, 'Teacher LMS Usage Rate %'),
            'teachers_uploading_content' => new external_value(PARAM_INT, 'Teachers uploading content'),
            'teachers_with_active_courses' => new external_value(PARAM_INT, 'Teachers with active courses'),
            'teacher_lms_activity_rate' => new external_value(PARAM_FLOAT, 'Teacher LMS Activity Rate %'),
            'teachers_with_activity' => new external_value(PARAM_INT, 'Teachers with any activity'),
            'digital_course_availability' => new external_value(PARAM_FLOAT, 'Digital Course Availability %'),
            'courses_with_materials' => new external_value(PARAM_INT, 'Courses with materials'),
            'period_start' => new external_value(PARAM_INT, 'Period start timestamp'),
            'period_end' => new external_value(PARAM_INT, 'Period end timestamp'),
        ]);
    }

    /**
     * Return zeroed-out metrics structure.
     */
    private static function zeroed_metrics(int $categoryid, string $categoryname,
            int $starttime, int $endtime): array {
        return [
            'category_id' => $categoryid,
            'category_name' => $categoryname,
            'total_courses' => 0,
            'total_enrolled_students' => 0,
            'total_teachers' => 0,
            'active_student_rate' => 0,
            'active_students' => 0,
            'activity_participation_rate' => 0,
            'students_with_activity' => 0,
            'avg_login_frequency' => 0,
            'avg_learning_time_minutes' => 0,
            'digital_assessment_performance' => 0,
            'assignment_submission_rate' => 0,
            'students_submitted_assignments' => 0,
            'quiz_participation_rate' => 0,
            'students_attempted_quizzes' => 0,
            'course_completion_rate' => 0,
            'students_completed_courses' => 0,
            'forum_interaction_rate' => 0,
            'students_with_forum_posts' => 0,
            'teacher_lms_usage_rate' => 0,
            'teachers_uploading_content' => 0,
            'teachers_with_active_courses' => 0,
            'teacher_lms_activity_rate' => 0,
            'teachers_with_activity' => 0,
            'digital_course_availability' => 0,
            'courses_with_materials' => 0,
            'period_start' => $starttime,
            'period_end' => $endtime,
        ];
    }

    // =========================================================================
    // get_blended_learning_schools — Schools in the blended program.
    // =========================================================================

    public static function get_blended_learning_schools_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back (0 = all time)', VALUE_DEFAULT, 30),
            'from_date' => new external_value(PARAM_INT, 'Start timestamp', VALUE_DEFAULT, 0),
            'to_date' => new external_value(PARAM_INT, 'End timestamp', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_blended_learning_schools(
        int $daysback = 30,
        int $fromdate = 0,
        int $todate = 0
    ): array {
        global $DB;

        $params = self::validate_parameters(self::get_blended_learning_schools_parameters(), [
            'days_back' => $daysback, 'from_date' => $fromdate, 'to_date' => $todate,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        [, , $courseids] = self::get_category_courses();
        [$starttime, $endtime] = self::resolve_time_range($params);

        if (empty($courseids)) {
            return ['schools' => []];
        }

        [$insql, $inparams] = self::get_course_in_sql($courseids, 'sc');

        // Get schools with student/teacher counts enrolled in blended courses.
        // Uses Moodle role assignments as source of truth, LEFT JOINs SDMS for school identification.
        $sqlparams = $inparams;
        $sql = "SELECT COALESCE(sch.school_code, '') AS school_code,
                       COALESCE(sch.school_name, 'Unlinked') AS school_name,
                       SUM(CASE WHEN r.shortname = 'student' THEN 1 ELSE 0 END) AS student_count,
                       SUM(CASE WHEN r.shortname IN ('editingteacher', 'teacher') THEN 1 ELSE 0 END) AS teacher_count
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('student', 'editingteacher', 'teacher')
                  LEFT JOIN {elby_sdms_users} su ON su.userid = ue.userid
                  LEFT JOIN {elby_schools} sch ON sch.id = su.schoolid
                 WHERE e.courseid {$insql}
                   AND ue.status = 0
              GROUP BY sch.school_code, sch.school_name
              ORDER BY student_count DESC";

        $schoolrows = $DB->get_records_sql($sql, $sqlparams);

        // For each school, compute completion rate and active rate.
        $schools = [];
        foreach ($schoolrows as $row) {
            $schoolcode = $row->school_code;
            $studentcount = (int) $row->student_count;

            // Build school filter condition: match by school_code, or match unlinked users (no SDMS link).
            $isunlinked = ($schoolcode === '');

            // Completion rate for this school's students.
            [$insql2, $inparams2] = self::get_course_in_sql($courseids, 'scc');
            if ($isunlinked) {
                $schoolwhere2 = "AND su2.userid IS NULL";
                $sqlparams2 = $inparams2;
            } else {
                $schoolwhere2 = "AND sch2.school_code = :scode";
                $sqlparams2 = array_merge($inparams2, ['scode' => $schoolcode]);
            }
            $completed = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT cc.userid)
                   FROM {course_completions} cc
                   JOIN {enrol} e2 ON e2.courseid = cc.course
                   JOIN {user_enrolments} ue2 ON ue2.enrolid = e2.id AND ue2.userid = cc.userid AND ue2.status = 0
                   JOIN {context} ctx2 ON ctx2.contextlevel = 50 AND ctx2.instanceid = e2.courseid
                   JOIN {role_assignments} ra2 ON ra2.contextid = ctx2.id AND ra2.userid = cc.userid
                   JOIN {role} r2 ON r2.id = ra2.roleid AND r2.shortname = 'student'
                   LEFT JOIN {elby_sdms_users} su2 ON su2.userid = cc.userid
                   LEFT JOIN {elby_schools} sch2 ON sch2.id = su2.schoolid
                  WHERE cc.course {$insql2}
                    AND cc.timecompleted IS NOT NULL
                    {$schoolwhere2}",
                $sqlparams2
            );
            $avgcompletionrate = $studentcount > 0 ? round($completed / $studentcount * 100, 1) : 0;

            // Active rate for this school's students.
            [$insql3, $inparams3] = self::get_course_in_sql($courseids, 'sac');
            if ($isunlinked) {
                $schoolwhere3 = "AND su3.userid IS NULL";
                $sqlparams3 = array_merge($inparams3, ['sstart' => $starttime, 'send' => $endtime]);
            } else {
                $schoolwhere3 = "AND sch3.school_code = :scode2";
                $sqlparams3 = array_merge($inparams3, [
                    'scode2' => $schoolcode, 'sstart' => $starttime, 'send' => $endtime,
                ]);
            }
            $active = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT log.userid)
                   FROM {logstore_standard_log} log
                   JOIN {enrol} e3 ON e3.courseid = log.courseid
                   JOIN {user_enrolments} ue3 ON ue3.enrolid = e3.id AND ue3.userid = log.userid AND ue3.status = 0
                   JOIN {context} ctx3 ON ctx3.contextlevel = 50 AND ctx3.instanceid = e3.courseid
                   JOIN {role_assignments} ra3 ON ra3.contextid = ctx3.id AND ra3.userid = log.userid
                   JOIN {role} r3 ON r3.id = ra3.roleid AND r3.shortname = 'student'
                   LEFT JOIN {elby_sdms_users} su3 ON su3.userid = log.userid
                   LEFT JOIN {elby_schools} sch3 ON sch3.id = su3.schoolid
                  WHERE log.courseid {$insql3}
                    AND log.timecreated >= :sstart AND log.timecreated <= :send
                    AND log.userid > 0
                    {$schoolwhere3}",
                $sqlparams3
            );
            $activestudentrate = $studentcount > 0 ? round($active / $studentcount * 100, 1) : 0;

            $schools[] = [
                'school_code' => $schoolcode,
                'school_name' => $row->school_name,
                'student_count' => $studentcount,
                'teacher_count' => (int) $row->teacher_count,
                'avg_completion_rate' => $avgcompletionrate,
                'active_student_rate' => $activestudentrate,
            ];
        }

        return ['schools' => $schools];
    }

    public static function get_blended_learning_schools_returns(): external_single_structure {
        return new external_single_structure([
            'schools' => new external_multiple_structure(
                new external_single_structure([
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'student_count' => new external_value(PARAM_INT, 'Number of students'),
                    'teacher_count' => new external_value(PARAM_INT, 'Number of teachers'),
                    'avg_completion_rate' => new external_value(PARAM_FLOAT, 'Average completion rate %'),
                    'active_student_rate' => new external_value(PARAM_FLOAT, 'Active student rate %'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // get_blended_learning_students — Students in blended courses.
    // =========================================================================

    public static function get_blended_learning_students_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back (0 = all time)', VALUE_DEFAULT, 30),
            'from_date' => new external_value(PARAM_INT, 'Start timestamp', VALUE_DEFAULT, 0),
            'to_date' => new external_value(PARAM_INT, 'End timestamp', VALUE_DEFAULT, 0),
            'school_code' => new external_value(PARAM_TEXT, 'Filter by school code', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Page number (0-indexed)', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Results per page', VALUE_DEFAULT, 50),
        ]);
    }

    public static function get_blended_learning_students(
        int $daysback = 30,
        int $fromdate = 0,
        int $todate = 0,
        string $schoolcode = '',
        int $page = 0,
        int $perpage = 50
    ): array {
        global $DB;

        $params = self::validate_parameters(self::get_blended_learning_students_parameters(), [
            'days_back' => $daysback, 'from_date' => $fromdate, 'to_date' => $todate,
            'school_code' => $schoolcode, 'page' => $page, 'perpage' => $perpage,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        [, , $courseids] = self::get_category_courses();

        if (empty($courseids)) {
            return ['students' => [], 'total_count' => 0, 'page' => 0, 'perpage' => $params['perpage']];
        }

        [$insql, $inparams] = self::get_course_in_sql($courseids, 'stc');

        $schooljoin = '';
        $schoolwhere = '';
        $sqlparams = $inparams;
        if (!empty($params['school_code'])) {
            $schoolwhere = " AND sch.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        // Get students enrolled in blended courses with their school info.
        $sql = "SELECT DISTINCT u.id AS userid, u.firstname, u.lastname, u.lastaccess,
                       COALESCE(sch.school_name, '') AS school_name,
                       COALESCE(sch.school_code, '') AS school_code,
                       COUNT(DISTINCT e.courseid) AS courses_enrolled
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                  JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                  LEFT JOIN {elby_sdms_users} su ON su.userid = u.id
                  LEFT JOIN {elby_schools} sch ON sch.id = su.schoolid
                 WHERE e.courseid {$insql}
                   AND ue.status = 0
                   AND u.deleted = 0{$schoolwhere}
              GROUP BY u.id, u.firstname, u.lastname, u.lastaccess, sch.school_name, sch.school_code
              ORDER BY u.lastname, u.firstname";

        // Count total.
        $countsql = "SELECT COUNT(DISTINCT ue.userid)
                       FROM {user_enrolments} ue
                       JOIN {enrol} e ON e.id = ue.enrolid
                       JOIN {user} u ON u.id = ue.userid
                       JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                       JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                       JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                       LEFT JOIN {elby_sdms_users} su ON su.userid = u.id
                       LEFT JOIN {elby_schools} sch ON sch.id = su.schoolid
                      WHERE e.courseid {$insql}
                        AND ue.status = 0
                        AND u.deleted = 0{$schoolwhere}";
        $totalcount = (int) $DB->count_records_sql($countsql, $sqlparams);

        $perpage = min(100, max(1, $params['perpage']));
        $offset = max(0, $params['page']) * $perpage;
        $records = $DB->get_records_sql($sql, $sqlparams, $offset, $perpage);

        // Get completion percentages for each student.
        $students = [];
        $totalblendedcourses = count($courseids);
        foreach ($records as $rec) {
            // Count completed blended courses for this student.
            [$insqlc, $inparamsc] = self::get_course_in_sql($courseids, 'cps');
            $inparamsc['uid'] = (int) $rec->userid;
            $completedcourses = (int) $DB->count_records_sql(
                "SELECT COUNT(*)
                   FROM {course_completions} cc
                  WHERE cc.course {$insqlc}
                    AND cc.userid = :uid
                    AND cc.timecompleted IS NOT NULL",
                $inparamsc
            );
            $enrolledcourses = (int) $rec->courses_enrolled;
            $completionpct = $enrolledcourses > 0
                ? round($completedcourses / $enrolledcourses * 100, 1) : 0;

            $students[] = [
                'userid' => (int) $rec->userid,
                'fullname' => $rec->firstname . ' ' . $rec->lastname,
                'school_name' => $rec->school_name,
                'school_code' => $rec->school_code,
                'courses_enrolled' => $enrolledcourses,
                'completion_pct' => $completionpct,
                'last_access' => (int) $rec->lastaccess,
            ];
        }

        return [
            'students' => $students,
            'total_count' => $totalcount,
            'page' => $params['page'],
            'perpage' => $perpage,
        ];
    }

    public static function get_blended_learning_students_returns(): external_single_structure {
        return new external_single_structure([
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'courses_enrolled' => new external_value(PARAM_INT, 'Courses enrolled'),
                    'completion_pct' => new external_value(PARAM_FLOAT, 'Completion percentage'),
                    'last_access' => new external_value(PARAM_INT, 'Last access timestamp'),
                ])
            ),
            'total_count' => new external_value(PARAM_INT, 'Total matching students'),
            'page' => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Results per page'),
        ]);
    }

    // =========================================================================
    // get_blended_learning_teachers — Teachers in blended courses.
    // =========================================================================

    public static function get_blended_learning_teachers_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back (0 = all time)', VALUE_DEFAULT, 30),
            'from_date' => new external_value(PARAM_INT, 'Start timestamp', VALUE_DEFAULT, 0),
            'to_date' => new external_value(PARAM_INT, 'End timestamp', VALUE_DEFAULT, 0),
            'school_code' => new external_value(PARAM_TEXT, 'Filter by school code', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Page number (0-indexed)', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Results per page', VALUE_DEFAULT, 50),
        ]);
    }

    public static function get_blended_learning_teachers(
        int $daysback = 30,
        int $fromdate = 0,
        int $todate = 0,
        string $schoolcode = '',
        int $page = 0,
        int $perpage = 50
    ): array {
        global $DB;

        $params = self::validate_parameters(self::get_blended_learning_teachers_parameters(), [
            'days_back' => $daysback, 'from_date' => $fromdate, 'to_date' => $todate,
            'school_code' => $schoolcode, 'page' => $page, 'perpage' => $perpage,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        [, , $courseids] = self::get_category_courses();
        [$starttime, $endtime] = self::resolve_time_range($params);

        if (empty($courseids)) {
            return ['teachers' => [], 'total_count' => 0, 'page' => 0, 'perpage' => $params['perpage']];
        }

        [$insql, $inparams] = self::get_course_in_sql($courseids, 'ttc');

        $schoolwhere = '';
        $sqlparams = $inparams;
        if (!empty($params['school_code'])) {
            $schoolwhere = " AND sch.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        $sql = "SELECT DISTINCT u.id AS userid, u.firstname, u.lastname,
                       COALESCE(sch.school_name, '') AS school_name,
                       COALESCE(sch.school_code, '') AS school_code,
                       COUNT(DISTINCT e.courseid) AS courses_teaching
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                  JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                  JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
                  LEFT JOIN {elby_sdms_users} su ON su.userid = u.id
                  LEFT JOIN {elby_schools} sch ON sch.id = su.schoolid
                 WHERE e.courseid {$insql}
                   AND ue.status = 0
                   AND u.deleted = 0{$schoolwhere}
              GROUP BY u.id, u.firstname, u.lastname, sch.school_name, sch.school_code
              ORDER BY u.lastname, u.firstname";

        $countsql = "SELECT COUNT(DISTINCT ue.userid)
                       FROM {user_enrolments} ue
                       JOIN {enrol} e ON e.id = ue.enrolid
                       JOIN {user} u ON u.id = ue.userid
                       JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                       JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                       JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
                       LEFT JOIN {elby_sdms_users} su ON su.userid = u.id
                       LEFT JOIN {elby_schools} sch ON sch.id = su.schoolid
                      WHERE e.courseid {$insql}
                        AND ue.status = 0
                        AND u.deleted = 0{$schoolwhere}";
        $totalcount = (int) $DB->count_records_sql($countsql, $sqlparams);

        $perpage = min(100, max(1, $params['perpage']));
        $offset = max(0, $params['page']) * $perpage;
        $records = $DB->get_records_sql($sql, $sqlparams, $offset, $perpage);

        // Compute activity rate for each teacher.
        $perioddays = max(1, ($endtime - $starttime) / 86400);
        $teachers = [];
        foreach ($records as $rec) {
            [$insqlt, $inparamst] = self::get_course_in_sql($courseids, 'tar');
            $dbfamily = $DB->get_dbfamily();
            $dateexpr = ($dbfamily === 'postgres')
                ? "DATE(TO_TIMESTAMP(log.timecreated))"
                : "DATE(FROM_UNIXTIME(log.timecreated))";
            $inparamst['tuid'] = (int) $rec->userid;
            $inparamst['tstart'] = $starttime;
            $inparamst['tend'] = $endtime;
            $activedays = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT {$dateexpr})
                   FROM {logstore_standard_log} log
                  WHERE log.courseid {$insqlt}
                    AND log.userid = :tuid
                    AND log.timecreated >= :tstart AND log.timecreated <= :tend",
                $inparamst
            );
            $activityrate = round($activedays / $perioddays * 100, 1);

            $teachers[] = [
                'userid' => (int) $rec->userid,
                'fullname' => $rec->firstname . ' ' . $rec->lastname,
                'school_name' => $rec->school_name,
                'school_code' => $rec->school_code,
                'courses_teaching' => (int) $rec->courses_teaching,
                'activity_rate' => min(100, $activityrate),
            ];
        }

        return [
            'teachers' => $teachers,
            'total_count' => $totalcount,
            'page' => $params['page'],
            'perpage' => $perpage,
        ];
    }

    public static function get_blended_learning_teachers_returns(): external_single_structure {
        return new external_single_structure([
            'teachers' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'courses_teaching' => new external_value(PARAM_INT, 'Courses teaching'),
                    'activity_rate' => new external_value(PARAM_FLOAT, 'Activity rate %'),
                ])
            ),
            'total_count' => new external_value(PARAM_INT, 'Total matching teachers'),
            'page' => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Results per page'),
        ]);
    }
}
