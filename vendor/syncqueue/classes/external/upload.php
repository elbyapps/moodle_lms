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
use local_syncqueue\central_processor;

/**
 * External function to receive uploads from schools.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
            'data' => new external_value(PARAM_RAW, 'JSON encoded sync payload'),
        ]);
    }

    /**
     * Execute the upload.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param string $data JSON encoded sync data.
     * @return array Upload results.
     */
    public static function execute(string $schoolid, string $apikey, string $data): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'data' => $data,
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

        $school = $schoolmanager->get_school($params['schoolid']);
        if (!$school || $school->status !== 'active') {
            throw new \moodle_exception('error_schoolinactive', 'local_syncqueue');
        }

        // Decode payload.
        $payload = json_decode($params['data'], true);
        if ($payload === null) {
            throw new \moodle_exception('error_invalidpayload', 'local_syncqueue');
        }

        // Process the uploaded items.
        $processor = new central_processor();
        $results = [];
        $successcount = 0;
        $failcount = 0;

        foreach ($payload['items'] ?? [] as $item) {
            try {
                $result = $processor->process_item($params['schoolid'], $item);
                $results[] = [
                    'id' => $item['id'],
                    'status' => $result['status'],
                    'message' => $result['message'] ?? '',
                    'centralid' => $result['centralid'] ?? 0,
                ];

                if ($result['status'] === 'success') {
                    $successcount++;
                } else {
                    $failcount++;
                }
            } catch (\Exception $e) {
                $results[] = [
                    'id' => $item['id'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'centralid' => 0,
                ];
                $failcount++;
            }
        }

        // Update school sync stats.
        $schoolmanager->update_sync_stats($params['schoolid'], count($results));

        return [
            'status' => 'ok',
            'processed' => count($results),
            'success' => $successcount,
            'failed' => $failcount,
            'results' => $results,
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
            'processed' => new external_value(PARAM_INT, 'Number of items processed'),
            'success' => new external_value(PARAM_INT, 'Number of successful items'),
            'failed' => new external_value(PARAM_INT, 'Number of failed items'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Original item ID from school'),
                    'status' => new external_value(PARAM_ALPHA, 'Result: success, conflict, error'),
                    'message' => new external_value(PARAM_TEXT, 'Result message'),
                    'centralid' => new external_value(PARAM_INT, 'Central ID if created/updated'),
                ])
            ),
        ]);
    }
}
