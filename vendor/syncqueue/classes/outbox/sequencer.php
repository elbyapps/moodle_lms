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

namespace local_syncqueue\outbox;

use local_syncqueue\fact_identity;
use local_syncqueue\fact_ledger;

/**
 * Post-commit dense sequencer for the v2 outbox (ELMS Sync v2 step 1).
 *
 * A single serialized assigner (named lock) stamps committed seq = NULL rows
 * with dense consecutive values from the 'outbox' counter, so consumer cursor
 * paging is exact and contiguous acks cannot wedge. Runs from the scheduled
 * task and inline before serving any pull.
 *
 * Step 2 extends this with FACT FINALIZATION: because this is the only
 * serialized writer, it is also where an upstream fact row's monotonic
 * factversion is assigned (fact_ledger::assign_next_version) and its factuuid
 * derived — race-free by construction, with no in-transaction row lock that
 * could block a learner's grade save. Downstream content rows (no lineageuuid)
 * are sequenced exactly as before.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sequencer {

    /** @var string Name of the counter row in local_syncqueue_seq. */
    const COUNTER = 'outbox';

    /**
     * Assign dense sequence numbers to committed unsequenced outbox rows.
     *
     * Skips silently (returns 0) when another sequencer holds the lock.
     *
     * @return int Number of rows sequenced.
     */
    public static function assign(): int {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_syncqueue');
        $lock = $lockfactory->get_lock('sequencer', 10);
        if (!$lock) {
            return 0;
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                // SKIP LOCKED avoids blocking on rows still inside live writer
                // transactions; those rows are picked up on the next run. Fact
                // rows need their lineage/hash/ledger link here to finalize.
                $suffix = self::skip_locked_supported() ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
                $rows = $DB->get_records_sql(
                    'SELECT id, ledgerid, lineageuuid, factversion, payloadhash
                       FROM {local_syncqueue_outbox} WHERE seq IS NULL ORDER BY id' . $suffix);

                if (!$rows) {
                    $transaction->allow_commit();
                    return 0;
                }

                $counter = $DB->get_record_sql(
                    'SELECT * FROM {local_syncqueue_seq} WHERE name = :name FOR UPDATE',
                    ['name' => self::COUNTER]);
                if (!$counter) {
                    $counter = new \stdClass();
                    $counter->name = self::COUNTER;
                    $counter->value = 0;
                    $counter->id = $DB->insert_record('local_syncqueue_seq', $counter);
                }

                $next = (int)$counter->value;
                foreach ($rows as $row) {
                    $next++;

                    // An upstream fact row: assign the seq and finalize identity.
                    $isfact = $row->ledgerid !== null
                        || ($row->lineageuuid !== null && $row->factversion === null);
                    if ($isfact && $row->lineageuuid !== null) {
                        $version = fact_ledger::assign_next_version($row->lineageuuid, (string)$row->payloadhash);
                        $factuuid = fact_identity::fact_uuid($row->lineageuuid, $version);

                        // Collision guard: a sibling unfinalized row with an
                        // identical lineage AND payloadhash (a concurrent double
                        // capture) already claimed this exact (lineage, version).
                        // Re-finalizing our ledger row to it would violate the
                        // ledger unique keys, roll back this whole transaction, and
                        // re-throw every run — permanently wedging ALL sequencing,
                        // downstream content included. Detecting it here (single
                        // writer + same-tx read-your-writes make the check exact)
                        // lets this row share the sibling's identity; central dedups
                        // on factuuid. We retire this row's own ledger row as a dead
                        // duplicate rather than finalizing a colliding version.
                        $sibling = fact_ledger::get_by_factuuid($factuuid);
                        $isduplicate = $sibling && (int)$sibling->id !== (int)$row->ledgerid;
                        if ($isduplicate) {
                            if ($row->ledgerid !== null) {
                                fact_ledger::retire_duplicate((int)$row->ledgerid);
                            }
                        } else if ($row->ledgerid !== null) {
                            fact_ledger::finalize((int)$row->ledgerid, $version, $next);
                        }

                        $update = new \stdClass();
                        $update->id = $row->id;
                        $update->seq = $next;
                        $update->entityversion = $version;
                        $update->factversion = $version;
                        $update->factuuid = $factuuid;
                        $DB->update_record('local_syncqueue_outbox', $update);
                        continue;
                    }

                    // Downstream content row: seq only (unchanged behaviour).
                    $DB->set_field('local_syncqueue_outbox', 'seq', $next, ['id' => $row->id]);
                }
                $DB->set_field('local_syncqueue_seq', 'value', $next, ['id' => $counter->id]);

                $transaction->allow_commit();
                return count($rows);
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the database supports FOR UPDATE SKIP LOCKED.
     *
     * MariaDB 10.6+, MySQL 8.0+, PostgreSQL 9.5+ (i.e. any supported version).
     *
     * @return bool
     */
    private static function skip_locked_supported(): bool {
        global $DB;
        static $supported = null;

        if ($supported === null) {
            $vendor = $DB->get_dbvendor();
            if ($vendor === 'postgres') {
                $supported = true;
            } else if ($vendor === 'mariadb') {
                $supported = version_compare($DB->get_server_info()['version'], '10.6.0', '>=');
            } else if ($vendor === 'mysql') {
                $supported = version_compare($DB->get_server_info()['version'], '8.0.0', '>=');
            } else {
                $supported = false;
            }
        }
        return $supported;
    }
}
