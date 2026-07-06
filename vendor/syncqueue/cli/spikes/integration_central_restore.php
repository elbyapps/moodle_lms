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
 * ELMS Sync v2 step 6 integration spike: central-restore re-incarnation detection (§4.5).
 *
 * Proves the detection + gating (the composition — re-bootstrap + re-queue — reuses the
 * snapshot and upstream spikes' code):
 *   1. observe_head persists central's head and flags a restore ONLY on a regression
 *      below the last observed head; growth and a zero head never flag; a single restore
 *      is not re-flagged every pull.
 *   2. handle() keeps the flag on an incomplete re-bootstrap (retry), is a no-op on
 *      central and when no restore is pending.
 *
 * Config-only (no fixtures); restores every touched config key.
 *
 * Usage:  php integration_central_restore.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\central_restore;

$crfailures = [];

/** Record + print one assertion. */
function cr_check(string $name, bool $ok, string $detail = ''): void {
    global $crfailures;
    if (!$ok) {
        $crfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/** Read the persisted central head seq. */
function cr_head(): int {
    return (int) get_config('local_syncqueue', central_restore::HEAD_KEY);
}

$confignames = ['mode', 'enabled', 'pull_v2', central_restore::HEAD_KEY,
    central_restore::FLAG, central_restore::DETECTED];
$saved = [];
foreach ($confignames as $n) {
    $saved[$n] = get_config('local_syncqueue', $n);
}

$fatal = null;
try {
    // Clean slate for the detector.
    unset_config(central_restore::HEAD_KEY, 'local_syncqueue');
    unset_config(central_restore::FLAG, 'local_syncqueue');

    // 1. Baseline + growth never flag.
    central_restore::observe_head(100);
    cr_check('observe_baseline', cr_head() === 100 && !central_restore::required(),
        'first head is the baseline, no restore flagged');
    central_restore::observe_head(150);
    cr_check('observe_grows', cr_head() === 150 && !central_restore::required(),
        'a growing head never flags a restore');

    // 2. A regression flags a restore and re-sets the baseline.
    central_restore::observe_head(80);
    cr_check('observe_regression', cr_head() === 80 && central_restore::required(),
        'head 80 < last 150 flags a central restore; baseline re-set to 80');

    // 3. Growth after a restore does not re-flag, and the flag persists.
    central_restore::observe_head(90);
    cr_check('observe_no_refire', cr_head() === 90 && central_restore::required(),
        '90 > 80 does not re-flag; the pending flag survives until handled');

    // 4. A zero/absent head is ignored (no false regression on a bad response).
    central_restore::observe_head(0);
    cr_check('observe_ignores_zero', cr_head() === 90,
        'a zero head is ignored (does not clobber the baseline or flag)');

    // 5. The push-side DETECTED flag (previously orphaned) also triggers re-incarnation.
    unset_config(central_restore::FLAG, 'local_syncqueue');
    set_config(central_restore::DETECTED, 1, 'local_syncqueue');
    cr_check('bridges_push_detector', central_restore::required(),
        'the push-side central_restore_detected flag now drives re-incarnation (was orphaned)');
    unset_config(central_restore::DETECTED, 'local_syncqueue');
    set_config(central_restore::FLAG, 1, 'local_syncqueue');

    // 6. handle() keeps the flag when the re-bootstrap can't complete (pull_v2 off).
    set_config('mode', 'school', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    set_config('pull_v2', 0, 'local_syncqueue');
    $r = central_restore::handle();
    cr_check('handle_retry_on_incomplete',
        ($r['status'] ?? '') === 'retry' && central_restore::required(),
        're-bootstrap incomplete -> handle returns retry and KEEPS the flag (no silent desync)');

    // 6. No-op on central and when no restore is pending.
    set_config('mode', 'central', 'local_syncqueue');
    cr_check('handle_noop_central', (central_restore::handle()['status'] ?? '') === 'none',
        'central never re-incarnates itself');
    set_config('mode', 'school', 'local_syncqueue');
    unset_config(central_restore::FLAG, 'local_syncqueue');
    cr_check('handle_noop_noflag', (central_restore::handle()['status'] ?? '') === 'none' && !central_restore::required(),
        'no pending restore -> handle is a clean no-op');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $crfailures[] = 'script_completed';
}

// Restore every touched config key.
foreach ($confignames as $n) {
    if ($saved[$n] === false) {
        unset_config($n, 'local_syncqueue');
    } else {
        set_config($n, $saved[$n], 'local_syncqueue');
    }
}
cr_check('cleanup_restored',
    get_config('local_syncqueue', 'mode') === $saved['mode']
        && get_config('local_syncqueue', central_restore::FLAG) === $saved[central_restore::FLAG],
    'all touched config keys restored');

if (empty($crfailures)) {
    cli_writeln('SPIKE RESULT: PASS - central-restore re-incarnation detection verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($crfailures)));
exit(1);
