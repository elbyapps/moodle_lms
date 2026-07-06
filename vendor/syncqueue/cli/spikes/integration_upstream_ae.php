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
 * ELMS Sync v2 step 6 integration spike: UPSTREAM anti-entropy (§9).
 *
 * Proves the school re-queues facts central lost:
 *   1. A captured+finalized fact and a matching central ingest row read CONVERGED
 *      (school-authored digest == central-received digest).
 *   2. Deleting central's ingest row (a restore lost it) makes the fact's bucket
 *      DIVERGENT; the updetail phase returns exactly that lineage as missing.
 *   3. The forced re-queue re-appends an outbox row and the sequencer re-finalises it to
 *      the IDENTICAL factuuid — so central re-receives the same fact (dedup/apply), even
 *      though local idempotency would otherwise no-op the re-capture.
 *
 * Fixtures (itestup) restore config and delete every fixture row — re-runnable, zero
 * residue.
 *
 * Usage:  php integration_upstream_ae.php [--keep]
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
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

use local_syncqueue\capture;
use local_syncqueue\digest;
use local_syncqueue\fact_ledger;
use local_syncqueue\school_manager;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\external\digest as digestendpoint;

list($options) = cli_get_params(['help' => false, 'keep' => false], []);
if (!empty($options['help'])) {
    cli_writeln("Drive the step-6 upstream anti-entropy loop and assert detection + re-queue.\n"
        . "  --keep   Leave fixtures in place.\n");
    exit(0);
}

const UP_COURSE_SHORT = 'itestup_course';
const UP_COURSE_IDN = 'itestup_courseidn';
const UP_GI_UUID = 'a9c5eaf0-0000-4000-8000-000000000001';
const UP_SDMS = 'ITESTUP-STU-1';
const UP_SCHOOL = 'itestupschool';
const UP_USERNAME = 'itestup_student';

$upfailures = [];

/**
 * Record + print one assertion.
 *
 * @param string $name Check id.
 * @param bool $ok Passed?
 * @param string $detail Detail.
 */
function up_check(string $name, bool $ok, string $detail = ''): void {
    global $upfailures;
    if (!$ok) {
        $upfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/** Decode a digest endpoint response's result JSON. */
function up_result(array $resp): array {
    $r = json_decode($resp['result'] ?? '{}', true);
    return is_array($r) ? $r : [];
}

/** Count the fixture school's upstream outbox rows. */
function up_outbox_count(): int {
    global $DB;
    return $DB->count_records_select('local_syncqueue_outbox',
        'partitionkey = :p', ['p' => 'learner:school:' . UP_SCHOOL]);
}

/** Delete every itestup fixture row. */
function up_purge(): void {
    global $DB;
    while ($course = $DB->get_record('course', ['shortname' => UP_COURSE_SHORT])) {
        delete_course($course->id, false);
    }
    foreach ($DB->get_records_select('user',
            $DB->sql_like('username', ':u') . ' AND deleted = 0', ['u' => 'itestup\\_%']) as $u) {
        delete_user($u);
    }
    $DB->delete_records_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 1',
        ['u' => 'itestup\\_%']);
    $DB->delete_records_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTUP%']);
    if ($DB->get_manager()->table_exists('elby_roster')) {
        $DB->delete_records_select('elby_roster', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTUP%']);
    }
    if ($DB->get_manager()->table_exists('local_syncqueue_ledger')) {
        $DB->delete_records('local_syncqueue_ledger', ['origin' => UP_SCHOOL]);
    }
    if ($DB->get_manager()->table_exists('local_syncqueue_ingest')) {
        $DB->delete_records('local_syncqueue_ingest', ['schoolid' => UP_SCHOOL]);
    }
    if ($DB->get_manager()->table_exists('local_syncqueue_outbox')) {
        $DB->delete_records_select('local_syncqueue_outbox',
            'partitionkey = :p', ['p' => 'learner:school:' . UP_SCHOOL]);
    }
    if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => UP_SCHOOL])) {
        $DB->delete_records('local_syncqueue_schools', ['schoolid' => UP_SCHOOL]);
    }
}

$confignames = ['mode', 'enabled', 'schoolid', 'push_v2', 'pull_v2'];
$savedconfig = [];
foreach ($confignames as $name) {
    $savedconfig[$name] = get_config('local_syncqueue', $name);
}
up_purge();

$cleanup = function () use ($confignames, $savedconfig) {
    up_purge();
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
    // -----------------------------------------------------------------------
    // Phase 0 — fixtures: author + finalize a fact; register the school.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 0: fixtures ---');
    set_config('mode', 'school', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    set_config('schoolid', UP_SCHOOL, 'local_syncqueue');
    // push_v2 OFF during setup so enrolment/account events aren't captured — the school
    // must author exactly ONE fact (the grade) for a clean converged/diverged assertion.
    set_config('push_v2', 0, 'local_syncqueue');
    $apikey = (new school_manager())->register_school(UP_SCHOOL, 'ITEST Upstream Fixture School');

    $cat = \core_course_category::get_default();
    $course = create_course((object) [
        'fullname' => 'ITEST UP Course', 'shortname' => UP_COURSE_SHORT, 'category' => $cat->id,
        'summary' => '', 'format' => 'topics', 'visible' => 1, 'idnumber' => UP_COURSE_IDN,
        'enablecompletion' => 1,
    ]);
    $courseid = (int) $course->id;
    $u = (object) [
        'username' => UP_USERNAME, 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'email' => UP_USERNAME . '@example.invalid',
        'firstname' => 'ITest', 'lastname' => 'UPStudent', 'idnumber' => '',
    ];
    $userid = (int) user_create_user($u, false, false);
    $DB->insert_record('elby_sdms_users', (object) [
        'userid' => $userid, 'sdms_id' => UP_SDMS, 'schoolid' => null, 'user_type' => 'student',
        'academic_year' => '2026', 'sdms_status' => 'active', 'sync_status' => 1, 'sync_error' => null,
        'last_synced' => time(), 'timecreated' => time(), 'timemodified' => time(),
    ]);
    $DB->insert_record('elby_roster', (object) [
        'sdms_id' => UP_SDMS, 'user_type' => 'student', 'school_code' => UP_SCHOOL,
        'names' => 'ITest UPStudent', 'payload' => '{}', 'timecached' => time(), 'timemodified' => time(),
    ]);
    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    enrol_try_internal_enrol($courseid, $userid, $studentrole->id);

    $mi = create_module((object) [
        'modulename' => 'assign', 'course' => $courseid, 'section' => 0, 'visible' => 1,
        'name' => 'ITEST UP Assign',
        'introeditor' => ['text' => 'a', 'format' => FORMAT_HTML, 'itemid' => 0],
        'grade' => 100, 'gradingduedate' => 0, 'duedate' => 0, 'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0,
        'assignsubmission_file_maxfiles' => 1, 'assignsubmission_file_maxsizebytes' => 1024,
        'assignsubmission_comments_enabled' => 0, 'assignfeedback_comments_enabled' => 0,
        'submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0,
        'sendlatenotifications' => 0, 'sendstudentnotifications' => 0, 'teamsubmission' => 0,
        'requireallteammemberssubmit' => 0, 'blindmarking' => 0, 'attemptreopenmethod' => 'none',
        'maxattempts' => -1, 'markingworkflow' => 0, 'markingallocation' => 0,
        'completion' => COMPLETION_TRACKING_NONE, 'cmidnumber' => '',
    ]);
    $giid = (int) $DB->get_field('grade_items', 'id',
        ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => (int) $mi->instance, 'itemnumber' => 0]);
    $DB->set_field('grade_items', 'idnumber', UP_GI_UUID, ['id' => $giid]);
    $DB->set_field('course_modules', 'idnumber', UP_GI_UUID, ['id' => (int) $mi->coursemodule]);

    // Now capture the ONE fixture fact: enable push_v2 and grade the learner.
    set_config('push_v2', 1, 'local_syncqueue');
    \grade_item::fetch(['id' => $giid])->update_final_grade($userid, 66.0, 'gradebook');
    sequencer::assign();
    $ledgers = fact_ledger::get_by_source('grade_grades',
        (int) $DB->get_field('grade_grades', 'id', ['itemid' => $giid, 'userid' => $userid]));
    $ledger = $ledgers ? reset($ledgers) : null;
    up_check('fixtures',
        $ledger !== null && $ledger->factversion !== null && $ledger->factuuid !== null
            && (string) $ledger->status === fact_ledger::STATUS_EXPORTED,
        'authored + finalised a fact (v' . ($ledger ? $ledger->factversion : '?')
            . ', status ' . ($ledger ? $ledger->status : '?') . ')');
    $ggid = (int) $DB->get_field('grade_grades', 'id', ['itemid' => $giid, 'userid' => $userid]);

    // Central received every fact the school pushed (grading a leaf also recomputes the
    // course total, so more than one fact may exist): a matching ingest row per lineage.
    $authoredrows = $DB->get_records_select('local_syncqueue_ledger',
        'origin = :o AND factversion IS NOT NULL', ['o' => UP_SCHOOL]);
    $schoolseq = 1;
    foreach ($authoredrows as $lr) {
        $DB->insert_record('local_syncqueue_ingest', (object) [
            'schoolid' => UP_SCHOOL, 'epoch' => 'itestup-epoch', 'schoolseq' => $schoolseq++,
            'factuuid' => $lr->factuuid, 'lineageuuid' => $lr->lineageuuid,
            'factversion' => (int) $lr->factversion, 'facttype' => $lr->facttype, 'entitykey' => $lr->lineageuuid,
            'payload' => '{}', 'payloadhash' => $lr->payloadhash, 'rostergen' => null,
            'status' => 'applied', 'attempts' => 0, 'lasterror' => null,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
    }

    // -----------------------------------------------------------------------
    // 1. Converged: school-authored digest == central-received digest.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: converged ---');
    $authored = digest::school_authored_map(UP_SCHOOL);
    $localsummary = digest::summary($authored);
    up_check('authored_map_has_fact',
        isset($authored['grade'][$ledger->lineageuuid])
            && $authored['grade'][$ledger->lineageuuid] === $ledger->payloadhash,
        'the finalised fact is in the school-authored map');

    set_config('mode', 'central', 'local_syncqueue');
    $conv = up_result(digestendpoint::execute(UP_SCHOOL, $apikey, 'upsummary'));
    $divconv = digest::divergent_buckets($localsummary, $conv['summary'] ?? []);
    up_check('upsummary_converged', empty($divconv),
        'with central holding the fact, no divergent buckets');

    // -----------------------------------------------------------------------
    // 2. Central loses it -> divergent -> updetail returns the lineage.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: central loss detected ---');
    $DB->delete_records('local_syncqueue_ingest', ['schoolid' => UP_SCHOOL]);
    $lost = up_result(digestendpoint::execute(UP_SCHOOL, $apikey, 'upsummary'));
    $divlost = digest::divergent_buckets($localsummary, $lost['summary'] ?? []);
    up_check('upsummary_detects_loss', count($divlost) >= 1,
        'after central lost the fact, its bucket is divergent (' . count($divlost) . ')');

    $keys = ['grade' => [$ledger->lineageuuid => $ledger->payloadhash]];
    $detail = up_result(digestendpoint::execute(UP_SCHOOL, $apikey, 'updetail',
        json_encode(['buckets' => $divlost, 'keys' => $keys])));
    $missinglineages = array_column($detail['missing'] ?? [], 'lineageuuid');
    up_check('updetail_returns_lineage', in_array($ledger->lineageuuid, $missinglineages, true),
        'updetail names the lost lineage as one the school must re-push');

    // -----------------------------------------------------------------------
    // 3. Forced re-queue re-appends the fact at the IDENTICAL factuuid.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: re-queue repair ---');
    set_config('mode', 'school', 'local_syncqueue');
    $before = up_outbox_count();
    $r = capture::regenerate_grade($ggid, true); // force: central lost it
    $after = up_outbox_count();
    sequencer::assign();
    $requeued = $DB->get_record_select('local_syncqueue_outbox',
        'partitionkey = :p AND factuuid = :fu AND seq IS NOT NULL',
        ['p' => 'learner:school:' . UP_SCHOOL, 'fu' => $ledger->factuuid]);
    up_check('requeue_reappends_same_factuuid',
        $r !== null && $r !== 0 && $after === $before + 1 && $requeued !== false,
        'forced re-queue appended one outbox row, finalised to the SAME factuuid ('
            . $ledger->factuuid . ') — central re-receives the identical fact');

    // -----------------------------------------------------------------------
    // 4. Departed-learner guard: the repair defers a learner off the roster.
    // -----------------------------------------------------------------------
    $task = new \local_syncqueue\task\upstream_anti_entropy();
    $rc = new \ReflectionMethod($task, 'learner_still_home');
    $rc->setAccessible(true);
    $homewhile = (bool) $rc->invoke($task, $ggid);
    $DB->delete_records('elby_roster', ['sdms_id' => UP_SDMS]);
    $homeafter = (bool) $rc->invoke($task, $ggid);
    up_check('departed_learner_deferred', $homewhile === true && $homeafter === false,
        'the still-home guard is true on-roster, false once the learner departs — the repair '
            . 'defers rather than mis-stamping today\'s generation onto a historical fact');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $upfailures[] = 'script_completed';
}

if (!empty($options['keep'])) {
    cli_writeln('INFO --keep: leaving fixtures in place');
} else {
    $cleanup();
    $residue = $DB->count_records('local_syncqueue_ledger', ['origin' => UP_SCHOOL])
        + $DB->count_records_select('user', $DB->sql_like('username', ':u'), ['u' => 'itestup%']);
    up_check('cleanup_zero_residue',
        $residue === 0 && !$DB->record_exists('local_syncqueue_schools', ['schoolid' => UP_SCHOOL]),
        "fixture ledger+user rows left={$residue}, fixture school removed");
}

if (empty($upfailures)) {
    cli_writeln('SPIKE RESULT: PASS - upstream anti-entropy detection + re-queue verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($upfailures)));
exit(1);
