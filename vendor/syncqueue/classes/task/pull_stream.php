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
use local_syncqueue\central_restore;
use local_syncqueue\outbox\applied_state;
use local_syncqueue\outbox\cursor;
use local_syncqueue\sync_client;
use local_syncqueue\update_processor;

/**
 * Scheduled task pulling the v2 sequenced stream from central (school mode).
 *
 * Apply-then-checkpoint loop (architecture doc section 4.2): pull a batch
 * after the local cursor, apply each row idempotently through the existing
 * appliers, deadletter failures (the batch always continues), then advance
 * the cursor to the response's advance_to. Deadletter rows in 'retry' state
 * are re-attempted before each batch, guarded against superseded versions.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pull_stream extends scheduled_task {

    /** @var string Peer name of the downstream stream on school instances. */
    const PEER = 'central';

    /** @var string Stream direction. */
    const DIRECTION = 'down';

    /** @var int Rows requested per pull batch. */
    const BATCH_LIMIT = 200;

    /** @var int Maximum batches per task run. */
    const MAX_BATCHES = 20;

    /** @var int Counted failed applies before a deadletter row goes dead. */
    const MAX_ATTEMPTS = 5;

    /** @var sync_client|null Lazily created client. */
    protected ?sync_client $client = null;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_pullstream', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (!get_config('local_syncqueue', 'pull_v2')) {
            mtrace('pull_v2 disabled');
            return;
        }

        try {
            $processor = new update_processor();
        } catch (\Exception $e) {
            mtrace('pull_stream: cannot start: ' . $e->getMessage());
            return;
        }

        for ($batch = 1; $batch <= self::MAX_BATCHES; $batch++) {
            $this->replay_deadletters($processor);

            $afterseq = cursor::get(self::PEER, self::DIRECTION);
            try {
                $response = $this->pull_batch($afterseq);
            } catch (\Exception $e) {
                mtrace("pull_stream: pull after seq {$afterseq} failed: " . $e->getMessage());
                return;
            }

            if ((int)($response->protocol_version ?? 0) !== 2) {
                mtrace('pull_stream: unexpected protocol_version from central; aborting run');
                return;
            }

            $minretained = (int)($response->min_retained_seq ?? 1);
            if ($afterseq + 1 < $minretained) {
                // Rows between our cursor and min_retained_seq were pruned before
                // we applied them; only a snapshot bootstrap can close that gap.
                mtrace("pull_stream: WARNING cursor {$afterseq} is behind min_retained_seq {$minretained}: "
                    . 'rows were pruned unapplied, a snapshot bootstrap is required');
            }

            $rows = $response->rows ?? [];
            usort($rows, static function($a, $b) {
                return (int)$a->seq <=> (int)$b->seq;
            });

            $counts = ['applied' => 0, 'stale' => 0, 'failed' => 0];
            foreach ($rows as $row) {
                $counts[$this->process_row($processor, $row)]++;
            }

            // Checkpoint advance_to (highest seq central scanned), never the
            // last row's seq: windows emptied by supersession must still advance.
            $advanceto = (int)($response->advance_to ?? $afterseq);
            $headseq = (int)($response->head_seq ?? 0);
            // Persist central's head and flag a restore if it regressed below what we saw
            // (§4.5) — a full-VM rollback that restores central's epoch is caught here too.
            central_restore::observe_head($headseq);
            if ($advanceto > $afterseq) {
                cursor::advance(self::PEER, self::DIRECTION, $advanceto);
            }

            mtrace("pull_stream: batch {$batch}: " . count($rows) . " row(s), {$counts['applied']} applied, "
                . "{$counts['stale']} stale-skipped, {$counts['failed']} deadlettered; "
                . "cursor {$afterseq} -> " . max($afterseq, $advanceto) . " (head {$headseq})");

            if ($advanceto >= $headseq || $advanceto <= $afterseq) {
                break;
            }
        }

        // If central regressed this run (a restore), re-incarnate: re-bootstrap the
        // downstream and re-queue school-owned facts. A no-op when no restore is pending.
        if (central_restore::required()) {
            $outcome = central_restore::handle();
            mtrace('pull_stream: central restore re-incarnation: ' . ($outcome['status'] ?? 'none'));
        }
    }

    /**
     * Fetch one batch from central. Separated so tests can stub the transport.
     *
     * @param int $afterseq Return rows with seq greater than this.
     * @return \stdClass Normalized pull response (see sync_client::pull()).
     */
    protected function pull_batch(int $afterseq): \stdClass {
        if ($this->client === null) {
            $this->client = new sync_client();
        }
        return $this->client->pull($afterseq, self::BATCH_LIMIT);
    }

    /**
     * Apply one stream row: staleness check, dispatch, applied-state recording.
     *
     * @param update_processor $processor
     * @param \stdClass $row Normalized stream row.
     * @return string One of 'applied', 'stale', 'failed'.
     */
    protected function process_row(update_processor $processor, \stdClass $row): string {
        $rowversion = (int)$row->entityversion;

        $state = applied_state::get($row->entitytype, $row->entitykey);
        if ($state && ((int)$state->entityversion > $rowversion
                || ((int)$state->entityversion === $rowversion && $state->payloadhash === $row->payloadhash))) {
            // Never apply an older version; an equal version with the same hash
            // is already applied. Equal version with a different hash re-applies
            // (self-repair; versions bump under a row lock so it should not occur).
            mtrace("  stale-skip seq {$row->seq} {$row->entitytype} {$row->entitykey} "
                . "v{$rowversion} (applied v{$state->entityversion})");
            return 'stale';
        }

        try {
            $localid = $processor->apply_outbox_row($row);
            applied_state::upsert($row->entitytype, $row->entitykey, $rowversion,
                (string)$row->payloadhash, $localid ?: null);
            mtrace("  applied seq {$row->seq} {$row->entitytype} {$row->entitykey} v{$rowversion}"
                . ' -> local id ' . ($localid ?: 'none'));
            return 'applied';
        } catch (\Throwable $e) {
            // Dependency-missing failures stay in 'retry' without counting
            // towards 'dead': they resolve themselves once the dependency lands.
            $countattempt = !($e instanceof \local_syncqueue\dependency_missing_exception);
            mtrace("  failed seq {$row->seq} {$row->entitytype} {$row->entitykey} v{$rowversion}: "
                . $e->getMessage());
            $this->deadletter($row, $e->getMessage(), $countattempt);
            return 'failed';
        }
    }

    /**
     * Record a failed row in the deadletter queue (the batch continues and the
     * cursor still advances past it).
     *
     * The stored payload is a self-describing envelope, not the bare entity
     * payload: replay needs action/payloadhash/contentversion and the
     * deadletter table has no columns for them.
     *
     * @param \stdClass $row Normalized stream row.
     * @param string $error Apply error message.
     * @param bool $countattempt Whether this failure counts towards 'dead'.
     */
    protected function deadletter(\stdClass $row, string $error, bool $countattempt): void {
        global $DB;

        $envelope = json_encode([
            'v2envelope' => 1,
            'action' => (string)$row->action,
            'payloadhash' => (string)$row->payloadhash,
            'contentversion' => $row->contentversion ?? null,
            'partitionkey' => (string)($row->partitionkey ?? ''),
            'payload' => $row->payload ?? null,
        ]);

        // One retry row per entity+version: a re-scan of the same stream row
        // (e.g. after a cursor rewind) updates it instead of duplicating.
        $existing = $DB->get_record('local_syncqueue_deadletter', [
            'peer' => self::PEER,
            'direction' => self::DIRECTION,
            'entitytype' => $row->entitytype,
            'entitykey' => $row->entitykey,
            'entityversion' => (int)$row->entityversion,
            'status' => 'retry',
        ]);

        if ($existing) {
            $existing->seq = isset($row->seq) ? (int)$row->seq : null;
            $existing->payload = $envelope;
            $existing->error = $error;
            if ($countattempt) {
                $existing->attempts = (int)$existing->attempts + 1;
                if ($existing->attempts >= self::MAX_ATTEMPTS) {
                    $existing->status = 'dead';
                    mtrace("  deadletter {$existing->id} {$row->entitytype} {$row->entitykey} "
                        . "v{$row->entityversion}: {$existing->attempts} attempts, marked dead");
                }
            }
            $existing->timemodified = time();
            $DB->update_record('local_syncqueue_deadletter', $existing);
            return;
        }

        $record = new \stdClass();
        $record->peer = self::PEER;
        $record->direction = self::DIRECTION;
        $record->seq = isset($row->seq) ? (int)$row->seq : null;
        $record->entitytype = $row->entitytype;
        $record->entitykey = $row->entitykey;
        $record->entityversion = (int)$row->entityversion;
        $record->payload = $envelope;
        $record->error = $error;
        $record->attempts = $countattempt ? 1 : 0;
        $record->status = 'retry';
        $record->timecreated = time();
        $record->timemodified = time();
        $DB->insert_record('local_syncqueue_deadletter', $record);
    }

    /**
     * Re-attempt deadletter rows in 'retry' state, with the staleness guard:
     * rows superseded by a newer applied entityversion are marked 'replayed'
     * without re-applying.
     *
     * @param update_processor $processor
     */
    protected function replay_deadletters(update_processor $processor): void {
        global $DB;

        $pending = $DB->get_records('local_syncqueue_deadletter',
            ['peer' => self::PEER, 'direction' => self::DIRECTION, 'status' => 'retry'], 'id ASC');
        if (!$pending) {
            return;
        }
        mtrace('pull_stream: replaying ' . count($pending) . ' deadletter row(s)');

        foreach ($pending as $dl) {
            $dlversion = (int)$dl->entityversion;
            $envelope = json_decode((string)$dl->payload, true);

            $state = applied_state::get($dl->entitytype, $dl->entitykey);
            if ($state && ((int)$state->entityversion > $dlversion
                    || ((int)$state->entityversion === $dlversion && is_array($envelope)
                        && $state->payloadhash === (string)($envelope['payloadhash'] ?? '')))) {
                $dl->status = 'replayed';
                $dl->error = "superseded: entityversion {$state->entityversion} already applied, "
                    . 'not re-applied; last error: ' . $dl->error;
                $dl->timemodified = time();
                $DB->update_record('local_syncqueue_deadletter', $dl);
                mtrace("  dlq {$dl->id} {$dl->entitytype} {$dl->entitykey} v{$dlversion}: "
                    . 'superseded, marked replayed');
                continue;
            }

            if (!is_array($envelope) || empty($envelope['v2envelope'])) {
                $dl->status = 'dead';
                $dl->error = 'unreplayable deadletter payload (missing v2 envelope); last error: ' . $dl->error;
                $dl->timemodified = time();
                $DB->update_record('local_syncqueue_deadletter', $dl);
                mtrace("  dlq {$dl->id} {$dl->entitytype} {$dl->entitykey} v{$dlversion}: "
                    . 'no v2 envelope, marked dead');
                continue;
            }

            $row = (object)[
                'seq' => $dl->seq,
                'entitytype' => $dl->entitytype,
                'entitykey' => $dl->entitykey,
                'entityversion' => $dlversion,
                'action' => (string)($envelope['action'] ?? 'upsert'),
                'payload' => $envelope['payload'] ?? null,
                'payloadhash' => (string)($envelope['payloadhash'] ?? ''),
                'contentversion' => $envelope['contentversion'] ?? null,
                'partitionkey' => (string)($envelope['partitionkey'] ?? ''),
            ];

            try {
                $localid = $processor->apply_outbox_row($row);
                applied_state::upsert($row->entitytype, $row->entitykey, $dlversion,
                    $row->payloadhash, $localid ?: null);
                $dl->status = 'replayed';
                $dl->error = 'replayed successfully; previous error: ' . $dl->error;
                $dl->timemodified = time();
                $DB->update_record('local_syncqueue_deadletter', $dl);
                mtrace("  dlq {$dl->id} {$dl->entitytype} {$dl->entitykey} v{$dlversion}: replayed OK"
                    . ' -> local id ' . ($localid ?: 'none'));
            } catch (\Throwable $e) {
                $dl->error = $e->getMessage();
                if (!($e instanceof \local_syncqueue\dependency_missing_exception)) {
                    $dl->attempts = (int)$dl->attempts + 1;
                    if ($dl->attempts >= self::MAX_ATTEMPTS) {
                        $dl->status = 'dead';
                    }
                }
                $dl->timemodified = time();
                $DB->update_record('local_syncqueue_deadletter', $dl);
                mtrace("  dlq {$dl->id} {$dl->entitytype} {$dl->entitykey} v{$dlversion}: "
                    . "replay failed (attempts {$dl->attempts}, status {$dl->status}): " . $e->getMessage());
            }
        }
    }
}
