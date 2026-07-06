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
use local_syncqueue\outbox\sequencer;
use local_syncqueue\school_manager;

/**
 * External function serving the v2 sequenced pull stream (ELMS Sync v2 step 1).
 *
 * Pure read: central keeps no correctness-bearing delivery state, so nothing
 * is written at read time (apply-then-checkpoint lives school-side). The only
 * write is the inline sequencer call stamping committed-but-unsequenced
 * outbox rows before the scan.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pull extends external_api {

    /** @var int Pull protocol version this endpoint speaks. */
    const PROTOCOL_VERSION = 2;

    /** @var int Default rows scanned per pull. */
    const DEFAULT_LIMIT = 200;

    /** @var int Hard cap on rows scanned per pull. */
    const MAX_LIMIT = 1000;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
            'after_seq' => new external_value(PARAM_INT, 'Return rows with seq greater than this', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum rows to scan', VALUE_DEFAULT, self::DEFAULT_LIMIT),
            'protocol_version' => new external_value(PARAM_INT, 'Pull protocol version the school speaks',
                VALUE_DEFAULT, self::PROTOCOL_VERSION),
        ]);
    }

    /**
     * Serve a window of the sequenced outbox stream to a school.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param int $after_seq Return rows with seq greater than this.
     * @param int $limit Maximum rows to scan.
     * @param int $protocol_version Protocol version the school speaks.
     * @return array Pull response.
     */
    public static function execute(string $schoolid, string $apikey, int $after_seq = 0,
            int $limit = self::DEFAULT_LIMIT, int $protocol_version = self::PROTOCOL_VERSION): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'after_seq' => $after_seq,
            'limit' => $limit,
            'protocol_version' => $protocol_version,
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

        $afterseq = max(0, (int) $params['after_seq']);
        $limit = min(max(1, (int) $params['limit']), self::MAX_LIMIT);

        // Stamp committed-but-unsequenced rows so this scan sees the current tail.
        sequencer::assign();

        $headseq = (int) $DB->get_field_sql('SELECT MAX(seq) FROM {local_syncqueue_outbox}');

        [$partitionsql, $partitionparams] = self::partition_filter_sql(
            $params['schoolid'], (int) ($school->onlyselected ?? 0));

        // Unsequenced (seq IS NULL) rows are invisible to consumers by contract.
        $sql = "SELECT o.*
                  FROM {local_syncqueue_outbox} o
                 WHERE o.seq IS NOT NULL
                   AND o.seq > :afterseq
                   AND {$partitionsql}
              ORDER BY o.seq ASC";
        $candidates = $DB->get_records_sql($sql, $partitionparams + ['afterseq' => $afterseq], 0, $limit);

        // The checkpoint target covers every seq this request scanned, including
        // rows omitted by supersession below: fewer than $limit candidates means
        // the scan exhausted the outbox, so it covered head_seq.
        if (count($candidates) < $limit) {
            $advanceto = max($afterseq, $headseq);
        } else {
            $advanceto = (int) end($candidates)->seq;
        }

        // Read-time supersession: within the scanned window only the newest
        // entityversion per entity survives (staleness is decided by
        // entityversion, never seq).
        $newest = [];
        foreach ($candidates as $candidate) {
            $key = $candidate->entitytype . '|' . $candidate->entitykey;
            if (!isset($newest[$key]) || (int) $candidate->entityversion > (int) $newest[$key]->entityversion) {
                $newest[$key] = $candidate;
            }
        }

        $rows = [];
        foreach ($candidates as $candidate) {
            $key = $candidate->entitytype . '|' . $candidate->entitykey;
            if ($newest[$key]->id != $candidate->id) {
                continue;
            }
            $rows[] = [
                'seq' => (int) $candidate->seq,
                'entitytype' => $candidate->entitytype,
                'entitykey' => $candidate->entitykey,
                'entityversion' => (int) $candidate->entityversion,
                'action' => $candidate->action,
                'payload' => $candidate->payload,
                'payloadhash' => $candidate->payloadhash,
                'contentversion' => $candidate->contentversion === null ? null : (int) $candidate->contentversion,
                'partitionkey' => $candidate->partitionkey,
            ];
        }

        return [
            'protocol_version' => self::PROTOCOL_VERSION,
            'head_seq' => $headseq,
            'min_retained_seq' => 1, // Nothing is pruned yet; the full stream is retained.
            'advance_to' => $advanceto,
            'rows' => $rows,
        ];
    }

    /**
     * SQL filter for the partitions a school subscribes to.
     *
     * Mirrors the legacy download entitlement exactly: every school receives
     * the global content partition (categories); course partitions cover all
     * courses unless the school opted into "only selected", which narrows them
     * to its selected courses. Trade/level priorities only ever ordered the
     * legacy feed — they never filtered it — so they do not narrow v2
     * subscriptions either.
     *
     * @param string $schoolid School identifier.
     * @param int $onlyselected The school's "only deliver selected courses" flag.
     * @return array [sql fragment, named params]
     */
    private static function partition_filter_sql(string $schoolid, int $onlyselected): array {
        global $DB;

        // A school always receives its own per-learner seed partition (history
        // down-sync, §8.3), independent of onlyselected: rows published under
        // seed:school:<schoolid> are targeted at exactly this school, so admitting
        // only the requester's own seed partition keeps a seeded learner history
        // private — the same isolation the upstream learner:school:<id> partition
        // gives each origin's facts.
        $seedpartition = 'seed:school:' . $schoolid;

        if (!$onlyselected) {
            return [
                '(o.partitionkey = :partglobal OR ' . $DB->sql_like('o.partitionkey', ':partcourse')
                    . ' OR o.partitionkey = :partseed)',
                ['partglobal' => 'content:global', 'partcourse' => 'content:course:%', 'partseed' => $seedpartition],
            ];
        }

        $partitions = ['content:global', $seedpartition];
        $courseids = $DB->get_fieldset_select('local_syncqueue_course_prefs', 'courseid',
            'schoolid = :schoolid AND selected = 1', ['schoolid' => $schoolid]);
        foreach ($courseids as $courseid) {
            $partitions[] = 'content:course:course:' . (int) $courseid;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($partitions, SQL_PARAMS_NAMED, 'part');
        return ['o.partitionkey ' . $insql, $inparams];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'protocol_version' => new external_value(PARAM_INT, 'Protocol version of this response'),
            'head_seq' => new external_value(PARAM_INT, 'Highest sequenced seq in the outbox'),
            'min_retained_seq' => new external_value(PARAM_INT, 'Lowest seq still retained (1 until pruning exists)'),
            'advance_to' => new external_value(PARAM_INT,
                'Highest seq this request scanned; the school checkpoints this, never the last row seq'),
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'seq' => new external_value(PARAM_INT, 'Dense sequence number'),
                    'entitytype' => new external_value(PARAM_ALPHANUMEXT, 'Entity type: course, category, course_content'),
                    'entitykey' => new external_value(PARAM_RAW, 'Stable entity identity, e.g. course:123'),
                    'entityversion' => new external_value(PARAM_INT, 'Per-entity monotonic version; decides staleness'),
                    'action' => new external_value(PARAM_ALPHA, 'upsert, delete or publish'),
                    'payload' => new external_value(PARAM_RAW, 'Canonical JSON payload, null for payloadless actions'),
                    'payloadhash' => new external_value(PARAM_ALPHANUMEXT, 'SHA256 of the canonical JSON payload'),
                    'contentversion' => new external_value(PARAM_INT, 'Content publication version for course_content rows'),
                    'partitionkey' => new external_value(PARAM_RAW, 'Delivery partition'),
                ])
            ),
        ]);
    }
}
