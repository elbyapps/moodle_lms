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
 * External function to receive sync completion reports from schools.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
            'results' => new external_value(PARAM_RAW, 'JSON encoded sync results'),
        ]);
    }

    /**
     * Execute the report.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param string $results JSON encoded results.
     * @return array Report acknowledgment.
     */
    public static function execute(string $schoolid, string $apikey, string $results): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'results' => $results,
        ]);

        // Check if running in central mode.
        $mode = get_config('local_syncqueue', 'mode');
        if ($mode !== 'central') {
            throw new \moodle_exception('error_notcentral', 'local_syncqueue');
        }

        // Check if enabled.
        if (!get_config('local_syncqueue', 'enabled')) {
            throw new \moodle_exception('error_disabled', 'local_syncqueue');
        }

        // Validate school and API key.
        $schoolmanager = new school_manager();
        if (!$schoolmanager->verify_apikey($params['schoolid'], $params['apikey'])) {
            throw new \moodle_exception('error_authfailed', 'local_syncqueue');
        }

        // Decode results.
        $resultsdata = json_decode($params['results'], true);
        if ($resultsdata === null) {
            throw new \moodle_exception('error_invalidpayload', 'local_syncqueue');
        }

        // Log the report.
        $log = new \stdClass();
        $log->schoolid = $params['schoolid'];
        $log->direction = $resultsdata['direction'] ?? 'unknown';
        $log->itemcount = $resultsdata['itemcount'] ?? 0;
        $log->successcount = $resultsdata['successcount'] ?? 0;
        $log->failcount = $resultsdata['failcount'] ?? 0;
        $log->conflictcount = $resultsdata['conflictcount'] ?? 0;
        $log->status = $resultsdata['status'] ?? 'unknown';
        $log->details = json_encode($resultsdata['details'] ?? []);
        $log->timecreated = time();

        $DB->insert_record('local_syncqueue_log', $log);

        return [
            'status' => 'ok',
            'message' => 'Report received',
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
            'message' => new external_value(PARAM_TEXT, 'Result message'),
            'servertime' => new external_value(PARAM_INT, 'Current server timestamp'),
        ]);
    }
}
