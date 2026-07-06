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
use local_syncqueue\digest;
use local_syncqueue\dependency_missing_exception;
use local_syncqueue\sync_client;
use local_syncqueue\update_processor;
use local_syncqueue\outbox\applied_state;

/**
 * School-side downstream anti-entropy (ELMS Sync v2 step 6, doc §9).
 *
 * Weekly, converges the school's replicated content state against central's: computes a
 * per-(entitytype, bucket) digest of its applied-state, asks central for the same over
 * what the school SHOULD hold, drills only the divergent buckets, and re-fetches +
 * applies the entities it is missing or stale on. This closes ordinary downstream
 * delivery loss (a dropped pull row, a batch never applied) independently of the seq
 * stream — the third net alongside the reconciler (local drift) and the capture-scan
 * (never-captured upstream facts).
 *
 * School mode + pull_v2 only (content flows over v2 pull). Best-effort: a repair that
 * can't resolve yet is simply retried by next week's run — the digest re-detects it.
 *
 * SCOPE (doc §9 "converges replicated state"): it re-fetches what the school LACKS or is
 * STALE on. It intentionally does NOT act on the two states that also flip a bucket but
 * have no downstream repair — local extras (content central no longer publishes to the
 * school; resolved by unsubscribe cleanup) and central-behind-school (a central restore;
 * resolved by the re-incarnation path). It also cannot detect content DRIFT on a course
 * the school already holds, because in-place course_content refresh is deferred to
 * step 7 — the applier advances applied-state to central's new hash without re-importing
 * the .mbz, so the digest reports converged while the content lags until step 7 lands.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anti_entropy extends scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_antientropy', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (get_config('local_syncqueue', 'mode') !== 'school'
                || !get_config('local_syncqueue', 'enabled')
                || !get_config('local_syncqueue', 'pull_v2')) {
            return;
        }

        $localsummary = digest::summary(digest::local_applied_map());

        $client = new sync_client();
        $summaryresp = $client->digest('summary', '');
        if (!empty($summaryresp['upgrade'])) {
            mtrace('anti_entropy: central digest_version differs; skipping (upgrade required)');
            return;
        }
        $centralsummary = is_array($summaryresp['summary'] ?? null) ? $summaryresp['summary'] : [];

        $divergent = digest::divergent_buckets($localsummary, $centralsummary);
        if (empty($divergent)) {
            mtrace('anti_entropy: converged (no divergent buckets)');
            return;
        }

        // Send our keys+hashes for the divergent buckets; central returns the entities
        // we are missing or stale on.
        $applied = digest::local_applied_map();
        $wantedbuckets = [];
        foreach ($divergent as $d) {
            $wantedbuckets[$d['entitytype'] . '|' . $d['bucket']] = true;
        }
        $keys = [];
        foreach ($applied as $entitytype => $keyhashes) {
            foreach ($keyhashes as $entitykey => $payloadhash) {
                if (isset($wantedbuckets[$entitytype . '|' . digest::bucket((string) $entitykey)])) {
                    $keys[$entitytype][$entitykey] = $payloadhash;
                }
            }
        }
        $detailresp = $client->digest('detail', json_encode(['buckets' => $divergent, 'keys' => $keys]));
        $entities = is_array($detailresp['entities'] ?? null) ? $detailresp['entities'] : [];

        if (empty($entities)) {
            // Divergent buckets with nothing to re-fetch are BENIGN: the school holds
            // extras central no longer expects (a de-selected course awaiting unsubscribe
            // cleanup) or central is momentarily behind the school (a central restore,
            // healed by the re-incarnation path — not this net). The digest surfaces
            // these but does not — and must not — repair them by downgrading or deleting.
            mtrace('anti_entropy: ' . count($divergent) . ' divergent bucket(s), all benign '
                . '(local extras / central-behind); nothing to re-fetch');
            return;
        }

        $this->apply_entities($entities, count($divergent));
    }

    /**
     * Apply the re-fetched entities through the normal applier (staleness-guarded).
     *
     * @param array $entities Entity rows from the detail response.
     * @param int $divergentcount Number of divergent buckets (for the trace).
     */
    protected function apply_entities(array $entities, int $divergentcount): void {
        $processor = new update_processor();
        $healed = 0;
        $deferred = 0;
        $failed = 0;

        foreach ($entities as $e) {
            $row = (object) [
                'entitytype' => (string) ($e['entitytype'] ?? ''),
                'entitykey' => (string) ($e['entitykey'] ?? ''),
                'entityversion' => (int) ($e['entityversion'] ?? 0),
                'action' => (string) ($e['action'] ?? 'upsert'),
                'payload' => $e['payload'] ?? null,
                'payloadhash' => (string) ($e['payloadhash'] ?? ''),
                'contentversion' => isset($e['contentversion']) && $e['contentversion'] !== null
                    ? (int) $e['contentversion'] : null,
            ];
            if ($row->entitytype === '' || $row->entitykey === '') {
                continue;
            }

            // Staleness: a concurrent pull may already have applied this or newer.
            $state = applied_state::get($row->entitytype, $row->entitykey);
            if ($state && ((int) $state->entityversion > $row->entityversion
                    || ((int) $state->entityversion === $row->entityversion
                        && $state->payloadhash === $row->payloadhash))) {
                continue;
            }

            try {
                $localid = $processor->apply_outbox_row($row);
                applied_state::upsert($row->entitytype, $row->entitykey, $row->entityversion,
                    $row->payloadhash, $localid ?: null);
                $healed++;
            } catch (dependency_missing_exception $ex) {
                // A prerequisite (e.g. the parent category/course) is not applied yet;
                // next week's digest re-detects and re-fetches it in order.
                $deferred++;
            } catch (\Throwable $ex) {
                debugging('anti_entropy: ' . $row->entitytype . ' ' . $row->entitykey . ': '
                    . $ex->getMessage(), DEBUG_DEVELOPER);
                $failed++;
            }
        }

        mtrace('anti_entropy: ' . $divergentcount . ' divergent bucket(s), ' . count($entities)
            . ' entity(ies): ' . $healed . ' healed, ' . $deferred . ' deferred, ' . $failed . ' failed');
    }
}
