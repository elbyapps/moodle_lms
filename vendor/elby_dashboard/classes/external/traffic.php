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
 * External API for platform traffic data.
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
 * External API for platform traffic data.
 */
class traffic extends external_api {

    /**
     * Parameters for get_platform_traffic.
     */
    public static function get_platform_traffic_parameters(): external_function_parameters {
        return new external_function_parameters([
            'period' => new external_value(PARAM_TEXT, 'Period: daily, weekly, or monthly', VALUE_DEFAULT, 'daily'),
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back (max 365)', VALUE_DEFAULT, 30),
            'from_date' => new external_value(PARAM_INT, 'Start timestamp (overrides days_back when > 0)', VALUE_DEFAULT, 0),
            'to_date' => new external_value(PARAM_INT, 'End timestamp (defaults to now)', VALUE_DEFAULT, 0),
            'school_code' => new external_value(PARAM_TEXT, 'Filter by school code', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get platform traffic data grouped by period.
     *
     * @param string $period Period grouping: daily, weekly, or monthly.
     * @param int $daysback Number of days to look back.
     * @param int $fromdate Start timestamp (overrides days_back when > 0).
     * @param int $todate End timestamp (defaults to now).
     * @return array Traffic data.
     */
    public static function get_platform_traffic(
        string $period = 'daily',
        int $daysback = 30,
        int $fromdate = 0,
        int $todate = 0,
        string $schoolcode = ''
    ): array {
        global $DB, $CFG;

        $params = self::validate_parameters(
            self::get_platform_traffic_parameters(),
            ['period' => $period, 'days_back' => $daysback, 'from_date' => $fromdate,
             'to_date' => $todate, 'school_code' => $schoolcode]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $period = in_array($params['period'], ['daily', 'weekly', 'monthly']) ? $params['period'] : 'daily';

        if ($params['from_date'] > 0) {
            $starttime = $params['from_date'];
            $endtime = $params['to_date'] > 0 ? $params['to_date'] : time();
        } else {
            $daysback = min(365, max(1, $params['days_back']));
            $starttime = time() - ($daysback * 86400);
            $endtime = time();
        }

        // Build DB-family-specific date formatting.
        $dbfamily = $DB->get_dbfamily();
        if ($dbfamily === 'postgres') {
            switch ($period) {
                case 'weekly':
                    $dateexpr = "TO_CHAR(DATE_TRUNC('week', TO_TIMESTAMP(log.timecreated)), 'YYYY-MM-DD')";
                    break;
                case 'monthly':
                    $dateexpr = "TO_CHAR(DATE_TRUNC('month', TO_TIMESTAMP(log.timecreated)), 'YYYY-MM')";
                    break;
                default: // daily.
                    $dateexpr = "TO_CHAR(TO_TIMESTAMP(log.timecreated), 'YYYY-MM-DD')";
            }
        } else {
            // MariaDB / MySQL.
            switch ($period) {
                case 'weekly':
                    $dateexpr = "DATE_FORMAT(FROM_UNIXTIME(log.timecreated), '%x-W%v')";
                    break;
                case 'monthly':
                    $dateexpr = "DATE_FORMAT(FROM_UNIXTIME(log.timecreated), '%Y-%m')";
                    break;
                default: // daily.
                    $dateexpr = "DATE_FORMAT(FROM_UNIXTIME(log.timecreated), '%Y-%m-%d')";
            }
        }

        $joinsql = '';
        $schoolwhere = '';
        $sqlparams = ['starttime' => $starttime, 'endtime' => $endtime];
        if (!empty($params['school_code'])) {
            $joinsql = " JOIN {elby_sdms_users} su ON su.userid = log.userid
                         JOIN {elby_schools} sch ON sch.id = su.schoolid";
            $schoolwhere = " AND sch.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        $sql = "SELECT {$dateexpr} AS period_label,
                       COUNT(*) AS total_actions,
                       COUNT(DISTINCT log.userid) AS unique_users,
                       MIN(log.timecreated) AS period_start
                FROM {logstore_standard_log} log
                {$joinsql}
                WHERE log.timecreated >= :starttime AND log.timecreated <= :endtime
                  AND log.userid > 0 AND log.anonymous = 0{$schoolwhere}
                GROUP BY period_label
                ORDER BY period_start ASC";

        $records = $DB->get_records_sql($sql, $sqlparams);

        $data = [];
        foreach ($records as $rec) {
            $data[] = [
                'period_label' => $rec->period_label,
                'total_actions' => (int) $rec->total_actions,
                'unique_users' => (int) $rec->unique_users,
                'period_start' => (int) $rec->period_start,
            ];
        }

        return ['data' => $data];
    }

    /**
     * Return structure for get_platform_traffic.
     */
    public static function get_platform_traffic_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_multiple_structure(
                new external_single_structure([
                    'period_label' => new external_value(PARAM_TEXT, 'Period label'),
                    'total_actions' => new external_value(PARAM_INT, 'Total actions in period'),
                    'unique_users' => new external_value(PARAM_INT, 'Unique users in period'),
                    'period_start' => new external_value(PARAM_INT, 'Period start timestamp'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // get_traffic_heatmap — Activity by day-of-week and hour-of-day.
    // =========================================================================

    public static function get_traffic_heatmap_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back', VALUE_DEFAULT, 30),
            'school_code' => new external_value(PARAM_TEXT, 'Filter by school code', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_traffic_heatmap(int $daysback = 30, string $schoolcode = ''): array {
        global $DB;

        $params = self::validate_parameters(self::get_traffic_heatmap_parameters(),
            ['days_back' => $daysback, 'school_code' => $schoolcode]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $daysback = min(365, max(1, $params['days_back']));
        $starttime = time() - ($daysback * 86400);
        $endtime = time();

        $dbfamily = $DB->get_dbfamily();
        if ($dbfamily === 'postgres') {
            $dowexpr = "((EXTRACT(DOW FROM TO_TIMESTAMP(log.timecreated))::int + 6) % 7)";
            $hourexpr = "EXTRACT(HOUR FROM TO_TIMESTAMP(log.timecreated))::int";
        } else {
            $dowexpr = "((DAYOFWEEK(FROM_UNIXTIME(log.timecreated)) + 5) % 7)";
            $hourexpr = "HOUR(FROM_UNIXTIME(log.timecreated))";
        }

        $joinsql = '';
        $schoolwhere = '';
        $sqlparams = ['starttime' => $starttime, 'endtime' => $endtime];
        if (!empty($params['school_code'])) {
            $joinsql = " JOIN {elby_sdms_users} su ON su.userid = log.userid
                         JOIN {elby_schools} sch ON sch.id = su.schoolid";
            $schoolwhere = " AND sch.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        $sql = "SELECT {$dowexpr} AS day_of_week,
                       {$hourexpr} AS hour_of_day,
                       COUNT(*) AS action_count
                FROM {logstore_standard_log} log
                {$joinsql}
                WHERE log.timecreated >= :starttime AND log.timecreated <= :endtime
                  AND log.userid > 0 AND log.anonymous = 0{$schoolwhere}
                GROUP BY {$dowexpr}, {$hourexpr}
                ORDER BY day_of_week, hour_of_day";

        $records = $DB->get_records_sql($sql, $sqlparams);
        $data = [];
        foreach ($records as $rec) {
            $data[] = [
                'day_of_week' => (int) $rec->day_of_week,
                'hour_of_day' => (int) $rec->hour_of_day,
                'action_count' => (int) $rec->action_count,
            ];
        }
        return ['data' => $data];
    }

    public static function get_traffic_heatmap_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_multiple_structure(
                new external_single_structure([
                    'day_of_week' => new external_value(PARAM_INT, 'Day of week (0=Mon..6=Sun)'),
                    'hour_of_day' => new external_value(PARAM_INT, 'Hour of day (0-23)'),
                    'action_count' => new external_value(PARAM_INT, 'Number of actions'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // get_top_active_users — Most active users ranked by total actions.
    // =========================================================================

    public static function get_top_active_users_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back', VALUE_DEFAULT, 30),
            'limit_count' => new external_value(PARAM_INT, 'Max users to return', VALUE_DEFAULT, 10),
            'school_code' => new external_value(PARAM_TEXT, 'Filter by school code', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_top_active_users(int $daysback = 30, int $limitcount = 10,
            string $schoolcode = ''): array {
        global $DB;

        $params = self::validate_parameters(self::get_top_active_users_parameters(),
            ['days_back' => $daysback, 'limit_count' => $limitcount, 'school_code' => $schoolcode]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $daysback = min(365, max(1, $params['days_back']));
        $limitcount = min(50, max(1, $params['limit_count']));
        $starttime = time() - ($daysback * 86400);
        $endtime = time();

        $dbfamily = $DB->get_dbfamily();
        $dateexpr = ($dbfamily === 'postgres')
            ? "DATE(TO_TIMESTAMP(log.timecreated))"
            : "DATE(FROM_UNIXTIME(log.timecreated))";

        $schooljoin = '';
        $schoolwhere = '';
        $sqlparams = ['starttime' => $starttime, 'endtime' => $endtime];
        if (!empty($params['school_code'])) {
            $schoolwhere = " AND sch.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        $sql = "SELECT log.userid,
                       u.firstname, u.lastname,
                       COALESCE(sch.school_name, '') AS school_name,
                       COALESCE(sch.school_code, '') AS school_code,
                       COALESCE(su.user_type, '') AS user_type,
                       COUNT(*) AS total_actions,
                       COUNT(DISTINCT {$dateexpr}) AS active_days
                FROM {logstore_standard_log} log
                JOIN {user} u ON u.id = log.userid
                LEFT JOIN {elby_sdms_users} su ON su.userid = log.userid
                LEFT JOIN {elby_schools} sch ON sch.id = su.schoolid
                WHERE log.timecreated >= :starttime AND log.timecreated <= :endtime
                  AND log.userid > 0 AND log.anonymous = 0 AND u.deleted = 0{$schoolwhere}
                GROUP BY log.userid, u.firstname, u.lastname, sch.school_name, sch.school_code, su.user_type
                ORDER BY total_actions DESC";

        $records = $DB->get_records_sql($sql, $sqlparams, 0, $limitcount);
        $data = [];
        foreach ($records as $rec) {
            $data[] = [
                'userid' => (int) $rec->userid,
                'fullname' => $rec->firstname . ' ' . $rec->lastname,
                'school_name' => $rec->school_name,
                'school_code' => $rec->school_code,
                'user_type' => $rec->user_type,
                'total_actions' => (int) $rec->total_actions,
                'active_days' => (int) $rec->active_days,
            ];
        }
        return ['data' => $data];
    }

    public static function get_top_active_users_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'user_type' => new external_value(PARAM_TEXT, 'User type (student/teacher)'),
                    'total_actions' => new external_value(PARAM_INT, 'Total actions'),
                    'active_days' => new external_value(PARAM_INT, 'Active days'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // get_traffic_by_school — Traffic grouped by school.
    // =========================================================================

    public static function get_traffic_by_school_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back', VALUE_DEFAULT, 30),
            'limit_count' => new external_value(PARAM_INT, 'Max schools to return', VALUE_DEFAULT, 10),
        ]);
    }

    public static function get_traffic_by_school(int $daysback = 30, int $limitcount = 10): array {
        global $DB;

        $params = self::validate_parameters(self::get_traffic_by_school_parameters(),
            ['days_back' => $daysback, 'limit_count' => $limitcount]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $daysback = min(365, max(1, $params['days_back']));
        $limitcount = min(50, max(1, $params['limit_count']));
        $starttime = time() - ($daysback * 86400);
        $endtime = time();

        $sql = "SELECT sch.school_code, sch.school_name,
                       COUNT(*) AS total_actions,
                       COUNT(DISTINCT log.userid) AS unique_users
                FROM {logstore_standard_log} log
                JOIN {elby_sdms_users} su ON su.userid = log.userid
                JOIN {elby_schools} sch ON sch.id = su.schoolid
                WHERE log.timecreated >= :starttime AND log.timecreated <= :endtime
                  AND log.userid > 0 AND log.anonymous = 0
                GROUP BY sch.school_code, sch.school_name
                ORDER BY total_actions DESC";

        $records = $DB->get_records_sql($sql, ['starttime' => $starttime, 'endtime' => $endtime],
            0, $limitcount);
        $data = [];
        foreach ($records as $rec) {
            $data[] = [
                'school_code' => $rec->school_code,
                'school_name' => $rec->school_name,
                'total_actions' => (int) $rec->total_actions,
                'unique_users' => (int) $rec->unique_users,
            ];
        }
        return ['data' => $data];
    }

    public static function get_traffic_by_school_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_multiple_structure(
                new external_single_structure([
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'total_actions' => new external_value(PARAM_INT, 'Total actions'),
                    'unique_users' => new external_value(PARAM_INT, 'Unique users'),
                ])
            ),
        ]);
    }

    // =========================================================================
    // get_traffic_action_breakdown — Actions grouped by component type.
    // =========================================================================

    public static function get_traffic_action_breakdown_parameters(): external_function_parameters {
        return new external_function_parameters([
            'days_back' => new external_value(PARAM_INT, 'Number of days to look back', VALUE_DEFAULT, 30),
            'school_code' => new external_value(PARAM_TEXT, 'Filter by school code', VALUE_DEFAULT, ''),
        ]);
    }

    public static function get_traffic_action_breakdown(int $daysback = 30, string $schoolcode = ''): array {
        global $DB;

        $params = self::validate_parameters(self::get_traffic_action_breakdown_parameters(),
            ['days_back' => $daysback, 'school_code' => $schoolcode]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        $daysback = min(365, max(1, $params['days_back']));
        $starttime = time() - ($daysback * 86400);
        $endtime = time();

        $joinsql = '';
        $schoolwhere = '';
        $sqlparams = ['starttime' => $starttime, 'endtime' => $endtime];
        if (!empty($params['school_code'])) {
            $joinsql = " JOIN {elby_sdms_users} su ON su.userid = log.userid
                         JOIN {elby_schools} sch ON sch.id = su.schoolid";
            $schoolwhere = " AND sch.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        $sql = "SELECT log.component, COUNT(*) AS action_count
                FROM {logstore_standard_log} log
                {$joinsql}
                WHERE log.timecreated >= :starttime AND log.timecreated <= :endtime
                  AND log.userid > 0 AND log.anonymous = 0{$schoolwhere}
                GROUP BY log.component
                ORDER BY action_count DESC";

        $records = $DB->get_records_sql($sql, $sqlparams);

        $labelmap = [
            'mod_quiz' => 'Quiz', 'mod_assign' => 'Assignment', 'mod_forum' => 'Forum',
            'mod_resource' => 'Resource', 'mod_page' => 'Page', 'mod_url' => 'URL',
            'mod_folder' => 'Folder', 'mod_book' => 'Book', 'mod_hvp' => 'Interactive Content',
            'mod_choice' => 'Choice', 'mod_feedback' => 'Feedback', 'mod_glossary' => 'Glossary',
            'mod_wiki' => 'Wiki', 'mod_chat' => 'Chat', 'mod_lesson' => 'Lesson',
            'mod_workshop' => 'Workshop', 'mod_data' => 'Database', 'mod_scorm' => 'SCORM',
            'mod_lti' => 'External Tool', 'core' => 'System',
        ];

        $data = [];
        foreach ($records as $rec) {
            $component = $rec->component;
            if (isset($labelmap[$component])) {
                $label = $labelmap[$component];
            } else {
                // Derive label: strip prefix, ucfirst.
                $label = $component;
                foreach (['mod_', 'local_', 'tool_', 'core_', 'report_'] as $prefix) {
                    if (strpos($label, $prefix) === 0) {
                        $label = substr($label, strlen($prefix));
                        break;
                    }
                }
                $label = ucfirst(str_replace('_', ' ', $label));
            }
            $data[] = [
                'component' => $component,
                'label' => $label,
                'action_count' => (int) $rec->action_count,
            ];
        }
        return ['data' => $data];
    }

    public static function get_traffic_action_breakdown_returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_multiple_structure(
                new external_single_structure([
                    'component' => new external_value(PARAM_TEXT, 'Component name'),
                    'label' => new external_value(PARAM_TEXT, 'Friendly label'),
                    'action_count' => new external_value(PARAM_INT, 'Number of actions'),
                ])
            ),
        ]);
    }
}
