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
use local_syncqueue\central_processor;
use local_syncqueue\tenure;
use stdClass;

/**
 * Central async ingest applier (ELMS Sync v2 step 2, doc §4.3 buffer-then-apply).
 *
 * The push endpoint buffers school facts into local_syncqueue_ingest and acks the
 * contiguous stored frontier without applying anything, so a central PHP timeout
 * can never eat an ack and 50 schools pushing at once never stall FPM. This task
 * drains that buffer asynchronously: it picks not-yet-applied rows oldest-first
 * and replays each through the existing step-0-hardened central_processor
 * appliers (SDMS-only resolution) — the same code the legacy synchronous upload
 * path used, so grade/quiz/completion applier semantics are untouched (their
 * rework is step 4).
 *
 * Per-row outcome maps to the ingest status:
 *  - applier success                  -> applied
 *  - a strictly newer factversion for the same lineage is already applied -> stale
 *    (skipped before the applier runs; also the applier's own 'conflict' verdict)
 *  - retryable applier error / a throw -> retry (attempts++, dead at maxretries)
 *
 * Each row is applied in isolation, so one failure never aborts the batch, and an
 * already-applied (or dead/stale) row is never re-selected, so a re-run is a
 * no-op. Central mode only: a school instance never buffers inbound facts.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_ingest extends scheduled_task {

    /** @var int Rows drained per run. */
    const BATCH_LIMIT = 200;

    /** @var int Default retry budget before a row is dead-lettered. */
    const DEFAULT_MAXRETRIES = 5;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_applyingest', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        if (get_config('local_syncqueue', 'mode') !== 'central') {
            mtrace('apply_ingest: not central mode; nothing to apply');
            return;
        }
        if (!get_config('local_syncqueue', 'enabled')) {
            mtrace('apply_ingest: sync disabled; skipping');
            return;
        }

        $maxretries = (int) get_config('local_syncqueue', 'ingest_maxretries');
        if ($maxretries <= 0) {
            $maxretries = self::DEFAULT_MAXRETRIES;
        }

        // Not-yet-applied rows, oldest-first. Holes are stored 'dead' and applied
        // / stale rows are terminal, so only buffered + retry rows are drained; a
        // retry row is naturally re-attempted on a later run (5-minute backoff).
        $rows = $DB->get_records_select('local_syncqueue_ingest',
            "status = 'buffered' OR status = 'retry'", [], 'id ASC', '*', 0, self::BATCH_LIMIT);
        if (empty($rows)) {
            return;
        }

        $processor = new central_processor();
        // v2 facts are authoritative-by-factversion: apply_row already enforces
        // supersession ordering (a higher applied factversion wins), so the
        // applier's wall-clock LWW gate must not additionally reject an update
        // whose source-deterministic payload carries no dispatch clock — that
        // would silently drop every regrade/re-submission after the first write.
        $processor->set_authoritative(true);
        $applied = 0;
        $stale = 0;
        $retry = 0;
        $dead = 0;

        foreach ($rows as $row) {
            try {
                $outcome = $this->apply_row($processor, $row, $maxretries);
            } catch (\Throwable $e) {
                // An applier that throws must never abort the rest of the batch.
                $outcome = $this->fail($row, $maxretries, 'apply threw: ' . $e->getMessage());
            }
            switch ($outcome) {
                case 'applied':
                    $applied++;
                    break;
                case 'stale':
                    $stale++;
                    break;
                case 'dead':
                    $dead++;
                    break;
                default:
                    $retry++;
                    break;
            }
        }

        mtrace('apply_ingest: ' . count($rows) . ' row(s): ' . $applied . ' applied, '
            . $stale . ' stale, ' . $retry . ' retry, ' . $dead . ' dead');
    }

    /**
     * Apply one buffered/retry ingest row through central_processor and persist
     * its resulting status.
     *
     * @param central_processor $processor Shared applier (its id-mapper caches per school).
     * @param stdClass $row The ingest row.
     * @param int $maxretries Retry budget before dead-lettering.
     * @return string Outcome bucket: applied|stale|retry|dead.
     */
    protected function apply_row(central_processor $processor, stdClass $row, int $maxretries): string {
        global $DB;

        // v2 staleness (AGS, doc §8.1): a strictly newer version of this lineage
        // has already applied and supersedes this one — never clobber it with an
        // older fact. Ordering comes from factversion, not a wall clock.
        $superseded = $DB->record_exists_select('local_syncqueue_ingest',
            'lineageuuid = :lu AND status = :applied AND factversion > :fv',
            ['lu' => $row->lineageuuid, 'applied' => 'applied', 'fv' => (int) $row->factversion]);
        if ($superseded) {
            return $this->settle($row, 'stale', (int) $row->attempts,
                'superseded: a higher factversion for this lineage is already applied');
        }

        $payload = json_decode((string) $row->payload, true);
        if (!is_array($payload)) {
            return $this->fail($row, $maxretries, 'ingest payload is not decodable JSON');
        }

        // Shape the item exactly as the legacy upload path did for process_item:
        // {id, eventtype, eventname, payload}. The applier only reads eventtype +
        // payload; id/eventname are carried for shape fidelity and diagnostics.
        $item = [
            'id' => (int) $row->id,
            'eventtype' => central_processor::eventtype_for_facttype((string) $row->facttype),
            'eventname' => $payload['event']['eventname'] ?? '',
            'payload' => $payload,
        ];

        // Give the applier this fact's ordering + tenure context (from the ingest
        // row) so the v2 appliers tenure-gate and AGS-order the write by
        // factversion + tenure, never a wall clock. Cleared after so it never
        // leaks to the next row.
        $processor->set_fact_context([
            'origin' => (string) $row->schoolid,
            'epoch' => (string) $row->epoch,
            'schoolseq' => (int) $row->schoolseq,
            'rostergen' => ($row->rostergen !== null) ? (int) $row->rostergen : null,
            'lineageuuid' => (string) $row->lineageuuid,
            'factuuid' => (string) $row->factuuid,
            'factversion' => (int) $row->factversion,
            'facttype' => (string) $row->facttype,
            'entitykey' => ($row->entitykey !== null) ? (string) $row->entitykey : null,
        ]);
        try {
            $result = $processor->process_item((string) $row->schoolid, $item);
        } finally {
            $processor->set_fact_context(null);
        }
        $status = $result['status'] ?? 'error';
        $message = (string) ($result['message'] ?? '');

        if ($status === 'success') {
            return $this->settle($row, 'applied', (int) $row->attempts, null);
        }
        if ($status === 'tenurefail') {
            // The origin did not hold home tenure for this learner at the fact's
            // roster generation (doc §8.1). Record the true rejection in the
            // conflicts table and mark the row stale — never silently dropped,
            // never wall-clock compared.
            $this->record_tenure_conflict($row, $result);
            return $this->settle($row, 'stale', (int) $row->attempts,
                $message !== '' ? $message : 'tenure not in force');
        }
        if ($status === 'stale') {
            // AGS out-of-order arrival (doc §8.1): terminal, logged, no retry burn.
            return $this->settle($row, 'stale', (int) $row->attempts,
                $message !== '' ? $message : 'AGS stale');
        }
        if ($status === 'conflict') {
            // Legacy wall-clock verdict (the v2 path uses tenure/AGS above); either
            // way the fact did not apply and is not a transport failure, so record it
            // as stale — logged, terminal, no retry burn.
            return $this->settle($row, 'stale', (int) $row->attempts, $message !== '' ? $message : 'applier reported conflict');
        }

        // Any other status ('error', unknown type, missing dependency) is retryable:
        // the applier defers unresolved SDMS/course/module until it appears.
        return $this->fail($row, $maxretries, $message !== '' ? $message : 'applier error');
    }

    /**
     * Record a tenure-fail rejection in the conflicts table (never a silent drop).
     *
     * @param stdClass $row The ingest row.
     * @param array $result The applier's tenurefail result (sdms, entitykey, rostergen, message).
     */
    protected function record_tenure_conflict(stdClass $row, array $result): void {
        tenure::record_conflict([
            'facttype' => (string) $row->facttype,
            'lineageuuid' => (string) $row->lineageuuid,
            'factuuid' => (string) $row->factuuid,
            'origin' => (string) $row->schoolid,
            'sdms' => $result['sdms'] ?? null,
            'entitykey' => $result['entitykey'] ?? ($row->entitykey ?? null),
            'rostergen' => $result['rostergen'] ?? (($row->rostergen !== null) ? (int) $row->rostergen : null),
            'reason' => 'tenure_not_in_force',
            'detail' => ['message' => (string) ($result['message'] ?? '')],
            'status' => 'open',
        ]);
    }

    /**
     * Bump the attempt count and route a retryable failure to retry, or to dead
     * once the budget is spent.
     *
     * @param stdClass $row The ingest row.
     * @param int $maxretries Retry budget.
     * @param string $message Error to record.
     * @return string 'retry' or 'dead'.
     */
    protected function fail(stdClass $row, int $maxretries, string $message): string {
        $attempts = (int) $row->attempts + 1;
        $status = ($attempts >= $maxretries) ? 'dead' : 'retry';
        return $this->settle($row, $status, $attempts, $message);
    }

    /**
     * Persist a row's status transition.
     *
     * @param stdClass $row The ingest row.
     * @param string $status New status (applied|stale|retry|dead).
     * @param int $attempts Attempt count to store.
     * @param string|null $lasterror Diagnostic error, or null to clear it.
     * @return string The status, for the caller's tally.
     */
    protected function settle(stdClass $row, string $status, int $attempts, ?string $lasterror): string {
        global $DB;

        $update = new stdClass();
        $update->id = (int) $row->id;
        $update->status = $status;
        $update->attempts = $attempts;
        $update->lasterror = $lasterror;
        $update->timemodified = time();
        $DB->update_record('local_syncqueue_ingest', $update);

        return $status;
    }
}
