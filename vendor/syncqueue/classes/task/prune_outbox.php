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

/**
 * Downstream outbox pruning + snapshot GC (ELMS Sync v2 step 7, central side).
 *
 * The content outbox was never pruned (min_retained_seq hardcoded 1) — re-publishes and
 * content bumps accumulate SUPERSEDED rows (older entityversions of a key) forever,
 * bloating the table and every central_expected_map / digest scan. This prunes them.
 *
 * Why superseded-only pruning is provably safe (no re-bootstrap needed):
 *  - A higher entityversion is published later, so the HEAD row's seq is always greater
 *    than any superseded row's seq for the same key.
 *  - An incremental pull returns, per key, the head among rows with seq > the school's
 *    cursor (read-time supersession). If a superseded row's seq exceeds a cursor, its
 *    head's (greater) seq does too — so the school always receives the head regardless.
 *  - Manifests and digests read the head (max entityversion) per key, never a superseded
 *    row. So pruning superseded rows can never cause a missed update or a broken
 *    bootstrap. (Aggressive HEAD pruning below a manifest floor — which WOULD need
 *    min_retained_seq + a re-bootstrap trigger — is a deliberate follow-up.)
 *
 * Also GCs snapshot manifests past their pin (lazy expiry only replaced them on fetch).
 * Time/row budgeted for 1 vCPU boxes.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_outbox extends scheduled_task {

    /** @var int Keep superseded rows at least this long (churn history / digest robustness). */
    const RETENTION_SECONDS = 7 * 86400;

    /** @var int Max rows deleted per run. */
    const MAX_ROWS = 5000;

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_prune_outbox', 'local_syncqueue');
    }

    /**
     * Prune superseded content rows + GC expired manifests.
     */
    public function execute(): void {
        global $DB;

        if (get_config('local_syncqueue', 'mode') !== 'central'
                || !get_config('local_syncqueue', 'enabled')) {
            mtrace('prune_outbox: not an enabled central instance, skipping.');
            return;
        }

        $cutoff = time() - self::RETENTION_SECONDS;

        // Superseded content rows: a strictly-higher entityversion exists for the same
        // entity, and this row is older than the retention window. Content partitions
        // only — upstream/learner rows have their own retain-until-ack lifecycle
        // (push_stream::prune_acked), and seeds are targeted+bounded.
        $ids = $DB->get_fieldset_sql(
            "SELECT o.id
               FROM {local_syncqueue_outbox} o
              WHERE " . $DB->sql_like('o.partitionkey', ':content') . "
                AND o.timecreated < :cutoff
                AND EXISTS (
                    SELECT 1 FROM {local_syncqueue_outbox} h
                     WHERE h.entitytype = o.entitytype
                       AND h.entitykey = o.entitykey
                       AND h.entityversion > o.entityversion
                       AND h.seq IS NOT NULL)
              ORDER BY o.id ASC",
            ['content' => 'content:%', 'cutoff' => $cutoff], 0, self::MAX_ROWS);

        $pruned = 0;
        foreach (array_chunk($ids, 500) as $batch) {
            [$insql, $inparams] = $DB->get_in_or_equal($batch, SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_syncqueue_outbox', 'id ' . $insql, $inparams);
            $pruned += count($batch);
        }

        // GC snapshot manifests past their pin (lazy expiry only swaps on the next fetch).
        $expired = $DB->count_records_select('local_syncqueue_snapshot', 'pinneduntil < :now', ['now' => time()]);
        if ($expired) {
            $DB->delete_records_select('local_syncqueue_snapshot', 'pinneduntil < :now', ['now' => time()]);
        }

        mtrace("prune_outbox: pruned {$pruned} superseded content rows, GC'd {$expired} expired manifest chunks.");
    }
}
