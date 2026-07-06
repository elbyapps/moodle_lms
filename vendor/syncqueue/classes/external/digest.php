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
use local_syncqueue\digest as digestlib;
use local_syncqueue\school_manager;

/**
 * Applied-state digest endpoint for anti-entropy (ELMS Sync v2 step 6, doc §9).
 *
 * Two phases over the requesting school's replicated content state:
 *  - 'summary': returns central's per-(entitytype, bucket) hashes of what the school
 *    SHOULD hold (the head published to its subscribed partitions). The school diffs
 *    these against its own applied-state summary to find divergent buckets.
 *  - 'detail': the school sends its (entitykey => payloadhash) for the divergent
 *    buckets; central returns the full current rows for every expected key the school
 *    is MISSING or STALE on. The school applies them through the normal applier.
 *
 * Central-owned, read-only: it never writes and never touches entities the school has
 * that central does not expect (benign extras are ignored). A digest_version mismatch
 * answers with an upgrade flag so a canonicalization change can't storm the fleet.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digest extends external_api {

    /** @var int Max entities returned per detail call; the school re-runs for the rest. */
    const MAX_DETAIL = 500;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key'),
            'phase' => new external_value(PARAM_ALPHA, 'summary|detail'),
            'payload' => new external_value(PARAM_RAW, 'Phase input as JSON (detail: divergent buckets + keys)',
                VALUE_DEFAULT, ''),
            'digest_version' => new external_value(PARAM_INT, 'Client digest canonicalization version',
                VALUE_DEFAULT, digestlib::VERSION),
        ]);
    }

    /**
     * Execute the digest exchange.
     *
     * @param string $schoolid
     * @param string $apikey
     * @param string $phase
     * @param string $payload
     * @param int $digest_version
     * @return array ['digest_version' => int, 'result' => json]
     */
    public static function execute(string $schoolid, string $apikey, string $phase,
            string $payload = '', int $digest_version = digestlib::VERSION): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'phase' => $phase,
            'payload' => $payload,
            'digest_version' => $digest_version,
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

        // A canonicalization mismatch skips cleanly (no fleet-wide repair storm).
        if ((int) $params['digest_version'] !== digestlib::VERSION) {
            return ['digest_version' => digestlib::VERSION, 'result' => json_encode(['upgrade' => true])];
        }

        $onlyselected = (int) ($school->onlyselected ?? 0);

        if ($params['phase'] === 'summary') {
            // Downstream: what content the school SHOULD hold.
            $result = ['summary' => digestlib::summary(
                digestlib::central_expected_map($params['schoolid'], $onlyselected))];
        } else if ($params['phase'] === 'detail') {
            $result = ['entities' => self::detail(
                digestlib::central_expected_map($params['schoolid'], $onlyselected),
                (string) $params['payload'])];
        } else if ($params['phase'] === 'upsummary') {
            // Upstream: what facts central has RECEIVED from this school.
            $result = ['summary' => digestlib::summary(
                digestlib::central_received_map($params['schoolid']))];
        } else if ($params['phase'] === 'updetail') {
            $result = ['missing' => self::updetail($params['schoolid'], (string) $params['payload'])];
        } else if ($params['phase'] === 'fetch') {
            // Snapshot bootstrap: return head rows for an explicit key list (scoped).
            $in = json_decode((string) $params['payload'], true);
            $keys = (is_array($in) && is_array($in['keys'] ?? null)) ? $in['keys'] : [];
            $result = ['entities' => digestlib::fetch_rows($keys, $params['schoolid'], $onlyselected, self::MAX_DETAIL)];
        } else {
            throw new \invalid_parameter_exception("Unknown digest phase '{$params['phase']}'");
        }

        return ['digest_version' => digestlib::VERSION, 'result' => json_encode($result)];
    }

    /**
     * Build the detail response: the head rows for every expected key in the requested
     * divergent buckets that the school reported missing or at a different hash.
     *
     * @param array $expected entitytype => [entitykey => payloadhash]
     * @param string $payloadjson {buckets: [{entitytype,bucket}], keys: {entitytype:{key:hash}}}
     * @return array list of entity rows
     */
    protected static function detail(array $expected, string $payloadjson): array {
        $in = json_decode($payloadjson, true);
        if (!is_array($in)) {
            return [];
        }
        // The divergent (entitytype, bucket) set the school asked about (defensively
        // parsed — a school can only break its own repair with malformed input).
        $wanted = [];
        foreach ((is_array($in['buckets'] ?? null) ? $in['buckets'] : []) as $b) {
            if (is_array($b) && isset($b['entitytype'], $b['bucket'])) {
                $wanted[$b['entitytype'] . '|' . (int) $b['bucket']] = true;
            }
        }
        $schoolkeys = is_array($in['keys'] ?? null) ? $in['keys'] : [];

        $entities = [];
        foreach ($expected as $entitytype => $keyhashes) {
            foreach ($keyhashes as $entitykey => $centralhash) {
                if (!isset($wanted[$entitytype . '|' . digestlib::bucket((string) $entitykey)])) {
                    continue;
                }
                $schoolhash = $schoolkeys[$entitytype][$entitykey] ?? null;
                if ($schoolhash === (string) $centralhash) {
                    continue; // school already holds the current version
                }
                $row = digestlib::central_head_row((string) $entitytype, (string) $entitykey);
                if ($row === null) {
                    continue;
                }
                $entities[] = [
                    'entitytype' => $row->entitytype,
                    'entitykey' => $row->entitykey,
                    'entityversion' => (int) $row->entityversion,
                    'action' => $row->action,
                    'payload' => $row->payload,
                    'payloadhash' => $row->payloadhash,
                    'contentversion' => $row->contentversion === null ? null : (int) $row->contentversion,
                ];
                if (count($entities) >= self::MAX_DETAIL) {
                    return $entities;
                }
            }
        }
        return $entities;
    }

    /**
     * Build the upstream detail response: the lineages central is MISSING or STALE on,
     * for the facts the school reported in the divergent buckets.
     *
     * Iterates the SCHOOL's reported keys (not central's) because the repair is
     * school→central: for each pushed fact whose head hash central does not hold
     * (absent, or an older version with a different hash) central is behind, so the
     * school must re-push it. Extras central holds that the school no longer has are
     * ignored (a school ledger loss is not repairable this direction).
     *
     * @param string $schoolid The requesting school.
     * @param string $payloadjson {buckets: [{entitytype,bucket}], keys: {facttype:{lineageuuid:hash}}}
     * @return array list of ['facttype' => string, 'lineageuuid' => string]
     */
    protected static function updetail(string $schoolid, string $payloadjson): array {
        $in = json_decode($payloadjson, true);
        if (!is_array($in)) {
            return [];
        }
        $wanted = [];
        foreach ((is_array($in['buckets'] ?? null) ? $in['buckets'] : []) as $b) {
            if (is_array($b) && isset($b['entitytype'], $b['bucket'])) {
                $wanted[$b['entitytype'] . '|' . (int) $b['bucket']] = true;
            }
        }
        $schoolkeys = is_array($in['keys'] ?? null) ? $in['keys'] : [];
        $received = digestlib::central_received_map($schoolid);

        $missing = [];
        foreach ($schoolkeys as $facttype => $lineages) {
            if (!is_array($lineages)) {
                continue;
            }
            foreach ($lineages as $lineageuuid => $schoolhash) {
                if (!isset($wanted[$facttype . '|' . digestlib::bucket((string) $lineageuuid)])) {
                    continue;
                }
                if (($received[$facttype][$lineageuuid] ?? null) !== (string) $schoolhash) {
                    $missing[] = ['facttype' => (string) $facttype, 'lineageuuid' => (string) $lineageuuid];
                    if (count($missing) >= self::MAX_DETAIL) {
                        return $missing;
                    }
                }
            }
        }
        return $missing;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'digest_version' => new external_value(PARAM_INT, 'Server digest version'),
            'result' => new external_value(PARAM_RAW, 'Phase result as JSON'),
        ]);
    }
}
