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
 * External function to register a new school.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'Desired school identifier'),
            'name' => new external_value(PARAM_TEXT, 'School display name'),
            'secret' => new external_value(PARAM_RAW, 'Registration secret'),
            'contactemail' => new external_value(PARAM_EMAIL, 'Contact email', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_TEXT, 'School description', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Execute the registration.
     *
     * @param string $schoolid Desired school identifier.
     * @param string $name School name.
     * @param string $secret Registration secret.
     * @param string $contactemail Contact email.
     * @param string $description Description.
     * @return array Registration result.
     */
    public static function execute(
        string $schoolid,
        string $name,
        string $secret,
        string $contactemail = '',
        string $description = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'name' => $name,
            'secret' => $secret,
            'contactemail' => $contactemail,
            'description' => $description,
        ]);

        // Check if running in central mode.
        $mode = get_config('local_syncqueue', 'mode');
        if ($mode !== 'central') {
            return [
                'status' => 'error',
                'message' => 'This server is not running in central mode',
                'apikey' => '',
            ];
        }

        // Check if enabled.
        if (!get_config('local_syncqueue', 'enabled')) {
            return [
                'status' => 'error',
                'message' => 'Sync queue is disabled',
                'apikey' => '',
            ];
        }

        // Check if registration is allowed.
        if (!get_config('local_syncqueue', 'allowregistration')) {
            return [
                'status' => 'error',
                'message' => 'School registration is disabled',
                'apikey' => '',
            ];
        }

        // Verify registration secret.
        $registrationsecret = get_config('local_syncqueue', 'registrationsecret');
        if (empty($registrationsecret) || $params['secret'] !== $registrationsecret) {
            return [
                'status' => 'error',
                'message' => 'Invalid registration secret',
                'apikey' => '',
            ];
        }

        // Check if school ID already exists.
        $schoolmanager = new school_manager();
        if ($schoolmanager->school_exists($params['schoolid'])) {
            return [
                'status' => 'error',
                'message' => 'School ID already registered',
                'apikey' => '',
            ];
        }

        // Register the school.
        try {
            $apikey = $schoolmanager->register_school(
                $params['schoolid'],
                $params['name'],
                $params['contactemail'],
                $params['description']
            );

            return [
                'status' => 'ok',
                'message' => 'School registered successfully',
                'apikey' => $apikey,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Registration failed: ' . $e->getMessage(),
                'apikey' => '',
            ];
        }
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
            'apikey' => new external_value(PARAM_RAW, 'Generated API key (only on success)'),
        ]);
    }
}
