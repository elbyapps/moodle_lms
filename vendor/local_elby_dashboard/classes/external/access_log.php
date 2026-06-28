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
 * External API for user access log data.
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
 * External API for user access log data.
 */
class access_log extends external_api {

    /**
     * Parameters for get_user_access_log.
     */
    public static function get_user_access_log_parameters(): external_function_parameters {
        return new external_function_parameters([
            'date_from' => new external_value(PARAM_INT, 'Start timestamp (0 for default)', VALUE_DEFAULT, 0),
            'date_to' => new external_value(PARAM_INT, 'End timestamp (0 for now)', VALUE_DEFAULT, 0),
            'school_code' => new external_value(PARAM_TEXT, 'School code filter', VALUE_DEFAULT, ''),
            'courseid' => new external_value(PARAM_INT, 'Course ID filter (0 for all)', VALUE_DEFAULT, 0),
            'user_type' => new external_value(PARAM_TEXT, 'User type: student, teacher, or empty', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_TEXT, 'Search query for name/SDMS ID', VALUE_DEFAULT, ''),
            'sort' => new external_value(PARAM_TEXT, 'Sort field', VALUE_DEFAULT, 'access_time'),
            'order' => new external_value(PARAM_TEXT, 'Sort order: ASC or DESC', VALUE_DEFAULT, 'DESC'),
            'page' => new external_value(PARAM_INT, 'Page number (0-based)', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Results per page', VALUE_DEFAULT, 50),
        ]);
    }

    /**
     * Get user access log with filters and pagination.
     *
     * @param int $datefrom Start timestamp.
     * @param int $dateto End timestamp.
     * @param string $schoolcode School code filter.
     * @param int $courseid Course ID filter.
     * @param string $usertype User type filter.
     * @param string $search Search query.
     * @param string $sort Sort field.
     * @param string $order Sort order.
     * @param int $page Page number.
     * @param int $perpage Per page count.
     * @return array Paginated access log entries.
     */
    public static function get_user_access_log(int $datefrom = 0, int $dateto = 0,
            string $schoolcode = '', int $courseid = 0, string $usertype = '',
            string $search = '', string $sort = 'access_time', string $order = 'DESC',
            int $page = 0, int $perpage = 50): array {
        global $DB;

        $params = self::validate_parameters(
            self::get_user_access_log_parameters(),
            [
                'date_from' => $datefrom,
                'date_to' => $dateto,
                'school_code' => $schoolcode,
                'courseid' => $courseid,
                'user_type' => $usertype,
                'search' => $search,
                'sort' => $sort,
                'order' => $order,
                'page' => $page,
                'perpage' => $perpage,
            ]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/elby_dashboard:viewreports', $context);

        // Sanitize parameters.
        $allowedsorts = ['access_time', 'user_fullname', 'course_name', 'school_name'];
        $sort = in_array($params['sort'], $allowedsorts) ? $params['sort'] : 'access_time';
        $order = strtoupper($params['order']) === 'ASC' ? 'ASC' : 'DESC';
        $page = max(0, $params['page']);
        $perpage = min(100, max(1, $params['perpage']));

        // Default date range: last 7 days.
        $dateto = $params['date_to'] > 0 ? $params['date_to'] : time();
        $datefrom = $params['date_from'] > 0 ? $params['date_from'] : ($dateto - (7 * 86400));

        $select = "SELECT log.id, log.timecreated AS access_time,
                          u.firstname, u.lastname,
                          su.sdms_id, su.user_type,
                          s.school_name, s.school_code,
                          c.fullname AS course_name,
                          log.action, log.target";

        $from = " FROM {logstore_standard_log} log
                  JOIN {user} u ON u.id = log.userid
                  LEFT JOIN {elby_sdms_users} su ON su.userid = u.id
                  LEFT JOIN {elby_schools} s ON s.id = su.schoolid
                  LEFT JOIN {course} c ON c.id = log.courseid";

        $where = " WHERE log.userid > 0 AND log.anonymous = 0 AND u.deleted = 0
                   AND log.timecreated >= :datefrom AND log.timecreated <= :dateto";
        $sqlparams = [
            'datefrom' => $datefrom,
            'dateto' => $dateto,
        ];

        // School filter.
        if (!empty($params['school_code'])) {
            $where .= " AND s.school_code = :schoolcode";
            $sqlparams['schoolcode'] = $params['school_code'];
        }

        // Course filter.
        if ($params['courseid'] > 0) {
            $where .= " AND log.courseid = :courseid";
            $sqlparams['courseid'] = $params['courseid'];
        }

        // User type filter.
        if (!empty($params['user_type'])) {
            $where .= " AND su.user_type = :usertype";
            $sqlparams['usertype'] = $params['user_type'];
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

        // Sort mapping.
        $sortmap = [
            'access_time' => 'log.timecreated',
            'user_fullname' => 'u.lastname',
            'course_name' => 'c.fullname',
            'school_name' => 's.school_name',
        ];
        $sortfield = $sortmap[$sort] ?? 'log.timecreated';
        $orderby = " ORDER BY {$sortfield} {$order}";

        // Count total.
        $countsql = "SELECT COUNT(*)" . $from . $where;
        $totalcount = $DB->count_records_sql($countsql, $sqlparams);

        // Fetch paginated results.
        $sql = $select . $from . $where . $orderby;
        $records = $DB->get_records_sql($sql, $sqlparams, $page * $perpage, $perpage);

        $entries = [];
        foreach ($records as $rec) {
            $entries[] = [
                'user_fullname' => $rec->firstname . ' ' . $rec->lastname,
                'sdms_id' => $rec->sdms_id ?? '',
                'user_type' => $rec->user_type ?? '',
                'school_name' => $rec->school_name ?? '',
                'school_code' => $rec->school_code ?? '',
                'course_name' => $rec->course_name ?? '',
                'access_time' => (int) $rec->access_time,
                'action' => $rec->action ?? '',
                'target' => $rec->target ?? '',
            ];
        }

        return [
            'entries' => $entries,
            'total_count' => $totalcount,
            'page' => $page,
            'perpage' => $perpage,
        ];
    }

    /**
     * Return structure for get_user_access_log.
     */
    public static function get_user_access_log_returns(): external_single_structure {
        return new external_single_structure([
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'user_fullname' => new external_value(PARAM_TEXT, 'User full name'),
                    'sdms_id' => new external_value(PARAM_TEXT, 'SDMS ID'),
                    'user_type' => new external_value(PARAM_TEXT, 'User type'),
                    'school_name' => new external_value(PARAM_TEXT, 'School name'),
                    'school_code' => new external_value(PARAM_TEXT, 'School code'),
                    'course_name' => new external_value(PARAM_TEXT, 'Course name'),
                    'access_time' => new external_value(PARAM_INT, 'Access timestamp'),
                    'action' => new external_value(PARAM_TEXT, 'Log action'),
                    'target' => new external_value(PARAM_TEXT, 'Log target'),
                ])
            ),
            'total_count' => new external_value(PARAM_INT, 'Total matching records'),
            'page' => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Results per page'),
        ]);
    }
}
