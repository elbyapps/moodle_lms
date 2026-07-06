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

namespace local_syncqueue;

use local_syncqueue\outbox\cursor;
use stdClass;

/**
 * Central ingest buffer for school-pushed upstream facts (ELMS Sync v2 §4.3).
 *
 * A push lands here in ONE cheap transaction: each item is buffered into
 * local_syncqueue_ingest (status 'buffered'), holes become dead-markers, and
 * the response acks the highest CONTIGUOUS school_seq now durably stored. No
 * applying happens here — a separate cron (the ingest-apply stage) deserializes
 * buffered rows asynchronously, so a central PHP timeout can never eat an ack
 * and 50 schools pushing at once never stall FPM.
 *
 * Dedup + two-tier fork detection ride the three unique keys (factuuid;
 * lineageuuid+factversion; schoolid+epoch+schoolseq):
 *  - factuuid collision, same payloadhash    -> benign replay, acked;
 *  - factuuid collision, different hash, OR
 *    (lineage,version) collision, other uuid -> lineage-version conflict: the
 *                                               school self-heals by re-exporting
 *                                               at central's high-water + 1 (no freeze);
 *  - (schoolid,epoch,schoolseq) collision,
 *    different fact                          -> incarnation fork: reincarnate_required.
 *
 * Collisions are detected by pre-checking SELECTs (not by catching insert
 * exceptions): a failed statement aborts the whole transaction on PostgreSQL,
 * so a duplicate insert would poison every subsequent item in the batch.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ingest_manager {

    /** @var string Direction used for central's per-school ack frontier cursor. */
    const UP = 'up';

    /**
     * Buffer a school push and compute the dense-seq ack.
     *
     * @param string $schoolid Authenticated pushing school id.
     * @param string $epoch The school's self epoch this batch was authored under.
     * @param int $headseq The school's current outbox head (MAX school_seq).
     * @param array $items Decoded wire items (each an assoc array with
     *        school_seq, factuuid, lineageuuid, factversion, facttype, action,
     *        entitykey, payload (JSON string), payloadhash, rostergen, kind, reason).
     * @return array Response struct: protocol_version, status, acked_through,
     *         stored[], stale[], forks[], reincarnate_required, central_epoch,
     *         central_head_seq.
     */
    public static function receive_push(string $schoolid, string $epoch, int $headseq, array $items): array {
        // Serialize concurrent pushes from the SAME school (e.g. a retry storm
        // hitting the externally-callable endpoint) so the check-then-insert dedup
        // in store_batch() is race-free. A per-school lock is used rather than
        // insert-then-catch because a duplicate insert would abort the whole batch
        // transaction on PostgreSQL (see this class's dedup note); different
        // schools never contend.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_syncqueue');
        $lock = $lockfactory->get_lock('ingest:' . $schoolid, 10);
        if (!$lock) {
            throw new \moodle_exception('error_syncfailed', 'local_syncqueue', '',
                'ingest busy for this school; please retry');
        }
        try {
            return self::store_batch($schoolid, $epoch, $headseq, $items);
        } finally {
            $lock->release();
        }
    }

    /**
     * Buffer a school push and compute the dense-seq ack. Serialized per school by
     * receive_push()'s lock, so the classify_and_store() pre-check dedup is exact.
     *
     * @param string $schoolid Authenticated pushing school id.
     * @param string $epoch The school's self epoch this batch was authored under.
     * @param int $headseq The school's current outbox head (MAX school_seq).
     * @param array $items Decoded wire items.
     * @return array Response struct (see receive_push()).
     */
    protected static function store_batch(string $schoolid, string $epoch, int $headseq, array $items): array {
        global $DB;

        $now = time();

        // Seq-regression check BEFORE observing (observe() is monotonic and would
        // hide a regression): a pushed head below the recorded high-water for the
        // SAME epoch means the school's outbox was restored/rolled back onto the
        // same incarnation — a re-incarnation trigger.
        $priorschool = epoch_store::get(epoch_store::SCOPE_SCHOOL, $schoolid);
        $reincarnate = ($priorschool && $priorschool->epoch === $epoch
            && $headseq < (int) $priorschool->headseq);

        $stored = [];
        $stale = [];
        $forks = [];
        $ackedthrough = 0;

        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $result = self::classify_and_store($schoolid, $epoch, $item, $now);
                switch ($result['bucket']) {
                    case 'stored':
                        $stored[] = $result['schoolseq'];
                        break;
                    case 'stale':
                        $stale[] = $result['schoolseq'];
                        $forks[] = $result['fork'];
                        break;
                    case 'fork':
                        $forks[] = $result['fork'];
                        if (!empty($result['reincarnate'])) {
                            $reincarnate = true;
                        }
                        break;
                }
            }

            // Persist the per-school head high-water (monotonic) for restore detection.
            epoch_store::observe(epoch_store::SCOPE_SCHOOL, $schoolid, $epoch, $headseq);

            // Dense-seq ack: highest school_seq whose whole prefix is now present.
            $ackedthrough = self::frontier($schoolid, $epoch);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return [
            'protocol_version' => 2,
            'status' => 'ok',
            'acked_through' => $ackedthrough,
            'stored' => $stored,
            'stale' => $stale,
            'forks' => $forks,
            'reincarnate_required' => $reincarnate,
            'central_epoch' => epoch_store::ensure_self()->epoch,
            'central_head_seq' => (int) $DB->get_field_sql('SELECT MAX(seq) FROM {local_syncqueue_outbox}'),
        ];
    }

    /**
     * Classify one item against the unique keys and buffer it when new.
     *
     * @param string $schoolid Pushing school id.
     * @param string $epoch School epoch.
     * @param array $item Wire item.
     * @param int $now Timestamp for the row.
     * @return array {bucket: 'stored'|'stale'|'fork', schoolseq: int,
     *         fork?: array, reincarnate?: bool}
     */
    protected static function classify_and_store(string $schoolid, string $epoch, array $item, int $now): array {
        global $DB;

        $schoolseq = (int) ($item['school_seq'] ?? 0);
        $ishole = (($item['kind'] ?? 'fact') === 'hole');
        $factuuid = (string) ($item['factuuid'] ?? '');
        $lineageuuid = (string) ($item['lineageuuid'] ?? '');
        $factversion = (int) ($item['factversion'] ?? 0);
        $payloadhash = (string) ($item['payloadhash'] ?? '');

        // A hole is a locally dead-lettered fact whose payload could not be sent;
        // the frontier still needs a marker at its school_seq. Synthesize a stable
        // identity if the school could not supply one so the unique keys hold.
        if ($ishole && $factuuid === '') {
            $factuuid = fact_identity::uuid_v5(fact_identity::SYNC_NAMESPACE, "hole:{$schoolid}:{$epoch}:{$schoolseq}");
        }
        if ($ishole && $lineageuuid === '') {
            $lineageuuid = $factuuid;
        }

        // (a) factuuid collision.
        $byfactuuid = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $factuuid]);
        if ($byfactuuid) {
            if ((string) $byfactuuid->payloadhash === $payloadhash) {
                // Same fact, same content. But first honour the slot contract: if the
                // incoming (schoolid, epoch, schoolseq) slot is already occupied by a
                // DIFFERENT fact, this replay is re-using a school_seq central assigned
                // to another fact — a same-slot incarnation fork (clone / rolled-back
                // snapshot), NOT a benign dedup. Defer to the fork path so the school
                // reincarnates, rather than silently acking over a divergent slot.
                // Only a REAL fact (factversion > 0) occupying the slot is a fork; a
                // synthetic hole/dedup marker (factversion 0) is not, and neither is
                // the fact's own original row (same factuuid) on a same-seq replay.
                $slotrow = $DB->get_record('local_syncqueue_ingest',
                    ['schoolid' => $schoolid, 'epoch' => $epoch, 'schoolseq' => $schoolseq]);
                if ($slotrow && (string) $slotrow->factuuid !== $factuuid && (int) $slotrow->factversion > 0) {
                    return ['bucket' => 'fork', 'schoolseq' => $schoolseq, 'reincarnate' => true,
                        'fork' => ['school_seq' => $schoolseq, 'tier' => 'incarnation',
                            'detail' => json_encode([
                                'reason' => 'same-fact replay onto a school_seq occupied by a different fact',
                                'existing_factuuid' => $slotrow->factuuid,
                            ])]];
                }

                // Benign replay: same exact fact version, same content. Already stored
                // under its original school_seq. But the school can re-export a fact
                // it already sent under a FRESH school_seq (anti-entropy regeneration,
                // a reincarnation requeue, or a concurrent duplicate-share), and that
                // new (schoolid, epoch, schoolseq) slot would otherwise never be
                // materialized — leaving a permanent gap that frontier() can never
                // cross, wedging this school's ack frontier forever. Drop a terminal
                // dedup marker in the empty slot so the contiguous frontier advances.
                self::mark_dedup_slot($schoolid, $epoch, $schoolseq, $factuuid, $now);
                return ['bucket' => 'stored', 'schoolseq' => $schoolseq];
            }
            // Same factuuid, different content: a restored ledger reused a version
            // central already holds. School re-exports at high-water + 1.
            return ['bucket' => 'stale', 'schoolseq' => $schoolseq,
                'fork' => self::lineage_fork($schoolseq, $lineageuuid, 'factuuid replay with a different payloadhash')];
        }

        // (b) (lineageuuid, factversion) collision with a different factuuid.
        $bylineage = $DB->get_record('local_syncqueue_ingest',
            ['lineageuuid' => $lineageuuid, 'factversion' => $factversion]);
        if ($bylineage) {
            return ['bucket' => 'stale', 'schoolseq' => $schoolseq,
                'fork' => self::lineage_fork($schoolseq, $lineageuuid,
                    'lineage version already held under factuuid ' . $bylineage->factuuid)];
        }

        // (c) (schoolid, epoch, schoolseq) collision with a different fact.
        // Incarnation fork (clone / rolled-back snapshot): return it as a fork but
        // do NOT buffer it, so the async applier (which only reads 'buffered'/
        // 'retry' rows) never applies a forked row. The reused slot stays owned by
        // the existing fact until the school re-incarnates under a fresh epoch.
        $byslot = $DB->get_record('local_syncqueue_ingest',
            ['schoolid' => $schoolid, 'epoch' => $epoch, 'schoolseq' => $schoolseq]);
        if ($byslot) {
            return ['bucket' => 'fork', 'schoolseq' => $schoolseq, 'reincarnate' => true,
                'fork' => ['school_seq' => $schoolseq, 'tier' => 'incarnation',
                    'detail' => json_encode([
                        'reason' => 'school_seq already occupied by a different fact',
                        'existing_factuuid' => $byslot->factuuid,
                    ])]];
        }

        // New: buffer the fact, or store a dead-marker for a hole so the frontier crosses it.
        $record = new stdClass();
        $record->schoolid = $schoolid;
        $record->epoch = $epoch;
        $record->schoolseq = $schoolseq;
        $record->factuuid = $factuuid;
        $record->lineageuuid = $lineageuuid;
        $record->factversion = $factversion;
        $record->facttype = (string) ($item['facttype'] ?? '');
        $record->entitykey = isset($item['entitykey']) ? (string) $item['entitykey'] : null;
        $record->payload = $ishole ? null : self::payload_string($item['payload'] ?? null);
        $record->payloadhash = $payloadhash;
        $record->rostergen = isset($item['rostergen']) && $item['rostergen'] !== null
            ? (int) $item['rostergen'] : null;
        $record->status = $ishole ? 'dead' : 'buffered';
        $record->attempts = 0;
        $record->lasterror = $ishole ? ('hole: ' . (string) ($item['reason'] ?? 'dead-lettered at school')) : null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_syncqueue_ingest', $record);

        return ['bucket' => 'stored', 'schoolseq' => $schoolseq];
    }

    /**
     * Materialize an empty school_seq slot with a terminal dedup marker so the
     * contiguous ack frontier can cross it.
     *
     * Called when a benign factuuid replay (same fact, same payloadhash) arrives at
     * a school_seq central has not seen: the fact's content is already stored under
     * its original seq, but the frontier walks actual ingest rows, so the new slot
     * must exist or acked_through stalls at it forever. The marker carries a
     * slot-scoped synthetic identity (never the real fact's factuuid /
     * lineageuuid+factversion) so it can't collide with the three unique keys, and
     * status 'dead' so the async applier never replays it. No-op when the slot is
     * already occupied (including by the original fact, i.e. a same-seq replay).
     *
     * @param string $schoolid Pushing school id.
     * @param string $epoch School epoch.
     * @param int $schoolseq The replayed slot to fill.
     * @param string $dupfactuuid The already-stored fact's uuid this replay matched.
     * @param int $now Timestamp for the row.
     */
    protected static function mark_dedup_slot(string $schoolid, string $epoch, int $schoolseq,
            string $dupfactuuid, int $now): void {
        global $DB;

        if ($DB->record_exists('local_syncqueue_ingest',
                ['schoolid' => $schoolid, 'epoch' => $epoch, 'schoolseq' => $schoolseq])) {
            return;
        }

        $markuuid = fact_identity::uuid_v5(fact_identity::SYNC_NAMESPACE,
            "dedup:{$schoolid}:{$epoch}:{$schoolseq}");
        $record = new stdClass();
        $record->schoolid = $schoolid;
        $record->epoch = $epoch;
        $record->schoolseq = $schoolseq;
        $record->factuuid = $markuuid;
        $record->lineageuuid = $markuuid;
        $record->factversion = 0;
        $record->facttype = 'dedup';
        $record->entitykey = null;
        $record->payload = null;
        $record->payloadhash = '';
        $record->rostergen = null;
        $record->status = 'dead';
        $record->attempts = 0;
        $record->lasterror = 'dedup: benign replay of ' . $dupfactuuid . ' at a new school_seq';
        $record->timecreated = $now;
        $record->timemodified = $now;
        $DB->insert_record('local_syncqueue_ingest', $record);
    }

    /**
     * Build a lineage-tier fork record carrying central's stored high-water so
     * the school can self-heal by re-exporting at high-water + 1.
     *
     * @param int $schoolseq School seq of the conflicting item.
     * @param string $lineageuuid Lineage in conflict.
     * @param string $reason Human-readable cause.
     * @return array {school_seq, tier, detail}
     */
    protected static function lineage_fork(int $schoolseq, string $lineageuuid, string $reason): array {
        return [
            'school_seq' => $schoolseq,
            'tier' => 'lineage',
            'detail' => json_encode([
                'reason' => $reason,
                'highwater' => self::lineage_highwater($lineageuuid),
            ]),
        ];
    }

    /**
     * Central's stored high-water factversion for a lineage (0 when unknown).
     *
     * @param string $lineageuuid Lineage UUID.
     * @return int
     */
    protected static function lineage_highwater(string $lineageuuid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT MAX(factversion) FROM {local_syncqueue_ingest} WHERE lineageuuid = :lu',
            ['lu' => $lineageuuid]);
    }

    /**
     * Highest school_seq whose entire prefix (from the last ack) is present.
     *
     * A row of any status (buffered, dead, applied, ...) counts as present, so a
     * hole dead-marker lets the frontier cross the gap it fills. Walks forward
     * from the persisted ack frontier and stops at the first gap, so only the
     * newly-contiguous rows are read; the frontier is persisted per school in the
     * central-side 'up' cursor (peer = schoolid).
     *
     * @param string $schoolid Pushing school id.
     * @param string $epoch School epoch.
     * @return int acked_through.
     */
    protected static function frontier(string $schoolid, string $epoch): int {
        global $DB;

        $floor = cursor::get($schoolid, self::UP);
        $expected = $floor + 1;

        $recordset = $DB->get_recordset_select('local_syncqueue_ingest',
            'schoolid = :sid AND epoch = :epoch AND schoolseq >= :fromseq',
            ['sid' => $schoolid, 'epoch' => $epoch, 'fromseq' => $expected],
            'schoolseq ASC', 'schoolseq');
        foreach ($recordset as $row) {
            $seq = (int) $row->schoolseq;
            if ($seq === $expected) {
                $expected++;
            } else if ($seq > $expected) {
                break;
            }
        }
        $recordset->close();

        $frontier = $expected - 1;
        if ($frontier > $floor) {
            cursor::advance($schoolid, self::UP, $frontier);
        }
        return $frontier;
    }

    /**
     * Normalize a wire payload to the JSON string stored in ingest.payload.
     *
     * The wire carries the fact payload as canonical JSON already; an array is
     * only seen when a caller hands receive_push a decoded payload directly.
     *
     * @param mixed $payload
     * @return string|null
     */
    protected static function payload_string($payload): ?string {
        if ($payload === null) {
            return null;
        }
        if (is_string($payload)) {
            return $payload;
        }
        return json_encode($payload);
    }
}
