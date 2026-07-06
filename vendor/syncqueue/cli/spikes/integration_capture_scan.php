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
 * ELMS Sync v2 step 6 integration spike: the capture-scan (§9 / §9.1).
 *
 * Proves the school regenerates learner facts that were never captured:
 *   1. A grade set while capture is suppressed leaves NO ledger row; the scan finds it
 *      (source has no ledger entry) and regenerates it — a ledger row + an upstream
 *      outbox row appear.
 *   2. Regeneration is LINEAGE-STABLE: a grade captured normally, then wiped from the
 *      ledger+outbox, is re-captured by the scan under the IDENTICAL lineageuuid — so
 *      its factuuid dedups against whatever central holds (§9.1, no fork).
 *   3. Idempotent: a second scan finds the ledger row and re-captures nothing.
 *   4. A ledger fact never exported whose source row is gone is surfaced as a local-loss
 *      finding (§9.1), never silent.
 *
 * Fixtures (itestcs) restore config and delete every fixture row — re-runnable, zero
 * residue.
 *
 * Usage:  php integration_capture_scan.php [--keep]
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
use local_syncqueue\fact_ledger;
use local_syncqueue\task\capture_scan;

list($options) = cli_get_params(['help' => false, 'keep' => false], []);
if (!empty($options['help'])) {
    cli_writeln("Drive the step-6 capture-scan and assert regeneration + local-loss findings.\n"
        . "  --keep   Leave fixtures in place.\n");
    exit(0);
}

const CS_COURSE_SHORT = 'itestcs_course';
const CS_COURSE_IDN = 'itestcs_courseidn';
const CS_GI_UUID = 'ca97e5ca-0000-4000-8000-000000000001';
const CS_SDMS = 'ITESTCS-STU-1';
const CS_SCHOOL = 'itestcsschool';
const CS_USERNAME = 'itestcs_student';

$csfailures = [];

/**
 * Record + print one assertion.
 *
 * @param string $name Check id.
 * @param bool $ok Passed?
 * @param string $detail Detail.
 */
function cs_check(string $name, bool $ok, string $detail = ''): void {
    global $csfailures;
    if (!$ok) {
        $csfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/** Ledger rows for a grade_grades source id. */
function cs_ledger(int $ggid): array {
    return fact_ledger::get_by_source('grade_grades', $ggid);
}

/** Delete every itestcs fixture row. */
function cs_purge(): void {
    global $DB;
    while ($course = $DB->get_record('course', ['shortname' => CS_COURSE_SHORT])) {
        delete_course($course->id, false);
    }
    foreach ($DB->get_records_select('user',
            $DB->sql_like('username', ':u') . ' AND deleted = 0', ['u' => 'itestcs\\_%']) as $u) {
        delete_user($u);
    }
    $DB->delete_records_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 1',
        ['u' => 'itestcs\\_%']);
    $DB->delete_records_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTCS%']);
    if ($DB->get_manager()->table_exists('elby_roster')) {
        $DB->delete_records_select('elby_roster', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTCS%']);
    }
    // Fixture facts: the ledger is keyed by origin; the outbox has no origin column —
    // this school's captured facts ride partitionkey 'learner:school:<origin>'.
    if ($DB->get_manager()->table_exists('local_syncqueue_ledger')) {
        $DB->delete_records('local_syncqueue_ledger', ['origin' => CS_SCHOOL]);
    }
    if ($DB->get_manager()->table_exists('local_syncqueue_outbox')) {
        $DB->delete_records_select('local_syncqueue_outbox',
            'partitionkey = :p', ['p' => 'learner:school:' . CS_SCHOOL]);
    }
}

/** Count the fixture school's upstream outbox rows (keyed by partition, not origin). */
function cs_outbox_count(): int {
    global $DB;
    return $DB->count_records_select('local_syncqueue_outbox',
        'partitionkey = :p', ['p' => 'learner:school:' . CS_SCHOOL]);
}

$confignames = ['mode', 'enabled', 'schoolid', 'push_v2', 'pull_v2'];
$savedconfig = [];
foreach ($confignames as $name) {
    $savedconfig[$name] = get_config('local_syncqueue', $name);
}
cs_purge();

$cleanup = function () use ($confignames, $savedconfig) {
    cs_purge();
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
    // Phase 0 — fixtures (school, push_v2 on).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 0: fixtures ---');
    set_config('mode', 'school', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    set_config('schoolid', CS_SCHOOL, 'local_syncqueue');
    set_config('push_v2', 1, 'local_syncqueue');

    $cat = \core_course_category::get_default();
    $course = create_course((object) [
        'fullname' => 'ITEST CS Course', 'shortname' => CS_COURSE_SHORT, 'category' => $cat->id,
        'summary' => '', 'format' => 'topics', 'visible' => 1, 'idnumber' => CS_COURSE_IDN,
        'enablecompletion' => 1,
    ]);
    $courseid = (int) $course->id;

    $u = (object) [
        'username' => CS_USERNAME, 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'email' => CS_USERNAME . '@example.invalid',
        'firstname' => 'ITest', 'lastname' => 'CSStudent', 'idnumber' => '',
    ];
    $userid = (int) user_create_user($u, false, false);
    $DB->insert_record('elby_sdms_users', (object) [
        'userid' => $userid, 'sdms_id' => CS_SDMS, 'schoolid' => null, 'user_type' => 'student',
        'academic_year' => '2026', 'sdms_status' => 'active', 'sync_status' => 1, 'sync_error' => null,
        'last_synced' => time(), 'timecreated' => time(), 'timemodified' => time(),
    ]);
    // The learner is STILL on this school's roster: the scan only regenerates for
    // still-home learners (the current-generation stamp is valid only then).
    $DB->insert_record('elby_roster', (object) [
        'sdms_id' => CS_SDMS, 'user_type' => 'student', 'school_code' => CS_SCHOOL,
        'names' => 'ITest CSStudent', 'payload' => '{}', 'timecached' => time(), 'timemodified' => time(),
    ]);
    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    enrol_try_internal_enrol($courseid, $userid, $studentrole->id);

    $mi = create_module((object) [
        'modulename' => 'assign', 'course' => $courseid, 'section' => 0, 'visible' => 1,
        'name' => 'ITEST CS Assign',
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
    $assigncmid = (int) $mi->coursemodule;
    $assignid = (int) $mi->instance;
    $giid = (int) $DB->get_field('grade_items', 'id',
        ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assignid, 'itemnumber' => 0]);
    $DB->set_field('grade_items', 'idnumber', CS_GI_UUID, ['id' => $giid]);
    $DB->set_field('course_modules', 'idnumber', CS_GI_UUID, ['id' => $assigncmid]);

    cs_check('fixtures', $courseid > 0 && $userid > 0 && $giid > 0, "course={$courseid} giid={$giid}");

    // -----------------------------------------------------------------------
    // 1. A grade set with capture SUPPRESSED is never captured; the scan finds it.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: regenerate a never-captured grade ---');
    $item = \grade_item::fetch(['id' => $giid]);
    capture::suppress(true);
    try {
        $item->update_final_grade($userid, 77.0, 'gradebook'); // fires user_graded; suppressed -> no fact
    } finally {
        capture::suppress(false);
    }
    $ggid = (int) $DB->get_field('grade_grades', 'id', ['itemid' => $giid, 'userid' => $userid]);
    cs_check('uncaptured_baseline', $ggid > 0 && empty(cs_ledger($ggid)),
        "grade {$ggid} exists with NO ledger row (never captured)");

    $outboxbefore = cs_outbox_count();
    (new capture_scan())->execute();
    $ledger = cs_ledger($ggid);
    $lineage = $ledger ? reset($ledger)->lineageuuid : '';
    $outboxafter = cs_outbox_count();
    cs_check('scan_regenerates',
        count($ledger) === 1 && $lineage !== '' && $outboxafter === $outboxbefore + 1,
        "scan created a ledger row (lineage {$lineage}) + one upstream outbox row");

    // -----------------------------------------------------------------------
    // 2. Idempotent: a second scan re-captures nothing.
    // -----------------------------------------------------------------------
    (new capture_scan())->execute();
    $outboxidem = cs_outbox_count();
    cs_check('scan_idempotent', $outboxidem === $outboxafter,
        "a second scan added no outbox row (ledger present -> skipped)");

    // -----------------------------------------------------------------------
    // 3. Regeneration is lineage-stable: wipe the fact, re-scan -> same lineageuuid.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: regeneration lineage stability ---');
    $DB->delete_records('local_syncqueue_ledger', ['origin' => CS_SCHOOL]);
    $DB->delete_records_select('local_syncqueue_outbox', 'partitionkey = :p', ['p' => 'learner:school:' . CS_SCHOOL]);
    cs_check('wiped', empty(cs_ledger($ggid)), 'ledger + outbox wiped for the fixture fact');

    (new capture_scan())->execute();
    $reledger = cs_ledger($ggid);
    $relineage = $reledger ? reset($reledger)->lineageuuid : '';
    cs_check('regeneration_lineage_stable',
        count($reledger) === 1 && $relineage === $lineage && $lineage !== '',
        "re-captured under the IDENTICAL lineageuuid ({$relineage}) — dedups against central, no fork");

    // -----------------------------------------------------------------------
    // 4. Local-loss finding: an unexported fact whose source row is gone.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: local-loss finding ---');
    $DB->insert_record('local_syncqueue_ledger', (object) [
        'origin' => CS_SCHOOL, 'facttype' => 'grade',
        'lineageuuid' => 'ca97e5ca-0000-4000-8000-0000000000ff',
        'factversion' => null, 'factuuid' => null,
        'naturalkey' => 'itestcs-lost', 'sourcetable' => 'grade_grades', 'sourceid' => 999999999,
        'payloadhash' => 'lost', 'sourceversion' => null, 'rostergen' => null, 'homeschool' => CS_SCHOOL,
        'capturedat' => time(), 'lastexportedseq' => null,
        'status' => fact_ledger::STATUS_CAPTURED, 'timemodified' => time(),
    ]);
    $losstask = new capture_scan();
    $rc = new \ReflectionMethod($losstask, 'scan_local_losses');
    $rc->setAccessible(true);
    $losses1 = (int) $rc->invoke($losstask);
    cs_check('local_loss_finding', $losses1 >= 1,
        "a CAPTURED (never-pushed) fact whose grade_grades source is gone is surfaced ({$losses1} finding(s))");

    // An ACKED fact whose source is gone is NOT a loss — central already holds it; the
    // finding must not fire on the normal synced-then-deleted terminal state.
    $DB->insert_record('local_syncqueue_ledger', (object) [
        'origin' => CS_SCHOOL, 'facttype' => 'grade',
        'lineageuuid' => 'ca97e5ca-0000-4000-8000-0000000000fe',
        'factversion' => 1, 'factuuid' => 'ca97e5ca-0000-4000-8000-0000000000fd',
        'naturalkey' => 'itestcs-acked', 'sourcetable' => 'grade_grades', 'sourceid' => 999999998,
        'payloadhash' => 'acked', 'sourceversion' => null, 'rostergen' => null, 'homeschool' => CS_SCHOOL,
        'capturedat' => time(), 'lastexportedseq' => 1,
        'status' => fact_ledger::STATUS_ACKED, 'timemodified' => time(),
    ]);
    $losses2 = (int) $rc->invoke($losstask);
    cs_check('local_loss_acked_excluded', $losses2 === $losses1,
        "an ACKED (already-pushed) fact whose source is gone is NOT a false loss ({$losses1} -> {$losses2})");

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $csfailures[] = 'script_completed';
}

if (!empty($options['keep'])) {
    cli_writeln('INFO --keep: leaving fixtures in place');
} else {
    $cleanup();
    $residueledger = $DB->count_records('local_syncqueue_ledger', ['origin' => CS_SCHOOL]);
    $residueuser = $DB->count_records_select('user', $DB->sql_like('username', ':u'), ['u' => 'itestcs%']);
    cs_check('cleanup_zero_residue', $residueledger === 0 && $residueuser === 0,
        "fixture ledger rows={$residueledger}, fixture users={$residueuser}");
}

if (empty($csfailures)) {
    cli_writeln('SPIKE RESULT: PASS - capture-scan regeneration + local-loss findings verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($csfailures)));
exit(1);
