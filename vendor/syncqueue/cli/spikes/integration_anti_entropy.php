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
 * ELMS Sync v2 step 6 integration spike: downstream anti-entropy (applied-state digest).
 *
 * Proves doc §9: a school converges its replicated content against central by digest,
 * re-fetching only what it is missing or stale on.
 *   1. Pure digest logic: identical maps converge; flipping one key flags exactly its
 *      bucket (order-independent, isolated).
 *   2. Endpoint 'summary': central returns per-(entitytype, bucket) hashes of what the
 *      school should hold, matching the school's own over the same fixtures.
 *   3. Endpoint 'detail': a MISSING entity and a STALE entity are returned (with the
 *      correct head payload); an EXTRA the school has but central does not expect is
 *      NOT returned; applying the head re-converges.
 *   4. digest_version mismatch answers with an upgrade flag (no repair storm).
 *
 * Central's expected set is the outbox (empty of real content on this box), so the
 * endpoint is isolated to the itestae fixtures. Fixtures restore config, delete every
 * fixture row, and unregister the fixture school — re-runnable, zero residue.
 *
 * Usage:  php integration_anti_entropy.php [--keep]
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\digest;
use local_syncqueue\school_manager;
use local_syncqueue\outbox\publisher;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\outbox\applied_state;
use local_syncqueue\external\digest as digestendpoint;

list($options) = cli_get_params(['help' => false, 'keep' => false], []);
if (!empty($options['help'])) {
    cli_writeln("Drive the step-6 downstream anti-entropy digest and assert detection + re-fetch.\n"
        . "  --keep   Leave fixtures in place.\n");
    exit(0);
}

const AE_SCHOOL = 'itestaeschool';
const AE_KEYPREFIX = 'category:itestae';

$aefailures = [];

/**
 * Record + print one assertion.
 *
 * @param string $name Check id.
 * @param bool $ok Passed?
 * @param string $detail Detail.
 */
function ae_check(string $name, bool $ok, string $detail = ''): void {
    global $aefailures;
    if (!$ok) {
        $aefailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/** The current head payloadhash for a fixture category entitykey. */
function ae_head_hash(string $entitykey): string {
    $row = digest::central_head_row('category', $entitykey);
    return $row ? (string) $row->payloadhash : '';
}

/** Decode a digest endpoint response's result JSON. */
function ae_result(array $resp): array {
    $r = json_decode($resp['result'] ?? '{}', true);
    return is_array($r) ? $r : [];
}

/** Delete every itestae fixture row. */
function ae_purge(): void {
    global $DB;
    $DB->delete_records_select('local_syncqueue_outbox',
        $DB->sql_like('entitykey', ':k'), ['k' => AE_KEYPREFIX . '%']);
    $DB->delete_records_select('local_syncqueue_applied',
        $DB->sql_like('entitykey', ':k'), ['k' => AE_KEYPREFIX . '%']);
    if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => AE_SCHOOL])) {
        $DB->delete_records('local_syncqueue_schools', ['schoolid' => AE_SCHOOL]);
    }
}

$savedmode = get_config('local_syncqueue', 'mode');
$savedenabled = get_config('local_syncqueue', 'enabled');
ae_purge();

$cleanup = function () use ($savedmode, $savedenabled) {
    ae_purge();
    foreach (['mode' => $savedmode, 'enabled' => $savedenabled] as $k => $v) {
        if ($v === false) {
            unset_config($k, 'local_syncqueue');
        } else {
            set_config($k, $v, 'local_syncqueue');
        }
    }
};

$fatal = null;
try {
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');

    $sm = new school_manager();
    $apikey = $sm->register_school(AE_SCHOOL, 'ITEST Anti-Entropy Fixture School');

    // Publish four fixture categories (content:global) and record their head hashes.
    $keys = [];
    for ($i = 1; $i <= 4; $i++) {
        $key = AE_KEYPREFIX . $i;
        publisher::publish('category', $key, 'upsert',
            ['categorykey' => 'itestae' . $i, 'name' => 'ITEST AE ' . $i], 'content:global');
        $keys[$i] = $key;
    }
    sequencer::assign();
    $heads = [];
    foreach ($keys as $i => $key) {
        $heads[$i] = ae_head_hash($key);
    }
    // Simulate the school having applied all four (applied-state = the real head hash).
    foreach ($keys as $i => $key) {
        applied_state::upsert('category', $key, 1, $heads[$i], null);
    }
    ae_check('fixtures', count(array_filter($heads)) === 4 && $apikey !== '',
        'published 4 fixture categories with head hashes; school registered');

    // -----------------------------------------------------------------------
    // 1. Pure digest logic (isolated, no DB).
    // -----------------------------------------------------------------------
    $fixmap = ['category' => [$keys[1] => $heads[1], $keys[2] => $heads[2],
        $keys[3] => $heads[3], $keys[4] => $heads[4]]];
    $sumA = digest::summary($fixmap);
    $sumB = digest::summary($fixmap);
    ae_check('logic_converged', empty(digest::divergent_buckets($sumA, $sumB)),
        'identical maps produce no divergent buckets');

    $flipped = $fixmap;
    $flipped['category'][$keys[2]] = 'deadbeef';
    $div = digest::divergent_buckets(digest::summary($flipped), $sumB);
    $expectbucket = digest::bucket($keys[2]);
    ae_check('logic_flip_one_bucket',
        count($div) === 1 && $div[0]['entitytype'] === 'category' && $div[0]['bucket'] === $expectbucket,
        'flipping one key flags exactly its bucket (' . $expectbucket . ')');

    // -----------------------------------------------------------------------
    // 2. Endpoint 'summary' matches the school's own over the fixtures.
    // -----------------------------------------------------------------------
    $summaryresp = ae_result(digestendpoint::execute(AE_SCHOOL, $apikey, 'summary'));
    $centralsummary = $summaryresp['summary'] ?? [];
    $localfixsummary = digest::summary($fixmap);
    // Every fixture bucket central reports must equal the school's fixture-scoped hash.
    $summaryok = !empty($centralsummary['category']);
    foreach (($localfixsummary['category'] ?? []) as $bucket => $hash) {
        if (($centralsummary['category'][$bucket] ?? null) !== $hash) {
            $summaryok = false;
        }
    }
    ae_check('endpoint_summary_matches', $summaryok,
        'central summary equals the school digest over the fixtures (converged)');

    // -----------------------------------------------------------------------
    // 3. 'detail' returns a MISSING + a STALE entity, ignores an EXTRA, converges.
    // -----------------------------------------------------------------------
    // Simulate downstream loss: the school never applied #1 (delete its applied-state),
    // and holds a STALE #2 (wrong hash). #3/#4 are current. Add an EXTRA the school has
    // but central does not (no outbox row).
    $DB->delete_records('local_syncqueue_applied', ['entitytype' => 'category', 'entitykey' => $keys[1]]);
    applied_state::upsert('category', $keys[2], 1, 'stalehash', null);
    applied_state::upsert('category', AE_KEYPREFIX . '99', 1, 'extrahash', null); // extra, not in outbox

    // Build the detail request over every fixture bucket, with the school's keys+hashes.
    $applied = digest::local_applied_map();
    $buckets = [];
    foreach ($keys as $key) {
        $buckets['category|' . digest::bucket($key)] = ['entitytype' => 'category', 'bucket' => digest::bucket($key)];
    }
    $extrabucket = digest::bucket(AE_KEYPREFIX . '99');
    $buckets['category|' . $extrabucket] = ['entitytype' => 'category', 'bucket' => $extrabucket];
    $reqkeys = [];
    foreach (($applied['category'] ?? []) as $k => $h) {
        $reqkeys['category'][$k] = $h;
    }
    $payload = json_encode(['buckets' => array_values($buckets), 'keys' => $reqkeys]);
    $detail = ae_result(digestendpoint::execute(AE_SCHOOL, $apikey, 'detail', $payload));
    $returned = [];
    foreach (($detail['entities'] ?? []) as $e) {
        $returned[$e['entitykey']] = $e;
    }
    ae_check('detail_returns_missing',
        isset($returned[$keys[1]]) && (int) $returned[$keys[1]]['entityversion'] === 1
            && $returned[$keys[1]]['payloadhash'] === $heads[1]
            && $returned[$keys[1]]['action'] === 'upsert',
        'the missing entity #1 is returned with its head payload');
    ae_check('detail_returns_stale', isset($returned[$keys[2]]) && $returned[$keys[2]]['payloadhash'] === $heads[2],
        'the stale entity #2 is returned at the current head hash');
    ae_check('detail_skips_current', !isset($returned[$keys[3]]) && !isset($returned[$keys[4]]),
        'entities the school already holds at head (#3, #4) are NOT returned');
    ae_check('detail_ignores_extra', !isset($returned[AE_KEYPREFIX . '99']),
        'an extra the school has but central does not expect is NOT returned');

    // Apply the repair (checkpoint the head), then re-run: #1/#2 no longer diverge.
    foreach ([$keys[1], $keys[2]] as $key) {
        applied_state::upsert('category', $key, 1, ae_head_hash($key), null);
    }
    $applied2 = digest::local_applied_map();
    $reqkeys2 = [];
    foreach (($applied2['category'] ?? []) as $k => $h) {
        if (strpos($k, AE_KEYPREFIX) === 0) {
            $reqkeys2['category'][$k] = $h;
        }
    }
    $detail2 = ae_result(digestendpoint::execute(AE_SCHOOL, $apikey, 'detail',
        json_encode(['buckets' => array_values($buckets), 'keys' => $reqkeys2])));
    $stillmissing = [];
    foreach (($detail2['entities'] ?? []) as $e) {
        if (strpos($e['entitykey'], AE_KEYPREFIX) === 0) {
            $stillmissing[$e['entitykey']] = true;
        }
    }
    ae_check('detail_converges_after_apply', empty($stillmissing),
        'after checkpointing the repaired heads, no fixture entity is returned again');

    // -----------------------------------------------------------------------
    // 4. digest_version mismatch -> upgrade flag, no repair.
    // -----------------------------------------------------------------------
    $mismatch = ae_result(digestendpoint::execute(AE_SCHOOL, $apikey, 'summary', '', 999));
    ae_check('digest_version_guard', !empty($mismatch['upgrade']),
        'a client on a different digest_version gets an upgrade flag, not a summary');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $aefailures[] = 'script_completed';
}

if (!empty($options['keep'])) {
    cli_writeln('INFO --keep: leaving fixtures in place');
} else {
    $cleanup();
    $residue = $DB->count_records_select('local_syncqueue_outbox',
        $DB->sql_like('entitykey', ':k'), ['k' => AE_KEYPREFIX . '%'])
        + $DB->count_records_select('local_syncqueue_applied',
            $DB->sql_like('entitykey', ':k'), ['k' => AE_KEYPREFIX . '%']);
    ae_check('cleanup_zero_residue',
        $residue === 0 && !$DB->record_exists('local_syncqueue_schools', ['schoolid' => AE_SCHOOL]),
        "fixture outbox+applied rows left={$residue}, fixture school removed");
}

if (empty($aefailures)) {
    cli_writeln('SPIKE RESULT: PASS - downstream anti-entropy digest verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($aefailures)));
exit(1);
