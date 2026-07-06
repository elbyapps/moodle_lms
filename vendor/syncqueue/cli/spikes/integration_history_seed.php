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
 * ELMS Sync v2 step 5 integration spike: history down-sync (seeding + handover).
 *
 * Single-site loop (central role publishes, school role applies) proving §8.3:
 *   Phase 1 — central republishes a learner's terminal overridden grade + completions
 *             as seed rows on the destination's own partition; enqueue coalesces and
 *             flap-quarantines.
 *   Phase 2 — the school applies them onto a CLEAN SLATE: an overridden (regrade-proof)
 *             grade at the seeded value + a latched completion (G2); idempotent re-apply.
 *   Phase 3 — move-back: an item that already has native local evidence is NOT
 *             overridden by the seed (local authority owns it, G3).
 *   Phase 4 — deterministic handover (G4): a re-take BELOW the record leaves the seeded
 *             85 standing; a re-take that MEETS/BEATS it releases to local; a human edit
 *             wins outright (G5).
 *
 * Fixtures are prefixed itesths and the run restores mode/config, deletes every fixture
 * row, and trims the outbox/applied tail, so it is re-runnable and leaves zero residue.
 *
 * Usage:  php integration_history_seed.php [--keep]
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

use local_syncqueue\seed_publisher;
use local_syncqueue\seed_applier;
use local_syncqueue\update_processor;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\task\history_republish;
use local_syncqueue\task\seed_handover;

list($options) = cli_get_params(['help' => false, 'keep' => false], []);
if (!empty($options['help'])) {
    cli_writeln("Drive the step-5 history seeding + handover loop and assert it end to end.\n"
        . "  --keep   Leave fixtures in place for inspection.\n");
    exit(0);
}

const HS_COURSE_SHORT = 'itesths_course';
const HS_COURSE_IDN = 'itesths_courseidn';
const HS_GI_UUID = '5eed9a1d-0000-4000-8000-000000000001';
const HS_SDMS = 'ITESTHS-STU-1';
const HS_SCHOOL = 'itesthsschool';
const HS_USERNAME = 'itesths_student';

$hsfailures = [];

/**
 * Record + print one assertion.
 *
 * @param string $name Check id.
 * @param bool $ok Passed?
 * @param string $detail Detail.
 */
function hs_check(string $name, bool $ok, string $detail = ''): void {
    global $hsfailures;
    if (!$ok) {
        $hsfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/** Set the sync role configs. */
function hs_role(string $mode): void {
    set_config('mode', $mode, 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    set_config('schoolid', HS_SCHOOL, 'local_syncqueue');
}

/** The learner's grade_grades row for an item (or null). */
function hs_grade(int $itemid, int $userid): ?\stdClass {
    global $DB;
    return $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $userid]) ?: null;
}

/** Apply a synthetic seed_grade row through the real applier. */
function hs_apply_seed_grade(float $finalgrade): void {
    $row = new \stdClass();
    $row->entitytype = 'seed_grade';
    $row->entitykey = 'seedgrade:' . HS_GI_UUID . ':' . HS_SDMS;
    $row->entityversion = 1;
    $row->action = 'upsert';
    $row->payload = json_encode([
        'sdms' => HS_SDMS, 'item_uuid' => HS_GI_UUID, 'course_idnumber' => HS_COURSE_IDN,
        'finalgrade' => $finalgrade, 'itemtype' => 'mod', 'itemname' => 'ITEST HS Assign',
    ]);
    $row->payloadhash = '';
    (new update_processor())->apply_outbox_row($row);
}

/** Reset an item's grade + its seed provenance to a clean slate. */
function hs_clear_grade(int $itemid, int $userid): void {
    global $DB;
    $DB->delete_records('grade_grades', ['itemid' => $itemid, 'userid' => $userid]);
    $DB->delete_records('local_syncqueue_seed', ['sdms' => HS_SDMS]);
}

// ---------------------------------------------------------------------------

$confignames = ['mode', 'enabled', 'schoolid', 'push_v2', 'pull_v2'];
$savedconfig = [];
foreach ($confignames as $name) {
    $savedconfig[$name] = get_config('local_syncqueue', $name);
}

/** Purge any fixture leftovers from a prior run. */
function hs_purge(): void {
    global $DB;
    while ($course = $DB->get_record('course', ['shortname' => HS_COURSE_SHORT])) {
        delete_course($course->id, false);
    }
    foreach ($DB->get_records_select('user',
            $DB->sql_like('username', ':u') . ' AND deleted = 0', ['u' => 'itesths\\_%']) as $u) {
        delete_user($u);
    }
    $DB->delete_records_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 1',
        ['u' => 'itesths\\_%']);
    $DB->delete_records_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTHS%']);
    $DB->delete_records('local_syncqueue_seed', ['sdms' => HS_SDMS]);
    $DB->delete_records('local_syncqueue_seedjob', ['sdms' => HS_SDMS]);
    $DB->delete_records_select('local_syncqueue_tenure', $DB->sql_like('sdms', ':s'), ['s' => 'ITESTHS%']);
    $DB->delete_records_select('local_syncqueue_outbox', $DB->sql_like('partitionkey', ':p'),
        ['p' => 'seed:school:' . HS_SCHOOL]);
    $DB->delete_records_select('local_syncqueue_applied', $DB->sql_like('entitykey', ':k'),
        ['k' => 'seed%:' . '%' . HS_SDMS]);
    // Facts this school captured while push_v2 was on (the ledger + its learner-fact
    // outbox rows) — otherwise they accumulate and eventually collide with other spikes.
    if ($DB->get_manager()->table_exists('local_syncqueue_ledger')) {
        $DB->delete_records('local_syncqueue_ledger', ['origin' => HS_SCHOOL]);
    }
    $DB->delete_records_select('local_syncqueue_outbox', 'partitionkey = :lp',
        ['lp' => 'learner:school:' . HS_SCHOOL]);
}

hs_purge();
$outboxstart = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id),0) FROM {local_syncqueue_outbox}');

$fixtureuserid = 0;
$fixturecourseid = 0;

$cleanup = function () use (&$fixturecourseid, $confignames, $savedconfig) {
    hs_purge();
    foreach ($confignames as $name) {
        if ($savedconfig[$name] === false) {
            unset_config($name, 'local_syncqueue');
        } else {
            set_config($name, $savedconfig[$name], 'local_syncqueue');
        }
    }
};

$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());

$fatal = null;
try {
    if (empty($CFG->enablecompletion)) {
        throw new \moodle_exception('generalexceptionmessage', 'error', '', 'site enablecompletion is off');
    }

    // -----------------------------------------------------------------------
    // Phase 0 — fixtures.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 0: fixtures ---');
    hs_role('school');

    $cat = \core_course_category::get_default();
    $course = create_course((object) [
        'fullname' => 'ITEST HS Course', 'shortname' => HS_COURSE_SHORT, 'category' => $cat->id,
        'summary' => '', 'format' => 'topics', 'visible' => 1, 'idnumber' => HS_COURSE_IDN,
        'enablecompletion' => 1,
    ]);
    $fixturecourseid = (int) $course->id;

    $criterion = new completion_criteria_self();
    $criterion->course = $fixturecourseid;
    $criterion->insert();

    $u = (object) [
        'username' => HS_USERNAME, 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'email' => HS_USERNAME . '@example.invalid',
        'firstname' => 'ITest', 'lastname' => 'HSStudent', 'idnumber' => '',
    ];
    $fixtureuserid = (int) user_create_user($u, false, false);
    $DB->insert_record('elby_sdms_users', (object) [
        'userid' => $fixtureuserid, 'sdms_id' => HS_SDMS, 'schoolid' => null, 'user_type' => 'student',
        'academic_year' => '2026', 'sdms_status' => 'active', 'sync_status' => 1, 'sync_error' => null,
        'last_synced' => time(), 'timecreated' => time(), 'timemodified' => time(),
    ]);
    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    enrol_try_internal_enrol($fixturecourseid, $fixtureuserid, $studentrole->id);

    $mi = create_module((object) [
        'modulename' => 'assign', 'course' => $fixturecourseid, 'section' => 0, 'visible' => 1,
        'name' => 'ITEST HS Assign',
        'introeditor' => ['text' => 'assessment', 'format' => FORMAT_HTML, 'itemid' => 0],
        'grade' => 100, 'gradingduedate' => 0, 'duedate' => 0, 'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0,
        'assignsubmission_file_maxfiles' => 1, 'assignsubmission_file_maxsizebytes' => 1024,
        'assignsubmission_comments_enabled' => 0, 'assignfeedback_comments_enabled' => 0,
        'submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0,
        'sendlatenotifications' => 0, 'sendstudentnotifications' => 0, 'teamsubmission' => 0,
        'requireallteammemberssubmit' => 0, 'blindmarking' => 0, 'attemptreopenmethod' => 'none',
        'maxattempts' => -1, 'markingworkflow' => 0, 'markingallocation' => 0,
        'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED,
        'cmidnumber' => '',
    ]);
    $assigncmid = (int) $mi->coursemodule;
    $assignid = (int) $mi->instance;
    $assigngiid = (int) $DB->get_field('grade_items', 'id',
        ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assignid, 'itemnumber' => 0]);

    // Stamp the cm and its itemnumber-0 grade item with the SAME UUID (the step-4
    // convention for itemnumber-0 items), so a module regrade re-syncs the grade
    // item idnumber from the cm to the same value instead of losing our stamp.
    $DB->set_field('grade_items', 'idnumber', HS_GI_UUID, ['id' => $assigngiid]);
    $DB->set_field('course_modules', 'idnumber', HS_GI_UUID, ['id' => $assigncmid]);

    hs_check('fixtures',
        $fixturecourseid > 0 && $fixtureuserid > 0 && $assigngiid > 0
            && \local_syncqueue\item_identity::is_uuid(HS_GI_UUID),
        "course={$fixturecourseid} user={$fixtureuserid} giid={$assigngiid} cm={$assigncmid}");

    // -----------------------------------------------------------------------
    // Phase 1 — central republishes the learner's terminal state as seeds.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: central publish ---');
    hs_role('central');

    // Central holds an overridden grade (85) + a latched course completion.
    $item = \grade_item::fetch(['id' => $assigngiid]);
    $item->update_final_grade($fixtureuserid, 85.0, 'local_syncqueue');
    $DB->insert_record('course_completions', (object) [
        'userid' => $fixtureuserid, 'course' => $fixturecourseid,
        'timeenrolled' => 0, 'timestarted' => 0, 'timecompleted' => time(), 'reaggregate' => 0,
    ]);

    // Coalescing + flap guard on enqueue.
    seed_publisher::enqueue(HS_SDMS, HS_SCHOOL, 5);
    seed_publisher::enqueue(HS_SDMS, HS_SCHOOL, 6); // repeat -> must coalesce to one row
    $jobcount = $DB->count_records('local_syncqueue_seedjob', ['sdms' => HS_SDMS, 'schoolid' => HS_SCHOOL]);
    hs_check('enqueue_coalesces', $jobcount === 1, "one job row for (learner, school), got {$jobcount}");

    $published = seed_publisher::republish(HS_SDMS, HS_SCHOOL);
    $seedgrade = $DB->get_record('local_syncqueue_outbox',
        ['entitytype' => 'seed_grade', 'entitykey' => 'seedgrade:' . HS_GI_UUID . ':' . HS_SDMS]);
    $seedpayload = $seedgrade ? json_decode($seedgrade->payload, true) : [];
    hs_check('republish_grade',
        $published >= 2 && $seedgrade !== false
            && (string) $seedgrade->partitionkey === 'seed:school:' . HS_SCHOOL
            && (float) ($seedpayload['finalgrade'] ?? 0) === 85.0
            && $seedgrade->seq !== null,
        "published {$published} seed rows; grade seed on partition seed:school:" . HS_SCHOOL
            . ' finalgrade=' . ($seedpayload['finalgrade'] ?? '?') . ' seq=' . ($seedgrade->seq ?? 'null'));
    $seedcompl = $DB->count_records('local_syncqueue_outbox',
        ['entitytype' => 'seed_completion', 'partitionkey' => 'seed:school:' . HS_SCHOOL]);
    hs_check('republish_completion', $seedcompl >= 1, "course completion seeded ({$seedcompl} completion seed row(s))");

    // Flap guard: > MAX_MONTHLY_MOVES tenure rows in 30 days -> the job is quarantined.
    for ($i = 0; $i < seed_publisher::MAX_MONTHLY_MOVES + 1; $i++) {
        $DB->insert_record('local_syncqueue_tenure', (object) [
            'sdms' => HS_SDMS, 'schoolid' => 'flap' . $i, 'fromrostergen' => $i + 1,
            'torostergen' => null, 'timecreated' => time(), 'timemodified' => time(),
        ]);
    }
    seed_publisher::enqueue(HS_SDMS, 'itesthsflap', 9);
    $flapjob = $DB->get_record('local_syncqueue_seedjob', ['sdms' => HS_SDMS, 'schoolid' => 'itesthsflap']);
    hs_check('flap_quarantines', $flapjob !== false && (string) $flapjob->status === 'quarantined',
        'a flapping learner reseed is quarantined, not pending (status='
            . ($flapjob ? $flapjob->status : 'none') . ')');

    // -----------------------------------------------------------------------
    // Phase 2 — the school applies onto a clean slate (G2), idempotently.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: clean-slate apply ---');
    hs_role('school');

    // Clean slate at the destination: drop the grade + the completion the seed will restore.
    hs_clear_grade($assigngiid, $fixtureuserid);
    $DB->delete_records('course_completions', ['userid' => $fixtureuserid, 'course' => $fixturecourseid]);

    // Apply the real published seed rows through the update processor.
    $seedrows = $DB->get_records_select('local_syncqueue_outbox',
        'partitionkey = :p AND seq IS NOT NULL', ['p' => 'seed:school:' . HS_SCHOOL], 'seq ASC');
    $processor = new update_processor();
    foreach ($seedrows as $r) {
        $processor->apply_outbox_row($r);
    }

    $g = hs_grade($assigngiid, $fixtureuserid);
    $prov = $DB->get_record('local_syncqueue_seed',
        ['sdms' => HS_SDMS, 'itemuuid' => HS_GI_UUID]);
    hs_check('seed_grade_applied',
        $g !== null && (int) $g->overridden > 0
            && !grade_floats_different((float) $g->finalgrade, 85.0)
            && $prov !== false && (string) $prov->status === 'seeded'
            && $prov->seededvalue !== null && !grade_floats_different((float) $prov->seededvalue, 85.0),
        'clean-slate grade seeded overridden at 85 (finalgrade=' . ($g ? $g->finalgrade : 'none')
            . ', provenance seeded value=' . ($prov ? $prov->seededvalue : 'none') . ')');
    $coursedone = $DB->record_exists_select('course_completions',
        'userid = :u AND course = :c AND timecompleted IS NOT NULL',
        ['u' => $fixtureuserid, 'c' => $fixturecourseid]);
    hs_check('seed_completion_applied', $coursedone, 'seeded course completion latched (course reads complete)');

    // Idempotent re-apply: no change.
    foreach ($seedrows as $r) {
        $processor->apply_outbox_row($r);
    }
    $g2 = hs_grade($assigngiid, $fixtureuserid);
    hs_check('seed_reapply_idempotent',
        $g2 !== null && !grade_floats_different((float) $g2->finalgrade, 85.0) && (int) $g2->overridden > 0,
        're-applying the same seeds left the grade at 85 overridden');

    // -----------------------------------------------------------------------
    // Phase 3 — move-back: native local evidence is NOT clobbered by the seed (G3).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: move-back (local evidence) ---');
    hs_clear_grade($assigngiid, $fixtureuserid);
    $item = \grade_item::fetch(['id' => $assigngiid]);
    $item->update_raw_grade($fixtureuserid, 40.0, 'mod/assign'); // native local work, no override
    hs_apply_seed_grade(85.0);
    $g3 = hs_grade($assigngiid, $fixtureuserid);
    $prov3 = $DB->get_record('local_syncqueue_seed', ['sdms' => HS_SDMS, 'itemuuid' => HS_GI_UUID]);
    hs_check('moveback_no_clobber',
        $g3 !== null && (int) $g3->overridden === 0 && $g3->rawgrade !== null
            && $prov3 !== false && (string) $prov3->status === 'released',
        'item with native evidence kept local authority (overridden=' . ($g3 ? $g3->overridden : '?')
            . '), seed recorded released');

    // 3b: a human override (a teacher's mark, no attempt -> rawgrade null) must NOT be
    // clobbered or raised by a seed (§8.3 G5) — grade_grades has no source column, so the
    // applier uses seed provenance to tell a human override from its own.
    hs_clear_grade($assigngiid, $fixtureuserid);
    \grade_item::fetch(['id' => $assigngiid])->update_final_grade($fixtureuserid, 70.0, 'gradebook');
    hs_apply_seed_grade(85.0);
    $g3b = hs_grade($assigngiid, $fixtureuserid);
    $prov3b = $DB->get_record('local_syncqueue_seed', ['sdms' => HS_SDMS, 'itemuuid' => HS_GI_UUID]);
    hs_check('human_override_not_clobbered',
        $g3b !== null && (int) $g3b->overridden > 0 && !grade_floats_different((float) $g3b->finalgrade, 70.0)
            && $prov3b !== false && (string) $prov3b->status === 'released',
        'a human override (70) survived a seed of 85 (finalgrade=' . ($g3b ? $g3b->finalgrade : '?')
            . ', seed deferred/released) — not raised under max-policy');

    // -----------------------------------------------------------------------
    // Phase 4 — deterministic handover (G4/G5).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 4: handover ---');

    // 4a: a re-take BELOW the record leaves the seeded 85 standing.
    hs_clear_grade($assigngiid, $fixtureuserid);
    hs_apply_seed_grade(85.0);                                   // seeded override 85
    \grade_item::fetch(['id' => $assigngiid])->update_raw_grade($fixtureuserid, 40.0, 'mod/assign');
    (new seed_handover())->execute();
    $g4a = hs_grade($assigngiid, $fixtureuserid);
    $prov4a = $DB->get_record('local_syncqueue_seed', ['sdms' => HS_SDMS, 'itemuuid' => HS_GI_UUID]);
    hs_check('handover_lower_protects_record',
        $g4a !== null && (int) $g4a->overridden > 0 && !grade_floats_different((float) $g4a->finalgrade, 85.0)
            && $prov4a !== false && (string) $prov4a->status === 'seeded',
        'a 40% re-take can NOT erase the seeded 85 (finalgrade=' . ($g4a ? $g4a->finalgrade : '?')
            . ' still overridden, provenance still seeded)');

    // 4b: a re-take that MEETS/BEATS the record releases to local authority. (After
    // release the leaf recomputes from module state; the release DECISION — override
    // cleared + provenance released because L >= the record — is what we assert, since
    // the exact recomputed value is module-plumbing, not handover logic.)
    \grade_item::fetch(['id' => $assigngiid])->update_raw_grade($fixtureuserid, 90.0, 'mod/assign');
    (new seed_handover())->execute();
    $g4b = hs_grade($assigngiid, $fixtureuserid);
    $prov4b = $DB->get_record('local_syncqueue_seed', ['sdms' => HS_SDMS, 'itemuuid' => HS_GI_UUID]);
    hs_check('handover_higher_releases',
        $g4b !== null && (int) $g4b->overridden === 0
            && $prov4b !== false && (string) $prov4b->status === 'released',
        'a 90% re-take (>= the 85 record) released the seed to local authority '
            . '(overridden cleared, provenance released)');

    // 4c: a human edit wins outright.
    hs_clear_grade($assigngiid, $fixtureuserid);
    hs_apply_seed_grade(85.0);
    \grade_item::fetch(['id' => $assigngiid])->update_final_grade($fixtureuserid, 70.0, 'gradebook');
    (new seed_handover())->execute();
    $g4c = hs_grade($assigngiid, $fixtureuserid);
    $prov4c = $DB->get_record('local_syncqueue_seed', ['sdms' => HS_SDMS, 'itemuuid' => HS_GI_UUID]);
    hs_check('handover_human_edit_wins',
        $g4c !== null && !grade_floats_different((float) $g4c->finalgrade, 70.0)
            && $prov4c !== false && (string) $prov4c->status === 'released',
        'a human edit (70, drifting from the seeded 85) won and released the seed (finalgrade='
            . ($g4c ? $g4c->finalgrade : '?') . ', provenance released)');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $hsfailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup + residue check.
// ---------------------------------------------------------------------------
if (!empty($options['keep'])) {
    cli_writeln('INFO --keep: leaving fixtures in place');
} else {
    $cleanup();
    $residueoutbox = $DB->count_records_select('local_syncqueue_outbox',
        'id > :start AND ' . $DB->sql_like('partitionkey', ':p'),
        ['start' => $outboxstart, 'p' => 'seed:school:' . HS_SCHOOL]);
    $residueseed = $DB->count_records_select('local_syncqueue_seed', $DB->sql_like('sdms', ':s'), ['s' => 'ITESTHS%']);
    $residueuser = $DB->count_records_select('user', $DB->sql_like('username', ':u'), ['u' => 'itesths%']);
    hs_check('cleanup_zero_residue', $residueoutbox === 0 && $residueseed === 0 && $residueuser === 0,
        "outbox seed rows={$residueoutbox}, seed prov={$residueseed}, fixture users={$residueuser}");
}

if (empty($hsfailures)) {
    cli_writeln('SPIKE RESULT: PASS - history down-sync (seeding + handover) verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($hsfailures)));
exit(1);
