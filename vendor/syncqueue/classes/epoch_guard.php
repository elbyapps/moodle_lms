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
use local_syncqueue\outbox\sequencer;

/**
 * Minimal epoch guard + re-incarnation handshake (ELMS Sync v2 §4.5).
 *
 * An epoch is a UUID naming one database incarnation. epoch_store holds and
 * compares epoch state without side effects; this class carries the POLICY the
 * upstream protocol needs so the new seq/ack/ledger stream is restore- and
 * clone-safe in the window before the full step-6 re-incarnation + snapshot:
 *
 *  - Detection (school): a DB restored onto a foreign dataroot shows a marker
 *    mismatch (check_self); central seq-regression / fork detection (built into
 *    ingest_manager) and central head rollback (detect_central_rollback) are the
 *    other two signals.
 *  - Handshake (school): run_reincarnation_handshake asks central for a fresh
 *    epoch seeded above every prior high-water, adopts it (DB row + dataroot
 *    marker), reseeds the outbox seq counter, and re-queues un-acked facts so
 *    they replay under the new epoch (factuuid dedup absorbs the replays).
 *  - Issuance (central): central_issue_epoch mints the new epoch and computes
 *    the seed so the school's next school_seq clears all of central's stored
 *    high-waters for that school across any prior epoch.
 *
 * Identity is deterministic (UUIDv5), so re-pushed facts keep their factuuids
 * and dedup exactly against whatever central still holds — un-acked facts are
 * never quarantined into limbo (§4.5).
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class epoch_guard {

    /** @var string Peer name of the upstream ack stream on a school. */
    const PEER = 'central';

    /** @var string Ack-frontier cursor direction (school -> central). */
    const UP = 'up';

    /**
     * School-side self check run at push/pull task start.
     *
     * @return string 'reincarnate' when the stored self epoch no longer matches
     *         the dataroot marker (a DB restored/cloned onto a foreign dataroot);
     *         'ok' otherwise (including 'missing'/'uninitialised', which
     *         epoch_store heals rather than treating as a restore).
     */
    public static function check_self(): string {
        return epoch_store::marker_status() === 'mismatch' ? 'reincarnate' : 'ok';
    }

    /**
     * School side: run the re-incarnation handshake and adopt a fresh epoch.
     *
     * The network call to central happens first, outside any DB transaction; the
     * local adoption (self epoch row + reseeded seq counter + re-queued un-acked
     * facts) commits atomically, and the dataroot marker — a filesystem write,
     * not transactional — is written only after that commit so a rolled-back
     * adoption can never leave a marker ahead of the DB epoch (which would read
     * as a fresh mismatch and simply retry the handshake).
     *
     * @throws \moodle_exception When central returns no usable epoch/seed.
     */
    public static function run_reincarnation_handshake(): void {
        global $DB;

        $self = epoch_store::ensure_self();
        $oldepoch = $self->epoch;

        // 1. Ask central for a new epoch seeded above every prior high-water.
        $response = static::make_client()->reincarnate($oldepoch);
        $newepoch = trim((string) ($response->new_epoch ?? ''));
        $seedseq = (int) ($response->seed_seq ?? 0);
        if ($newepoch === '' || $newepoch === $oldepoch || $seedseq < 1) {
            throw new \moodle_exception('error_syncfailed', 'local_syncqueue', '',
                'reincarnation handshake returned no usable epoch/seed');
        }

        // 2. Adopt the new epoch locally in one transaction.
        $transaction = $DB->start_delegated_transaction();
        try {
            $self->epoch = $newepoch;
            $self->bootcount = (int) $self->bootcount + 1;
            $self->timemodified = time();
            $DB->update_record('local_syncqueue_epoch', $self);

            // The seq counter holds the LAST assigned value, so store seedseq - 1
            // to make the next assigned school_seq exactly seedseq. Monotonic:
            // never lower a counter that already ran ahead of central's high-water.
            self::reseed_outbox_counter($seedseq - 1);

            // Re-queue un-acked facts under the new epoch: strip seq + finalized
            // identity so the sequencer re-assigns a fresh seq (>= seedseq) and
            // re-derives the SAME factuuid (unchanged content reuses the ledger
            // version), which central dedups on replay. ledgerid is preserved so
            // the sequencer re-finalizes and re-exports the ledger row.
            self::requeue_unacked();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // 3. Mirror the adopted epoch into the dataroot marker (post-commit).
        epoch_store::write_marker($newepoch, (int) $self->bootcount);

        // Adoption succeeded: clear any freeze flag a prior push/pull run set.
        unset_config('reincarnate_required', 'local_syncqueue');

        mtrace('epoch_guard: adopted new epoch ' . $newepoch . ' (was ' . $oldepoch
            . '); reseeded outbox seq counter to ' . ($seedseq - 1)
            . '; re-queued un-acked facts for replay under the new epoch');
    }

    /**
     * Build the sync client for the handshake. A seam so tests can stub the
     * transport (cf. push_stream::push_batch).
     *
     * @return sync_client
     */
    protected static function make_client(): sync_client {
        return new sync_client();
    }

    /**
     * Central side: mint a new epoch for a re-incarnating school and compute the
     * seed so its next school_seq clears every high-water central has stored.
     *
     * seed_seq is central's highest stored school_seq for this school across ANY
     * prior epoch, plus one. observe(SCOPE_SCHOOL, ...) records the new epoch at
     * head-seq seed_seq - 1, so the school's first push under it (head_seq >=
     * seed_seq) is not mistaken for a seq regression.
     *
     * The per-school ack frontier cursor is also advanced to seed_seq - 1 so the
     * new epoch's frontier starts expecting seed_seq (the first re-pushed
     * school_seq). Without it, a gap between the retired epoch's ack floor and
     * seed_seq (rows central buffered but had not yet acked) would wedge
     * acked_through at the old floor: the epoch-filtered frontier could never
     * reach the reincarnated rows and the school could never prune.
     *
     * Runs on the receiving (central) side regardless of this box's configured
     * mode — the mode gate lives in the reincarnate external function.
     *
     * @param string $schoolid Re-incarnating school id.
     * @param string $oldepoch The epoch the school is retiring (informational).
     * @return array ['new_epoch' => string, 'seed_seq' => int]
     */
    public static function central_issue_epoch(string $schoolid, string $oldepoch): array {
        global $DB;

        $newepoch = \core\uuid::generate();

        // High-water across ANY epoch: authoritative from the ingest buffer,
        // backed by the epoch registry in case ingest payloads were pruned.
        $ingesthw = (int) $DB->get_field_sql(
            'SELECT MAX(schoolseq) FROM {local_syncqueue_ingest} WHERE schoolid = :sid',
            ['sid' => $schoolid]);
        $priorrow = epoch_store::get(epoch_store::SCOPE_SCHOOL, $schoolid);
        $reghw = $priorrow ? (int) $priorrow->headseq : 0;
        $seedseq = max($ingesthw, $reghw) + 1;

        // Record the new incarnation's baseline (resets headseq on the epoch change).
        epoch_store::observe(epoch_store::SCOPE_SCHOOL, $schoolid, $newepoch, $seedseq - 1);

        // Rebase the per-school ack frontier onto the seed so the new epoch's
        // frontier() starts at seed_seq instead of the retired epoch's ack floor.
        // Monotonic: seed_seq - 1 is central's cross-epoch high-water, always >=
        // any contiguous ack the frontier had already reached, so this never
        // regresses a live cursor (cursor::advance also guards this in SQL).
        cursor::advance($schoolid, self::UP, $seedseq - 1);

        return ['new_epoch' => $newepoch, 'seed_seq' => $seedseq];
    }

    /**
     * School side: has central's outbox head regressed under an unchanged epoch?
     *
     * A full-VM rollback restores central's DB and its epoch together, so the
     * epoch alone cannot reveal it. Each school persists the last central head it
     * observed (epoch_store SCOPE_CENTRAL); a head below that high-water for the
     * SAME epoch is a central restore. Pure detection — does not observe.
     *
     * @param string $centralepoch Central epoch echoed on the response.
     * @param int $centralhead Central outbox head echoed on the response.
     * @return bool True when a central restore is detected.
     */
    public static function detect_central_rollback(string $centralepoch, int $centralhead): bool {
        if ($centralepoch === '') {
            return false;
        }
        $prior = epoch_store::get(epoch_store::SCOPE_CENTRAL);
        return $prior && $prior->epoch === $centralepoch && $centralhead < (int) $prior->headseq;
    }

    /**
     * Set the 'outbox' seq counter to a given last-assigned value, monotonically.
     *
     * @param int $lastassigned Value the counter should hold (next seq is +1).
     */
    protected static function reseed_outbox_counter(int $lastassigned): void {
        global $DB;

        $lastassigned = max(0, $lastassigned);

        if (!$DB->record_exists('local_syncqueue_seq', ['name' => sequencer::COUNTER])) {
            $counter = new \stdClass();
            $counter->name = sequencer::COUNTER;
            $counter->value = $lastassigned;
            try {
                $DB->insert_record('local_syncqueue_seq', $counter);
                return;
            } catch (\dml_write_exception $e) {
                // Concurrent creator won the unique-name race; fall through to raise.
            }
        }

        // Never lower a live counter: a reseed only ever clears a HIGHER prior
        // high-water; dropping below an already-emitted seq risks reuse. The guard
        // lives in SQL (mirroring cursor::advance) because this reseed runs OUTSIDE
        // the sequencer's named lock — a concurrent sequencer::assign() that
        // advanced the counter above $lastassigned between a plain read and a
        // set_field would otherwise be clobbered back down, reusing school_seqs.
        $DB->execute(
            'UPDATE {local_syncqueue_seq} SET value = :v1 WHERE name = :n AND value < :v2',
            ['v1' => $lastassigned, 'n' => sequencer::COUNTER, 'v2' => $lastassigned]);
    }

    /**
     * Re-queue this school's sequenced-but-un-acked learner facts for replay.
     *
     * Retained-until-ack means every learner-partition outbox row still present
     * with a seq is un-acked, so clearing seq + finalized identity (keeping
     * ledgerid) hands them back to the sequencer to re-assign fresh seqs under
     * the adopted epoch.
     */
    protected static function requeue_unacked(): void {
        global $DB;

        $schoolid = get_config('local_syncqueue', 'schoolid');
        if (empty($schoolid)) {
            return;
        }
        $partition = 'learner:school:' . $schoolid;

        $DB->execute(
            'UPDATE {local_syncqueue_outbox}
                SET seq = NULL, factversion = NULL, factuuid = NULL, entityversion = 0
              WHERE partitionkey = :pk AND seq IS NOT NULL',
            ['pk' => $partition]);
    }
}
