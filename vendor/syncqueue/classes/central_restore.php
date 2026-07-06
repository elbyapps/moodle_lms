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

use local_syncqueue\task\upstream_anti_entropy;

/**
 * Central-restore re-incarnation (ELMS Sync v2 step 6, doc §4.5).
 *
 * A full-VM rollback restores central's DB AND its epoch together, so schools cannot
 * rely on the epoch alone. Instead each school persists the last central head seq it
 * observed; a pull response whose head has REGRESSED below it means central lost its tail
 * (a restore), even with an unchanged epoch. The school then re-incarnates the central
 * relationship by COMPOSING the pieces already built:
 *
 *   1. Re-bootstrap the downstream via the snapshot ({@see snapshot_bootstrap}) — the
 *      school's pull cursor now sits PAST central's lower head, so a normal pull would
 *      deliver nothing and miss central's restored state; the bootstrap reloads the head
 *      state and resets the cursor to central's current head.
 *   2. Re-queue school-owned facts ({@see upstream_anti_entropy}) — it detects the facts
 *      central's restore lost and force-re-pushes them, so central's lost week is
 *      recovered from the fleet rather than lost. Deterministic UUIDv5 identity means the
 *      rebuilt facts dedup exactly against whatever central still holds.
 *
 * There is no explicit "freeze": once the cursor is past central's head a normal pull
 * applies nothing, so the school is inert until the re-bootstrap resets the cursor.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class central_restore {

    /** @var string Config key: the highest central head seq this school has observed. */
    const HEAD_KEY = 'central_head_seq';

    /** @var string Config flag: a central restore was detected (pull side) and needs re-incarnation. */
    const FLAG = 'central_restore_required';

    /** @var string Config flag set by the PUSH side (push_stream::observe_central, monotonic epoch high-water). */
    const DETECTED = 'central_restore_detected';

    /**
     * Observe central's head seq from a pull response; flag a restore on regression.
     *
     * Called by pull_stream after each batch. A head strictly below the last observed
     * value is a central restore (its tail was lost). The baseline is then re-set to the
     * observed head (whether it grew or regressed) so the next regression is measured
     * against the current reality, and a single restore is not re-flagged every pull.
     *
     * @param int $headseq Central head seq echoed in the pull response.
     */
    public static function observe_head(int $headseq): void {
        if ($headseq <= 0) {
            return;
        }
        $last = (int) get_config('local_syncqueue', self::HEAD_KEY);
        if ($last > 0 && $headseq < $last && !get_config('local_syncqueue', self::FLAG)) {
            set_config(self::FLAG, 1, 'local_syncqueue');
            debugging("central_restore: central head regressed {$last} -> {$headseq}; "
                . 'central restore detected — re-incarnation required', DEBUG_DEVELOPER);
        }
        set_config(self::HEAD_KEY, $headseq, 'local_syncqueue');
    }

    /**
     * Whether a central restore is pending re-incarnation.
     *
     * @return bool
     */
    public static function required(): bool {
        // Honour BOTH detectors: the pull-side FLAG (observe_head) and the push-side
        // DETECTED flag (push_stream::observe_central, which has the more robust MONOTONIC
        // epoch high-water and catches a restore that regrew before the school next pulled).
        // The push-side flag was previously orphaned — nothing consumed it.
        return (bool) get_config('local_syncqueue', self::FLAG)
            || (bool) get_config('local_syncqueue', self::DETECTED);
    }

    /**
     * Re-incarnate the central relationship if a restore was detected.
     *
     * Re-bootstraps the downstream, then re-queues school-owned facts. The flag is cleared
     * only once the (idempotent) re-bootstrap completes, so a failure retries on the next
     * pull rather than leaving the school silently desynced. School mode only.
     *
     * @return array{status:string}
     */
    public static function handle(): array {
        global $DB;

        if (!self::required() || get_config('local_syncqueue', 'mode') !== 'school') {
            return ['status' => 'none'];
        }

        // 1. Re-bootstrap the downstream (reset the cursor to central's restored head).
        try {
            $boot = snapshot_bootstrap::run();
        } catch (\Throwable $e) {
            debugging('central_restore: re-bootstrap threw: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['status' => 'retry'];
        }
        if (($boot['status'] ?? '') !== 'done') {
            // Incomplete/skipped — keep the flag and retry next pull.
            return ['status' => 'retry'];
        }

        // 2. Re-queue school-owned facts central lost (best-effort; the weekly upstream
        //    digest is the backstop if this transient-fails).
        try {
            (new upstream_anti_entropy())->execute();
        } catch (\Throwable $e) {
            debugging('central_restore: upstream re-queue threw: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Re-anchor BOTH detectors to central's restored (lower) head so the same restore
        // is not re-flagged every run (the epoch high-water is monotonic and would keep
        // firing until central regrew): the pull-side baseline via config, the push-side by
        // clearing its SCOPE_CENTRAL row — the next push re-seeds it at the current head.
        set_config(self::HEAD_KEY, (int) ($boot['headseq'] ?? 0), 'local_syncqueue');
        if ($DB->get_manager()->table_exists('local_syncqueue_epoch')) {
            $DB->delete_records('local_syncqueue_epoch', ['scope' => epoch_store::SCOPE_CENTRAL, 'schoolid' => '']);
        }
        unset_config(self::FLAG, 'local_syncqueue');
        unset_config(self::DETECTED, 'local_syncqueue');
        return ['status' => 'handled'];
    }
}
