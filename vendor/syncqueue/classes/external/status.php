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
 * External function to check sync API status.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier', VALUE_DEFAULT, ''),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the status check.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @return array Status information.
     */
    public static function execute(string $schoolid = '', string $apikey = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
        ]);

        // Check if running in central mode.
        $mode = get_config('local_syncqueue', 'mode');
        if ($mode !== 'central') {
            return [
                'status' => 'error',
                'message' => 'This server is not running in central mode',
                'registered' => false,
                'schoolname' => '',
                'lastsynced' => 0,
                'servertime' => time(),
            ];
        }

        // Check if enabled.
        if (!get_config('local_syncqueue', 'enabled')) {
            return [
                'status' => 'error',
                'message' => 'Sync queue is disabled',
                'registered' => false,
                'schoolname' => '',
                'lastsynced' => 0,
                'servertime' => time(),
            ];
        }

        // If no school ID provided, just return basic status.
        if (empty($params['schoolid'])) {
            return [
                'status' => 'ok',
                'message' => 'Sync API is available',
                'registered' => false,
                'schoolname' => '',
                'lastsynced' => 0,
                'servertime' => time(),
            ];
        }

        // Validate school registration.
        $schoolmanager = new school_manager();
        $school = $schoolmanager->get_school($params['schoolid']);

        if (!$school) {
            return [
                'status' => 'error',
                'message' => 'School not registered',
                'registered' => false,
                'schoolname' => '',
                'lastsynced' => 0,
                'servertime' => time(),
            ];
        }

        // Verify API key.
        if (!empty($params['apikey']) && !$schoolmanager->verify_apikey($params['schoolid'], $params['apikey'])) {
            return [
                'status' => 'error',
                'message' => 'Invalid API key',
                'registered' => true,
                'schoolname' => $school->name,
                'lastsynced' => 0,
                'servertime' => time(),
            ];
        }

        // Check school status.
        if ($school->status !== 'active') {
            return [
                'status' => 'error',
                'message' => 'School is ' . $school->status,
                'registered' => true,
                'schoolname' => $school->name,
                'lastsynced' => (int) $school->lastsynced,
                'servertime' => time(),
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'School is registered and active',
            'registered' => true,
            'schoolname' => $school->name,
            'lastsynced' => (int) $school->lastsynced,
            'servertime' => time(),
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status: ok or error'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'registered' => new external_value(PARAM_BOOL, 'Whether school is registered'),
            'schoolname' => new external_value(PARAM_TEXT, 'School name if registered'),
            'lastsynced' => new external_value(PARAM_INT, 'Last sync timestamp'),
            'servertime' => new external_value(PARAM_INT, 'Current server timestamp'),
        ]);
    }
}
