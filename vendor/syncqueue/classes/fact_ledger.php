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

use stdClass;

/**
 * Fact ledger accessor (ELMS Sync v2 §9.1).
 *
 * The ledger proves what upstream facts were captured, pins their deterministic
 * two-level identity, and preserves the tenure stamp so a regenerated fact keeps
 * its original roster generation. It holds no payloads.
 *
 * Identity is split across two phases to stay correct under concurrency:
 *
 *  - CAPTURE (may run inside a business transaction, concurrently): record the
 *    lineage, natural key, payloadhash and tenure. factversion/factuuid are left
 *    NULL. This is a plain insert with no per-lineage counter, so concurrent
 *    captures never race.
 *
 *  - FINALIZE (the single serialized sequencer, post-commit): assign the
 *    monotonic factversion and derive the factuuid. Because exactly one writer
 *    runs this, version assignment is race-free by construction — no in-
 *    transaction row-lock that could block or deadlock a learner's grade save.
 *
 * The lineageuuid is deterministic from content, so the same fact regenerated
 * from source tables after a restore mints the identical identity and dedups
 * exactly (§9.1 regeneration contract).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fact_ledger {

    /** @var string Ledger row lifecycle states. */
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_EXPORTED = 'exported';
    public const STATUS_ACKED = 'acked';
    public const STATUS_STALE = 'stale';
    public const STATUS_DEAD = 'dead';

    /**
     * Record a captured fact (capture phase).
     *
     * Idempotent on unchanged content: if the newest ledger row for this
     * (sourcetable, sourceid) already carries the same payloadhash, it is
     * returned unchanged rather than inserting a duplicate — so re-capture and
     * the capture-scan backstop never bloat the ledger. A changed payloadhash
     * inserts a fresh captured row (a new version, finalized later).
     *
     * @param string $origin Authoring school id.
     * @param string $facttype Fact type (grade, quiz_attempt, ...).
     * @param string $naturalkey Stable natural key.
     * @param string $sourcetable Source Moodle table.
     * @param int|null $sourceid Local source row id (null for a tombstone fact).
     * @param string $payloadhash SHA256 of the canonical fact payload.
     * @param array $opts Optional: rostergen, homeschool, sourceversion.
     * @return stdClass The ledger row (existing or freshly inserted).
     */
    public static function record(string $origin, string $facttype, string $naturalkey,
            string $sourcetable, ?int $sourceid, string $payloadhash, array $opts = []): stdClass {
        global $DB;

        $lineageuuid = fact_identity::lineage_uuid($origin, $facttype, $naturalkey);
        $now = time();

        // Idempotency: newest ledger row for this source row.
        if ($sourceid !== null) {
            $existing = $DB->get_records('local_syncqueue_ledger',
                ['sourcetable' => $sourcetable, 'sourceid' => $sourceid], 'id DESC', '*', 0, 1);
            $existing = $existing ? reset($existing) : null;
            if ($existing && $existing->payloadhash === $payloadhash) {
                return $existing;
            }
        }

        $row = new stdClass();
        $row->origin = $origin;
        $row->facttype = $facttype;
        $row->lineageuuid = $lineageuuid;
        $row->factversion = null;
        $row->factuuid = null;
        $row->naturalkey = $naturalkey;
        $row->sourcetable = $sourcetable;
        $row->sourceid = $sourceid;
        $row->payloadhash = $payloadhash;
        $row->sourceversion = $opts['sourceversion'] ?? null;
        $row->rostergen = $opts['rostergen'] ?? null;
        $row->homeschool = $opts['homeschool'] ?? null;
        $row->capturedat = $now;
        $row->lastexportedseq = null;
        $row->status = self::STATUS_CAPTURED;
        $row->timemodified = $now;
        $row->id = $DB->insert_record('local_syncqueue_ledger', $row);

        return $row;
    }

    /**
     * Decide the factversion for a lineage given a new payloadhash (finalize phase).
     *
     * MUST be called by a serialized writer (the sequencer). Compares against the
     * newest FINALIZED version of the lineage: unchanged hash reuses that version
     * (idempotent — the same content is never two versions); changed hash yields
     * the next version. A lineage whose only rows are still unfinalized starts at 1.
     *
     * @param string $lineageuuid Lineage UUID.
     * @param string $payloadhash Candidate payloadhash.
     * @return int The factversion to assign.
     */
    public static function assign_next_version(string $lineageuuid, string $payloadhash): int {
        global $DB;

        $latest = self::latest_finalized($lineageuuid);
        if ($latest === null) {
            return 1;
        }
        if ($latest->payloadhash === $payloadhash) {
            return (int) $latest->factversion;
        }
        return (int) $latest->factversion + 1;
    }

    /**
     * Finalize a captured ledger row: stamp its factversion and derived factuuid.
     *
     * @param int $ledgerid Ledger row id.
     * @param int $factversion Version assigned by assign_next_version().
     * @param int|null $exportedseq Outbox seq this fact is exported at, if known.
     * @return stdClass The updated ledger row.
     */
    public static function finalize(int $ledgerid, int $factversion, ?int $exportedseq = null): stdClass {
        global $DB;

        $row = $DB->get_record('local_syncqueue_ledger', ['id' => $ledgerid], '*', MUST_EXIST);
        $row->factversion = $factversion;
        $row->factuuid = fact_identity::fact_uuid($row->lineageuuid, $factversion);
        if ($exportedseq !== null) {
            $row->lastexportedseq = $exportedseq;
            $row->status = self::STATUS_EXPORTED;
        }
        $row->timemodified = time();
        $DB->update_record('local_syncqueue_ledger', $row);

        return $row;
    }

    /**
     * Newest finalized (factversion IS NOT NULL) ledger row for a lineage, or null.
     *
     * @param string $lineageuuid Lineage UUID.
     * @return stdClass|null
     */
    public static function latest_finalized(string $lineageuuid): ?stdClass {
        global $DB;

        $rows = $DB->get_records_select('local_syncqueue_ledger',
            'lineageuuid = :lineage AND factversion IS NOT NULL',
            ['lineage' => $lineageuuid], 'factversion DESC', '*', 0, 1);

        return $rows ? reset($rows) : null;
    }

    /**
     * Fetch a ledger row by its finalized fact UUID.
     *
     * @param string $factuuid Fact UUID.
     * @return stdClass|null
     */
    public static function get_by_factuuid(string $factuuid): ?stdClass {
        global $DB;
        $row = $DB->get_record('local_syncqueue_ledger', ['factuuid' => $factuuid]);
        return $row ?: null;
    }

    /**
     * All ledger rows for a source row, newest first.
     *
     * @param string $sourcetable Source table.
     * @param int $sourceid Source row id.
     * @return stdClass[]
     */
    public static function get_by_source(string $sourcetable, int $sourceid): array {
        global $DB;
        return $DB->get_records('local_syncqueue_ledger',
            ['sourcetable' => $sourcetable, 'sourceid' => $sourceid], 'id DESC');
    }

    /**
     * Set the lifecycle status of a finalized fact (by factuuid).
     *
     * @param string $factuuid Fact UUID.
     * @param string $status One of the STATUS_* constants.
     * @param int|null $exportedseq Optionally record the export seq.
     */
    public static function mark_status(string $factuuid, string $status, ?int $exportedseq = null): void {
        global $DB;

        $row = $DB->get_record('local_syncqueue_ledger', ['factuuid' => $factuuid]);
        if (!$row) {
            return;
        }
        $row->status = $status;
        if ($exportedseq !== null) {
            $row->lastexportedseq = $exportedseq;
        }
        $row->timemodified = time();
        $DB->update_record('local_syncqueue_ledger', $row);
    }

    /**
     * Retire a still-captured ledger row as a benign duplicate of a sibling that
     * already finalized the identical (lineage, version) identity.
     *
     * Used by the sequencer when two unfinalized rows share a lineage AND
     * payloadhash: finalizing both to the same version would violate the ledger
     * unique keys and roll back the whole shared batch. The duplicate keeps
     * factversion/factuuid NULL (so no unique-key clash) and is marked dead; the
     * outbox row it belongs to still ships under the sibling's factuuid, which
     * central dedups as a benign replay.
     *
     * @param int $ledgerid Ledger row id to retire.
     */
    public static function retire_duplicate(int $ledgerid): void {
        global $DB;

        $row = $DB->get_record('local_syncqueue_ledger', ['id' => $ledgerid]);
        if (!$row || $row->factversion !== null) {
            // Missing, or already finalized in its own right — leave it alone.
            return;
        }
        $row->status = self::STATUS_DEAD;
        $row->timemodified = time();
        $DB->update_record('local_syncqueue_ledger', $row);
    }
}
