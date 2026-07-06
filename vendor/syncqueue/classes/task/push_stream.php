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

namespace local_syncqueue\task;

use core\task\scheduled_task;
use local_syncqueue\epoch_guard;
use local_syncqueue\epoch_store;
use local_syncqueue\fact_ledger;
use local_syncqueue\outbox\cursor;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\sync_client;

/**
 * Scheduled task pushing the v2 sequenced upstream stream to central (school mode).
 *
 * Retained-until-ack loop (architecture doc §4.3): finalize identity inline via
 * the sequencer, read un-acked learner-partition outbox rows after the ack
 * cursor, push them in school_seq order, then process the response. A
 * reincarnate_required signal (incarnation fork / seq-regression) is handled
 * FIRST and short-circuits the run: its acked_through belongs to a foreign
 * incarnation's frontier, so the run must never prune on it — the epoch stage's
 * handshake re-seeds and re-queues the retained rows instead. Otherwise advance
 * the cursor to acked_through, prune acked rows (marking their ledger rows
 * acked), and self-heal lineage-version conflicts by re-exporting at central's
 * high-water + 1. Exits immediately when push_v2 is off, leaving the legacy
 * queue untouched.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_stream extends scheduled_task {

    /** @var string Peer name of the upstream stream on school instances. */
    const PEER = 'central';

    /** @var string Ack-frontier cursor direction. */
    const UP = 'up';

    /** @var int Rows pushed per batch. */
    const BATCH_LIMIT = 200;

    /** @var int Maximum batches per task run. */
    const MAX_BATCHES = 20;

    /** @var sync_client|null Lazily created client. */
    protected ?sync_client $client = null;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_pushstream', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        if (!get_config('local_syncqueue', 'push_v2')) {
            mtrace('push_v2 disabled');
            return;
        }

        $schoolid = get_config('local_syncqueue', 'schoolid');
        if (empty($schoolid)) {
            mtrace('push_stream: no schoolid configured; aborting');
            return;
        }
        $partition = 'learner:school:' . $schoolid;

        // A prior run flagged reincarnate_required (a handshake that failed, or a
        // fork/seq-regression central signalled mid-batch). Do NOT push on the
        // stale incarnation: go straight to the handshake and clear the flag only
        // on success. Without this gate the task re-pushes every 5 min, central
        // re-detects the same fork, and the flag is re-set forever — a busy loop.
        if (get_config('local_syncqueue', 'reincarnate_required')) {
            mtrace('push_stream: reincarnate_required set by a prior run; retrying the handshake '
                . 'before any push (v2 push is frozen until it succeeds)');
            try {
                epoch_guard::run_reincarnation_handshake();
                unset_config('reincarnate_required', 'local_syncqueue');
                mtrace('push_stream: re-incarnation handshake succeeded; v2 push resumes next run');
            } catch (\Throwable $e) {
                mtrace('push_stream: re-incarnation handshake still failing: ' . $e->getMessage());
            }
            return;
        }

        // Epoch guard: a DB restored onto a foreign dataroot shows a marker
        // mismatch. Run the re-incarnation handshake now and return; the next run
        // pushes under the freshly adopted epoch.
        $self = epoch_store::ensure_self();
        if (epoch_guard::check_self() === 'reincarnate') {
            mtrace('push_stream: epoch marker mismatch (DB restored onto a foreign dataroot); '
                . 'running re-incarnation handshake');
            try {
                epoch_guard::run_reincarnation_handshake();
                unset_config('reincarnate_required', 'local_syncqueue');
            } catch (\Throwable $e) {
                mtrace('push_stream: re-incarnation handshake failed: ' . $e->getMessage()
                    . '; freezing v2 push until the next run');
                set_config('reincarnate_required', 1, 'local_syncqueue');
            }
            return;
        }
        $epoch = $self->epoch;

        // Finalize identity on committed-but-unsequenced fact rows before reading.
        sequencer::assign();

        $readfrom = cursor::get(self::PEER, self::UP);

        for ($batch = 1; $batch <= self::MAX_BATCHES; $batch++) {
            $rows = $DB->get_records_select('local_syncqueue_outbox',
                'partitionkey = :pk AND seq IS NOT NULL AND seq > :fromseq',
                ['pk' => $partition, 'fromseq' => $readfrom], 'seq ASC', '*', 0, self::BATCH_LIMIT);
            if (empty($rows)) {
                if ($batch === 1) {
                    mtrace('push_stream: nothing to push (outbox drained past ack cursor ' . $readfrom . ')');
                }
                break;
            }

            $headseq = (int) $DB->get_field_sql(
                'SELECT MAX(seq) FROM {local_syncqueue_outbox} WHERE partitionkey = :pk', ['pk' => $partition]);

            try {
                $response = $this->push_batch(array_values($rows), $epoch, $headseq);
            } catch (\Exception $e) {
                mtrace('push_stream: push from seq ' . $readfrom . ' failed: ' . $e->getMessage());
                return;
            }

            if ((int) ($response->protocol_version ?? 0) !== 2) {
                mtrace('push_stream: unexpected protocol_version from central; aborting run');
                return;
            }

            // Reincarnation takes priority over the ack/prune below and must run
            // BEFORE it. On a reincarnate_required response, central's acked_through
            // belongs to a FOREIGN incarnation's contiguous frontier and can cover
            // school_seqs this incarnation reused for brand-new facts that central
            // forked out (never stored). Pruning on it (prune_acked deletes seq <=
            // acked unconditionally) would drop an un-acked fact that the handshake's
            // requeue_unacked — which only touches rows still present — can no longer
            // recover. So run the handshake and abandon the run WITHOUT pruning; the
            // handshake re-seeds above every high-water and re-queues the retained
            // rows, and factuuid dedup absorbs anything central already holds.
            if (!empty($response->reincarnate_required)) {
                mtrace('push_stream: central signalled reincarnate_required (incarnation fork or seq '
                    . 'regression); running re-incarnation handshake (skipping ack/prune of a foreign frontier)');
                try {
                    epoch_guard::run_reincarnation_handshake();
                    unset_config('reincarnate_required', 'local_syncqueue');
                } catch (\Throwable $e) {
                    mtrace('push_stream: re-incarnation handshake failed: ' . $e->getMessage()
                        . '; freezing v2 push until the next run');
                    set_config('reincarnate_required', 1, 'local_syncqueue');
                }
                return;
            }

            $acked = (int) $response->acked_through;
            $floor = cursor::get(self::PEER, self::UP);
            if ($acked > $floor) {
                cursor::advance(self::PEER, self::UP, $acked);
                $this->prune_acked($partition, $acked);
            }

            $healed = $this->self_heal($schoolid, $partition, $response);
            $this->observe_central($response);

            $lastseq = 0;
            foreach ($rows as $row) {
                $lastseq = max($lastseq, (int) $row->seq);
            }
            mtrace('push_stream: batch ' . $batch . ': pushed ' . count($rows) . ' row(s) (seq '
                . ((int) reset($rows)->seq) . '-' . $lastseq . '), acked_through ' . $acked
                . ', stored ' . count($response->stored) . ', stale ' . count($response->stale)
                . ', forks ' . count($response->forks) . ($healed ? ", self-healed {$healed}" : ''));

            $readfrom = max($readfrom, $lastseq);
            if (count($rows) < self::BATCH_LIMIT) {
                break;
            }
        }
    }

    /**
     * Push one batch. Separated so tests can stub the transport.
     *
     * @param array $rows Outbox rows to push, in seq order.
     * @param string $epoch This school's self epoch.
     * @param int $headseq This school's outbox head.
     * @return \stdClass Normalized push response (see sync_client::push()).
     */
    protected function push_batch(array $rows, string $epoch, int $headseq): \stdClass {
        if ($this->client === null) {
            $this->client = new sync_client();
        }
        return $this->client->push($rows, $epoch, $headseq);
    }

    /**
     * Prune acked outbox rows (retained-until-ack) and mark their ledger acked.
     *
     * @param string $partition Learner partition key.
     * @param int $acked Highest acked school_seq.
     */
    protected function prune_acked(string $partition, int $acked): void {
        global $DB;

        $select = 'partitionkey = :pk AND seq IS NOT NULL AND seq <= :acked';
        $params = ['pk' => $partition, 'acked' => $acked];

        $done = $DB->get_records_select('local_syncqueue_outbox', $select, $params, 'seq ASC',
            'id, seq, factuuid');
        foreach ($done as $row) {
            if (!empty($row->factuuid)) {
                fact_ledger::mark_status($row->factuuid, fact_ledger::STATUS_ACKED);
            }
        }
        $DB->delete_records_select('local_syncqueue_outbox', $select, $params);
    }

    /**
     * Self-heal lineage-version conflicts: bump each conflicting fact to a
     * version above central's high-water and re-queue a fresh outbox row.
     *
     * @param string $schoolid This school id.
     * @param string $partition Learner partition key.
     * @param \stdClass $response Normalized push response.
     * @return int Number of conflicts self-healed.
     */
    protected function self_heal(string $schoolid, string $partition, \stdClass $response): int {
        $healed = 0;
        foreach ($response->forks as $fork) {
            if (($fork->tier ?? '') !== 'lineage') {
                continue;
            }
            $detail = json_decode((string) $fork->detail, true);
            $highwater = is_array($detail) ? (int) ($detail['highwater'] ?? 0) : 0;
            try {
                // Best-effort: a failed heal (rolled back and re-thrown by Moodle)
                // must not abort the run — the row is retried on the next push.
                if ($this->reexport_above($partition, (int) $fork->school_seq, $highwater)) {
                    $healed++;
                }
            } catch (\Throwable $e) {
                mtrace('push_stream: self-heal of seq ' . (int) $fork->school_seq . ' failed: ' . $e->getMessage());
            }
        }
        return $healed;
    }

    /**
     * Re-export the fact at $schoolseq as a version strictly above both central's
     * high-water and this school's latest finalized version, then abandon the
     * conflicting row so it is never re-pushed.
     *
     * @param string $partition Learner partition key.
     * @param int $schoolseq School seq of the conflicting fact.
     * @param int $highwater Central's stored high-water version for the lineage.
     * @return bool True when a fresh row was queued.
     */
    protected function reexport_above(string $partition, int $schoolseq, int $highwater): bool {
        global $DB;

        $row = $DB->get_record_select('local_syncqueue_outbox',
            'partitionkey = :pk AND seq = :seq', ['pk' => $partition, 'seq' => $schoolseq]);
        if (!$row || $row->lineageuuid === null) {
            // Already pruned or not a fact row: nothing to heal.
            return false;
        }

        $latest = fact_ledger::latest_finalized($row->lineageuuid);
        $localhw = $latest ? (int) $latest->factversion : 0;
        $newversion = max($highwater, $localhw) + 1;

        $transaction = $DB->start_delegated_transaction();
        try {
            // Advance the fact's ledger high-water so the sequencer re-finalizes
            // the re-queued row at $newversion (same content reuses that version),
            // superseding central's conflicting copy instead of colliding again.
            if ($row->ledgerid !== null) {
                fact_ledger::finalize((int) $row->ledgerid, $newversion, null);
            }

            $fresh = clone $row;
            unset($fresh->id);
            $fresh->seq = null;
            $fresh->factversion = null;
            $fresh->factuuid = null;
            $fresh->entityversion = 0;
            $fresh->timecreated = time();
            $DB->insert_record('local_syncqueue_outbox', $fresh);

            // Convert the conflicting row IN PLACE into a hole marker rather than
            // deleting it. Central never buffered the stale fact (a lineage
            // conflict is not stored), so without a marker at this school_seq its
            // contiguous ack frontier for this school would stall here forever.
            // The hole keeps its seq, sheds its fact identity (central synthesizes
            // a slot identity from schoolid:epoch:seq), and is pushed as kind=hole;
            // central stores a dead-marker the frontier crosses, then it is pruned
            // once acked.
            $hole = new \stdClass();
            $hole->id = $row->id;
            $hole->action = 'hole';
            $hole->ledgerid = null;
            $hole->lineageuuid = null;
            $hole->factversion = null;
            $hole->factuuid = null;
            $hole->entityversion = 0;
            $hole->payload = null;
            $hole->timecreated = time();
            $DB->update_record('local_syncqueue_outbox', $hole);

            $transaction->allow_commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return false;
    }

    /**
     * Persist the observed central epoch/head and flag a central restore when
     * the head regresses under an unchanged epoch (the epoch stage re-bootstraps).
     *
     * @param \stdClass $response Normalized push response.
     */
    protected function observe_central(\stdClass $response): void {
        $centralepoch = (string) ($response->central_epoch ?? '');
        if ($centralepoch === '') {
            return;
        }
        $centralhead = (int) ($response->central_head_seq ?? 0);

        // Central restore (full-VM rollback restores central's DB and epoch
        // together): a head below the observed high-water under an unchanged
        // epoch. Freeze + flag for step 6's pull re-bootstrap; the school cannot
        // resolve this by re-incarnating itself.
        if (epoch_guard::detect_central_rollback($centralepoch, $centralhead)) {
            $prior = epoch_store::get(epoch_store::SCOPE_CENTRAL);
            mtrace('push_stream: central head regressed (' . (int) ($prior->headseq ?? 0) . ' -> ' . $centralhead
                . ') under an unchanged epoch; flagging central restore for the epoch stage');
            set_config('central_restore_detected', 1, 'local_syncqueue');
        }
        epoch_store::observe(epoch_store::SCOPE_CENTRAL, '', $centralepoch, $centralhead);
    }
}
