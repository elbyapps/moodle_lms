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
use core_external\external_multiple_structure;
use core_external\external_value;
use local_syncqueue\school_manager;
use local_syncqueue\update_manager;

/**
 * External function: return the course catalog available to a school (F3).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catalog extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
        ]);
    }

    /**
     * Return the catalog of available courses plus the school's current preferences.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @return array
     */
    public static function execute(string $schoolid, string $apikey): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
        ]);

        if (get_config('local_syncqueue', 'mode') !== 'central') {
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

        $updatemanager = new update_manager();
        $catalog = $updatemanager->get_catalog_for_school($params['schoolid']);

        return [
            'status' => 'ok',
            'onlyselected' => (int) $school->onlyselected,
            'courses' => $catalog,
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'onlyselected' => new external_value(PARAM_INT, '1 if school only pulls selected courses'),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Central course id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'categorypath' => new external_value(PARAM_TEXT, 'Category path'),
                    'tradecode' => new external_value(PARAM_TEXT, 'Trade code'),
                    'level' => new external_value(PARAM_TEXT, 'Level'),
                    'selected' => new external_value(PARAM_BOOL, 'Currently selected by the school'),
                    'weight' => new external_value(PARAM_INT, 'Priority weight (lower first)'),
                ])
            ),
        ]);
    }
}
