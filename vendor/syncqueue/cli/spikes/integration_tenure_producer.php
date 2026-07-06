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
 * ELMS Sync v2 step 4 (Option B) integration spike: the home-tenure PRODUCER.
 *
 * The tenure gate (integration_home_authorship) proved the CONSUMER — that an
 * out-of-tenure fact is rejected once intervals exist. This spike proves the
 * piece that was missing: that central actually POPULATES those intervals, in a
 * single fleet-wide roster-generation space, from the roster it serves.
 *
 * It drives tenure::record_home (the producer tdmp_roster::execute calls with the
 * served student set) and asserts, on the live DB:
 *   1. first serve      -> one generation tick, an OPEN interval per learner;
 *   2. idempotent serve  -> NO tick, no new rows (daily serves never inflate);
 *   3. new learner       -> one tick, a fresh interval, others untouched;
 *   4. a home MOVE       -> one tick, origin interval CLOSED at the tick, the
 *                           destination OPEN at it, in_force judged at G either
 *                           side of the boundary, a non-moved peer unaffected;
 *   5. idempotent move   -> NO tick;
 *   6. rostergen::adopt  -> the school mirrors central's clock monotonically
 *                           (never regresses), the unit the roster refresh runs.
 *
 * All state is fixture-scoped (ITESTTP-* codes) and the cleanup restores the
 * central generation counter + rostergen stamp and deletes every fixture
 * interval, so the spike is re-runnable and leaves zero residue.
 *
 * Usage:  php integration_tenure_producer.php [--keep]
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\tenure;
use local_syncqueue\rostergen;

list($options, $unrecognized) = cli_get_params(['help' => false, 'keep' => false], []);
if ($options['help']) {
    cli_writeln("Drive the Option B home-tenure producer and assert generation + intervals.\n"
        . "  --keep   Do not clean up fixture rows/config on exit (for inspection).\n");
    exit(0);
}

// Fixture identities — namespaced so they never collide with real roster data.
const TP_SCHOOL_A = 'ITESTTP-SCH-A';
const TP_SCHOOL_B = 'ITESTTP-SCH-B';
const TP_S1 = 'ITESTTP-STU-1';
const TP_S2 = 'ITESTTP-STU-2';
const TP_S3 = 'ITESTTP-STU-3';

/** @var string[] Every fixture SDMS this spike may create an interval for. */
const TP_ALL_SDMS = [TP_S1, TP_S2, TP_S3];

$tpfailures = [];

/**
 * Record and print one assertion.
 *
 * @param string $name Check id.
 * @param bool $ok Whether it passed.
 * @param string $detail Human-readable detail.
 */
function tp_check(string $name, bool $ok, string $detail = ''): void {
    global $tpfailures;
    if (!$ok) {
        $tpfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/**
 * The open interval row for (sdms, schoolid), or null.
 *
 * @param string $sdms Learner code.
 * @param string $schoolid School.
 * @return \stdClass|null
 */
function tp_open(string $sdms, string $schoolid): ?\stdClass {
    global $DB;
    $row = $DB->get_record_select('local_syncqueue_tenure',
        'sdms = :sdms AND schoolid = :sch AND torostergen IS NULL',
        ['sdms' => $sdms, 'sch' => $schoolid]);
    return $row ?: null;
}

/**
 * Delete every fixture interval this spike may have created — plus any reseed job
 * a recorded move triggered (record_home enqueues one per moved learner since step 5).
 */
function tp_purge_intervals(): void {
    global $DB;
    if (!$DB->get_manager()->table_exists('local_syncqueue_tenure')) {
        return;
    }
    [$insql, $inparams] = $DB->get_in_or_equal(TP_ALL_SDMS, SQL_PARAMS_NAMED, 'tp');
    $DB->delete_records_select('local_syncqueue_tenure', "sdms $insql", $inparams);
    if ($DB->get_manager()->table_exists('local_syncqueue_seedjob')) {
        [$jsql, $jparams] = $DB->get_in_or_equal(TP_ALL_SDMS, SQL_PARAMS_NAMED, 'tj');
        $DB->delete_records_select('local_syncqueue_seedjob', "sdms $jsql", $jparams);
    }
}

// ---------------------------------------------------------------------------
// Save state so the run is non-destructive.
// ---------------------------------------------------------------------------

if (!$DB->get_manager()->table_exists('local_syncqueue_tenure')) {
    cli_writeln('SPIKE RESULT: SKIP - local_syncqueue_tenure not installed (run the step-4 upgrade first)');
    exit(0);
}

$savedgen = get_config('local_syncqueue', 'central_rostergen');
$savedrostergen = get_config('local_syncqueue', 'rostergen');

// A prior aborted run must not skew the deltas: clear fixture intervals first.
tp_purge_intervals();

$cleanup = function () use ($savedgen, $savedrostergen) {
    tp_purge_intervals();
    // Restore the shared counters exactly (false => the key was unset).
    foreach (['central_rostergen' => $savedgen, 'rostergen' => $savedrostergen] as $key => $val) {
        if ($val === false) {
            unset_config($key, 'local_syncqueue');
        } else {
            set_config($key, $val, 'local_syncqueue');
        }
    }
};

$fatal = null;
try {
    $start = tenure::current_generation();
    cli_writeln('INFO start generation = ' . $start);

    // -----------------------------------------------------------------------
    // 1. First serve of school A with two learners: one tick, both intervals
    //    open at it.
    // -----------------------------------------------------------------------
    $g1 = tenure::record_home([TP_S1, TP_S2], TP_SCHOOL_A);
    $o1 = tp_open(TP_S1, TP_SCHOOL_A);
    $o2 = tp_open(TP_S2, TP_SCHOOL_A);
    tp_check('first_serve_one_tick',
        $g1 === $start + 1 && tenure::current_generation() === $start + 1,
        "serving {A: S1,S2} advanced the clock exactly once: $start -> $g1");
    tp_check('first_serve_opens_intervals',
        $o1 !== null && (int) $o1->fromrostergen === $g1 && $o1->torostergen === null
            && $o2 !== null && (int) $o2->fromrostergen === $g1 && $o2->torostergen === null
            && tenure::in_force(TP_S1, TP_SCHOOL_A, $g1) && tenure::in_force(TP_S2, TP_SCHOOL_A, $g1),
        "both learners open under A at gen $g1 and in force there");

    // -----------------------------------------------------------------------
    // 2. Idempotent re-serve of the SAME set: no tick, no new rows.
    // -----------------------------------------------------------------------
    $before = $DB->count_records('local_syncqueue_tenure');
    $g1b = tenure::record_home([TP_S2, TP_S1], TP_SCHOOL_A); // order must not matter
    tp_check('idempotent_serve_no_tick',
        $g1b === $g1 && tenure::current_generation() === $g1
            && $DB->count_records('local_syncqueue_tenure') === $before,
        "re-serving the same roster did not tick ($g1b) nor add rows");

    // -----------------------------------------------------------------------
    // 3. A new (first-ever) learner joins A. The clock still ticks once, but the
    //    learner's interval opens at the FLOOR, not the new tick — so any work
    //    they authored before this serve first recorded them (stamped with a
    //    generation adopted earlier, e.g. via individual signup) still falls in
    //    tenure. Peers keep their existing interval.
    // -----------------------------------------------------------------------
    $g3 = tenure::record_home([TP_S1, TP_S2, TP_S3], TP_SCHOOL_A);
    $o3 = tp_open(TP_S3, TP_SCHOOL_A);
    $o1still = tp_open(TP_S1, TP_SCHOOL_A);
    tp_check('new_learner_one_tick', $g3 === $g1 + 1,
        "adding S3 ticked the clock once ($g1 -> $g3)");
    tp_check('new_learner_floor_open',
        $o3 !== null && (int) $o3->fromrostergen === tenure::FLOOR_GENERATION && $o3->torostergen === null
            && tenure::in_force(TP_S3, TP_SCHOOL_A, tenure::FLOOR_GENERATION)
            && tenure::in_force(TP_S3, TP_SCHOOL_A, $g3),
        'S3 first-ever interval opened at the floor (' . tenure::FLOOR_GENERATION
            . "), in force from the floor through serve gen $g3 — pre-serve work lands");
    tp_check('new_learner_leaves_peers',
        $o1still !== null && (int) $o1still->fromrostergen === $g1,
        "S1's interval was NOT re-opened (still from $g1)");

    // -----------------------------------------------------------------------
    // 4. A home MOVE: S1 is now served by B. One tick; A's interval closes at
    //    it, B's opens at it; in_force judged at G on both sides of the border.
    // -----------------------------------------------------------------------
    $gm = tenure::record_home([TP_S1], TP_SCHOOL_B);
    $aclosed = $DB->get_record_select('local_syncqueue_tenure',
        'sdms = :s AND schoolid = :a AND torostergen IS NOT NULL',
        ['s' => TP_S1, 'a' => TP_SCHOOL_A]);
    $bopen = tp_open(TP_S1, TP_SCHOOL_B);
    tp_check('move_one_tick', $gm === $g3 + 1, "the move ticked once ($g3 -> $gm)");
    tp_check('move_closes_origin_opens_dest',
        $aclosed !== null && (int) $aclosed->torostergen === $gm
            && $bopen !== null && (int) $bopen->fromrostergen === $gm && $bopen->torostergen === null,
        "A's interval closed at $gm ([{$aclosed->fromrostergen},$gm)); B's opened at $gm");
    tp_check('move_in_force_boundary',
        tenure::in_force(TP_S1, TP_SCHOOL_A, $g1)          // pre-move: A was home
            && !tenure::in_force(TP_S1, TP_SCHOOL_A, $gm)  // at the border: A no longer home
            && tenure::in_force(TP_S1, TP_SCHOOL_B, $gm)   // B is home from the border
            && !tenure::in_force(TP_S1, TP_SCHOOL_B, $g1), // B was NOT home before it
        "in_force: A@$g1=Y A@$gm=N (half-open) B@$gm=Y B@$g1=N");
    tp_check('move_leaves_peer',
        tenure::in_force(TP_S2, TP_SCHOOL_A, $gm) && tp_open(TP_S2, TP_SCHOOL_A) !== null,
        "the non-moved peer S2 stays home at A across the move");

    // -----------------------------------------------------------------------
    // 5. Idempotent move: re-serving B with S1 does not tick again.
    // -----------------------------------------------------------------------
    $gm2 = tenure::record_home([TP_S1], TP_SCHOOL_B);
    tp_check('idempotent_move_no_tick', $gm2 === $gm && tenure::current_generation() === $gm,
        "re-serving B with S1 held the clock at $gm");

    // -----------------------------------------------------------------------
    // 6. School-side adopt() unit: mirror central's clock, monotonically.
    // -----------------------------------------------------------------------
    unset_config('rostergen', 'local_syncqueue');
    rostergen::adopt(0);
    $afterzero = rostergen::current();
    rostergen::adopt(5);
    $afterfive = rostergen::current();
    rostergen::adopt(3); // stale/reordered response — must not regress
    $afterthree = rostergen::current();
    rostergen::adopt(7);
    $afterseven = rostergen::current();
    tp_check('adopt_monotonic',
        $afterzero === null && $afterfive === 5 && $afterthree === 5 && $afterseven === 7,
        "adopt: 0->null(no-op) 5->5 3->5(no regress) 7->7");

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $tpfailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup + residue verification.
// ---------------------------------------------------------------------------

if (!empty($options['keep'])) {
    cli_writeln('INFO --keep set: leaving fixture intervals + counters in place');
} else {
    $cleanup();
    [$rin, $rparams] = $DB->get_in_or_equal(TP_ALL_SDMS, SQL_PARAMS_NAMED, 'r');
    $residue = $DB->count_records_select('local_syncqueue_tenure', "sdms $rin", $rparams);
    $genrestored = get_config('local_syncqueue', 'central_rostergen') === $savedgen;
    tp_check('cleanup_zero_residue', $residue === 0 && $genrestored,
        "fixture intervals left=$residue, central_rostergen restored=" . ($genrestored ? 'yes' : 'no'));
}

if (empty($tpfailures)) {
    cli_writeln('SPIKE RESULT: PASS - the Option B home-tenure producer is correct');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($tpfailures)));
exit(1);
