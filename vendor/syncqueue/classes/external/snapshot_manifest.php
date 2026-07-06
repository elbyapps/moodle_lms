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
use stdClass;

/**
 * Snapshot bootstrap manifest endpoint (ELMS Sync v2 step 6, central, §4.4).
 *
 * A fresh or re-incarnated school fetches its full head content state here, in resumable
 * chunks, then applies each entity and sets its pull cursor to the pinned head seq H —
 * instead of replaying the entire outbox from 0 or the fatal "cursor = MAX(id)" that
 * silently discards backlogs.
 *
 * On the first call (no manifest id, or an expired one) central MATERIALISES a manifest:
 * it snapshots the head (entitytype, entitykey, payloadhash) of everything the school is
 * subscribed to at the current head seq, assigns a manifest id, chunks it, and pins it.
 * Every chunk call echoes the manifest id so the school detects a superseded manifest and
 * restarts cleanly. Central-owned, read-only w.r.t. sync state (it only writes its own
 * manifest cache).
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot_manifest extends external_api {

    /** @var int Manifest entries per chunk. */
    const CHUNK_SIZE = 500;

    /** @var int How long a materialised manifest (and its pinned entities) is retained. */
    const PIN_SECONDS = 7 * DAYSECS;

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key'),
            'manifestid' => new external_value(PARAM_RAW, 'Manifest id to resume (empty to (re)materialise)',
                VALUE_DEFAULT, ''),
            'chunkindex' => new external_value(PARAM_INT, 'Chunk to fetch', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return a manifest chunk (materialising the manifest on first call).
     *
     * @param string $schoolid
     * @param string $apikey
     * @param string $manifestid
     * @param int $chunkindex
     * @return array
     */
    public static function execute(string $schoolid, string $apikey, string $manifestid = '',
            int $chunkindex = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'manifestid' => $manifestid,
            'chunkindex' => $chunkindex,
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

        // Reuse a valid pinned manifest, else materialise a fresh one.
        $head = null;
        if ($params['manifestid'] !== '') {
            $head = $DB->get_record('local_syncqueue_snapshot',
                ['manifestid' => $params['manifestid'], 'schoolid' => $params['schoolid'], 'chunkindex' => 0]);
            if ($head && (int) $head->pinneduntil < time()) {
                $head = null; // expired — materialise anew (a different manifest id)
            }
        }
        if (!$head) {
            $head = self::materialise($params['schoolid'], (int) ($school->onlyselected ?? 0));
        }

        $idx = max(0, (int) $params['chunkindex']);
        $chunk = $DB->get_record('local_syncqueue_snapshot',
            ['manifestid' => $head->manifestid, 'schoolid' => $params['schoolid'], 'chunkindex' => $idx]);
        $entries = ($chunk && $chunk->entries !== '') ? $chunk->entries : '[]';

        return [
            'manifestid' => $head->manifestid,
            'headseq' => (int) $head->headseq,
            'numchunks' => (int) $head->numchunks,
            'chunkindex' => $idx,
            'entries' => $entries,
        ];
    }

    /**
     * Materialise a fresh manifest for a school: snapshot the head content state at the
     * current head seq, chunk it, pin it, and supersede any prior manifest.
     *
     * @param string $schoolid
     * @param int $onlyselected
     * @return stdClass The chunk-0 row (carries manifestid, headseq, numchunks).
     */
    protected static function materialise(string $schoolid, int $onlyselected): stdClass {
        global $DB;

        // Read the head seq BEFORE snapshotting the state, so it is a LOWER bound: any row
        // committed+sequenced during materialisation has seq > headseq and is re-delivered
        // by the next incremental pull (idempotent), never skipped by a cursor set past it.
        $headseq = (int) $DB->get_field_sql('SELECT COALESCE(MAX(seq), 0) FROM {local_syncqueue_outbox}');
        $expected = digestlib::central_expected_map($schoolid, $onlyselected);
        $entries = [];
        foreach ($expected as $entitytype => $keyhashes) {
            foreach ($keyhashes as $entitykey => $payloadhash) {
                $entries[] = ['entitytype' => $entitytype, 'entitykey' => $entitykey, 'payloadhash' => $payloadhash];
            }
        }
        $manifestid = \core\uuid::generate();
        $chunks = array_chunk($entries, self::CHUNK_SIZE);
        if (empty($chunks)) {
            $chunks = [[]]; // always at least one (empty) chunk so the school terminates
        }
        $numchunks = count($chunks);
        $now = time();
        $pin = $now + self::PIN_SECONDS;

        // One manifest per school: a fresh materialisation supersedes the old, atomically
        // so a concurrent materialisation can never expose a torn (mixed/partial) manifest.
        $transaction = $DB->start_delegated_transaction();
        $chunkzero = null;
        try {
            $DB->delete_records('local_syncqueue_snapshot', ['schoolid' => $schoolid]);
            foreach ($chunks as $i => $chunkentries) {
                $row = new stdClass();
                $row->manifestid = $manifestid;
                $row->schoolid = $schoolid;
                $row->headseq = $headseq;
                $row->chunkindex = $i;
                $row->numchunks = $numchunks;
                $row->entries = json_encode(array_values($chunkentries));
                $row->pinneduntil = $pin;
                $row->timecreated = $now;
                $row->id = $DB->insert_record('local_syncqueue_snapshot', $row);
                if ($i === 0) {
                    $chunkzero = $row;
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return $chunkzero;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'manifestid' => new external_value(PARAM_RAW, 'Manifest identity'),
            'headseq' => new external_value(PARAM_INT, 'Head seq the snapshot is pinned at'),
            'numchunks' => new external_value(PARAM_INT, 'Total chunks'),
            'chunkindex' => new external_value(PARAM_INT, 'Chunk index returned'),
            'entries' => new external_value(PARAM_RAW, 'JSON [{entitytype, entitykey, payloadhash}]'),
        ]);
    }
}
