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
 * External function to send updates to schools.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
            'since' => new external_value(PARAM_INT, 'Get updates since this timestamp', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum updates to return', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * Execute the download.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param int $since Get updates since timestamp.
     * @param int $limit Maximum updates.
     * @return array Updates for school.
     */
    public static function execute(string $schoolid, string $apikey, int $since = 0, int $limit = 100): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'since' => $since,
            'limit' => $limit,
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

        // Get pending updates for this school.
        $updatemanager = new update_manager();
        $updates = $updatemanager->get_updates_for_school(
            $params['schoolid'],
            $params['since'],
            min($params['limit'], 500) // Cap at 500.
        );

        // Debug: Log what we found.
        debugging('Download request from school: ' . $params['schoolid'] . ', since: ' . $params['since'] . ', found: ' . count($updates), DEBUG_DEVELOPER);

        // Format updates for response.
        $formattedUpdates = [];
        foreach ($updates as $update) {
            $formattedUpdates[] = [
                'id' => (int) $update->id,
                'type' => $update->updatetype,
                'action' => $update->action,
                'timestamp' => (int) $update->timecreated,
                'data' => $update->payload, // Already JSON string.
            ];

            // Mark as downloaded.
            $updatemanager->mark_downloaded($params['schoolid'], $update->id);
        }

        return [
            'status' => 'ok',
            'count' => count($formattedUpdates),
            'since' => $params['since'],
            'servertime' => time(),
            'updates' => $formattedUpdates,
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
            'count' => new external_value(PARAM_INT, 'Number of updates returned'),
            'since' => new external_value(PARAM_INT, 'Requested since timestamp'),
            'servertime' => new external_value(PARAM_INT, 'Current server timestamp'),
            'updates' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Update ID'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Update type'),
                    'action' => new external_value(PARAM_ALPHA, 'Action: create, update, delete'),
                    'timestamp' => new external_value(PARAM_INT, 'Update timestamp'),
                    'data' => new external_value(PARAM_RAW, 'JSON encoded update data'),
                ])
            ),
        ]);
    }
}
