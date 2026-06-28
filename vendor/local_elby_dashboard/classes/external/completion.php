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
 * External API for course completion statistics.
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
use context_coursecat;
use core_course_category;

/**
 * External API for course completion statistics.
 */
class completion extends external_api {

    /**
     * Returns description of get_course_completion_stats parameters.
     *
     * @return external_function_parameters
     */
    public static function get_course_completion_stats_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get completion statistics for a course.
     *
     * @param int $courseid Course ID
     * @return array Completion statistics with sections and activities
     */
    public static function get_course_completion_stats(int $courseid): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(
            self::get_course_completion_stats_parameters(),
            ['courseid' => $courseid]
        );
        $courseid = $params['courseid'];

        // Get course and validate it exists.
        $course = get_course($courseid);
        $context = context_course::instance($course->id);

        // Validate context.
        self::validate_context($context);

        // Check capability.
        require_capability('local/elby_dashboard:view', $context);

        // Get total enrolled users.
        $totalparticipants = count_enrolled_users($context);

        // Get course completions count.
        $completedparticipants = $DB->count_records_select(
            'course_completions',
            'course = :courseid AND timecompleted IS NOT NULL',
            ['courseid' => $courseid]
        );

        // Calculate completion rate.
        $completionrate = $totalparticipants > 0
            ? round(($completedparticipants / $totalparticipants) * 100, 2)
            : 0.0;

        // Get course module info for activity names.
        $modinfo = get_fast_modinfo($course);

        // Get all visible sections with their activities.
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            if (!$sectioninfo->visible) {
                continue;
            }

            // Get course modules in this section.
            $sectioncms = $modinfo->sections[$sectioninfo->section] ?? [];
            if (empty($sectioncms)) {
                continue; // Skip empty sections.
            }

            $activities = [];
            $sectioncompletionsum = 0;
            $sectionactivitiescount = 0;

            foreach ($sectioncms as $cmid) {
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm || !$cm->visible || !$cm->uservisible) {
                    continue;
                }

                // Get completion count for this activity.
                $completedcount = $DB->count_records_select(
                    'course_modules_completion',
                    'coursemoduleid = :cmid AND completionstate >= 1',
                    ['cmid' => $cm->id]
                );

                // Calculate activity completion rate.
                $activitycompletionrate = $totalparticipants > 0
                    ? round(($completedcount / $totalparticipants) * 100, 2)
                    : 0.0;

                $activities[] = [
                    'cmid' => $cm->id,
                    'name' => $cm->name,
                    'module_type' => $cm->modname,
                    'completion_enabled' => $cm->completion > 0,
                    'completed_count' => $completedcount,
                    'completion_rate' => $activitycompletionrate,
                ];

                $sectionactivitiescount++;
                $sectioncompletionsum += $activitycompletionrate;
            }

            if (empty($activities)) {
                continue; // Skip sections with no visible activities.
            }

            // Calculate section average completion.
            $sectioncompletionavg = $sectionactivitiescount > 0
                ? round($sectioncompletionsum / $sectionactivitiescount, 2)
                : 0.0;

            // Get section name (use default if empty).
            $sectionname = $sectioninfo->name;
            if (empty($sectionname)) {
                $sectionname = get_string('section') . ' ' . $sectioninfo->section;
            }

            $sections[] = [
                'id' => $sectioninfo->id,
                'name' => $sectionname,
                'section_number' => $sectioninfo->section,
                'total_activities' => $sectionactivitiescount,
                'completed_activities_avg' => $sectioncompletionavg,
                'activities' => $activities,
            ];
        }

        return [
            'courseid' => $courseid,
            'total_participants' => $totalparticipants,
            'completed_participants' => $completedparticipants,
            'completion_rate' => $completionrate,
            'sections' => $sections,
        ];
    }

    /**
     * Returns description of get_course_completion_stats return value.
     *
     * @return external_single_structure
     */
    public static function get_course_completion_stats_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'total_participants' => new external_value(PARAM_INT, 'Total enrolled participants'),
            'completed_participants' => new external_value(PARAM_INT, 'Participants who completed the course'),
            'completion_rate' => new external_value(PARAM_FLOAT, 'Course completion percentage (0-100)'),
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Section ID'),
                    'name' => new external_value(PARAM_TEXT, 'Section name'),
                    'section_number' => new external_value(PARAM_INT, 'Section number'),
                    'total_activities' => new external_value(PARAM_INT, 'Total activities in section'),
                    'completed_activities_avg' => new external_value(PARAM_FLOAT, 'Average activity completion percentage'),
                    'activities' => new external_multiple_structure(
                        new external_single_structure([
                            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                            'name' => new external_value(PARAM_TEXT, 'Activity name'),
                            'module_type' => new external_value(PARAM_ALPHANUMEXT, 'Module type (e.g., assign, quiz)'),
                            'completion_enabled' => new external_value(PARAM_BOOL, 'Whether completion tracking is enabled'),
                            'completed_count' => new external_value(PARAM_INT, 'Number of users who completed'),
                            'completion_rate' => new external_value(PARAM_FLOAT, 'Activity completion percentage (0-100)'),
                        ]),
                        'Activities in this section'
                    ),
                ]),
                'Course sections with activities'
            ),
        ]);
    }

    /**
     * Returns description of get_category_completion_stats parameters.
     *
     * @return external_function_parameters
     */
    public static function get_category_completion_stats_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category ID'),
        ]);
    }

    /**
     * Get completion statistics for all courses in a category.
     *
     * @param int $categoryid Category ID
     * @return array Completion statistics for all courses in the category
     */
    public static function get_category_completion_stats(int $categoryid): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(
            self::get_category_completion_stats_parameters(),
            ['categoryid' => $categoryid]
        );
        $categoryid = $params['categoryid'];

        // Get category and validate it exists.
        $category = core_course_category::get($categoryid);
        $context = context_coursecat::instance($categoryid);

        // Validate context.
        self::validate_context($context);

        // Check capability at category level.
        require_capability('local/elby_dashboard:view', $context);

        // Get all courses in this category (including subcategories).
        $courses = $category->get_courses(['recursive' => true]);

        // Aggregate stats.
        $totalcourses = 0;
        $totalparticipants = 0;
        $totalcompleted = 0;
        $coursesdata = [];

        foreach ($courses as $course) {
            // Skip the site course.
            if ($course->id == SITEID) {
                continue;
            }

            // Get course context.
            $coursecontext = context_course::instance($course->id);

            // Check if user can view this course.
            if (!has_capability('local/elby_dashboard:view', $coursecontext)) {
                continue;
            }

            // Get course stats using existing method logic.
            $coursestats = self::get_course_stats_internal($course);

            // Get category path for this course.
            $coursecategory = core_course_category::get($course->category);
            $categorypath = $coursecategory->get_nested_name(false);

            $coursesdata[] = [
                'courseid' => $course->id,
                'course_name' => $course->fullname,
                'category_path' => $categorypath,
                'total_participants' => $coursestats['total_participants'],
                'completed_participants' => $coursestats['completed_participants'],
                'completion_rate' => $coursestats['completion_rate'],
                'sections' => $coursestats['sections'],
            ];

            $totalcourses++;
            $totalparticipants += $coursestats['total_participants'];
            $totalcompleted += $coursestats['completed_participants'];
        }

        // Calculate overall completion rate.
        $overallrate = $totalparticipants > 0
            ? round(($totalcompleted / $totalparticipants) * 100, 2)
            : 0.0;

        return [
            'categoryid' => $categoryid,
            'category_name' => $category->name,
            'total_courses' => $totalcourses,
            'total_participants' => $totalparticipants,
            'completed_participants' => $totalcompleted,
            'completion_rate' => $overallrate,
            'courses' => $coursesdata,
        ];
    }

    /**
     * Internal method to get course completion stats without validation.
     *
     * @param object $course Course object
     * @return array Course completion statistics
     */
    private static function get_course_stats_internal(object $course): array {
        global $DB;

        $context = context_course::instance($course->id);

        // Get total enrolled users.
        $totalparticipants = count_enrolled_users($context);

        // Get course completions count.
        $completedparticipants = $DB->count_records_select(
            'course_completions',
            'course = :courseid AND timecompleted IS NOT NULL',
            ['courseid' => $course->id]
        );

        // Calculate completion rate.
        $completionrate = $totalparticipants > 0
            ? round(($completedparticipants / $totalparticipants) * 100, 2)
            : 0.0;

        // Get course module info for activity names.
        $modinfo = get_fast_modinfo($course);

        // Get all visible sections with their activities.
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            if (!$sectioninfo->visible) {
                continue;
            }

            // Get course modules in this section.
            $sectioncms = $modinfo->sections[$sectioninfo->section] ?? [];
            if (empty($sectioncms)) {
                continue;
            }

            $activities = [];
            $sectioncompletionsum = 0;
            $sectionactivitiescount = 0;

            foreach ($sectioncms as $cmid) {
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm || !$cm->visible) {
                    continue;
                }

                // Get completion count for this activity.
                $completedcount = $DB->count_records_select(
                    'course_modules_completion',
                    'coursemoduleid = :cmid AND completionstate >= 1',
                    ['cmid' => $cm->id]
                );

                // Calculate activity completion rate.
                $activitycompletionrate = $totalparticipants > 0
                    ? round(($completedcount / $totalparticipants) * 100, 2)
                    : 0.0;

                $activities[] = [
                    'cmid' => $cm->id,
                    'name' => $cm->name,
                    'module_type' => $cm->modname,
                    'completion_enabled' => $cm->completion > 0,
                    'completed_count' => $completedcount,
                    'completion_rate' => $activitycompletionrate,
                ];

                $sectionactivitiescount++;
                $sectioncompletionsum += $activitycompletionrate;
            }

            if (empty($activities)) {
                continue;
            }

            // Calculate section average completion.
            $sectioncompletionavg = $sectionactivitiescount > 0
                ? round($sectioncompletionsum / $sectionactivitiescount, 2)
                : 0.0;

            // Get section name (use default if empty).
            $sectionname = $sectioninfo->name;
            if (empty($sectionname)) {
                $sectionname = get_string('section') . ' ' . $sectioninfo->section;
            }

            $sections[] = [
                'id' => $sectioninfo->id,
                'name' => $sectionname,
                'section_number' => $sectioninfo->section,
                'total_activities' => $sectionactivitiescount,
                'completed_activities_avg' => $sectioncompletionavg,
                'activities' => $activities,
            ];
        }

        return [
            'total_participants' => $totalparticipants,
            'completed_participants' => $completedparticipants,
            'completion_rate' => $completionrate,
            'sections' => $sections,
        ];
    }

    /**
     * Returns description of get_category_completion_stats return value.
     *
     * @return external_single_structure
     */
    public static function get_category_completion_stats_returns(): external_single_structure {
        return new external_single_structure([
            'categoryid' => new external_value(PARAM_INT, 'Category ID'),
            'category_name' => new external_value(PARAM_TEXT, 'Category name'),
            'total_courses' => new external_value(PARAM_INT, 'Total courses in category'),
            'total_participants' => new external_value(PARAM_INT, 'Total participants across all courses'),
            'completed_participants' => new external_value(PARAM_INT, 'Total completions across all courses'),
            'completion_rate' => new external_value(PARAM_FLOAT, 'Overall completion percentage (0-100)'),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'course_name' => new external_value(PARAM_TEXT, 'Course name'),
                    'category_path' => new external_value(PARAM_TEXT, 'Category hierarchy path'),
                    'total_participants' => new external_value(PARAM_INT, 'Total enrolled participants'),
                    'completed_participants' => new external_value(PARAM_INT, 'Participants who completed the course'),
                    'completion_rate' => new external_value(PARAM_FLOAT, 'Course completion percentage (0-100)'),
                    'sections' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Section ID'),
                            'name' => new external_value(PARAM_TEXT, 'Section name'),
                            'section_number' => new external_value(PARAM_INT, 'Section number'),
                            'total_activities' => new external_value(PARAM_INT, 'Total activities in section'),
                            'completed_activities_avg' => new external_value(PARAM_FLOAT, 'Average activity completion percentage'),
                            'activities' => new external_multiple_structure(
                                new external_single_structure([
                                    'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                                    'name' => new external_value(PARAM_TEXT, 'Activity name'),
                                    'module_type' => new external_value(PARAM_ALPHANUMEXT, 'Module type'),
                                    'completion_enabled' => new external_value(PARAM_BOOL, 'Whether completion tracking is enabled'),
                                    'completed_count' => new external_value(PARAM_INT, 'Number of users who completed'),
                                    'completion_rate' => new external_value(PARAM_FLOAT, 'Activity completion percentage (0-100)'),
                                ]),
                                'Activities in this section'
                            ),
                        ]),
                        'Course sections with activities'
                    ),
                ]),
                'Courses in this category'
            ),
        ]);
    }
}
