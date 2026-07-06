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
use local_syncqueue\ingest_manager;
use local_syncqueue\school_manager;

/**
 * External function receiving the v2 sequenced upstream push (ELMS Sync v2 §4.3).
 *
 * Buffer-then-apply: items are inserted into the ingest table in one cheap
 * transaction and the response acks the highest contiguous school_seq now
 * durably stored (not yet applied). A separate cron applies asynchronously.
 * Dual-stack with the legacy upload endpoint, which keeps serving unmigrated
 * schools (push_v2 = 0).
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push extends external_api {

    /** @var int Push protocol version this endpoint speaks. */
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
            'protocol_version' => new external_value(PARAM_INT, 'Push protocol version the school speaks',
                VALUE_DEFAULT, self::PROTOCOL_VERSION),
            'epoch' => new external_value(PARAM_RAW, 'The school self epoch this batch was authored under'),
            'head_seq' => new external_value(PARAM_INT, 'The school current outbox head (MAX school_seq)',
                VALUE_DEFAULT, 0),
            'items' => new external_value(PARAM_RAW, 'JSON array of push items in school_seq order'),
        ]);
    }

    /**
     * Receive and buffer a batch of upstream facts.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param int $protocol_version Protocol version the school speaks.
     * @param string $epoch School self epoch.
     * @param int $head_seq School outbox head.
     * @param string $items JSON array of push items.
     * @return array Push response (see execute_returns).
     */
    public static function execute(string $schoolid, string $apikey, int $protocol_version,
            string $epoch, int $head_seq, string $items): array {

        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'protocol_version' => $protocol_version,
            'epoch' => $epoch,
            'head_seq' => $head_seq,
            'items' => $items,
        ]);

        if ((int) $params['protocol_version'] !== self::PROTOCOL_VERSION) {
            throw new \invalid_parameter_exception('Unsupported protocol_version '
                . (int) $params['protocol_version'] . ' (this server speaks ' . self::PROTOCOL_VERSION . ')');
        }

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

        // Decode the items array (a JSON string, like the legacy upload data blob).
        $decoded = json_decode($params['items'], true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('error_invalidpayload', 'local_syncqueue');
        }

        $epoch = trim((string) $params['epoch']);
        if ($epoch === '') {
            throw new \moodle_exception('error_invalidpayload', 'local_syncqueue');
        }

        return ingest_manager::receive_push($params['schoolid'], $epoch, (int) $params['head_seq'], $decoded);
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'protocol_version' => new external_value(PARAM_INT, 'Protocol version of this response'),
            'status' => new external_value(PARAM_ALPHA, 'Status: ok'),
            'acked_through' => new external_value(PARAM_INT,
                'Highest contiguous school_seq now durably stored (buffered) at central'),
            'stored' => new external_multiple_structure(
                new external_value(PARAM_INT, 'A school_seq now durably stored (incl. benign replays)')),
            'stale' => new external_multiple_structure(
                new external_value(PARAM_INT, 'A school_seq whose lineage version conflicts; self-heal at high-water + 1')),
            'forks' => new external_multiple_structure(
                new external_single_structure([
                    'school_seq' => new external_value(PARAM_INT, 'School seq of the forked item'),
                    'tier' => new external_value(PARAM_ALPHA, 'Fork tier: lineage or incarnation'),
                    'detail' => new external_value(PARAM_RAW, 'JSON detail (e.g. central high-water)'),
                ])),
            'reincarnate_required' => new external_value(PARAM_BOOL,
                'True on an incarnation fork or head_seq regression; the school runs the re-incarnation handshake'),
            'central_epoch' => new external_value(PARAM_RAW, 'Central database incarnation epoch'),
            'central_head_seq' => new external_value(PARAM_INT, 'Central outbox head seq (0 when empty)'),
        ]);
    }
}
