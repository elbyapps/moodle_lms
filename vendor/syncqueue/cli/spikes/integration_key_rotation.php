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
 * ELMS Sync v2 step 7 (part 4) integration spike: dual-validity API key rotation.
 *
 * Proves §4.6:
 *   1. A rotation makes the new key current AND keeps the old key valid through the
 *      grace window (dual-validity) — an offline school can't be bricked.
 *   2. The rotation is idempotent (a retried rotation with the still-valid old key is a
 *      no-op that doesn't demote the just-adopted key).
 *   3. Once the grace window passes, the old key is rejected.
 *   4. The school-side rotate_local generates a key, has central adopt it, and adopts it
 *      locally only on confirmation.
 *
 * Config-only (a fixture school row); restores everything.
 *
 * Usage:  php integration_key_rotation.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\external\rotate_key as rotate_key_endpoint;
use local_syncqueue\key_rotation;
use local_syncqueue\school_manager;
use local_syncqueue\sync_client;

const KR_SCHOOL = 'itestkrschool';

/** Test-double client: call the real rotate_key endpoint in central mode (no HTTP). */
class itest_kr_client extends sync_client {
    public function rotate_key(string $newkey): array {
        $sid = (string) get_config('local_syncqueue', 'schoolid');
        $key = (string) get_config('local_syncqueue', 'apikey');
        $prev = get_config('local_syncqueue', 'mode');
        set_config('mode', 'central', 'local_syncqueue');
        try {
            return rotate_key_endpoint::execute($sid, $key, $newkey);
        } finally {
            set_config('mode', $prev !== false ? $prev : 'school', 'local_syncqueue');
        }
    }
}

$krfailures = [];
function kr_check(string $name, bool $ok, string $detail = ''): void {
    global $krfailures;
    if (!$ok) {
        $krfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

global $DB;
$confignames = ['mode', 'enabled', 'schoolid', 'apikey', 'centralserver', 'wstoken', key_rotation::PENDING];
$saved = [];
foreach ($confignames as $n) {
    $saved[$n] = get_config('local_syncqueue', $n);
}

$fatal = null;
try {
    if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => KR_SCHOOL])) {
        $DB->delete_records('local_syncqueue_schools', ['schoolid' => KR_SCHOOL]);
    }
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    $sm = new school_manager();
    $keyA = $sm->register_school(KR_SCHOOL, 'ITEST Key Rotation Fixture');

    // -----------------------------------------------------------------------
    // Phase 1 — rotation via the endpoint + dual-validity.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: rotate + dual-validity ---');
    kr_check('baseline_auth', $sm->verify_apikey(KR_SCHOOL, $keyA), 'the initial key authenticates');

    $keyB = school_manager::generate_key();
    $r1 = rotate_key_endpoint::execute(KR_SCHOOL, $keyA, $keyB);
    kr_check('rotate_swaps', !empty($r1['rotated']) && !empty($r1['current']) && (int) $r1['prev_expires'] > time(),
        'rotating with the old key adopts the new key + sets a grace window');
    kr_check('new_key_valid', $sm->verify_apikey(KR_SCHOOL, $keyB), 'the NEW key now authenticates');
    kr_check('old_key_still_valid', $sm->verify_apikey(KR_SCHOOL, $keyA),
        'the OLD key STILL authenticates during the grace window (dual-validity — no brick)');

    // -----------------------------------------------------------------------
    // Phase 2 — idempotence (retry with the old key, same new key).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: idempotent retry ---');
    $r2 = rotate_key_endpoint::execute(KR_SCHOOL, $keyA, $keyB);
    kr_check('idempotent_retry', empty($r2['rotated']) && !empty($r2['current']),
        're-submitting the already-current key is a no-op success (not a second swap)');
    kr_check('idempotent_preserves',
        $sm->verify_apikey(KR_SCHOOL, $keyB) && $sm->verify_apikey(KR_SCHOOL, $keyA),
        'the retry did not demote keyB or invalidate keyA');

    // A genuine rotation authenticated by the grace-window PREVIOUS key must be refused
    // (a leaked, rotated-away key cannot seize the account or chain-rotate the school out).
    $keyEvil = school_manager::generate_key();
    kr_check('prev_key_cannot_set_new', (function () use ($keyA, $keyEvil) {
        try {
            rotate_key_endpoint::execute(KR_SCHOOL, $keyA, $keyEvil);
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    })(), 'a NEW-key rotation authed by the previous (grace) key is rejected; and it left the keys intact: '
        . (($sm->verify_apikey(KR_SCHOOL, $keyB) && !$sm->verify_apikey(KR_SCHOOL, $keyEvil)) ? 'yes' : 'NO'));

    // -----------------------------------------------------------------------
    // Phase 3 — grace expiry retires the old key.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: grace expiry ---');
    $DB->set_field('local_syncqueue_schools', 'apikey_prev_expires', time() - 1, ['schoolid' => KR_SCHOOL]);
    kr_check('old_key_expires', !$sm->verify_apikey(KR_SCHOOL, $keyA),
        'past the grace window the old key is rejected');
    kr_check('new_key_survives_expiry', $sm->verify_apikey(KR_SCHOOL, $keyB),
        'the current key still authenticates after the previous key expired');
    kr_check('reject_short_key', (function () use ($sm, $keyB) {
        try {
            rotate_key_endpoint::execute(KR_SCHOOL, $keyB, 'tooshort');
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    })(), 'a too-short new key is rejected (never weaken the credential)');

    // -----------------------------------------------------------------------
    // Phase 4 — the school-side rotate_local (double client -> endpoint).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 4: school-side rotate_local ---');
    // Reset to a known single-key state for the school side.
    $DB->delete_records('local_syncqueue_schools', ['schoolid' => KR_SCHOOL]);
    $keyS = $sm->register_school(KR_SCHOOL, 'ITEST Key Rotation Fixture');
    set_config('mode', 'school', 'local_syncqueue');
    set_config('schoolid', KR_SCHOOL, 'local_syncqueue');
    set_config('apikey', $keyS, 'local_syncqueue');
    set_config('centralserver', 'https://localhost', 'local_syncqueue');
    // A distinct Moodle wstoken must exist or rotate_local refuses (rotating the apikey
    // would otherwise break the WS transport when the apikey doubles as the token).
    set_config('wstoken', 'itest-distinct-wstoken', 'local_syncqueue');
    unset_config(key_rotation::PENDING, 'local_syncqueue');

    $res = key_rotation::rotate_local(new itest_kr_client());
    $adopted = (string) get_config('local_syncqueue', 'apikey');
    kr_check('rotate_local_adopts',
        ($res['status'] ?? '') === 'rotated' && $adopted !== $keyS
            && get_config('local_syncqueue', key_rotation::PENDING) === false,
        'rotate_local adopted a new local key and cleared the pending marker');
    // Central holds the adopted key as current; the old school key is in grace.
    set_config('mode', 'central', 'local_syncqueue');
    kr_check('rotate_local_confirmed',
        $sm->verify_apikey(KR_SCHOOL, $adopted) && $sm->verify_apikey(KR_SCHOOL, $keyS),
        'central authenticates the adopted key, and the pre-rotation key is in grace');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $krfailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
$DB->delete_records('local_syncqueue_schools', ['schoolid' => KR_SCHOOL]);
foreach ($confignames as $n) {
    if ($saved[$n] === false) {
        unset_config($n, 'local_syncqueue');
    } else {
        set_config($n, $saved[$n], 'local_syncqueue');
    }
}
kr_check('cleanup_restored', !$DB->record_exists('local_syncqueue_schools', ['schoolid' => KR_SCHOOL]),
    'fixture school and configs removed');

if (empty($krfailures)) {
    cli_writeln('SPIKE RESULT: PASS - dual-validity key rotation verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($krfailures)));
exit(1);
