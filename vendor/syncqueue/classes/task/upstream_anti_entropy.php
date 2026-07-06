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
use local_syncqueue\capture;
use local_syncqueue\digest;
use local_syncqueue\fact_ledger;
use local_syncqueue\sync_client;

/**
 * School-side UPSTREAM anti-entropy (ELMS Sync v2 step 6, doc §9).
 *
 * The mirror of {@see anti_entropy}: it converges the FACTS this school authored against
 * what central has received. Weekly, it digests its pushed facts (the fact ledger),
 * asks central for the digest of what it received from this school, drills the divergent
 * buckets, and RE-QUEUES the facts central is missing or stale on by regenerating them
 * from local source tables (§9.1). A central that lost a week to a restore is recovered
 * from the fleet — the school re-pushes the affected learners' facts.
 *
 * Regeneration reconstructs the exact source event and re-captures it: because a fact's
 * payload includes the acting user, a re-capture yields a NEW version (never an
 * idempotent no-op), which central applies to restore the terminal state. A fact central
 * lacks whose source row is gone can't be regenerated and is surfaced as a local loss.
 *
 * v1 regenerates GRADE facts (the primary learner fact); other fact types are deferred
 * with the capture-scan. School mode + push_v2 only.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upstream_anti_entropy extends scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_upstreamantientropy', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (get_config('local_syncqueue', 'mode') !== 'school'
                || !get_config('local_syncqueue', 'enabled')
                || !get_config('local_syncqueue', 'push_v2')) {
            return;
        }
        $schoolid = (string) (get_config('local_syncqueue', 'schoolid') ?: '');
        if ($schoolid === '') {
            return;
        }

        $authored = digest::school_authored_map($schoolid);
        $localsummary = digest::summary($authored);

        $client = new sync_client();
        $summaryresp = $client->digest('upsummary', '');
        if (!empty($summaryresp['upgrade'])) {
            mtrace('upstream_anti_entropy: central digest_version differs; skipping (upgrade required)');
            return;
        }
        $centralsummary = is_array($summaryresp['summary'] ?? null) ? $summaryresp['summary'] : [];

        $divergent = digest::divergent_buckets($localsummary, $centralsummary);
        if (empty($divergent)) {
            mtrace('upstream_anti_entropy: converged (central holds every pushed fact)');
            return;
        }

        // Send our pushed-fact keys for the divergent buckets; central returns the
        // lineages it is missing or stale on.
        $wanted = [];
        foreach ($divergent as $d) {
            $wanted[$d['entitytype'] . '|' . $d['bucket']] = true;
        }
        $keys = [];
        foreach ($authored as $facttype => $lineages) {
            foreach ($lineages as $lineageuuid => $payloadhash) {
                if (isset($wanted[$facttype . '|' . digest::bucket((string) $lineageuuid)])) {
                    $keys[$facttype][$lineageuuid] = $payloadhash;
                }
            }
        }
        $detailresp = $client->digest('updetail', json_encode(['buckets' => $divergent, 'keys' => $keys]));
        $missing = is_array($detailresp['missing'] ?? null) ? $detailresp['missing'] : [];

        $this->requeue($missing, count($divergent));
    }

    /**
     * Re-queue the facts central is behind on by regenerating them from source.
     *
     * @param array $missing list of ['facttype' => string, 'lineageuuid' => string]
     * @param int $divergentcount Divergent buckets, for the trace.
     */
    protected function requeue(array $missing, int $divergentcount): void {
        global $DB;

        $requeued = 0;
        $losses = 0;
        $skipped = 0;

        foreach ($missing as $m) {
            $facttype = (string) ($m['facttype'] ?? '');
            $lineageuuid = (string) ($m['lineageuuid'] ?? '');
            if ($lineageuuid === '' || $facttype !== 'grade') {
                // v1 regenerates grades only; other fact types are a capture-scan follow-up.
                $skipped++;
                continue;
            }
            $ledger = fact_ledger::latest_finalized($lineageuuid);
            if (!$ledger || (string) $ledger->sourcetable !== 'grade_grades' || $ledger->sourceid === null) {
                $skipped++;
                continue;
            }
            if (!$DB->record_exists('grade_grades', ['id' => (int) $ledger->sourceid])) {
                // Central lacks it AND the source is gone — a genuine loss (§9.1), surfaced.
                debugging('upstream_anti_entropy: LOCAL LOSS — central lacks fact for deleted grade_grades '
                    . $ledger->sourceid . ' (lineage ' . $lineageuuid . ')', DEBUG_DEVELOPER);
                $losses++;
                continue;
            }
            if (!$this->learner_still_home((int) $ledger->sourceid)) {
                // Regenerate stamps the CURRENT roster generation, valid only while the
                // learner is still home here (symmetric with the capture-scan). A departed
                // learner's lost fact would be stamped past central's closed tenure interval
                // and rejected under enforcement, so defer it (pending §9.1 bracketing)
                // rather than mis-stamp it into a silent rejection.
                $skipped++;
                continue;
            }
            try {
                // force: central lost the fact, so re-append the outbox row even though
                // this school still holds it (idempotency would otherwise no-op).
                if (capture::regenerate_grade((int) $ledger->sourceid, true) !== null) {
                    $requeued++;
                }
            } catch (\Throwable $e) {
                debugging('upstream_anti_entropy: lineage ' . $lineageuuid . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        mtrace('upstream_anti_entropy: ' . $divergentcount . ' divergent bucket(s), ' . count($missing)
            . ' behind: ' . $requeued . ' re-queued, ' . $losses . ' local-loss, ' . $skipped . ' skipped');
    }

    /**
     * Whether the learner behind a grade is still on this school's roster.
     *
     * The re-queue stamps the current roster generation, which is only valid for a
     * still-home learner; a departed one must be deferred. Absent elby_roster (a box
     * that can't determine home) proceeds best-effort, matching the capture-scan.
     *
     * @param int $ggid grade_grades row id.
     * @return bool
     */
    protected function learner_still_home(int $ggid): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists('elby_roster')) {
            return true;
        }
        $userid = $DB->get_field('grade_grades', 'userid', ['id' => $ggid]);
        if (!$userid) {
            return false;
        }
        $sdms = $DB->get_field('elby_sdms_users', 'sdms_id', ['userid' => $userid]);
        if (empty($sdms)) {
            return false;
        }
        return $DB->record_exists('elby_roster', ['sdms_id' => $sdms]);
    }
}
