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

namespace local_syncqueue\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_syncqueue\school_manager;

/**
 * Return a school's full student/teacher roster from TDMP (central proxy).
 *
 * A school pulls its own roster to cache locally for offline signup/linking.
 * The roster is always scoped to the requesting school's own code, so a school
 * can never fetch another school's people.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tdmp_roster extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier (used as the schoolCode filter)'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
        ]);
    }

    /**
     * Fetch the roster.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @return array ['students' => json, 'teachers' => json, 'count' => int]
     */
    public static function execute(string $schoolid, string $apikey): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
        ]);

        $mode = get_config('local_syncqueue', 'mode');
        if ($mode !== 'central') {
            throw new \moodle_exception('error_notcentral', 'local_syncqueue');
        }
        if (!get_config('local_syncqueue', 'enabled')) {
            throw new \moodle_exception('error_disabled', 'local_syncqueue');
        }

        $schoolmanager = new school_manager();
        if (!$schoolmanager->verify_apikey($params['schoolid'], $params['apikey'])) {
            throw new \moodle_exception('error_authfailed', 'local_syncqueue');
        }
        $school = $schoolmanager->get_school($params['schoolid']);
        if (!$school || $school->status !== 'active') {
            throw new \moodle_exception('error_schoolinactive', 'local_syncqueue');
        }

        if (!class_exists('\local_elby_dashboard\tdmp_client')) {
            throw new \moodle_exception('error_syncfailed', 'local_syncqueue', '',
                'TDMP client is not available on the central server');
        }

        try {
            $client = new \local_elby_dashboard\tdmp_client();
            // Scope strictly to the requesting school's own code.
            $students = $client->get_students_by_school($params['schoolid']);
            $teachers = $client->get_teachers_by_school($params['schoolid']);
        } catch (\Exception $e) {
            throw new \moodle_exception('error_syncfailed', 'local_syncqueue', '', $e->getMessage());
        }

        return [
            'students' => json_encode(array_values($students)),
            'teachers' => json_encode(array_values($teachers)),
            'count' => count($students) + count($teachers),
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'students' => new external_value(PARAM_RAW, 'JSON array of student records'),
            'teachers' => new external_value(PARAM_RAW, 'JSON array of teacher records'),
            'count' => new external_value(PARAM_INT, 'Total records returned'),
        ]);
    }
}
