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
use local_syncqueue\epoch_guard;
use local_syncqueue\school_manager;

/**
 * External function issuing a new epoch to a re-incarnating school (ELMS Sync v2 §4.5).
 *
 * A school whose DB was restored/cloned onto a foreign dataroot (marker
 * mismatch), whose head_seq regressed, or that central flagged with an
 * incarnation fork, runs the handshake here: central mints a fresh epoch seeded
 * above every high-water it holds for the school, so the school's replayed and
 * new facts always clear old high-waters and dedup by factuuid. Central-mode,
 * apikey-authenticated, POST — mirrors the other v2 endpoints.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reincarnate extends external_api {

    /** @var int Protocol version this endpoint speaks. */
    const PROTOCOL_VERSION = 2;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
            'protocol_version' => new external_value(PARAM_INT, 'Protocol version the school speaks',
                VALUE_DEFAULT, self::PROTOCOL_VERSION),
            'old_epoch' => new external_value(PARAM_RAW, 'The epoch the school is retiring'),
        ]);
    }

    /**
     * Issue a fresh epoch and seed to the re-incarnating school.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param int $protocol_version Protocol version the school speaks.
     * @param string $old_epoch The epoch the school is retiring.
     * @return array {protocol_version, new_epoch, seed_seq}
     */
    public static function execute(string $schoolid, string $apikey, int $protocol_version,
            string $old_epoch): array {

        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'protocol_version' => $protocol_version,
            'old_epoch' => $old_epoch,
        ]);

        if ((int) $params['protocol_version'] !== self::PROTOCOL_VERSION) {
            throw new \invalid_parameter_exception('Unsupported protocol_version '
                . (int) $params['protocol_version'] . ' (this server speaks ' . self::PROTOCOL_VERSION . ')');
        }

        // Central-mode only endpoint.
        if (get_config('local_syncqueue', 'mode') !== 'central') {
            throw new \moodle_exception('error_notcentral', 'local_syncqueue');
        }

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

        $issued = epoch_guard::central_issue_epoch($params['schoolid'], (string) $params['old_epoch']);

        return [
            'protocol_version' => self::PROTOCOL_VERSION,
            'new_epoch' => $issued['new_epoch'],
            'seed_seq' => $issued['seed_seq'],
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'protocol_version' => new external_value(PARAM_INT, 'Protocol version of this response'),
            'new_epoch' => new external_value(PARAM_RAW, 'The freshly issued epoch UUID the school must adopt'),
            'seed_seq' => new external_value(PARAM_INT,
                'First school_seq the school must use under the new epoch (clears all prior high-waters)'),
        ]);
    }
}
