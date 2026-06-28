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
 * External API for course reports grouped by school code.
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
use context_course;
use context_system;

/**
 * External API for course reports grouped by school code.
 */
class course_report extends external_api {

    /**
     * Returns description of get_course_report_by_school parameters.
     *
     * @return external_function_parameters
     */
    public static function get_course_report_by_school_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get course report data grouped by school code.
     *
     * Returns completion rates and average grades for each section (unit)
     * grouped by school code.
     *
     * @param int $courseid Course ID
     * @return array Course report data grouped by school
     */
    public static function get_course_report_by_school(int $courseid): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(
            self::get_course_report_by_school_parameters(),
            ['courseid' => $courseid]
        );
        $courseid = $params['courseid'];

        // Get course and validate it exists.
        $course = get_course($courseid);
        $context = context_course::instance($course->id);

        // Validate context.
        self::validate_context($context);

        // Check capability.
        require_capability('local/elby_dashboard:viewreports', $context);

        // Get course module info.
        $modinfo = get_fast_modinfo($course);

        // Build section data (units).
        $sections = self::get_course_sections($modinfo);

        // Get all enrolled students with their school codes.
        $students = self::get_enrolled_students_with_schools($context);

        // Group students by school code.
        $studentsBySchool = [];
        foreach ($students as $student) {
            $schoolcode = $student->schoolcode ?: 'UNKNOWN';
            if (!isset($studentsBySchool[$schoolcode])) {
                $studentsBySchool[$schoolcode] = [];
            }
            $studentsBySchool[$schoolcode][] = $student;
        }

        // Get completion and grade data for all students.
        $completionData = self::get_completion_data($courseid, array_keys($sections));
        $gradeData = self::get_grade_data($courseid, array_keys($sections));

        // Build report data per school.
        $schoolReports = [];
        $totalEnrolled = count($students);

        foreach ($studentsBySchool as $schoolcode => $schoolStudents) {
            $studentIds = array_column($schoolStudents, 'id');
            $studentCount = count($schoolStudents);

            // Calculate per-section stats for this school.
            $sectionStats = [];
            foreach ($sections as $sectionNum => $sectionData) {
                $sectionActivities = $sectionData['activities'];

                // Calculate completion rate for this section.
                $completionRate = self::calculate_section_completion_rate(
                    $studentIds,
                    $sectionActivities,
                    $completionData
                );

                // Calculate average grade for this section.
                $averageGrade = self::calculate_section_average_grade(
                    $studentIds,
                    $sectionData['grade_items'],
                    $gradeData
                );

                $sectionStats[] = [
                    'section_number' => $sectionNum,
                    'section_name' => $sectionData['name'],
                    'completion_rate' => $completionRate,
                    'average_grade' => $averageGrade,
                ];
            }

            $schoolReports[] = [
                'school_code' => $schoolcode,
                'school_name' => self::get_school_name($schoolcode),
                'student_count' => $studentCount,
                'sections' => $sectionStats,
            ];
        }

        // Sort by student count descending.
        usort($schoolReports, function($a, $b) {
            return $b['student_count'] - $a['student_count'];
        });

        // Calculate overview totals per section.
        $overviewSections = [];
        foreach ($sections as $sectionNum => $sectionData) {
            $sectionActivities = $sectionData['activities'];
            $allStudentIds = array_column($students, 'id');

            $completionRate = self::calculate_section_completion_rate(
                $allStudentIds,
                $sectionActivities,
                $completionData
            );

            $overviewSections[] = [
                'section_number' => $sectionNum,
                'section_name' => $sectionData['name'],
                'completion_rate' => $completionRate,
            ];
        }

        return [
            'courseid' => $courseid,
            'course_name' => $course->fullname,
            'total_enrolled' => $totalEnrolled,
            'total_schools' => count($schoolReports),
            'overview_sections' => $overviewSections,
            'schools' => $schoolReports,
        ];
    }

    /**
     * Get course sections with their activities and grade items.
     *
     * @param \course_modinfo $modinfo Course module info
     * @return array Sections data indexed by section number
     */
    private static function get_course_sections(\course_modinfo $modinfo): array {
        global $DB;

        $sections = [];
        $course = $modinfo->get_course();

        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            // Skip section 0 (general section).
            if ($sectioninfo->section == 0) {
                continue;
            }

            if (!$sectioninfo->visible) {
                continue;
            }

            // Get course modules in this section.
            $sectioncms = $modinfo->sections[$sectioninfo->section] ?? [];

            $activities = [];
            $gradeItems = [];

            foreach ($sectioncms as $cmid) {
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm || !$cm->visible) {
                    continue;
                }

                // Only track activities with completion enabled.
                if ($cm->completion > 0) {
                    $activities[] = $cm->id;
                }

                // Get grade item for this activity.
                $gradeitem = $DB->get_record('grade_items', [
                    'courseid' => $course->id,
                    'itemmodule' => $cm->modname,
                    'iteminstance' => $cm->instance,
                ]);

                if ($gradeitem) {
                    $gradeItems[] = $gradeitem->id;
                }
            }

            // Get section name (use "Unit X" if empty).
            $sectionName = $sectioninfo->name;
            if (empty($sectionName)) {
                $sectionName = 'Unit ' . $sectioninfo->section;
            }

            $sections[$sectioninfo->section] = [
                'name' => $sectionName,
                'activities' => $activities,
                'grade_items' => $gradeItems,
            ];
        }

        return $sections;
    }

    /**
     * Get enrolled students with their school codes.
     *
     * @param \context $context Course context
     * @return array Array of student objects with id, firstname, lastname, schoolcode
     */
    private static function get_enrolled_students_with_schools(\context $context): array {
        global $DB;

        // Get student role ID.
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        if (!$studentroleid) {
            return [];
        }

        // Get enrolled users with student role.
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.schoolcode
                FROM {user} u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {role_assignments} ra ON ra.userid = u.id
                WHERE e.courseid = :courseid
                  AND u.deleted = 0
                  AND ra.roleid = :roleid
                  AND ra.contextid = :contextid
                ORDER BY u.schoolcode, u.lastname, u.firstname";

        $courseid = $context->instanceid;

        return $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'roleid' => $studentroleid,
            'contextid' => $context->id,
        ]);
    }

    /**
     * Get completion data for all activities in the course.
     *
     * @param int $courseid Course ID
     * @param array $sectionNums Section numbers
     * @return array Completion data indexed by cmid then userid
     */
    private static function get_completion_data(int $courseid, array $sectionNums): array {
        global $DB;

        // Get all completion records for this course.
        $sql = "SELECT cmc.id, cmc.coursemoduleid, cmc.userid, cmc.completionstate
                FROM {course_modules_completion} cmc
                JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                WHERE cm.course = :courseid
                  AND cmc.completionstate >= 1";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        // Index by cmid then userid.
        $data = [];
        foreach ($records as $record) {
            if (!isset($data[$record->coursemoduleid])) {
                $data[$record->coursemoduleid] = [];
            }
            $data[$record->coursemoduleid][$record->userid] = $record->completionstate;
        }

        return $data;
    }

    /**
     * Get grade data for all grade items in the course.
     *
     * @param int $courseid Course ID
     * @param array $sectionNums Section numbers
     * @return array Grade data indexed by itemid then userid
     */
    private static function get_grade_data(int $courseid, array $sectionNums): array {
        global $DB;

        // Get all grades for this course.
        $sql = "SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade, gi.grademax
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gi.id = gg.itemid
                WHERE gi.courseid = :courseid
                  AND gg.finalgrade IS NOT NULL";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        // Index by itemid then userid.
        $data = [];
        foreach ($records as $record) {
            if (!isset($data[$record->itemid])) {
                $data[$record->itemid] = [];
            }
            // Store as percentage.
            $percentage = $record->grademax > 0
                ? ($record->finalgrade / $record->grademax) * 100
                : 0;
            $data[$record->itemid][$record->userid] = $percentage;
        }

        return $data;
    }

    /**
     * Calculate completion rate for a section for given students.
     *
     * @param array $studentIds Student IDs
     * @param array $activityIds Activity (course module) IDs
     * @param array $completionData Pre-fetched completion data
     * @return float Completion rate as percentage (0-100)
     */
    private static function calculate_section_completion_rate(
        array $studentIds,
        array $activityIds,
        array $completionData
    ): float {
        if (empty($studentIds) || empty($activityIds)) {
            return 0.0;
        }

        $totalPossible = count($studentIds) * count($activityIds);
        $totalCompleted = 0;

        foreach ($activityIds as $cmid) {
            $activityCompletions = $completionData[$cmid] ?? [];
            foreach ($studentIds as $studentId) {
                if (isset($activityCompletions[$studentId]) && $activityCompletions[$studentId] >= 1) {
                    $totalCompleted++;
                }
            }
        }

        return $totalPossible > 0
            ? round(($totalCompleted / $totalPossible) * 100, 1)
            : 0.0;
    }

    /**
     * Calculate average grade for a section for given students.
     *
     * @param array $studentIds Student IDs
     * @param array $gradeItemIds Grade item IDs
     * @param array $gradeData Pre-fetched grade data
     * @return float Average grade as percentage (0-100)
     */
    private static function calculate_section_average_grade(
        array $studentIds,
        array $gradeItemIds,
        array $gradeData
    ): float {
        if (empty($studentIds) || empty($gradeItemIds)) {
            return 0.0;
        }

        $totalGrades = 0;
        $gradeCount = 0;

        foreach ($gradeItemIds as $itemId) {
            $itemGrades = $gradeData[$itemId] ?? [];
            foreach ($studentIds as $studentId) {
                if (isset($itemGrades[$studentId])) {
                    $totalGrades += $itemGrades[$studentId];
                    $gradeCount++;
                }
            }
        }

        return $gradeCount > 0
            ? round($totalGrades / $gradeCount, 1)
            : 0.0;
    }

    /**
     * Get school name from school code.
     *
     * In the future, this could look up from a school registry table.
     * For now, returns the code itself.
     *
     * @param string $schoolcode School code
     * @return string School name
     */
    private static function get_school_name(string $schoolcode): string {
        // TODO: Implement school name lookup from registry.
        // For now, just return the school code.
        return $schoolcode;
    }

    /**
     * Returns description of get_course_report_by_school return value.
     *
     * @return external_single_structure
     */
    public static function get_course_report_by_school_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'course_name' => new external_value(PARAM_TEXT, 'Course name'),
            'total_enrolled' => new external_value(PARAM_INT, 'Total enrolled students'),
            'total_schools' => new external_value(PARAM_INT, 'Total number of schools'),
            'overview_sections' => new external_multiple_structure(
                new external_single_structure([
                    'section_number' => new external_value(PARAM_INT, 'Section/Unit number'),
                    'section_name' => new external_value(PARAM_TEXT, 'Section/Unit name'),
                    'completion_rate' => new external_value(PARAM_FLOAT, 'Overall completion rate (0-100)'),
                ]),
                'Overview completion rates per section'
            ),
            'schools' => new external_multiple_structure(
                new external_single_structure([
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'student_count' => new external_value(PARAM_INT, 'Number of students from this school'),
                    'sections' => new external_multiple_structure(
                        new external_single_structure([
                            'section_number' => new external_value(PARAM_INT, 'Section/Unit number'),
                            'section_name' => new external_value(PARAM_TEXT, 'Section/Unit name'),
                            'completion_rate' => new external_value(PARAM_FLOAT, 'Completion rate (0-100)'),
                            'average_grade' => new external_value(PARAM_FLOAT, 'Average grade (0-100)'),
                        ]),
                        'Per-section statistics'
                    ),
                ]),
                'Statistics grouped by school'
            ),
        ]);
    }

    /**
     * Returns description of get_all_courses_report parameters.
     *
     * @return external_function_parameters
     */
    public static function get_all_courses_report_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get report data for all courses grouped by school code.
     *
     * @return array Report data for all courses
     */
    public static function get_all_courses_report(): array {
        global $DB;

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/elby_dashboard:viewreports', $context);

        // Get all courses (exclude site course).
        $courses = $DB->get_records_select(
            'course',
            'id > 1',
            null,
            'fullname ASC',
            'id, shortname, fullname'
        );

        $coursesReport = [];
        foreach ($courses as $course) {
            try {
                $report = self::get_course_report_by_school_internal($course->id);
                if ($report['total_enrolled'] > 0) {
                    $coursesReport[] = $report;
                }
            } catch (\Exception $e) {
                // Skip courses that fail (e.g., permission issues).
                continue;
            }
        }

        return [
            'total_courses' => count($coursesReport),
            'courses' => $coursesReport,
        ];
    }

    /**
     * Internal method to get course report without validation.
     *
     * @param int $courseid Course ID
     * @return array Course report data
     */
    private static function get_course_report_by_school_internal(int $courseid): array {
        global $DB;

        $course = get_course($courseid);
        $context = context_course::instance($course->id);

        // Get course module info.
        $modinfo = get_fast_modinfo($course);

        // Build section data (units).
        $sections = self::get_course_sections($modinfo);

        // Get all enrolled students with their school codes.
        $students = self::get_enrolled_students_with_schools($context);

        // Group students by school code.
        $studentsBySchool = [];
        foreach ($students as $student) {
            $schoolcode = $student->schoolcode ?: 'UNKNOWN';
            if (!isset($studentsBySchool[$schoolcode])) {
                $studentsBySchool[$schoolcode] = [];
            }
            $studentsBySchool[$schoolcode][] = $student;
        }

        // Get completion and grade data.
        $completionData = self::get_completion_data($courseid, array_keys($sections));
        $gradeData = self::get_grade_data($courseid, array_keys($sections));

        // Build report data per school.
        $schoolReports = [];
        $totalEnrolled = count($students);

        foreach ($studentsBySchool as $schoolcode => $schoolStudents) {
            $studentIds = array_column($schoolStudents, 'id');
            $studentCount = count($schoolStudents);

            $sectionStats = [];
            foreach ($sections as $sectionNum => $sectionData) {
                $completionRate = self::calculate_section_completion_rate(
                    $studentIds,
                    $sectionData['activities'],
                    $completionData
                );

                $averageGrade = self::calculate_section_average_grade(
                    $studentIds,
                    $sectionData['grade_items'],
                    $gradeData
                );

                $sectionStats[] = [
                    'section_number' => $sectionNum,
                    'section_name' => $sectionData['name'],
                    'completion_rate' => $completionRate,
                    'average_grade' => $averageGrade,
                ];
            }

            $schoolReports[] = [
                'school_code' => $schoolcode,
                'school_name' => self::get_school_name($schoolcode),
                'student_count' => $studentCount,
                'sections' => $sectionStats,
            ];
        }

        // Sort by student count descending.
        usort($schoolReports, function($a, $b) {
            return $b['student_count'] - $a['student_count'];
        });

        // Calculate overview totals per section.
        $overviewSections = [];
        foreach ($sections as $sectionNum => $sectionData) {
            $allStudentIds = array_column($students, 'id');

            $completionRate = self::calculate_section_completion_rate(
                $allStudentIds,
                $sectionData['activities'],
                $completionData
            );

            $overviewSections[] = [
                'section_number' => $sectionNum,
                'section_name' => $sectionData['name'],
                'completion_rate' => $completionRate,
            ];
        }

        return [
            'courseid' => $courseid,
            'course_name' => $course->fullname,
            'total_enrolled' => $totalEnrolled,
            'total_schools' => count($schoolReports),
            'overview_sections' => $overviewSections,
            'schools' => $schoolReports,
        ];
    }

    // =========================================================================
    // get_school_courses_report — Per-school report on trades, levels, courses.
    // =========================================================================

    /**
     * Returns description of get_school_courses_report parameters.
     *
     * @return external_function_parameters
     */
    public static function get_school_courses_report_parameters(): external_function_parameters {
        return new external_function_parameters([
            'school_code' => new external_value(PARAM_TEXT, 'School code to report on'),
        ]);
    }

    /**
     * Get a per-school report on trades, levels, and their Moodle courses.
     *
     * For each trade:level combination found among the school's students,
     * looks up matching Moodle categories (by idnumber = "{combinationCode}:{level}")
     * and returns the courses under those categories with enrollment counts.
     *
     * @param string $school_code School code.
     * @return array Report data.
     */
    public static function get_school_courses_report(string $school_code): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(
            self::get_school_courses_report_parameters(),
            ['school_code' => $school_code]
        );
        $school_code = $params['school_code'];

        // Validate context and capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        // Look up the school.
        $school = $DB->get_record('elby_schools', ['school_code' => $school_code]);
        $schoolname = $school ? $school->school_name : $school_code;
        $schoolid = $school ? $school->id : null;

        if (!$schoolid) {
            return [
                'school_code' => $school_code,
                'school_name' => $schoolname,
                'trades' => [],
            ];
        }

        // Get the school's students with their trade (program_code) and class_grade.
        $sql = "SELECT s.id, s.program_code, s.program, s.class_grade, su.userid
                  FROM {elby_students} s
                  JOIN {elby_sdms_users} su ON su.id = s.sdms_userid
                 WHERE su.schoolid = :schoolid
                   AND su.user_type = 'student'
                   AND s.program_code IS NOT NULL
                   AND s.class_grade IS NOT NULL";
        $students = $DB->get_records_sql($sql, ['schoolid' => $schoolid]);

        // Group students by program_code → level_number.
        $tradegroups = [];
        foreach ($students as $student) {
            $code = $student->program_code;
            $classgrade = $student->class_grade;

            // Extract level number.
            if (!preg_match('/(\d+)/', $classgrade, $m)) {
                continue;
            }
            $levelnumber = $m[1];

            if (!isset($tradegroups[$code])) {
                $tradegroups[$code] = [
                    'name' => $student->program ?? $code,
                    'levels' => [],
                ];
            }
            if (!isset($tradegroups[$code]['levels'][$levelnumber])) {
                $tradegroups[$code]['levels'][$levelnumber] = [
                    'student_count' => 0,
                    'user_ids' => [],
                ];
            }
            $tradegroups[$code]['levels'][$levelnumber]['student_count']++;
            $tradegroups[$code]['levels'][$levelnumber]['user_ids'][] = $student->userid;
        }

        // Get student role ID for enrollment count queries.
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

        // Build output.
        $trades = [];
        foreach ($tradegroups as $code => $tradedata) {
            $levels = [];
            foreach ($tradedata['levels'] as $levelnumber => $leveldata) {
                $lookupkey = $code . ':' . $levelnumber;

                // Find matching Moodle category.
                $categories = $DB->get_records('course_categories', ['idnumber' => $lookupkey], '', 'id');

                $courses = [];
                foreach ($categories as $cat) {
                    $category = \core_course_category::get($cat->id, \IGNORE_MISSING);
                    if (!$category) {
                        continue;
                    }

                    $catcourses = $category->get_courses(['recursive' => true]);
                    foreach ($catcourses as $course) {
                        // Count enrolled students in this course.
                        $enrolledcount = 0;
                        if ($studentroleid) {
                            $coursecontext = context_course::instance($course->id, \IGNORE_MISSING);
                            if ($coursecontext) {
                                $enrolledcount = (int) $DB->count_records_sql(
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

                        $courses[] = [
                            'id' => $course->id,
                            'fullname' => $course->fullname,
                            'enrolled_count' => $enrolledcount,
                        ];
                    }
                }

                $levels[] = [
                    'level_number' => (int) $levelnumber,
                    'level_name' => 'Level ' . $levelnumber,
                    'student_count' => $leveldata['student_count'],
                    'courses' => $courses,
                ];
            }

            // Sort levels by level_number.
            usort($levels, fn($a, $b) => $a['level_number'] - $b['level_number']);

            $trades[] = [
                'code' => $code,
                'name' => $tradedata['name'],
                'levels' => $levels,
            ];
        }

        // Sort trades alphabetically by name.
        usort($trades, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'school_code' => $school_code,
            'school_name' => $schoolname,
            'trades' => $trades,
        ];
    }

    /**
     * Returns description of get_school_courses_report return value.
     *
     * @return external_single_structure
     */
    public static function get_school_courses_report_returns(): external_single_structure {
        return new external_single_structure([
            'school_code' => new external_value(PARAM_TEXT, 'School code'),
            'school_name' => new external_value(PARAM_TEXT, 'School name'),
            'trades' => new external_multiple_structure(
                new external_single_structure([
                    'code' => new external_value(PARAM_TEXT, 'Trade/combination code'),
                    'name' => new external_value(PARAM_TEXT, 'Trade/combination name'),
                    'levels' => new external_multiple_structure(
                        new external_single_structure([
                            'level_number' => new external_value(PARAM_INT, 'Level number'),
                            'level_name' => new external_value(PARAM_TEXT, 'Level name'),
                            'student_count' => new external_value(PARAM_INT, 'Number of students at this level'),
                            'courses' => new external_multiple_structure(
                                new external_single_structure([
                                    'id' => new external_value(PARAM_INT, 'Course ID'),
                                    'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                                    'enrolled_count' => new external_value(PARAM_INT, 'Number of enrolled students'),
                                ]),
                                'Courses under the matching category'
                            ),
                        ]),
                        'Levels within this trade'
                    ),
                ]),
                'Trades at this school'
            ),
        ]);
    }

    // =========================================================================
    // get_enrollment_coverage — Platform-wide enrollment coverage report.
    // =========================================================================

    /**
     * Returns description of get_enrollment_coverage parameters.
     *
     * @return external_function_parameters
     */
    public static function get_enrollment_coverage_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get enrollment coverage report showing all trade:level combos and their mapping status.
     *
     * For each distinct (program_code, class_grade) combination found in SDMS student data,
     * checks if a matching Moodle category exists (by idnumber = "{code}:{level}"),
     * counts courses under matched categories, and compares SDMS student counts
     * with actual Moodle enrollments.
     *
     * @return array Enrollment coverage report data.
     */
    public static function get_enrollment_coverage(): array {
        global $DB;

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        // Get all distinct (program_code, class_grade) combos with student counts.
        $sql = "SELECT s.program_code, s.program, s.class_grade,
                       COUNT(DISTINCT su.userid) AS sdms_student_count
                  FROM {elby_students} s
                  JOIN {elby_sdms_users} su ON su.id = s.sdms_userid
                 WHERE s.program_code IS NOT NULL
                   AND s.class_grade IS NOT NULL
                   AND su.user_type = 'student'
              GROUP BY s.program_code, s.program, s.class_grade
              ORDER BY s.program_code, s.class_grade";
        $combos = $DB->get_records_sql($sql);

        // Get student role ID.
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);

        $entries = [];
        $totalmapped = 0;
        $totalunmapped = 0;
        $totalsdmsstudents = 0;
        $totalenrolled = 0;

        foreach ($combos as $combo) {
            // Extract level number from class_grade.
            if (!preg_match('/(\d+)/', $combo->class_grade, $m)) {
                continue;
            }
            $levelnumber = (int) $m[1];
            $lookupkey = $combo->program_code . ':' . $levelnumber;

            $sdmscount = (int) $combo->sdms_student_count;
            $totalsdmsstudents += $sdmscount;

            // Check for matching Moodle category.
            $category = $DB->get_record('course_categories', ['idnumber' => $lookupkey], 'id, name');

            $categoryid = 0;
            $categoryname = '';
            $coursecount = 0;
            $enrolledcount = 0;
            $coveragestatus = 'unmapped';

            if ($category) {
                $categoryid = (int) $category->id;
                $categoryname = $category->name;

                // Count courses under this category (including subcategories).
                $catobj = \core_course_category::get($category->id, \IGNORE_MISSING);
                if ($catobj) {
                    $courses = $catobj->get_courses(['recursive' => true]);
                    $coursecount = count($courses);

                    // Count enrolled students across all courses.
                    foreach ($courses as $course) {
                        if ($studentroleid) {
                            $coursecontext = context_course::instance($course->id, \IGNORE_MISSING);
                            if ($coursecontext) {
                                $enrolledcount += (int) $DB->count_records_sql(
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

                if ($coursecount > 0 && $enrolledcount > 0) {
                    $coveragestatus = 'mapped';
                } else {
                    $coveragestatus = 'partial';
                }
                $totalmapped++;
            } else {
                $totalunmapped++;
            }

            $totalenrolled += $enrolledcount;

            // Build level name from class_grade.
            $levelname = 'Level ' . $levelnumber;

            $entries[] = [
                'combination_code' => $combo->program_code,
                'combination_name' => $combo->program ?? $combo->program_code,
                'level_number' => $levelnumber,
                'level_name' => $levelname,
                'sdms_student_count' => $sdmscount,
                'category_id' => $categoryid,
                'category_name' => $categoryname,
                'course_count' => $coursecount,
                'enrolled_student_count' => $enrolledcount,
                'coverage_status' => $coveragestatus,
            ];
        }

        return [
            'entries' => $entries,
            'summary' => [
                'total_combos' => count($entries),
                'mapped_combos' => $totalmapped,
                'unmapped_combos' => $totalunmapped,
                'total_sdms_students' => $totalsdmsstudents,
                'total_enrolled_students' => $totalenrolled,
            ],
        ];
    }

    /**
     * Returns description of get_enrollment_coverage return value.
     *
     * @return external_single_structure
     */
    public static function get_enrollment_coverage_returns(): external_single_structure {
        return new external_single_structure([
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'combination_code' => new external_value(PARAM_TEXT, 'Trade/combination code'),
                    'combination_name' => new external_value(PARAM_TEXT, 'Trade/combination name'),
                    'level_number' => new external_value(PARAM_INT, 'Level number'),
                    'level_name' => new external_value(PARAM_TEXT, 'Level name'),
                    'sdms_student_count' => new external_value(PARAM_INT, 'Students with this combo in SDMS'),
                    'category_id' => new external_value(PARAM_INT, 'Matched category ID (0 if none)'),
                    'category_name' => new external_value(PARAM_TEXT, 'Category name'),
                    'course_count' => new external_value(PARAM_INT, 'Courses under category'),
                    'enrolled_student_count' => new external_value(PARAM_INT, 'Actually enrolled in those courses'),
                    'coverage_status' => new external_value(PARAM_TEXT, 'mapped, unmapped, or partial'),
                ]),
                'Coverage entries per trade:level combo'
            ),
            'summary' => new external_single_structure([
                'total_combos' => new external_value(PARAM_INT, 'Total trade:level combos'),
                'mapped_combos' => new external_value(PARAM_INT, 'Combos with matching categories'),
                'unmapped_combos' => new external_value(PARAM_INT, 'Combos without matching categories'),
                'total_sdms_students' => new external_value(PARAM_INT, 'Total students in SDMS'),
                'total_enrolled_students' => new external_value(PARAM_INT, 'Total enrolled in Moodle'),
            ]),
        ]);
    }

    /**
     * Returns description of get_all_courses_report return value.
     *
     * @return external_single_structure
     */
    public static function get_all_courses_report_returns(): external_single_structure {
        return new external_single_structure([
            'total_courses' => new external_value(PARAM_INT, 'Total number of courses'),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'course_name' => new external_value(PARAM_TEXT, 'Course name'),
                    'total_enrolled' => new external_value(PARAM_INT, 'Total enrolled students'),
                    'total_schools' => new external_value(PARAM_INT, 'Total number of schools'),
                    'overview_sections' => new external_multiple_structure(
                        new external_single_structure([
                            'section_number' => new external_value(PARAM_INT, 'Section/Unit number'),
                            'section_name' => new external_value(PARAM_TEXT, 'Section/Unit name'),
                            'completion_rate' => new external_value(PARAM_FLOAT, 'Overall completion rate (0-100)'),
                        ]),
                        'Overview completion rates per section'
                    ),
                    'schools' => new external_multiple_structure(
                        new external_single_structure([
                            'school_code' => new external_value(PARAM_TEXT, 'School code'),
                            'school_name' => new external_value(PARAM_TEXT, 'School name'),
                            'student_count' => new external_value(PARAM_INT, 'Number of students'),
                            'sections' => new external_multiple_structure(
                                new external_single_structure([
                                    'section_number' => new external_value(PARAM_INT, 'Section number'),
                                    'section_name' => new external_value(PARAM_TEXT, 'Section name'),
                                    'completion_rate' => new external_value(PARAM_FLOAT, 'Completion rate'),
                                    'average_grade' => new external_value(PARAM_FLOAT, 'Average grade'),
                                ]),
                                'Per-section statistics'
                            ),
                        ]),
                        'Statistics grouped by school'
                    ),
                ]),
                'Course reports'
            ),
        ]);
    }
}
