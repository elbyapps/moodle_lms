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
 * Proxy a single TDMP gateway lookup on behalf of a school.
 *
 * Schools never hold the TDMP API key; they call this central endpoint, which
 * runs the real lookup via local_elby_dashboard's TDMP client and returns the
 * canonical record. The school caches and links from the returned data.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tdmp_lookup extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
            'sdms_code' => new external_value(PARAM_TEXT, 'TDMP identifier (student/staff/school/trade code)'),
            'user_type' => new external_value(PARAM_ALPHA, 'Lookup type: student, teacher, staff, school or trade'),
        ]);
    }

    /**
     * Run the proxied lookup.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param string $sdmscode TDMP identifier.
     * @param string $usertype Lookup type.
     * @return array ['found' => bool, 'data' => string] where data is a JSON-encoded record.
     */
    public static function execute(string $schoolid, string $apikey, string $sdmscode, string $usertype): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'sdms_code' => $sdmscode,
            'user_type' => $usertype,
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

        // The TDMP client (and its API key) live in local_elby_dashboard on central.
        if (!class_exists('\local_elby_dashboard\tdmp_client')) {
            throw new \moodle_exception('error_syncfailed', 'local_syncqueue', '',
                'TDMP client is not available on the central server');
        }

        $code = trim($params['sdms_code']);
        if ($code === '') {
            return ['found' => false, 'data' => ''];
        }

        try {
            $client = new \local_elby_dashboard\tdmp_client();
            switch ($params['user_type']) {
                case 'student':
                    $data = $client->get_student($code);
                    break;
                case 'teacher':
                case 'staff':
                    $data = $client->get_teacher($code);
                    break;
                case 'school':
                    $data = $client->get_school($code);
                    break;
                case 'trade':
                    $data = $client->get_trade($code);
                    break;
                default:
                    return ['found' => false, 'data' => ''];
            }
        } catch (\Exception $e) {
            throw new \moodle_exception('error_syncfailed', 'local_syncqueue', '', $e->getMessage());
        }

        if ($data === null) {
            return ['found' => false, 'data' => ''];
        }

        return ['found' => true, 'data' => json_encode($data)];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found' => new external_value(PARAM_BOOL, 'Whether a record was found'),
            'data' => new external_value(PARAM_RAW, 'JSON-encoded canonical record, empty when not found'),
        ]);
    }
}
