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
 * External API for metrics data.
 *
 * Provides web service endpoints for querying computed metrics.
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

/**
 * External API for metrics data.
 */
class metrics extends external_api {

    // =========================================================================
    // get_school_metrics — Full school metrics record for a period.
    // =========================================================================

    /**
     * Parameters for get_school_metrics.
     */
    public static function get_school_metrics_parameters(): external_function_parameters {
        return new external_function_parameters([
            'school_code' => new external_value(PARAM_TEXT, 'SDMS school code'),
            'courseid' => new external_value(PARAM_INT, 'Course ID (0 for school-wide)', VALUE_DEFAULT, 0),
            'period_type' => new external_value(PARAM_TEXT, 'Period type: weekly or monthly', VALUE_DEFAULT, 'weekly'),
        ]);
    }

    /**
     * Get school metrics for the most recent matching period.
     *
     * @param string $schoolcode SDMS school code.
     * @param int $courseid Course ID (0 for school-wide).
     * @param string $periodtype Period type.
     * @return array School metrics data.
     */
    public static function get_school_metrics(string $schoolcode, int $courseid = 0,
            string $periodtype = 'weekly'): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_school_metrics_parameters(),
            ['school_code' => $schoolcode, 'courseid' => $courseid, 'period_type' => $periodtype]
        );
        $schoolcode = $params['school_code'];
        $courseid = $params['courseid'];
        $periodtype = $params['period_type'];

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        // Resolve school.
        $school = $DB->get_record('elby_schools', ['school_code' => $schoolcode], 'id, school_name, school_code');
        if (!$school) {
            return [
                'success' => false,
                'error' => 'School not found',
                'school_code' => $schoolcode,
                'school_name' => '',
            ];
        }

        // Get the most recent metrics record.
        $metrics = $DB->get_records_sql(
            "SELECT *
             FROM {elby_school_metrics}
             WHERE schoolid = :schoolid
               AND courseid = :courseid
               AND period_type = :periodtype
             ORDER BY period_start DESC
             LIMIT 1",
            [
                'schoolid' => $school->id,
                'courseid' => $courseid,
                'periodtype' => $periodtype,
            ]
        );

        $metrics = reset($metrics);

        if (!$metrics) {
            return [
                'success' => true,
                'error' => '',
                'school_code' => $school->school_code,
                'school_name' => $school->school_name,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'school_code' => $school->school_code,
            'school_name' => $school->school_name,
            'metrics' => [
                'period_start' => (int) $metrics->period_start,
                'period_end' => (int) $metrics->period_end,
                'period_type' => $metrics->period_type,
                'total_enrolled' => (int) $metrics->total_enrolled,
                'total_active' => (int) $metrics->total_active,
                'total_inactive' => (int) $metrics->total_inactive,
                'new_enrollments' => (int) $metrics->new_enrollments,
                'avg_actions_per_student' => (float) ($metrics->avg_actions_per_student ?? 0),
                'avg_active_days' => (float) ($metrics->avg_active_days ?? 0),
                'avg_time_spent_minutes' => (float) ($metrics->avg_time_spent_minutes ?? 0),
                'total_resource_views' => (int) $metrics->total_resource_views,
                'avg_resources_per_student' => (float) ($metrics->avg_resources_per_student ?? 0),
                'total_submissions' => (int) $metrics->total_submissions,
                'total_quiz_attempts' => (int) $metrics->total_quiz_attempts,
                'avg_assignment_score' => (float) ($metrics->avg_assignment_score ?? 0),
                'avg_quiz_score' => (float) ($metrics->avg_quiz_score ?? 0),
                'submission_rate' => (float) ($metrics->submission_rate ?? 0),
                'avg_course_progress' => (float) ($metrics->avg_course_progress ?? 0),
                'completion_rate' => (float) ($metrics->completion_rate ?? 0),
                'high_engagement_count' => (int) $metrics->high_engagement_count,
                'medium_engagement_count' => (int) $metrics->medium_engagement_count,
                'low_engagement_count' => (int) $metrics->low_engagement_count,
                'at_risk_count' => (int) $metrics->at_risk_count,
            ],
        ];
    }

    /**
     * Return structure for get_school_metrics.
     */
    public static function get_school_metrics_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether data was found'),
            'error' => new external_value(PARAM_TEXT, 'Error message'),
            'school_code' => new external_value(PARAM_TEXT, 'School code'),
            'school_name' => new external_value(PARAM_TEXT, 'School name'),
            'metrics' => new external_single_structure([
                'period_start' => new external_value(PARAM_INT, 'Period start timestamp'),
                'period_end' => new external_value(PARAM_INT, 'Period end timestamp'),
                'period_type' => new external_value(PARAM_TEXT, 'Period type'),
                'total_enrolled' => new external_value(PARAM_INT, 'Total enrolled students'),
                'total_active' => new external_value(PARAM_INT, 'Total active students'),
                'total_inactive' => new external_value(PARAM_INT, 'Total inactive students'),
                'new_enrollments' => new external_value(PARAM_INT, 'New enrollments'),
                'avg_actions_per_student' => new external_value(PARAM_FLOAT, 'Average actions per student'),
                'avg_active_days' => new external_value(PARAM_FLOAT, 'Average active days'),
                'avg_time_spent_minutes' => new external_value(PARAM_FLOAT, 'Average time spent in minutes'),
                'total_resource_views' => new external_value(PARAM_INT, 'Total resource views'),
                'avg_resources_per_student' => new external_value(PARAM_FLOAT, 'Average resources per student'),
                'total_submissions' => new external_value(PARAM_INT, 'Total submissions'),
                'total_quiz_attempts' => new external_value(PARAM_INT, 'Total quiz attempts'),
                'avg_assignment_score' => new external_value(PARAM_FLOAT, 'Average assignment score'),
                'avg_quiz_score' => new external_value(PARAM_FLOAT, 'Average quiz score'),
                'submission_rate' => new external_value(PARAM_FLOAT, 'Submission rate'),
                'avg_course_progress' => new external_value(PARAM_FLOAT, 'Average course progress'),
                'completion_rate' => new external_value(PARAM_FLOAT, 'Completion rate'),
                'high_engagement_count' => new external_value(PARAM_INT, 'High engagement count'),
                'medium_engagement_count' => new external_value(PARAM_INT, 'Medium engagement count'),
                'low_engagement_count' => new external_value(PARAM_INT, 'Low engagement count'),
                'at_risk_count' => new external_value(PARAM_INT, 'At-risk count'),
            ], 'Metrics data', VALUE_OPTIONAL),
        ]);
    }

    // =========================================================================
    // get_student_list — Paginated student metrics list.
    // =========================================================================

    /**
     * Parameters for get_student_list.
     */
    public static function get_student_list_parameters(): external_function_parameters {
        return new external_function_parameters([
            'school_code' => new external_value(PARAM_TEXT, 'SDMS school code (empty for all)', VALUE_DEFAULT, ''),
            'courseid' => new external_value(PARAM_INT, 'Course ID (0 for all courses)', VALUE_DEFAULT, 0),
            'sort' => new external_value(PARAM_TEXT, 'Sort field', VALUE_DEFAULT, 'lastname'),
            'order' => new external_value(PARAM_TEXT, 'Sort order: ASC or DESC', VALUE_DEFAULT, 'ASC'),
            'page' => new external_value(PARAM_INT, 'Page number (0-based)', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Results per page', VALUE_DEFAULT, 25),
            'search' => new external_value(PARAM_TEXT, 'Search query', VALUE_DEFAULT, ''),
            'engagement_level' => new external_value(PARAM_TEXT, 'Filter: high, medium, low, at_risk, or empty',
                VALUE_DEFAULT, ''),
            'user_type' => new external_value(PARAM_TEXT, 'Filter: student, teacher, or empty for students only',
                VALUE_DEFAULT, ''),
            'program_code' => new external_value(PARAM_TEXT, 'Filter by program/trade code', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get paginated student list with metrics.
     *
     * @param string $schoolcode School code filter.
     * @param int $courseid Course filter.
     * @param string $sort Sort field.
     * @param string $order Sort order.
     * @param int $page Page number.
     * @param int $perpage Per page count.
     * @param string $search Search query.
     * @param string $engagementlevel Engagement level filter.
     * @param string $usertype User type filter: student, teacher, or empty.
     * @param string $programcode Program code filter.
     * @return array Paginated student list.
     */
    public static function get_student_list(string $schoolcode = '', int $courseid = 0,
            string $sort = 'lastname', string $order = 'ASC', int $page = 0, int $perpage = 25,
            string $search = '', string $engagementlevel = '', string $usertype = '',
            string $programcode = ''): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_student_list_parameters(),
            [
                'school_code' => $schoolcode,
                'courseid' => $courseid,
                'sort' => $sort,
                'order' => $order,
                'page' => $page,
                'perpage' => $perpage,
                'search' => $search,
                'engagement_level' => $engagementlevel,
                'user_type' => $usertype,
                'program_code' => $programcode,
            ]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        // Sanitize sort parameters.
        $allowedsorts = ['lastname', 'firstname', 'active_days', 'total_actions',
            'quizzes_avg_score', 'course_progress', 'last_access', 'enrolled_courses'];
        $sort = in_array($params['sort'], $allowedsorts) ? $params['sort'] : 'lastname';
        $order = strtoupper($params['order']) === 'DESC' ? 'DESC' : 'ASC';
        $page = max(0, $params['page']);
        $perpage = min(100, max(1, $params['perpage']));
        $isteacher = ($params['user_type'] === 'teacher');

        // 30-day window for log aggregates.
        $logstart = time() - (30 * 86400);

        // Build query — join elby_teachers or elby_students based on user_type.
        $suusertype = $isteacher ? 'teacher' : 'student';

        // Subquery 1: Log aggregates (active_days + total_actions) from last 30 days.
        $logwhere = "l.timecreated >= :logstart AND l.anonymous = 0 AND l.userid > 0";
        if ($params['courseid'] > 0) {
            $logwhere .= " AND l.courseid = :log_courseid";
        }
        $logsub = "(SELECT l.userid,
                           COUNT(DISTINCT FLOOR(l.timecreated / 86400)) AS active_days,
                           COUNT(*) AS total_actions
                    FROM {logstore_standard_log} l
                    WHERE {$logwhere}
                    GROUP BY l.userid)";

        // Subquery 2: Quiz average score (all-time).
        $quizwhere = "q.grade > 0";
        if ($params['courseid'] > 0) {
            $quizwhere .= " AND q.course = :quiz_courseid";
        }
        $quizsub = "(SELECT qg.userid,
                            AVG(qg.grade / q.grade * 100) AS quizzes_avg_score
                     FROM {quiz_grades} qg
                     JOIN {quiz} q ON q.id = qg.quiz
                     WHERE {$quizwhere}
                     GROUP BY qg.userid)";

        // Subquery 3: Course progress (only when a specific course is selected).
        $hascoursefilter = ($params['courseid'] > 0);
        $progresssub = "";
        if ($hascoursefilter) {
            $progresssub = " LEFT JOIN (
                SELECT ue2.userid,
                       CASE WHEN COUNT(cm2.id) = 0 THEN NULL
                            ELSE SUM(CASE WHEN cmc2.completionstate >= 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(cm2.id)
                       END AS course_progress
                FROM {user_enrolments} ue2
                JOIN {enrol} e2 ON e2.id = ue2.enrolid
                JOIN {course_modules} cm2 ON cm2.course = e2.courseid AND cm2.completion > 0
                    AND cm2.deletioninprogress = 0
                LEFT JOIN {course_modules_completion} cmc2 ON cmc2.coursemoduleid = cm2.id
                    AND cmc2.userid = ue2.userid
                WHERE e2.courseid = :progress_courseid
                GROUP BY ue2.userid
            ) progress_agg ON progress_agg.userid = u.id";
        }

        $progresscol = $hascoursefilter ? "progress_agg.course_progress" : "NULL AS course_progress";

        // Subquery 4: Enrolled courses count.
        $enrollsub = "(SELECT ue_ec.userid, COUNT(DISTINCT e_ec.courseid) AS enrolled_courses
                       FROM {user_enrolments} ue_ec
                       JOIN {enrol} e_ec ON e_ec.id = ue_ec.enrolid
                       GROUP BY ue_ec.userid)";

        // Subquery 5: Combination descriptions (trade names) from SDMS cache.
        $combosub = "(SELECT combination_code, MAX(combination_desc) AS combination_desc
                      FROM {elby_combinations}
                      GROUP BY combination_code)";

        $select = "SELECT u.id AS userid, u.firstname, u.lastname,
                          su.sdms_id,
                          " . ($isteacher ? "et.position" : "COALESCE(combo.combination_desc, st.program, '') AS program") . ",
                          " . ($isteacher ? "et.gender" : "st.gender") . ",
                          " . ($isteacher ? "NULL AS date_of_birth" : "st.date_of_birth") . ",
                          " . ($isteacher ? "NULL AS class_grade" : "st.class_grade") . ",
                          " . ($isteacher ? "NULL AS program_code" : "st.program_code") . ",
                          u.lastaccess AS last_access,
                          COALESCE(log_agg.active_days, 0) AS active_days,
                          COALESCE(log_agg.total_actions, 0) AS total_actions,
                          quiz_agg.quizzes_avg_score,
                          {$progresscol},
                          COALESCE(enrol_agg.enrolled_courses, 0) AS enrolled_courses,
                          sch2.school_name,
                          sch2.school_code,
                          CASE
                              WHEN u.lastaccess < :atriskthreshold THEN 'at_risk'
                              WHEN u.lastaccess IS NULL THEN 'at_risk'
                              ELSE 'active'
                          END AS status";

        $from = " FROM {user} u
                  JOIN {elby_sdms_users} su ON su.userid = u.id AND su.user_type = :suusertype"
                  . ($isteacher
                      ? " LEFT JOIN {elby_teachers} et ON et.sdms_userid = su.id"
                      : " LEFT JOIN {elby_students} st ON st.sdms_userid = su.id")
                  . " LEFT JOIN {elby_schools} sch2 ON sch2.id = su.schoolid
                  LEFT JOIN {$logsub} log_agg ON log_agg.userid = u.id
                  LEFT JOIN {$quizsub} quiz_agg ON quiz_agg.userid = u.id
                  LEFT JOIN {$enrollsub} enrol_agg ON enrol_agg.userid = u.id"
                  . ($isteacher ? '' : " LEFT JOIN {$combosub} combo ON combo.combination_code = st.program_code")
                  . $progresssub;

        $where = " WHERE u.deleted = 0";
        $sqlparams = [
            'atriskthreshold' => time() - (7 * 86400),
            'logstart' => $logstart,
            'suusertype' => $suusertype,
        ];

        // Course filter params for subqueries.
        if ($params['courseid'] > 0) {
            $sqlparams['log_courseid'] = $params['courseid'];
            $sqlparams['quiz_courseid'] = $params['courseid'];
            if ($hascoursefilter) {
                $sqlparams['progress_courseid'] = $params['courseid'];
            }
        }

        // School filter.
        if (!empty($params['school_code'])) {
            $where .= " AND sch2.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        // Search filter.
        if (!empty($params['search'])) {
            $searchterm = '%' . $DB->sql_like_escape($params['search']) . '%';
            $where .= " AND (" . $DB->sql_like('u.firstname', ':search1', false) .
                       " OR " . $DB->sql_like('u.lastname', ':search2', false) .
                       " OR " . $DB->sql_like('su.sdms_id', ':search3', false) . ")";
            $sqlparams['search1'] = $searchterm;
            $sqlparams['search2'] = $searchterm;
            $sqlparams['search3'] = $searchterm;
        }

        // Program/trade filter (students only).
        if (!empty($params['program_code']) && !$isteacher) {
            $where .= " AND st.program_code = :programcode";
            $sqlparams['programcode'] = $params['program_code'];
        }

        // Engagement level filter.
        if (!empty($params['engagement_level'])) {
            if ($params['engagement_level'] === 'at_risk') {
                $where .= " AND (u.lastaccess < :engthreshold OR u.lastaccess IS NULL)";
                $sqlparams['engthreshold'] = time() - (7 * 86400);
            } else if ($params['engagement_level'] === 'high') {
                $where .= " AND COALESCE(log_agg.total_actions, 0) > 50";
            } else if ($params['engagement_level'] === 'medium') {
                $where .= " AND COALESCE(log_agg.total_actions, 0) BETWEEN 10 AND 50";
            } else if ($params['engagement_level'] === 'low') {
                $where .= " AND COALESCE(log_agg.total_actions, 0) > 0 AND COALESCE(log_agg.total_actions, 0) < 10";
            }
        }

        // Map sort fields that come from joins.
        $sortmap = [
            'lastname' => 'u.lastname',
            'firstname' => 'u.firstname',
            'active_days' => 'active_days',
            'total_actions' => 'total_actions',
            'quizzes_avg_score' => 'quiz_agg.quizzes_avg_score',
            'course_progress' => ($hascoursefilter ? 'progress_agg.course_progress' : 'u.lastname'),
            'last_access' => 'u.lastaccess',
            'enrolled_courses' => 'enrolled_courses',
        ];
        $sortfield = $sortmap[$sort] ?? 'u.lastname';

        $orderby = " ORDER BY {$sortfield} {$order}";

        // Count total.
        $countsql = "SELECT COUNT(DISTINCT u.id)" . $from . $where;
        $totalcount = $DB->count_records_sql($countsql, $sqlparams);

        // Fetch paginated results.
        $programorposition = $isteacher ? 'et.position' : 'combo.combination_desc, st.program';
        $progressgroupby = $hascoursefilter ? ', progress_agg.course_progress' : '';
        $gendercol = $isteacher ? 'et.gender' : 'st.gender';
        $dobcol = $isteacher ? '' : ', st.date_of_birth';
        $classgradecol = $isteacher ? '' : ', st.class_grade, st.program_code';
        $groupby = " GROUP BY u.id, u.firstname, u.lastname, su.sdms_id, {$programorposition},
                     {$gendercol}{$dobcol}{$classgradecol},
                     u.lastaccess, log_agg.active_days, log_agg.total_actions,
                     quiz_agg.quizzes_avg_score{$progressgroupby},
                     enrol_agg.enrolled_courses,
                     sch2.school_name, sch2.school_code";
        $sql = $select . $from . $where . $groupby . $orderby;
        $records = $DB->get_records_sql($sql, $sqlparams, $page * $perpage, $perpage);

        $students = [];
        foreach ($records as $rec) {
            // Compute age from date_of_birth.
            $age = null;
            if (!empty($rec->date_of_birth)) {
                $dob = \DateTime::createFromFormat('Y-m-d', $rec->date_of_birth);
                if ($dob) {
                    $age = $dob->diff(new \DateTime())->y;
                }
            }

            $students[] = [
                'userid' => (int) $rec->userid,
                'fullname' => $rec->firstname . ' ' . $rec->lastname,
                'sdms_id' => $rec->sdms_id ?? '',
                'program' => $isteacher ? '' : ($rec->program ?? ''),
                'program_code' => $isteacher ? '' : ($rec->program_code ?? ''),
                'position' => $isteacher ? ($rec->position ?? '') : '',
                'gender' => $rec->gender ?? '',
                'age' => $age,
                'class_grade' => $isteacher ? '' : ($rec->class_grade ?? ''),
                'school_name' => $rec->school_name ?? '',
                'school_code' => $rec->school_code ?? '',
                'last_access' => (int) ($rec->last_access ?? 0),
                'active_days' => (int) $rec->active_days,
                'total_actions' => (int) $rec->total_actions,
                'quizzes_avg_score' => $rec->quizzes_avg_score !== null ? (float) $rec->quizzes_avg_score : null,
                'course_progress' => $rec->course_progress !== null ? (float) $rec->course_progress : null,
                'enrolled_courses' => (int) $rec->enrolled_courses,
                'status' => $rec->status,
            ];
        }

        // Compute summary stats for the full filtered set.
        $summaryparams = [
            'sum_ut' => $suusertype,
            'sum_active' => time() - (7 * 86400),
        ];

        $summarybase = " FROM {user} u
            JOIN {elby_sdms_users} su ON su.userid = u.id AND su.user_type = :sum_ut"
            . (!$isteacher ? " LEFT JOIN {elby_students} st2 ON st2.sdms_userid = su.id" : "")
            . " LEFT JOIN {elby_schools} sch ON sch.id = su.schoolid
            LEFT JOIN (
                SELECT qg2.userid, AVG(qg2.grade / q2.grade * 100) AS quiz_avg
                FROM {quiz_grades} qg2
                JOIN {quiz} q2 ON q2.id = qg2.quiz AND q2.grade > 0
                GROUP BY qg2.userid
            ) sum_quiz ON sum_quiz.userid = u.id";

        $summarywhere = " WHERE u.deleted = 0";

        if (!empty($params['school_code'])) {
            $summarywhere .= " AND sch.school_code = :sum_sc";
            $summaryparams['sum_sc'] = $params['school_code'];
        }
        if (!empty($params['search'])) {
            $searchterm2 = '%' . $DB->sql_like_escape($params['search']) . '%';
            $summarywhere .= " AND (" . $DB->sql_like('u.firstname', ':sum_s1', false)
                . " OR " . $DB->sql_like('u.lastname', ':sum_s2', false)
                . " OR " . $DB->sql_like('su.sdms_id', ':sum_s3', false) . ")";
            $summaryparams['sum_s1'] = $searchterm2;
            $summaryparams['sum_s2'] = $searchterm2;
            $summaryparams['sum_s3'] = $searchterm2;
        }
        if (!empty($params['program_code']) && !$isteacher) {
            $summarywhere .= " AND st2.program_code = :sum_pc";
            $summaryparams['sum_pc'] = $params['program_code'];
        }

        $sumrow = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT u.id) AS total,
                    COUNT(DISTINCT CASE WHEN u.lastaccess >= :sum_active THEN u.id END) AS active_count,
                    AVG(sum_quiz.quiz_avg) AS avg_quiz_score"
            . $summarybase . $summarywhere,
            $summaryparams
        );

        $summaryactive = (int) ($sumrow->active_count ?? 0);
        $summarytotal = (int) ($sumrow->total ?? $totalcount);
        $summary = [
            'total' => $summarytotal,
            'active_count' => $summaryactive,
            'at_risk_count' => $summarytotal - $summaryactive,
            'avg_quiz_score' => $sumrow->avg_quiz_score !== null
                ? round((float) $sumrow->avg_quiz_score, 1) : null,
        ];

        // Get distinct programs for filter dropdown (students only).
        $programs = [];
        if (!$isteacher) {
            $programsql = "SELECT s.program_code,
                                  COALESCE(MAX(c.combination_desc), MAX(s.program), s.program_code) AS program_name
                             FROM {elby_students} s
                             JOIN {elby_sdms_users} su ON su.id = s.sdms_userid
                             LEFT JOIN {elby_combinations} c ON c.combination_code = s.program_code
                            WHERE s.program_code IS NOT NULL AND s.program_code != ''
                              AND su.user_type = 'student'
                         GROUP BY s.program_code
                         ORDER BY program_name";
            $programrecords = $DB->get_records_sql($programsql);
            foreach ($programrecords as $rec) {
                $programs[] = [
                    'code' => $rec->program_code,
                    'name' => $rec->program_name ?? $rec->program_code,
                ];
            }
        }

        return [
            'students' => $students,
            'total_count' => $totalcount,
            'page' => $page,
            'perpage' => $perpage,
            'summary' => $summary,
            'programs' => $programs,
        ];
    }

    /**
     * Return structure for get_student_list.
     */
    public static function get_student_list_returns(): external_single_structure {
        return new external_single_structure([
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'sdms_id' => new external_value(PARAM_TEXT, 'SDMS ID'),
                    'program' => new external_value(PARAM_TEXT, 'Program name'),
                    'program_code' => new external_value(PARAM_TEXT, 'Program/trade code'),
                    'position' => new external_value(PARAM_TEXT, 'Teacher position'),
                    'gender' => new external_value(PARAM_TEXT, 'Gender'),
                    'age' => new external_value(PARAM_INT, 'Age in years', VALUE_OPTIONAL),
                    'class_grade' => new external_value(PARAM_TEXT, 'Class grade/level (e.g. Level 3)'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'last_access' => new external_value(PARAM_INT, 'Last access timestamp'),
                    'active_days' => new external_value(PARAM_INT, 'Active days this week'),
                    'total_actions' => new external_value(PARAM_INT, 'Total actions this week'),
                    'quizzes_avg_score' => new external_value(PARAM_FLOAT, 'Average quiz score', VALUE_OPTIONAL),
                    'course_progress' => new external_value(PARAM_FLOAT, 'Course progress percentage', VALUE_OPTIONAL),
                    'enrolled_courses' => new external_value(PARAM_INT, 'Number of enrolled courses'),
                    'status' => new external_value(PARAM_TEXT, 'Status: active or at_risk'),
                ])
            ),
            'total_count' => new external_value(PARAM_INT, 'Total matching records'),
            'page' => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Results per page'),
            'summary' => new external_single_structure([
                'total' => new external_value(PARAM_INT, 'Total students matching filters'),
                'active_count' => new external_value(PARAM_INT, 'Active students (last 7 days)'),
                'at_risk_count' => new external_value(PARAM_INT, 'At-risk students'),
                'avg_quiz_score' => new external_value(PARAM_FLOAT, 'Average quiz score across all', VALUE_OPTIONAL),
            ]),
            'programs' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_TEXT, 'Program/trade code'),
                    'name' => new external_value(PARAM_TEXT, 'Program/trade name'),
                ]),
                'Distinct programs for filter dropdown'
            ),
        ]);
    }

    // =========================================================================
    // get_engagement_distribution — Engagement breakdown for a school.
    // =========================================================================

    /**
     * Parameters for get_engagement_distribution.
     */
    public static function get_engagement_distribution_parameters(): external_function_parameters {
        return new external_function_parameters([
            'school_code' => new external_value(PARAM_TEXT, 'SDMS school code'),
            'courseid' => new external_value(PARAM_INT, 'Course ID (0 for school-wide)', VALUE_DEFAULT, 0),
            'period_type' => new external_value(PARAM_TEXT, 'Period type: weekly or monthly', VALUE_DEFAULT, 'weekly'),
        ]);
    }

    /**
     * Get engagement distribution for a school.
     *
     * @param string $schoolcode SDMS school code.
     * @param int $courseid Course ID (0 for school-wide).
     * @param string $periodtype Period type.
     * @return array Engagement distribution data.
     */
    public static function get_engagement_distribution(string $schoolcode, int $courseid = 0,
            string $periodtype = 'weekly'): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_engagement_distribution_parameters(),
            ['school_code' => $schoolcode, 'courseid' => $courseid, 'period_type' => $periodtype]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $school = $DB->get_record('elby_schools', ['school_code' => $params['school_code']], 'id');
        if (!$school) {
            return [
                'high_engagement_count' => 0,
                'medium_engagement_count' => 0,
                'low_engagement_count' => 0,
                'at_risk_count' => 0,
                'total_enrolled' => 0,
            ];
        }

        // Get the most recent metrics record.
        $metrics = $DB->get_records_sql(
            "SELECT high_engagement_count, medium_engagement_count, low_engagement_count,
                    at_risk_count, total_enrolled
             FROM {elby_school_metrics}
             WHERE schoolid = :schoolid
               AND courseid = :courseid
               AND period_type = :periodtype
             ORDER BY period_start DESC
             LIMIT 1",
            [
                'schoolid' => $school->id,
                'courseid' => $params['courseid'],
                'periodtype' => $params['period_type'],
            ]
        );

        $metrics = reset($metrics);

        if (!$metrics) {
            return [
                'high_engagement_count' => 0,
                'medium_engagement_count' => 0,
                'low_engagement_count' => 0,
                'at_risk_count' => 0,
                'total_enrolled' => 0,
            ];
        }

        return [
            'high_engagement_count' => (int) $metrics->high_engagement_count,
            'medium_engagement_count' => (int) $metrics->medium_engagement_count,
            'low_engagement_count' => (int) $metrics->low_engagement_count,
            'at_risk_count' => (int) $metrics->at_risk_count,
            'total_enrolled' => (int) $metrics->total_enrolled,
        ];
    }

    /**
     * Return structure for get_engagement_distribution.
     */
    public static function get_engagement_distribution_returns(): external_single_structure {
        return new external_single_structure([
            'high_engagement_count' => new external_value(PARAM_INT, 'High engagement count'),
            'medium_engagement_count' => new external_value(PARAM_INT, 'Medium engagement count'),
            'low_engagement_count' => new external_value(PARAM_INT, 'Low engagement count'),
            'at_risk_count' => new external_value(PARAM_INT, 'At-risk count'),
            'total_enrolled' => new external_value(PARAM_INT, 'Total enrolled'),
        ]);
    }

    // =========================================================================
    // get_trades_report — Trades/programs offered with school counts.
    // =========================================================================

    /**
     * Parameters for get_trades_report.
     */
    public static function get_trades_report_parameters(): external_function_parameters {
        return new external_function_parameters([
            'level_name' => new external_value(PARAM_TEXT, 'Optional level name filter', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get trades report showing which trades are offered and how many schools offer each.
     *
     * @param string $levelname Optional level filter.
     * @return array Trades report data.
     */
    public static function get_trades_report(string $levelname = ''): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_trades_report_parameters(),
            ['level_name' => $levelname]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        // Get trade counts.
        $where = '';
        $sqlparams = [];
        if (!empty($params['level_name'])) {
            $where = " AND l.level_name = :levelname";
            $sqlparams['levelname'] = $params['level_name'];
        }

        $sql = "SELECT c.id, c.combination_code, c.combination_name, c.combination_desc,
                       COUNT(DISTINCT s.id) AS school_count
                FROM {elby_combinations} c
                JOIN {elby_levels} l ON l.id = c.levelid
                JOIN {elby_schools} s ON s.id = l.schoolid
                WHERE 1=1 {$where}
                GROUP BY c.id, c.combination_code, c.combination_name, c.combination_desc
                ORDER BY school_count DESC";
        $trades = $DB->get_records_sql($sql, $sqlparams);

        // For each trade, get the list of schools.
        $result = [];
        foreach ($trades as $trade) {
            $schoolssql = "SELECT DISTINCT s.school_code, s.school_name
                           FROM {elby_combinations} c
                           JOIN {elby_levels} l ON l.id = c.levelid
                           JOIN {elby_schools} s ON s.id = l.schoolid
                           WHERE c.combination_code = :code" . $where . "
                           ORDER BY s.school_name";
            $schoolparams = array_merge(['code' => $trade->combination_code], $sqlparams);
            $schools = $DB->get_records_sql($schoolssql, $schoolparams);

            $schoollist = [];
            foreach ($schools as $school) {
                $schoollist[] = [
                    'school_name' => $school->school_name,
                    'school_code' => $school->school_code,
                ];
            }

            // Get level/category/course data for this trade.
            $levelssql = "SELECT DISTINCT l.level_name,
                                 g.grade_name
                            FROM {elby_combinations} c
                            JOIN {elby_levels} l ON l.id = c.levelid
                            JOIN {elby_grades} g ON g.combinationid = c.id
                           WHERE c.combination_code = :code" . $where . "
                        ORDER BY g.grade_name";
            $levelparams = array_merge(['code' => $trade->combination_code], $sqlparams);
            $levelrecords = $DB->get_records_sql($levelssql, $levelparams);

            // Extract unique level numbers from grade names.
            $seenlevels = [];
            foreach ($levelrecords as $rec) {
                if (preg_match('/(\d+)/', $rec->grade_name, $m)) {
                    $levnum = (int) $m[1];
                    if (!isset($seenlevels[$levnum])) {
                        $seenlevels[$levnum] = true;
                    }
                }
            }

            $levels = [];
            $totalcoursecount = 0;
            $hascategorymapping = false;

            foreach (array_keys($seenlevels) as $levnum) {
                $lookupkey = $trade->combination_code . ':' . $levnum;
                $cat = $DB->get_record('course_categories', ['idnumber' => $lookupkey], 'id, name');

                $catid = 0;
                $catname = '';
                $coursecount = 0;
                $totalenrolled = 0;

                if ($cat) {
                    $catid = (int) $cat->id;
                    $catname = $cat->name;
                    $hascategorymapping = true;

                    $catobj = \core_course_category::get($cat->id, \IGNORE_MISSING);
                    if ($catobj) {
                        $courses = $catobj->get_courses(['recursive' => true]);
                        $coursecount = count($courses);
                        $totalcoursecount += $coursecount;

                        // Count enrolled students.
                        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
                        if ($studentroleid) {
                            foreach ($courses as $course) {
                                $coursecontext = \context_course::instance($course->id, \IGNORE_MISSING);
                                if ($coursecontext) {
                                    $totalenrolled += (int) $DB->count_records_sql(
                                        "SELECT COUNT(DISTINCT u.id)
                                           FROM {user} u
                                           JOIN {user_enrolments} ue ON ue.userid = u.id
                                           JOIN {enrol} e ON e.id = ue.enrolid
                                           JOIN {role_assignments} ra ON ra.userid = u.id
                                          WHERE e.courseid = :courseid
                                            AND u.deleted = 0
                                            AND ra.roleid = :roleid
                                            AND ra.contextid = :contextid",
                                        [
                                            'courseid' => $course->id,
                                            'roleid' => $studentroleid,
                                            'contextid' => $coursecontext->id,
                                        ]
                                    );
                                }
                            }
                        }
                    }
                }

                $levels[] = [
                    'level_number' => $levnum,
                    'category_id' => $catid,
                    'category_name' => $catname,
                    'course_count' => $coursecount,
                    'total_enrolled' => $totalenrolled,
                ];
            }

            // Sort levels by level_number.
            usort($levels, fn($a, $b) => $a['level_number'] - $b['level_number']);

            $result[] = [
                'trade_name' => $trade->combination_name,
                'trade_code' => $trade->combination_code,
                'trade_desc' => $trade->combination_desc ?? '',
                'school_count' => (int) $trade->school_count,
                'schools' => $schoollist,
                'levels' => $levels,
                'total_course_count' => $totalcoursecount,
                'has_category_mapping' => $hascategorymapping,
            ];
        }

        return ['trades' => $result];
    }

    /**
     * Return structure for get_trades_report.
     */
    public static function get_trades_report_returns(): external_single_structure {
        return new external_single_structure([
            'trades' => new external_multiple_structure(
                new external_single_structure([
                    'trade_name' => new external_value(PARAM_TEXT, 'Trade/combination name'),
                    'trade_code' => new external_value(PARAM_TEXT, 'Trade/combination code'),
                    'trade_desc' => new external_value(PARAM_TEXT, 'Trade description'),
                    'school_count' => new external_value(PARAM_INT, 'Number of schools offering this trade'),
                    'schools' => new external_multiple_structure(
                        new external_single_structure([
                            'school_name' => new external_value(PARAM_TEXT, 'School name'),
                            'school_code' => new external_value(PARAM_TEXT, 'School code'),
                        ])
                    ),
                    'levels' => new external_multiple_structure(
                        new external_single_structure([
                            'level_number' => new external_value(PARAM_INT, 'Level number'),
                            'category_id' => new external_value(PARAM_INT, 'Matching Moodle category ID (0 if none)'),
                            'category_name' => new external_value(PARAM_TEXT, 'Category name'),
                            'course_count' => new external_value(PARAM_INT, 'Number of courses under category'),
                            'total_enrolled' => new external_value(PARAM_INT, 'Total enrolled students'),
                        ]),
                        'Level/category data for this trade'
                    ),
                    'total_course_count' => new external_value(PARAM_INT, 'Total courses across all levels'),
                    'has_category_mapping' => new external_value(PARAM_BOOL, 'Whether any level has a Moodle category'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // get_school_user_counts — Student/teacher comparison per school.
    // =========================================================================

    /**
     * Parameters for get_school_user_counts.
     */
    public static function get_school_user_counts_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get student and teacher counts per school for comparison.
     *
     * @return array School user counts data.
     */
    public static function get_school_user_counts(): array {
        global $DB;

        self::validate_parameters(self::get_school_user_counts_parameters(), []);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $sql = "SELECT s.id, s.school_code, s.school_name,
                       SUM(CASE WHEN su.user_type = 'student' THEN 1 ELSE 0 END) AS student_count,
                       SUM(CASE WHEN su.user_type = 'teacher' THEN 1 ELSE 0 END) AS teacher_count
                FROM {elby_schools} s
                LEFT JOIN {elby_sdms_users} su ON su.schoolid = s.id
                GROUP BY s.id, s.school_code, s.school_name
                HAVING SUM(CASE WHEN su.user_type = 'student' THEN 1 ELSE 0 END) > 0
                    OR SUM(CASE WHEN su.user_type = 'teacher' THEN 1 ELSE 0 END) > 0
                ORDER BY s.school_name";

        $records = $DB->get_records_sql($sql);

        $schools = [];
        foreach ($records as $rec) {
            $schools[] = [
                'school_code' => $rec->school_code,
                'school_name' => $rec->school_name,
                'student_count' => (int) $rec->student_count,
                'teacher_count' => (int) $rec->teacher_count,
            ];
        }

        return ['schools' => $schools];
    }

    /**
     * Return structure for get_school_user_counts.
     */
    public static function get_school_user_counts_returns(): external_single_structure {
        return new external_single_structure([
            'schools' => new external_multiple_structure(
                new external_single_structure([
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'student_count' => new external_value(PARAM_INT, 'Number of students'),
                    'teacher_count' => new external_value(PARAM_INT, 'Number of teachers'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // get_school_demographics — Gender breakdown + age distribution.
    // =========================================================================

    /**
     * Parameters for get_school_demographics.
     */
    public static function get_school_demographics_parameters(): external_function_parameters {
        return new external_function_parameters([
            'school_code' => new external_value(PARAM_TEXT, 'SDMS school code'),
        ]);
    }

    /**
     * Get school demographics: gender breakdown for students/teachers and student age distribution.
     *
     * @param string $schoolcode SDMS school code.
     * @return array Demographics data.
     */
    public static function get_school_demographics(string $schoolcode): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_school_demographics_parameters(),
            ['school_code' => $schoolcode]
        );
        $schoolcode = $params['school_code'];

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $school = $DB->get_record('elby_schools', ['school_code' => $schoolcode], 'id');
        if (!$school) {
            return [
                'success' => false,
                'error' => 'School not found',
                'students' => ['total' => 0, 'male' => 0, 'female' => 0],
                'teachers' => ['total' => 0, 'male' => 0, 'female' => 0],
                'age_distribution' => [],
            ];
        }

        // Student demographics.
        $studentrow = $DB->get_record_sql(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN st.gender = 'MALE' THEN 1 ELSE 0 END) AS male,
                    SUM(CASE WHEN st.gender = 'FEMALE' THEN 1 ELSE 0 END) AS female
             FROM {elby_sdms_users} su
             JOIN {elby_students} st ON st.sdms_userid = su.id
             WHERE su.schoolid = :schoolid AND su.user_type = 'student'",
            ['schoolid' => $school->id]
        );

        // Teacher demographics.
        $teacherrow = $DB->get_record_sql(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN et.gender = 'MALE' THEN 1 ELSE 0 END) AS male,
                    SUM(CASE WHEN et.gender = 'FEMALE' THEN 1 ELSE 0 END) AS female
             FROM {elby_sdms_users} su
             JOIN {elby_teachers} et ON et.sdms_userid = su.id
             WHERE su.schoolid = :schoolid AND su.user_type = 'teacher'",
            ['schoolid' => $school->id]
        );

        // Age distribution from student date_of_birth.
        $dobrows = $DB->get_records_sql(
            "SELECT st.date_of_birth
             FROM {elby_sdms_users} su
             JOIN {elby_students} st ON st.sdms_userid = su.id
             WHERE su.schoolid = :schoolid AND su.user_type = 'student'
               AND st.date_of_birth IS NOT NULL AND st.date_of_birth != ''",
            ['schoolid' => $school->id]
        );

        $today = new \DateTime();
        $agebuckets = [
            'Under 16' => 0,
            '16-17' => 0,
            '18-19' => 0,
            '20-21' => 0,
            '22-24' => 0,
            '25+' => 0,
        ];
        foreach ($dobrows as $row) {
            $dob = \DateTime::createFromFormat('Y-m-d', $row->date_of_birth);
            if (!$dob) {
                continue;
            }
            $age = $dob->diff($today)->y;
            if ($age < 16) {
                $agebuckets['Under 16']++;
            } else if ($age <= 17) {
                $agebuckets['16-17']++;
            } else if ($age <= 19) {
                $agebuckets['18-19']++;
            } else if ($age <= 21) {
                $agebuckets['20-21']++;
            } else if ($age <= 24) {
                $agebuckets['22-24']++;
            } else {
                $agebuckets['25+']++;
            }
        }

        $agedist = [];
        foreach ($agebuckets as $label => $count) {
            $agedist[] = ['label' => $label, 'count' => (int) $count];
        }

        return [
            'success' => true,
            'error' => '',
            'students' => [
                'total' => (int) ($studentrow->total ?? 0),
                'male' => (int) ($studentrow->male ?? 0),
                'female' => (int) ($studentrow->female ?? 0),
            ],
            'teachers' => [
                'total' => (int) ($teacherrow->total ?? 0),
                'male' => (int) ($teacherrow->male ?? 0),
                'female' => (int) ($teacherrow->female ?? 0),
            ],
            'age_distribution' => $agedist,
        ];
    }

    /**
     * Return structure for get_school_demographics.
     */
    public static function get_school_demographics_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether data was found'),
            'error' => new external_value(PARAM_TEXT, 'Error message'),
            'students' => new external_single_structure([
                'total' => new external_value(PARAM_INT, 'Total students'),
                'male' => new external_value(PARAM_INT, 'Male students'),
                'female' => new external_value(PARAM_INT, 'Female students'),
            ]),
            'teachers' => new external_single_structure([
                'total' => new external_value(PARAM_INT, 'Total teachers'),
                'male' => new external_value(PARAM_INT, 'Male teachers'),
                'female' => new external_value(PARAM_INT, 'Female teachers'),
            ]),
            'age_distribution' => new external_multiple_structure(
                new external_single_structure([
                    'label' => new external_value(PARAM_TEXT, 'Age bucket label'),
                    'count' => new external_value(PARAM_INT, 'Count in this bucket'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // trigger_task — Run a scheduled task manually.
    // =========================================================================

    /**
     * Parameters for trigger_task.
     */
    public static function trigger_task_parameters(): external_function_parameters {
        return new external_function_parameters([
            'task_name' => new external_value(PARAM_TEXT,
                'Task to run: compute_user_metrics, aggregate_school_metrics, or refresh_sdms_cache'),
        ]);
    }

    /**
     * Trigger a scheduled task to run immediately.
     *
     * @param string $taskname The task name.
     * @return array Result with success flag and message.
     */
    public static function trigger_task(string $taskname): array {
        $params = self::validate_parameters(
            self::trigger_task_parameters(),
            ['task_name' => $taskname]
        );
        $taskname = $params['task_name'];

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:manage', $context);

        // Map task names to class names.
        $taskmap = [
            'compute_user_metrics' => '\local_elby_dashboard\task\compute_user_metrics',
            'aggregate_school_metrics' => '\local_elby_dashboard\task\aggregate_school_metrics',
            'refresh_sdms_cache' => '\local_elby_dashboard\task\refresh_sdms_cache',
            'auto_link_by_email' => '\local_elby_dashboard\task\auto_link_by_email',
        ];

        if (!isset($taskmap[$taskname])) {
            return [
                'success' => false,
                'message' => 'Unknown task: ' . $taskname,
            ];
        }

        $taskclass = $taskmap[$taskname];

        // Long-running tasks are queued as ad-hoc tasks to avoid blocking the web request.
        $asynctasks = [
            'auto_link_by_email' => '\local_elby_dashboard\task\auto_link_by_email_adhoc',
        ];

        if (isset($asynctasks[$taskname])) {
            try {
                $adhoc = new $asynctasks[$taskname]();
                \core\task\manager::queue_adhoc_task($adhoc, true); // true = don't duplicate.
                return [
                    'success' => true,
                    'message' => 'Task queued for background execution: ' . $taskname .
                        '. It will run on the next cron cycle.',
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Failed to queue task: ' . $e->getMessage(),
                ];
            }
        }

        try {
            $task = \core\task\manager::get_scheduled_task($taskclass);
            if (!$task) {
                return [
                    'success' => false,
                    'message' => 'Task not found: ' . $taskclass,
                ];
            }
            // Buffer output — tasks use mtrace() which prints to stdout.
            ob_start();
            try {
                $task->execute();
            } finally {
                ob_end_clean();
            }
            return [
                'success' => true,
                'message' => 'Task completed successfully: ' . $taskname,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Task failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Return structure for trigger_task.
     */
    public static function trigger_task_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the task ran successfully'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }
}
