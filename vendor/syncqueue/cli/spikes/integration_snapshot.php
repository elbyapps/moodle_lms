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

/**
 * ELMS Sync v2 step 6 integration spike: snapshot/manifest bootstrap (§4.4).
 *
 * Proves the bootstrap primitives:
 *   1. Central MATERIALISES a per-school manifest — head seq pinned, one entry per
 *      subscribed content head, chunked, with a manifest id.
 *   2. Resuming with the manifest id returns the SAME manifest (not re-materialised);
 *      a fresh materialisation SUPERSEDES the old (one per school, old chunks gone).
 *   3. The fetch phase returns the head rows for the manifest's keys (scoped).
 *   4. cursor::advance sets the pull cursor to the pinned head (resume from H, monotonic)
 *      — the bootstrap's key outcome.
 *   5. The bootstrap's apply checkpoint skips an entity the school already holds.
 *
 * Central's expected set is the outbox (empty of real content here), so the manifest is
 * isolated to the itestsb fixtures. Fixtures restore config + delete every fixture row.
 *
 * Usage:  php integration_snapshot.php [--keep]
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\school_manager;
use local_syncqueue\snapshot_bootstrap;
use local_syncqueue\outbox\publisher;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\outbox\applied_state;
use local_syncqueue\outbox\cursor;
use local_syncqueue\external\snapshot_manifest;
use local_syncqueue\external\digest as digestendpoint;

list($options) = cli_get_params(['help' => false, 'keep' => false], []);
if (!empty($options['help'])) {
    cli_writeln("Drive the step-6 snapshot bootstrap primitives and assert them.\n  --keep  Leave fixtures.\n");
    exit(0);
}

const SB_SCHOOL = 'itestsbschool';
const SB_KEYPREFIX = 'category:itestsb';
const SB_PEER = 'itestsbpeer';

$sbfailures = [];

/** Record + print one assertion. */
function sb_check(string $name, bool $ok, string $detail = ''): void {
    global $sbfailures;
    if (!$ok) {
        $sbfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/** Decode a digest endpoint response's result JSON. */
function sb_result(array $resp): array {
    $r = json_decode($resp['result'] ?? '{}', true);
    return is_array($r) ? $r : [];
}

/** Delete every itestsb fixture row. */
function sb_purge(): void {
    global $DB;
    $DB->delete_records_select('local_syncqueue_outbox', $DB->sql_like('entitykey', ':k'), ['k' => SB_KEYPREFIX . '%']);
    $DB->delete_records_select('local_syncqueue_applied', $DB->sql_like('entitykey', ':k'), ['k' => SB_KEYPREFIX . '%']);
    $DB->delete_records_select('local_syncqueue_deadletter', $DB->sql_like('entitykey', ':k'), ['k' => SB_KEYPREFIX . '%']);
    if ($DB->get_manager()->table_exists('local_syncqueue_snapshot')) {
        $DB->delete_records('local_syncqueue_snapshot', ['schoolid' => SB_SCHOOL]);
    }
    $DB->delete_records('local_syncqueue_cursor', ['peer' => SB_PEER]);
    if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => SB_SCHOOL])) {
        $DB->delete_records('local_syncqueue_schools', ['schoolid' => SB_SCHOOL]);
    }
}

$savedmode = get_config('local_syncqueue', 'mode');
$savedenabled = get_config('local_syncqueue', 'enabled');
sb_purge();

$cleanup = function () use ($savedmode, $savedenabled) {
    sb_purge();
    foreach (['mode' => $savedmode, 'enabled' => $savedenabled] as $k => $v) {
        ($v === false) ? unset_config($k, 'local_syncqueue') : set_config($k, $v, 'local_syncqueue');
    }
};

$fatal = null;
try {
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    $sm = new school_manager();
    $apikey = $sm->register_school(SB_SCHOOL, 'ITEST Snapshot Fixture School');

    // Publish four fixture categories (content:global) and record head hashes.
    $keys = [];
    for ($i = 1; $i <= 4; $i++) {
        $key = SB_KEYPREFIX . $i;
        publisher::publish('category', $key, 'upsert',
            ['categorykey' => 'itestsb' . $i, 'name' => 'ITEST SB ' . $i], 'content:global');
        $keys[$i] = $key;
    }
    sequencer::assign();
    $expectedhead = (int) $DB->get_field_sql('SELECT COALESCE(MAX(seq), 0) FROM {local_syncqueue_outbox}');
    $heads = [];
    foreach ($keys as $i => $key) {
        $heads[$i] = (string) $DB->get_field_sql(
            'SELECT payloadhash FROM {local_syncqueue_outbox} WHERE entitykey = ? AND seq IS NOT NULL '
                . 'ORDER BY entityversion DESC', [$key], IGNORE_MULTIPLE);
    }

    // -----------------------------------------------------------------------
    // 1. Materialise the manifest.
    // -----------------------------------------------------------------------
    $m = snapshot_manifest::execute(SB_SCHOOL, $apikey, '', 0);
    $manifestid = (string) $m['manifestid'];
    $entries = json_decode($m['entries'], true) ?: [];
    $bykey = [];
    foreach ($entries as $e) {
        $bykey[$e['entitykey']] = $e['payloadhash'];
    }
    sb_check('manifest_materialises',
        $manifestid !== '' && (int) $m['headseq'] === $expectedhead && (int) $m['numchunks'] >= 1
            && ($bykey[$keys[1]] ?? null) === $heads[1] && ($bykey[$keys[4]] ?? null) === $heads[4],
        'manifest ' . substr($manifestid, 0, 8) . ' at head ' . $m['headseq'] . ', '
            . count($entries) . ' entries, fixture heads present');

    // -----------------------------------------------------------------------
    // 2. Resume returns the SAME manifest; a fresh one SUPERSEDES it.
    // -----------------------------------------------------------------------
    $resume = snapshot_manifest::execute(SB_SCHOOL, $apikey, $manifestid, 0);
    sb_check('manifest_resume_stable', (string) $resume['manifestid'] === $manifestid,
        'resuming with the id returns the same manifest (not re-materialised)');

    $fresh = snapshot_manifest::execute(SB_SCHOOL, $apikey, '', 0);
    $newid = (string) $fresh['manifestid'];
    sb_check('manifest_supersedes',
        $newid !== $manifestid
            && !$DB->record_exists('local_syncqueue_snapshot', ['manifestid' => $manifestid])
            && $DB->record_exists('local_syncqueue_snapshot', ['manifestid' => $newid]),
        'a fresh materialisation replaced the old manifest (one per school)');
    $manifestid = $newid;

    // -----------------------------------------------------------------------
    // 3. Fetch returns the head rows for the manifest's keys.
    // -----------------------------------------------------------------------
    $fetchkeys = [];
    foreach ($keys as $key) {
        $fetchkeys[] = ['entitytype' => 'category', 'entitykey' => $key];
    }
    $fetch = sb_result(digestendpoint::execute(SB_SCHOOL, $apikey, 'fetch', json_encode(['keys' => $fetchkeys])));
    $fetched = [];
    foreach (($fetch['entities'] ?? []) as $ent) {
        $fetched[$ent['entitykey']] = $ent;
    }
    sb_check('fetch_returns_heads',
        isset($fetched[$keys[1]], $fetched[$keys[4]])
            && $fetched[$keys[1]]['payloadhash'] === $heads[1]
            && $fetched[$keys[1]]['payload'] !== null,
        'fetch returned the head rows (with payloads) for the manifest keys');

    // -----------------------------------------------------------------------
    // 4. Cursor advances to the pinned head (resume from H, monotonic).
    // -----------------------------------------------------------------------
    cursor::advance(SB_PEER, 'down', (int) $m['headseq']);
    $atkeep = cursor::get(SB_PEER, 'down');
    cursor::advance(SB_PEER, 'down', (int) $m['headseq'] - 5); // lower ignored
    sb_check('cursor_sets_head',
        $atkeep === $expectedhead && cursor::get(SB_PEER, 'down') === $expectedhead,
        'cursor advanced to the pinned head ' . $expectedhead . ' and refused to regress');

    // The bootstrap uses cursor::reset (authoritative), which CAN move backward — needed
    // when a central-restore re-bootstrap resets a cursor past central's lower head.
    cursor::reset(SB_PEER, 'down', 3);
    sb_check('cursor_reset_backward', cursor::get(SB_PEER, 'down') === 3,
        'cursor::reset moved the cursor BACKWARD (advance would have refused)');

    // -----------------------------------------------------------------------
    // 5. The bootstrap apply checkpoint skips an entity the school already holds.
    // -----------------------------------------------------------------------
    applied_state::upsert('category', $keys[1], 1, $heads[1], null);
    $rc = new \ReflectionMethod(snapshot_bootstrap::class, 'apply_entity');
    $rc->setAccessible(true);
    $ent = ['entitytype' => 'category', 'entitykey' => $keys[1], 'entityversion' => 1,
        'action' => 'upsert', 'payload' => '{}', 'payloadhash' => $heads[1], 'contentversion' => null];
    [$applied, $deferred] = $rc->invoke(null, new \local_syncqueue\update_processor(), $ent);
    sb_check('apply_checkpoint_idempotent', $applied === false && $deferred === false,
        'an entity already at the applied head is a no-op (no re-apply)');

    // A FAILED apply is deadlettered (so advancing the cursor past it can't strand it —
    // the pull's replay_deadletters retries it). A category upsert with a null payload throws.
    $badkey = SB_KEYPREFIX . '9';
    $bad = ['entitytype' => 'category', 'entitykey' => $badkey, 'entityversion' => 1,
        'action' => 'upsert', 'payload' => null, 'payloadhash' => 'x', 'contentversion' => null];
    $rc->invoke(null, new \local_syncqueue\update_processor(), $bad);
    $dl = $DB->get_record('local_syncqueue_deadletter',
        ['peer' => 'central', 'direction' => 'down', 'entitytype' => 'category',
            'entitykey' => $badkey, 'status' => 'retry']);
    sb_check('failed_apply_deadlettered', $dl !== false,
        'a failed bootstrap apply is deadlettered for the pull to retry, never dropped below the cursor');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $sbfailures[] = 'script_completed';
}

if (!empty($options['keep'])) {
    cli_writeln('INFO --keep: leaving fixtures in place');
} else {
    $cleanup();
    $residue = $DB->count_records('local_syncqueue_snapshot', ['schoolid' => SB_SCHOOL])
        + $DB->count_records_select('local_syncqueue_outbox', $DB->sql_like('entitykey', ':k'), ['k' => SB_KEYPREFIX . '%']);
    sb_check('cleanup_zero_residue',
        $residue === 0 && !$DB->record_exists('local_syncqueue_schools', ['schoolid' => SB_SCHOOL]),
        "fixture snapshot+outbox rows left={$residue}, fixture school removed");
}

if (empty($sbfailures)) {
    cli_writeln('SPIKE RESULT: PASS - snapshot/manifest bootstrap verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($sbfailures)));
exit(1);
