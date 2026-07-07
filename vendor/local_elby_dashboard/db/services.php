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
 * Web service definitions for the Elby Dashboard plugin.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // Completion statistics APIs.
    'local_elby_dashboard_get_course_completion_stats' => [
        'classname'    => 'local_elby_dashboard\external\completion',
        'methodname'   => 'get_course_completion_stats',
        'description'  => 'Get completion statistics for a course including section and activity breakdown',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:view',
    ],
    'local_elby_dashboard_get_category_completion_stats' => [
        'classname'    => 'local_elby_dashboard\external\completion',
        'methodname'   => 'get_category_completion_stats',
        'description'  => 'Get completion statistics for all courses in a category (including subcategories)',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:view',
    ],

    // Course report by school APIs.
    'local_elby_dashboard_get_course_report_by_school' => [
        'classname'    => 'local_elby_dashboard\external\course_report',
        'methodname'   => 'get_course_report_by_school',
        'description'  => 'Get course report data grouped by school code with completion rates and grades per section',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_all_courses_report' => [
        'classname'    => 'local_elby_dashboard\external\course_report',
        'methodname'   => 'get_all_courses_report',
        'description'  => 'Get report data for all courses grouped by school code',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_school_courses_report' => [
        'classname'    => 'local_elby_dashboard\external\course_report',
        'methodname'   => 'get_school_courses_report',
        'description'  => 'Get per-school report on trades, levels, and their Moodle courses',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],

    // SDMS integration APIs.
    'local_elby_dashboard_get_user_sdms_profile' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'get_user_sdms_profile',
        'description'  => 'Get user SDMS profile from local cache (refreshes if stale)',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:view',
    ],
    'local_elby_dashboard_get_school_info' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'get_school_info',
        'description'  => 'Get school information with hierarchy from cache (syncs on miss)',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_lookup_sdms_user' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'lookup_sdms_user',
        'description'  => 'Live lookup of SDMS user data with school hierarchy (no caching)',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],
    'local_elby_dashboard_link_user' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'link_user',
        'description'  => 'Link a new SDMS user to a Moodle account',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],
    'local_elby_dashboard_refresh_user' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'refresh_user',
        'description'  => 'Force refresh cached SDMS data for an existing linked user',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],
    'local_elby_dashboard_sync_school_now' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'sync_school_now',
        'description'  => 'Force sync a school from SDMS API',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],

    // Metrics APIs.
    'local_elby_dashboard_get_school_metrics' => [
        'classname'    => 'local_elby_dashboard\external\metrics',
        'methodname'   => 'get_school_metrics',
        'description'  => 'Get aggregated school metrics for the most recent period',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_student_list' => [
        'classname'    => 'local_elby_dashboard\external\metrics',
        'methodname'   => 'get_student_list',
        'description'  => 'Get paginated student list with engagement metrics',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_engagement_distribution' => [
        'classname'    => 'local_elby_dashboard\external\metrics',
        'methodname'   => 'get_engagement_distribution',
        'description'  => 'Get engagement distribution breakdown for a school',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],

    // Trades report API.
    'local_elby_dashboard_get_trades_report' => [
        'classname'    => 'local_elby_dashboard\external\metrics',
        'methodname'   => 'get_trades_report',
        'description'  => 'Get trades/programs report with school counts',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_school_user_counts' => [
        'classname'    => 'local_elby_dashboard\external\metrics',
        'methodname'   => 'get_school_user_counts',
        'description'  => 'Get student and teacher counts per school',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_school_demographics' => [
        'classname'    => 'local_elby_dashboard\external\metrics',
        'methodname'   => 'get_school_demographics',
        'description'  => 'Get school demographics: gender breakdown and age distribution',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_trigger_task' => [
        'classname'    => 'local_elby_dashboard\external\metrics',
        'methodname'   => 'trigger_task',
        'description'  => 'Trigger a scheduled task to run immediately',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],

    // Platform traffic API.
    'local_elby_dashboard_get_platform_traffic' => [
        'classname'    => 'local_elby_dashboard\external\traffic',
        'methodname'   => 'get_platform_traffic',
        'description'  => 'Get platform traffic data grouped by period',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],

    'local_elby_dashboard_get_traffic_heatmap' => [
        'classname'    => 'local_elby_dashboard\external\traffic',
        'methodname'   => 'get_traffic_heatmap',
        'description'  => 'Get traffic heatmap data (day of week x hour of day)',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_top_active_users' => [
        'classname'    => 'local_elby_dashboard\external\traffic',
        'methodname'   => 'get_top_active_users',
        'description'  => 'Get top active users ranked by total actions',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_traffic_by_school' => [
        'classname'    => 'local_elby_dashboard\external\traffic',
        'methodname'   => 'get_traffic_by_school',
        'description'  => 'Get traffic data grouped by school',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_traffic_action_breakdown' => [
        'classname'    => 'local_elby_dashboard\external\traffic',
        'methodname'   => 'get_traffic_action_breakdown',
        'description'  => 'Get traffic action breakdown by component type',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],

    // User access log API.
    'local_elby_dashboard_get_user_access_log' => [
        'classname'    => 'local_elby_dashboard\external\access_log',
        'methodname'   => 'get_user_access_log',
        'description'  => 'Get paginated user access log with filters',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],

    // RISE recruitment dashboard APIs (proxy to the external RISE API).
    'local_elby_dashboard_rise_get_campaigns' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'get_campaigns',
        'description'  => 'Get the list of RISE campaigns',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_rise_get_applicants' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'get_applicants',
        'description'  => 'Get a paginated, filtered list of applicants for a RISE campaign',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_rise_get_reviews' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'get_reviews',
        'description'  => 'Get saved NESA reviews for a RISE campaign, keyed by applicant id',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_rise_get_nesa_stats' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'get_nesa_stats',
        'description'  => 'Get NESA review decision counts per RISE campaign',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_rise_save_review' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'save_review',
        'description'  => 'Save a NESA eligibility review for a RISE learner',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_rise_validate_nid' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'validate_nid',
        'description'  => 'Validate a RISE learner National ID against TMIS/NIDA',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_rise_get_user_status' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'get_user_status',
        'description'  => 'Resolve Moodle account status for a batch of RISE applicants',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_rise_create_user' => [
        'classname'    => 'local_elby_dashboard\external\rise',
        'methodname'   => 'create_user',
        'description'  => 'Provision the Moodle account for a RISE learner (identity re-fetched server-side)',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manageriseusers',
    ],

    // SDMS self-link API (for logged-in users linking their own account).
    'local_elby_dashboard_self_link_sdms' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'self_link_sdms',
        'description'  => 'Link the current user\'s account to an SDMS record',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:view',
    ],

    // Admin school override for teachers.
    'local_elby_dashboard_update_user_school' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'update_user_school',
        'description'  => 'Manually set a user\'s school code (admin override)',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],
    'local_elby_dashboard_get_schools_list' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'get_schools_list',
        'description'  => 'Get all schools for dropdown selection',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],

    // Enrollment coverage report API.
    'local_elby_dashboard_get_enrollment_coverage' => [
        'classname'    => 'local_elby_dashboard\external\course_report',
        'methodname'   => 'get_enrollment_coverage',
        'description'  => 'Get enrollment coverage report showing trade:level mapping status and gaps',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],

    // Enrollment logs API.
    'local_elby_dashboard_get_enrollment_logs' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'get_enrollment_logs',
        'description'  => 'Get paginated auto-enrollment logs with summary',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],

    // Admin search for unlinked users.
    'local_elby_dashboard_search_unlinked_users' => [
        'classname'    => 'local_elby_dashboard\external\sdms',
        'methodname'   => 'search_unlinked_users',
        'description'  => 'Search for Moodle users not linked to SDMS',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:manage',
    ],

    // Blended Learning APIs.
    'local_elby_dashboard_get_blended_learning_metrics' => [
        'classname'    => 'local_elby_dashboard\external\blended_learning',
        'methodname'   => 'get_blended_learning_metrics',
        'description'  => 'Get blended learning metrics for the configured parent category',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_blended_learning_schools' => [
        'classname'    => 'local_elby_dashboard\external\blended_learning',
        'methodname'   => 'get_blended_learning_schools',
        'description'  => 'Get schools participating in blended learning courses',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_blended_learning_students' => [
        'classname'    => 'local_elby_dashboard\external\blended_learning',
        'methodname'   => 'get_blended_learning_students',
        'description'  => 'Get paginated student list enrolled in blended learning courses',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],
    'local_elby_dashboard_get_blended_learning_teachers' => [
        'classname'    => 'local_elby_dashboard\external\blended_learning',
        'methodname'   => 'get_blended_learning_teachers',
        'description'  => 'Get paginated teacher list assigned to blended learning courses',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/elby_dashboard:viewreports',
    ],

    // SDMS self-registration APIs (no login required).
    'local_elby_dashboard_lookup_for_signup' => [
        'classname'     => 'local_elby_dashboard\external\signup',
        'methodname'    => 'lookup_for_signup',
        'description'   => 'Look up SDMS user for self-registration (no login required)',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => false,
    ],
    'local_elby_dashboard_register_sdms_user' => [
        'classname'     => 'local_elby_dashboard\external\signup',
        'methodname'    => 'register_sdms_user',
        'description'   => 'Register a new Moodle user from SDMS data (no login required)',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => false,
    ],
];

$services = [
    'Elby Dashboard Service' => [
        'functions' => [
            'local_elby_dashboard_get_course_completion_stats',
            'local_elby_dashboard_get_category_completion_stats',
            'local_elby_dashboard_get_course_report_by_school',
            'local_elby_dashboard_get_all_courses_report',
            'local_elby_dashboard_get_school_courses_report',
            'local_elby_dashboard_get_user_sdms_profile',
            'local_elby_dashboard_get_school_info',
            'local_elby_dashboard_lookup_sdms_user',
            'local_elby_dashboard_link_user',
            'local_elby_dashboard_refresh_user',
            'local_elby_dashboard_sync_school_now',
            'local_elby_dashboard_get_school_metrics',
            'local_elby_dashboard_get_student_list',
            'local_elby_dashboard_get_engagement_distribution',
            'local_elby_dashboard_get_trades_report',
            'local_elby_dashboard_get_school_user_counts',
            'local_elby_dashboard_get_school_demographics',
            'local_elby_dashboard_trigger_task',
            'local_elby_dashboard_get_platform_traffic',
            'local_elby_dashboard_get_traffic_heatmap',
            'local_elby_dashboard_get_top_active_users',
            'local_elby_dashboard_get_traffic_by_school',
            'local_elby_dashboard_get_traffic_action_breakdown',
            'local_elby_dashboard_get_user_access_log',
            'local_elby_dashboard_self_link_sdms',
            'local_elby_dashboard_search_unlinked_users',
            'local_elby_dashboard_update_user_school',
            'local_elby_dashboard_get_schools_list',
            'local_elby_dashboard_get_enrollment_coverage',
            'local_elby_dashboard_get_enrollment_logs',
            'local_elby_dashboard_lookup_for_signup',
            'local_elby_dashboard_register_sdms_user',
            'local_elby_dashboard_get_blended_learning_metrics',
            'local_elby_dashboard_get_blended_learning_schools',
            'local_elby_dashboard_get_blended_learning_students',
            'local_elby_dashboard_get_blended_learning_teachers',
            'local_elby_dashboard_rise_get_campaigns',
            'local_elby_dashboard_rise_get_applicants',
            'local_elby_dashboard_rise_get_reviews',
            'local_elby_dashboard_rise_get_nesa_stats',
            'local_elby_dashboard_rise_save_review',
            'local_elby_dashboard_rise_validate_nid',
            'local_elby_dashboard_rise_get_user_status',
            'local_elby_dashboard_rise_create_user',
        ],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'elby_dashboard',
    ],
];
