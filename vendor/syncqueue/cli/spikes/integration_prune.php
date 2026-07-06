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
 * ELMS Sync v2 step 7 (part 5) integration spike: outbox pruning + snapshot GC.
 *
 * Proves prune_outbox:
 *   1. A SUPERSEDED content row (a higher entityversion exists) older than the
 *      retention window is pruned.
 *   2. A superseded row still inside the retention window is kept.
 *   3. A HEAD row (no higher version) is NEVER pruned, even when old.
 *   4. A non-content (upstream/learner) row is never touched.
 *   5. Snapshot manifest chunks past their pin are GC'd; live ones are kept.
 *
 * Uses distinctive fixture entitykeys; restores every touched row.
 *
 * Usage:  php integration_prune.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\task\prune_outbox;

const PR_KEY = 'course:99900177';
const PR_LEARNER = 'course:99900178';

$prfailures = [];
function pr_check(string $name, bool $ok, string $detail = ''): void {
    global $prfailures;
    if (!$ok) {
        $prfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

global $DB;
$saved = ['mode' => get_config('local_syncqueue', 'mode'), 'enabled' => get_config('local_syncqueue', 'enabled')];

/** Insert a fixture outbox row; returns its id. Rows carry a real (sequenced) seq so the
 *  "supersessor is sequenced" prune guard applies as it would in production. */
function pr_row(string $entitykey, int $ev, string $partition, int $timecreated, int $seq): int {
    global $DB;
    return (int) $DB->insert_record('local_syncqueue_outbox', (object) [
        'seq' => $seq, 'entitytype' => 'course', 'entitykey' => $entitykey, 'entityversion' => $ev,
        'action' => 'upsert', 'payload' => '{}', 'payloadhash' => hash('sha256', $entitykey . $ev),
        'contentversion' => null, 'partitionkey' => $partition, 'timecreated' => $timecreated,
    ]);
}

$old = time() - (30 * 86400);
$recent = time();
$ids = [];
$manids = [];
$fatal = null;
try {
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');

    // Content key with three versions (seq monotonic with entityversion, as in production).
    $part = 'content:course:' . PR_KEY;
    $base = 999000000;
    $v1 = pr_row(PR_KEY, 1, $part, $old, $base + 1);      // superseded + old  -> prune
    $v2 = pr_row(PR_KEY, 2, $part, $recent, $base + 2);   // superseded + recent -> keep
    $v3 = pr_row(PR_KEY, 3, $part, $old, $base + 3);      // head + old -> keep (head-safety)
    // A non-content (upstream/learner) old superseded row -> never touched.
    $l1 = pr_row(PR_LEARNER, 1, 'learner:school:' . PR_LEARNER, $old, $base + 4);
    $l2 = pr_row(PR_LEARNER, 2, 'learner:school:' . PR_LEARNER, $old, $base + 5);
    $ids = [$v1, $v2, $v3, $l1, $l2];

    // Snapshot chunks: one expired, one live.
    $manids[] = (int) $DB->insert_record('local_syncqueue_snapshot', (object) [
        'manifestid' => 'itestprune-expired', 'schoolid' => 'itestpruneschool', 'headseq' => 1,
        'chunkindex' => 0, 'numchunks' => 1, 'entries' => '[]', 'pinneduntil' => time() - 100,
        'timecreated' => $old,
    ]);
    $manids[] = (int) $DB->insert_record('local_syncqueue_snapshot', (object) [
        'manifestid' => 'itestprune-live', 'schoolid' => 'itestpruneschool', 'headseq' => 2,
        'chunkindex' => 0, 'numchunks' => 1, 'entries' => '[]', 'pinneduntil' => time() + 10000,
        'timecreated' => $recent,
    ]);

    cli_writeln('--- run prune_outbox ---');
    (new prune_outbox())->execute();

    pr_check('superseded_old_pruned', !$DB->record_exists('local_syncqueue_outbox', ['id' => $v1]),
        'the superseded + old content row was pruned');
    pr_check('superseded_recent_kept', $DB->record_exists('local_syncqueue_outbox', ['id' => $v2]),
        'the superseded but recent content row was kept (inside the retention window)');
    pr_check('head_never_pruned', $DB->record_exists('local_syncqueue_outbox', ['id' => $v3]),
        'the HEAD row was kept even though it is old (no higher version exists)');
    pr_check('noncontent_untouched',
        $DB->record_exists('local_syncqueue_outbox', ['id' => $l1])
            && $DB->record_exists('local_syncqueue_outbox', ['id' => $l2]),
        'non-content (learner) rows were never touched by the content prune');
    pr_check('expired_manifest_gcd',
        !$DB->record_exists('local_syncqueue_snapshot', ['manifestid' => 'itestprune-expired']),
        'the expired snapshot chunk was GC\'d');
    pr_check('live_manifest_kept',
        $DB->record_exists('local_syncqueue_snapshot', ['manifestid' => 'itestprune-live']),
        'the live (pinned) snapshot chunk was kept');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $prfailures[] = 'script_completed';
}

// Cleanup.
$DB->delete_records_select('local_syncqueue_outbox',
    'entitykey = :a OR entitykey = :b', ['a' => PR_KEY, 'b' => PR_LEARNER]);
$DB->delete_records('local_syncqueue_snapshot', ['schoolid' => 'itestpruneschool']);
foreach (['mode', 'enabled'] as $n) {
    if ($saved[$n] === false) {
        unset_config($n, 'local_syncqueue');
    } else {
        set_config($n, $saved[$n], 'local_syncqueue');
    }
}
$residue = $DB->count_records_select('local_syncqueue_outbox',
    'entitykey = :a OR entitykey = :b', ['a' => PR_KEY, 'b' => PR_LEARNER])
    + $DB->count_records('local_syncqueue_snapshot', ['schoolid' => 'itestpruneschool']);
pr_check('cleanup_restored', $residue === 0,
    $residue === 0 ? 'all fixture outbox + snapshot rows removed' : "residue={$residue}");

if (empty($prfailures)) {
    cli_writeln('SPIKE RESULT: PASS - outbox pruning + snapshot GC verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($prfailures)));
exit(1);
