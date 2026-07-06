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
 * Dual-validity API key rotation endpoint (ELMS Sync v2 step 7, doc §4.6).
 *
 * A school authenticates with its CURRENT key (or its still-valid previous key) and
 * submits a fresh key it has generated; central adopts it as the current key and keeps
 * the old one valid through a grace window. Because both keys authenticate during the
 * window, rotating can never brick a school that is offline or mid-retry when the
 * rotation lands. Idempotent: submitting the already-current key is a no-op success, so
 * a lost ack is safe to retry (with the old key, still valid).
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rotate_key extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'Current (or still-valid previous) API key'),
            'newkey' => new external_value(PARAM_ALPHANUM, 'The new API key the school has generated'),
        ]);
    }

    /**
     * Rotate the school's key.
     *
     * @param string $schoolid
     * @param string $apikey Authenticating key.
     * @param string $newkey New key to adopt.
     * @return array{rotated:bool, current:bool, prev_expires:int}
     */
    public static function execute(string $schoolid, string $apikey, string $newkey): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid, 'apikey' => $apikey, 'newkey' => $newkey,
        ]);

        if (get_config('local_syncqueue', 'mode') !== 'central') {
            throw new \moodle_exception('error_notcentral', 'local_syncqueue');
        }
        if (!get_config('local_syncqueue', 'enabled')) {
            throw new \moodle_exception('error_disabled', 'local_syncqueue');
        }
        $schoolmanager = new school_manager();
        // Authenticate with the CURRENT or still-valid PREVIOUS key (dual-validity).
        if (!$schoolmanager->verify_apikey($params['schoolid'], $params['apikey'])) {
            throw new \moodle_exception('error_authfailed', 'local_syncqueue');
        }
        // A too-short new key is rejected — never weaken the credential during rotation.
        if (strlen($params['newkey']) < 40) {
            throw new \moodle_exception('error_authfailed', 'local_syncqueue');
        }

        // Pass the authenticating key so rotate_key can require the CURRENT key for a
        // genuine rotation (a grace-window previous key may only re-confirm, not re-set).
        return $schoolmanager->rotate_key($params['schoolid'], $params['apikey'], $params['newkey']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rotated' => new external_value(PARAM_BOOL, 'Whether a swap occurred (false = already current)'),
            'current' => new external_value(PARAM_BOOL, 'Whether the submitted key is now the current key'),
            'prev_expires' => new external_value(PARAM_INT, 'Unix time the previous key stops being accepted'),
        ]);
    }
}
