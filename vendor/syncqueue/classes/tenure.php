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
 * Central-side apply-ordering guards for upstream learner facts (ELMS Sync v2
 * §5/§8.1): home tenure and the AGS per-item origin-seq high-water.
 *
 * home(learner) is the single school on their current roster row; exactly one
 * author at a time. A fact from origin school S for learner SDMS, stamped with
 * S's roster generation G, is only authoritative if S held tenure for SDMS at
 * generation G — judged against the tenure in force WHEN THE FACT WAS AUTHORED,
 * never against arrival time. This makes late delivery safe: a school
 * reconnecting after six months still lands its in-tenure work, and a
 * graduate's post-move facts for the old school are accepted only for the
 * period the old school was home.
 *
 * The AGS guard is the complementary transport-order rule: within
 * (origin, epoch, learner, item) the origin's own sequence (school_seq) must be
 * strictly increasing; a lower arrival is explicitly stale, never wall-clock
 * compared. It spans lineages — the pre/post item-UUID-stamping cutover splits
 * one item's facts across two lineages (a natural-key change), and this
 * per-(learner, item) high-water still orders them.
 *
 * These are pure guards: they store and read ordering state and answer
 * admits/stale questions. They do NOT apply facts — the step-4 appliers stage
 * consults them (via central_processor's fact-context seam) and owns the writes.
 * Every method is table_exists-guarded so a partially-migrated or push_v2=0 site
 * is unaffected.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tenure {

    /** @var string Home-tenure interval table. */
    const TENURE_TABLE = 'local_syncqueue_tenure';

    /** @var string Per-item origin-seq high-water table (AGS). */
    const AGS_TABLE = 'local_syncqueue_ags';

    /** @var string True-contradiction record table. */
    const CONFLICTS_TABLE = 'local_syncqueue_conflicts';

    // --- Home tenure -------------------------------------------------------

    /**
     * Record that $schoolid is home for $sdms from roster generation $rostergen.
     *
     * Keeps exactly one OPEN interval (torostergen IS NULL) per learner: a home
     * change closes the prior open interval at $rostergen and opens a new one
     * from $rostergen, yielding contiguous half-open intervals [from, to). A
     * repeat signal for the school that is already home is an idempotent no-op
     * (the open interval already covers the later generation). A signal whose
     * generation is not strictly greater than the open interval's start is a
     * stale/out-of-order home signal and is skipped rather than inverting an
     * interval — the ledger's original rostergen stamp (§9.1) is the ordering
     * authority, not the arrival order of these signals.
     *
     * @param string $sdms Learner SDMS code.
     * @param string $schoolid School that is home.
     * @param int $rostergen Roster generation at which the school became home.
     */
    public static function record_tenure(string $sdms, string $schoolid, int $rostergen): void {
        global $DB;

        if ($sdms === '' || $schoolid === '' || !self::table(self::TENURE_TABLE)) {
            return;
        }

        $now = time();
        $open = $DB->get_records_select(self::TENURE_TABLE,
            'sdms = :sdms AND torostergen IS NULL', ['sdms' => $sdms], 'fromrostergen DESC', '*', 0, 1);
        $open = $open ? reset($open) : null;

        if ($open) {
            if ((string) $open->schoolid === $schoolid) {
                // Same school still home: the open interval already covers it.
                return;
            }
            if ($rostergen <= (int) $open->fromrostergen) {
                // A close at or before the open interval's start would invert it:
                // a replayed or out-of-order home signal. Leave history intact.
                debugging("tenure: skipping out-of-order home signal for {$sdms} -> {$schoolid}"
                    . " at gen {$rostergen} (open interval starts at {$open->fromrostergen})", DEBUG_DEVELOPER);
                return;
            }
            $open->torostergen = $rostergen;
            $open->timemodified = $now;
            $DB->update_record(self::TENURE_TABLE, $open);
        }

        $row = new stdClass();
        $row->sdms = $sdms;
        $row->schoolid = $schoolid;
        $row->fromrostergen = $rostergen;
        $row->torostergen = null;
        $row->timecreated = $now;
        $row->timemodified = $now;
        $DB->insert_record(self::TENURE_TABLE, $row);
    }

    /**
     * Whether $schoolid held tenure for $sdms at roster generation $rostergen.
     *
     * True iff an interval for (sdms, schoolid) has fromrostergen <= G and is
     * either open or ends strictly after G (half-open [from, to)). Judged against
     * the generation stamped on the fact, so a late fact for a period the school
     * was home still admits, and a fact for a period after the learner moved does
     * not.
     *
     * @param string $sdms Learner SDMS code.
     * @param string $schoolid Candidate authoring school.
     * @param int $rostergen Generation stamped on the fact.
     * @return bool
     */
    public static function in_force(string $sdms, string $schoolid, int $rostergen): bool {
        global $DB;

        if ($sdms === '' || $schoolid === '' || !self::table(self::TENURE_TABLE)) {
            return false;
        }
        return $DB->record_exists_select(self::TENURE_TABLE,
            'sdms = :sdms AND schoolid = :schoolid AND fromrostergen <= :fromg'
                . ' AND (torostergen IS NULL OR :tog < torostergen)',
            ['sdms' => $sdms, 'schoolid' => $schoolid, 'fromg' => $rostergen, 'tog' => $rostergen]);
    }

    // --- Central roster generation + home producer (doc §5, Option B) -------

    /** @var string Config key: central's fleet-wide monotonic roster generation. */
    const HOMEGEN = 'central_rostergen';

    /** @var int Generation a learner's first-ever home interval opens at (doc §8.1). */
    const FLOOR_GENERATION = 1;

    /**
     * The current central roster generation (0 before the first home change).
     *
     * This is the single fleet-wide logical clock (Option B): central owns it,
     * stamps it on every roster served to a school (which the school adopts as its
     * local rostergen), and records tenure intervals in this same space. A fact's
     * rostergen and a tenure interval's fromrostergen/torostergen are therefore
     * always commensurable — the incompatible numbering that per-school local
     * counters produced (each school independently starting at 1) is gone.
     *
     * @return int
     */
    public static function current_generation(): int {
        $gen = get_config('local_syncqueue', self::HOMEGEN);
        return ($gen === false || $gen === '') ? 0 : (int) $gen;
    }

    /**
     * Read-modify-write the central generation counter, returning the new value.
     *
     * MUST be called while holding the self::HOMEGEN lock (record_home is the only
     * caller and holds it across the whole detect->advance->record section), so the
     * read and write cannot interleave with a concurrent serve and lose a tick. The
     * first advance yields 1.
     *
     * @return int The new generation.
     */
    protected static function increment_generation(): int {
        $next = self::current_generation() + 1;
        set_config(self::HOMEGEN, $next, 'local_syncqueue');
        return $next;
    }

    /**
     * Record the home assignments TDMP currently reports for a school, returning
     * the roster generation to stamp on that school's roster response (Option B
     * producer, doc §5/§8.1).
     *
     * Called by tdmp_roster::execute at the instant central fetches
     * get_students_by_school(S): that response IS TDMP's authoritative "these
     * learners are home at S right now". For each such learner this ensures S is
     * their open home interval. A learner whose open interval names a DIFFERENT
     * school has moved, so record_tenure closes the prior interval at the new
     * generation and opens S's — the destination's positive claim is what closes
     * the origin, never mere absence (mirroring the reconciler's
     * absence-vs-departure rule: a truncated roster must not close intervals). A
     * learner already open under S is an idempotent no-op.
     *
     * The clock advances ONCE per serve that carries at least one change, and every
     * changed learner in that serve shares the new generation (they all became home
     * at the same tick). A serve with no changes does not advance it and simply
     * returns the current generation, so idempotent daily serves never inflate it.
     *
     * A first-ever home (the learner has NO interval at all) is opened at the FLOOR
     * generation, not the freshly-advanced one: a learner linked via individual
     * signup can have authored in-tenure work stamped with a generation adopted
     * BEFORE this serve first recorded them, and an interval opened at the advanced
     * generation would reject that work; there is no earlier home to over-accept
     * for, so the floor is safe. A move or a re-appearance (an interval already
     * exists) keeps the advanced generation as its boundary.
     *
     * The detect->advance->record section is serialized by a named lock: reading the
     * open intervals and writing them must be atomic against a concurrent serve of
     * the SAME school (a manual "sync roster now" overlapping the cron refresh), or
     * two serves could each insert a second open interval for one learner that no
     * later move can close — permanently stranding the departed school's authorship.
     * On lock contention nothing is recorded this serve (any move is caught on the
     * next one), which is always safer than risking that corruption.
     *
     * @param array $sdmslist SDMS codes home at $schoolid (the served student set).
     * @param string $schoolid The school being served (its fact-origin id).
     * @return int The generation to stamp on the response (unchanged when nothing moved).
     */
    public static function record_home(array $sdmslist, string $schoolid): int {
        global $DB;

        if ($schoolid === '' || !self::table(self::TENURE_TABLE)) {
            return self::current_generation();
        }

        // Normalise to a unique, non-empty string set.
        $sdmsset = [];
        foreach ($sdmslist as $sdms) {
            $sdms = trim((string) $sdms);
            if ($sdms !== '') {
                $sdmsset[$sdms] = true;
            }
        }
        if (empty($sdmsset)) {
            return self::current_generation();
        }
        $sdmscodes = array_keys($sdmsset);

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_syncqueue');
        $lock = $lockfactory->get_lock(self::HOMEGEN, 20);
        if (!$lock) {
            debugging('tenure::record_home: home lock contended; deferring this serve to the next refresh',
                DEBUG_DEVELOPER);
            return self::current_generation();
        }

        try {
            // One query for the open interval of every served learner; compare in PHP
            // so a steady-state serve (nothing moved) costs a single SELECT and no
            // writes. A learner with no open interval, or one open under another
            // school, changed.
            [$insql, $inparams] = $DB->get_in_or_equal($sdmscodes, SQL_PARAMS_NAMED, 'sd');
            $open = $DB->get_records_select(self::TENURE_TABLE,
                "sdms $insql AND torostergen IS NULL", $inparams, '', 'id, sdms, schoolid');
            $homeof = [];
            foreach ($open as $row) {
                $homeof[(string) $row->sdms] = (string) $row->schoolid;
            }

            $changed = [];
            foreach ($sdmscodes as $sdms) {
                if (($homeof[$sdms] ?? null) !== $schoolid) {
                    $changed[] = $sdms;
                }
            }
            if (empty($changed)) {
                return self::current_generation();
            }

            // Which changed learners already have ANY interval (open elsewhere, or a
            // closed one from a prior stint)? Those are moves / re-appearances and
            // take the advanced generation as their boundary; the rest are first-ever
            // homes and open at the floor.
            [$cin, $cparams] = $DB->get_in_or_equal($changed, SQL_PARAMS_NAMED, 'ch');
            $known = array_flip($DB->get_fieldset_select(self::TENURE_TABLE, 'DISTINCT sdms', "sdms $cin", $cparams));

            // Advance the clock once for the whole serve so recorded homes are stamped
            // in a positive space (schools adopt it and stamp non-null facts, which
            // keeps the tenure gate live); moved learners share that new boundary.
            $gen = self::increment_generation();
            foreach ($changed as $sdms) {
                if (isset($known[$sdms])) {
                    // A genuine move / re-appearance to this school. Record the boundary
                    // and queue a history reseed (§8.3) so the destination doesn't start
                    // the learner from a clean slate. enqueue is best-effort and never
                    // throws, so it can't break tenure recording or the roster serve.
                    self::record_tenure($sdms, $schoolid, $gen);
                    seed_publisher::enqueue($sdms, $schoolid, $gen);
                } else {
                    // A first-ever home opens at the floor (no earlier work to reseed).
                    self::record_tenure($sdms, $schoolid, self::FLOOR_GENERATION);
                }
            }
            return $gen;
        } finally {
            $lock->release();
        }
    }

    // --- AGS per-item origin-seq high-water --------------------------------

    /**
     * Whether an arriving fact's origin sequence is stale for its (learner, item).
     *
     * Stale means not strictly greater than the highest school_seq already
     * observed for (origin, epoch, learner, item): a lower OR equal seq is a
     * superseded or replayed arrival. A group with no prior observation is never
     * stale (the first fact establishes the high-water). The high-water is
     * epoch-scoped, so a re-incarnation (new epoch) starts fresh — cross-epoch
     * supersession ordering is the re-incarnation stage's concern, not this
     * within-epoch monotonicity guard.
     *
     * @param string $origin Authoring school id.
     * @param string $epoch School database incarnation the fact was authored under.
     * @param string $sdms Learner SDMS code.
     * @param string $itemuuid Stamped cm / grade-item UUID identifying the item.
     * @param int $schoolseq The fact's origin sequence.
     * @return bool True when the fact is stale (must be explicitly acked stale).
     */
    public static function is_stale(string $origin, string $epoch, string $sdms,
            string $itemuuid, int $schoolseq): bool {
        global $DB;

        if (!self::table(self::AGS_TABLE)) {
            return false;
        }
        $hw = $DB->get_field(self::AGS_TABLE, 'schoolseq',
            ['origin' => $origin, 'epoch' => $epoch, 'agskey' => self::ags_key($sdms, $itemuuid)]);
        if ($hw === false) {
            return false;
        }
        return $schoolseq <= (int) $hw;
    }

    /**
     * Advance the (origin, epoch, learner, item) origin-seq high-water.
     *
     * Monotonic: a lower or equal seq never lowers the stored high-water, so
     * out-of-order replays cannot regress it. Called by the appliers stage after
     * a fact is durably applied; idempotent on replay.
     *
     * @param string $origin Authoring school id.
     * @param string $epoch School database incarnation.
     * @param string $sdms Learner SDMS code.
     * @param string $itemuuid Stamped cm / grade-item UUID.
     * @param int $schoolseq Origin sequence of the applied fact.
     */
    public static function observe_seq(string $origin, string $epoch, string $sdms,
            string $itemuuid, int $schoolseq): void {
        global $DB;

        if (!self::table(self::AGS_TABLE)) {
            return;
        }
        $now = time();
        $agskey = self::ags_key($sdms, $itemuuid);
        $existing = $DB->get_record(self::AGS_TABLE,
            ['origin' => $origin, 'epoch' => $epoch, 'agskey' => $agskey]);
        if ($existing) {
            if ($schoolseq > (int) $existing->schoolseq) {
                $existing->schoolseq = $schoolseq;
                $existing->timemodified = $now;
                $DB->update_record(self::AGS_TABLE, $existing);
            }
            return;
        }
        $row = new stdClass();
        $row->origin = $origin;
        $row->epoch = $epoch;
        $row->agskey = $agskey;
        $row->schoolseq = $schoolseq;
        $row->timemodified = $now;
        $DB->insert_record(self::AGS_TABLE, $row);
    }

    // --- Conflicts ---------------------------------------------------------

    /**
     * Record a TRUE contradiction (two in-tenure origins disagreeing beyond the
     * pinned policy). Cross-tenure disagreements merge under Highest and are NOT
     * recorded here. Provided so the conflicts table has an owner; the appliers
     * stage decides when a disagreement is a genuine contradiction.
     *
     * @param array $fields facttype, lineageuuid, origin (required-ish) plus
     *        optional factuuid, sdms, entitykey, rostergen, reason, detail
     *        (array|string), status.
     * @return int Inserted row id, or 0 when the table is absent.
     */
    public static function record_conflict(array $fields): int {
        global $DB;

        if (!self::table(self::CONFLICTS_TABLE)) {
            return 0;
        }
        $now = time();
        $detail = $fields['detail'] ?? null;
        if (is_array($detail)) {
            $detail = json_encode($detail);
        }
        $row = new stdClass();
        $row->facttype = (string) ($fields['facttype'] ?? '');
        $row->lineageuuid = (string) ($fields['lineageuuid'] ?? '');
        $row->factuuid = isset($fields['factuuid']) ? (string) $fields['factuuid'] : null;
        $row->origin = (string) ($fields['origin'] ?? '');
        $row->sdms = isset($fields['sdms']) ? (string) $fields['sdms'] : null;
        $row->entitykey = isset($fields['entitykey']) ? (string) $fields['entitykey'] : null;
        $row->rostergen = isset($fields['rostergen']) && $fields['rostergen'] !== null
            ? (int) $fields['rostergen'] : null;
        $row->reason = (string) ($fields['reason'] ?? '');
        $row->detail = ($detail !== null) ? (string) $detail : null;
        $row->status = (string) ($fields['status'] ?? 'open');
        $row->timecreated = $now;
        $row->timemodified = $now;
        return (int) $DB->insert_record(self::CONFLICTS_TABLE, $row);
    }

    // --- Internals ---------------------------------------------------------

    /**
     * The deterministic AGS group key for a (learner, item) pair.
     *
     * A sync-namespace UUIDv5 so the key is fixed-width and delimiter-safe; the
     * item UUID contains no '|', so the join is unambiguous.
     *
     * @param string $sdms Learner SDMS code.
     * @param string $itemuuid Item UUID.
     * @return string
     */
    protected static function ags_key(string $sdms, string $itemuuid): string {
        return fact_identity::uuid_v5(fact_identity::SYNC_NAMESPACE, 'ags|' . $sdms . '|' . $itemuuid);
    }

    /**
     * Whether one of this stage's tables is installed (dual-stack guard).
     *
     * @param string $table Table name.
     * @return bool
     */
    protected static function table(string $table): bool {
        global $DB;
        return $DB->get_manager()->table_exists($table);
    }
}
