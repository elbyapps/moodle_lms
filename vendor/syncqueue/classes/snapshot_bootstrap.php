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

use local_syncqueue\dependency_missing_exception;
use local_syncqueue\outbox\applied_state;
use local_syncqueue\outbox\cursor;

/**
 * School-side snapshot bootstrap (ELMS Sync v2 step 6, doc §4.4).
 *
 * Loads a fresh (or re-incarnated) school's full head content state from central's
 * pinned manifest, applies what it lacks, and sets the pull cursor to the manifest's
 * head seq — so incremental pulls resume from H rather than replaying the whole stream
 * (and never the fatal "cursor = MAX(id)" that discards undelivered backlogs).
 *
 * Resumable: the manifest is fetched chunk by chunk; if central supersedes the manifest
 * mid-load (a different id echoed) the load restarts cleanly. Entities are fetched only
 * for keys the school is missing or stale on, applied in parent-first order, and a
 * prerequisite not yet present is deferred (a re-run or the next incremental pull
 * resolves it). Idempotent: re-running on an up-to-date school is a cheap no-op.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot_bootstrap {

    /** @var int Entities fetched per request. */
    const FETCH_BATCH = 200;

    /** @var int Manifest reload attempts before giving up on a churning manifest. */
    const MAX_RELOADS = 5;

    /** @var string[] Parent-first apply order (a course_content needs its course, etc.). */
    const TYPE_ORDER = ['category', 'course', 'course_content', 'identity_map'];

    /**
     * Run the bootstrap. Returns a summary array.
     *
     * @return array{status:string, entries:int, applied:int, deferred:int, headseq:int}
     */
    public static function run(): array {
        if (get_config('local_syncqueue', 'mode') !== 'school'
                || !get_config('local_syncqueue', 'enabled')
                || !get_config('local_syncqueue', 'pull_v2')) {
            return ['status' => 'skipped', 'entries' => 0, 'applied' => 0, 'deferred' => 0, 'headseq' => 0];
        }

        $client = new sync_client();
        [$headseq, $entries, $completed] = self::load_manifest($client);
        if (!$completed) {
            // The manifest kept churning (concurrent re-materialisation, or central mid-
            // publish): do NOT advance the cursor on an incomplete load — surface it so the
            // operator retries rather than resuming from a head we never fully loaded.
            return ['status' => 'incomplete', 'entries' => 0, 'applied' => 0, 'deferred' => 0, 'headseq' => 0];
        }

        // Only fetch what we are missing or stale on; order parents first.
        $tofetch = [];
        foreach ($entries as $e) {
            $type = (string) ($e['entitytype'] ?? '');
            $key = (string) ($e['entitykey'] ?? '');
            $hash = (string) ($e['payloadhash'] ?? '');
            if ($type === '' || $key === '') {
                continue;
            }
            $state = applied_state::get($type, $key);
            if (!$state || (string) $state->payloadhash !== $hash) {
                $tofetch[] = ['entitytype' => $type, 'entitykey' => $key];
            }
        }
        usort($tofetch, function ($a, $b) {
            $oa = array_search($a['entitytype'], self::TYPE_ORDER, true);
            $ob = array_search($b['entitytype'], self::TYPE_ORDER, true);
            return ($oa === false ? 99 : $oa) <=> ($ob === false ? 99 : $ob);
        });

        $applied = 0;
        $deferred = 0;
        $processor = new update_processor();
        foreach (array_chunk($tofetch, self::FETCH_BATCH) as $batch) {
            $resp = $client->digest('fetch', json_encode(['keys' => $batch]));
            foreach (($resp['entities'] ?? []) as $ent) {
                [$ok, $isdeferred] = self::apply_entity($processor, $ent);
                $applied += $ok ? 1 : 0;
                $deferred += $isdeferred ? 1 : 0;
            }
        }

        // Set the cursor to the pinned head authoritatively (reset, not monotonic advance):
        // the bootstrap has just loaded the full state as of headseq, and a re-incarnation
        // re-bootstrap must be able to move the cursor BACK to central's restored (lower)
        // head — a monotonic advance would silently ignore that and never re-sync.
        cursor::reset('central', 'down', $headseq);

        return ['status' => 'done', 'entries' => count($entries), 'applied' => $applied,
            'deferred' => $deferred, 'headseq' => $headseq];
    }

    /**
     * Load the full manifest (all chunks), restarting cleanly if central supersedes it.
     *
     * @param sync_client $client
     * @return array{0:int,1:array,2:bool} [headseq, entries, completed]
     */
    protected static function load_manifest(sync_client $client): array {
        for ($attempt = 0; $attempt < self::MAX_RELOADS; $attempt++) {
            $manifestid = '';
            $chunkindex = 0;
            $numchunks = 1;
            $headseq = 0;
            $entries = [];
            $restart = false;

            do {
                $resp = $client->snapshot_manifest($manifestid, $chunkindex);
                if ($manifestid !== '' && $resp['manifestid'] !== $manifestid) {
                    // The manifest was superseded mid-load — start over with the new one.
                    $restart = true;
                    break;
                }
                $manifestid = $resp['manifestid'];
                $headseq = $resp['headseq'];
                $numchunks = max(1, $resp['numchunks']);
                foreach ($resp['entries'] as $e) {
                    $entries[] = $e;
                }
                $chunkindex++;
            } while ($chunkindex < $numchunks);

            if (!$restart) {
                return [$headseq, $entries, true];
            }
        }
        // Manifest kept churning; signal an incomplete load (the caller won't advance).
        return [0, [], false];
    }

    /**
     * Apply one fetched entity, staleness-guarded.
     *
     * @param update_processor $processor
     * @param array $ent Entity row from the fetch response.
     * @return array{0:bool,1:bool} [applied, deferred]
     */
    protected static function apply_entity(update_processor $processor, array $ent): array {
        $type = (string) ($ent['entitytype'] ?? '');
        $key = (string) ($ent['entitykey'] ?? '');
        $version = (int) ($ent['entityversion'] ?? 0);
        $hash = (string) ($ent['payloadhash'] ?? '');
        if ($type === '' || $key === '') {
            return [false, false];
        }

        $state = applied_state::get($type, $key);
        if ($state && ((int) $state->entityversion > $version
                || ((int) $state->entityversion === $version && (string) $state->payloadhash === $hash))) {
            return [false, false]; // already current (a concurrent pull won)
        }

        $row = (object) [
            'entitytype' => $type,
            'entitykey' => $key,
            'entityversion' => $version,
            'action' => (string) ($ent['action'] ?? 'upsert'),
            'payload' => $ent['payload'] ?? null,
            'payloadhash' => $hash,
            'contentversion' => isset($ent['contentversion']) && $ent['contentversion'] !== null
                ? (int) $ent['contentversion'] : null,
            'partitionkey' => '',
            'seq' => null,
        ];
        try {
            $localid = $processor->apply_outbox_row($row);
            applied_state::upsert($type, $key, $version, $hash, $localid ?: null);
            return [true, false];
        } catch (dependency_missing_exception $e) {
            // A prerequisite isn't applied yet: deadletter it (retry-not-burn) so the pull's
            // replay retries it — otherwise advancing the cursor past headseq would strand
            // it forever (the incremental pull only returns seq > cursor).
            self::deadletter($row, $e->getMessage(), false);
            return [false, true];
        } catch (\Throwable $e) {
            // A transient apply failure (e.g. an .mbz restore error): deadletter it (burns
            // attempts, dead at the pull's max) so it is retried, never silently dropped
            // below the advanced cursor.
            self::deadletter($row, $e->getMessage(), true);
            return [false, false];
        }
    }

    /**
     * Record an unapplied bootstrap entity in the downstream deadletter queue, in the
     * exact shape pull_stream writes so its replay_deadletters (every 10 min) retries it.
     * This is what makes advancing the cursor to headseq safe: a deferred/failed entity is
     * never stranded below the cursor.
     *
     * @param \stdClass $row The entity row (entitytype/entitykey/entityversion/action/…).
     * @param string $error Apply error.
     * @param bool $countattempt Whether this counts towards the dead threshold (false for
     *        a missing-prerequisite retry, true for a hard failure).
     */
    protected static function deadletter(\stdClass $row, string $error, bool $countattempt): void {
        global $DB;

        $envelope = json_encode([
            'v2envelope' => 1,
            'action' => (string) $row->action,
            'payloadhash' => (string) $row->payloadhash,
            'contentversion' => $row->contentversion ?? null,
            'partitionkey' => (string) ($row->partitionkey ?? ''),
            'payload' => $row->payload ?? null,
        ]);

        // One retry row per entity+version (matches pull_stream): dedupe against a row the
        // pull may have already queued.
        $key = ['peer' => 'central', 'direction' => 'down', 'entitytype' => $row->entitytype,
            'entitykey' => $row->entitykey, 'entityversion' => (int) $row->entityversion, 'status' => 'retry'];
        $existing = $DB->get_record('local_syncqueue_deadletter', $key);
        if ($existing) {
            $existing->payload = $envelope;
            $existing->error = $error;
            if ($countattempt) {
                $existing->attempts = (int) $existing->attempts + 1;
            }
            $existing->timemodified = time();
            $DB->update_record('local_syncqueue_deadletter', $existing);
            return;
        }
        $record = (object) [
            'peer' => 'central', 'direction' => 'down', 'seq' => $row->seq ?? null,
            'entitytype' => $row->entitytype, 'entitykey' => $row->entitykey,
            'entityversion' => (int) $row->entityversion, 'payload' => $envelope, 'error' => $error,
            'attempts' => $countattempt ? 1 : 0, 'status' => 'retry',
            'timecreated' => time(), 'timemodified' => time(),
        ];
        $DB->insert_record('local_syncqueue_deadletter', $record);
    }
}
