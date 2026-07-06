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
 * End-to-end integration test for ELMS Sync v2 step 4 (home authorship).
 *
 * Single-site both-roles test: this instance plays school (stamped-UUID capture of
 * a grade + an activity completion + a course completion for an SDMS-linked
 * learner) and central (buffer-then-apply through the authoritative appliers,
 * home-tenure gating, echo suppression) by driving the real classes in-process.
 * The network transport is replaced by a direct call to ingest_manager::
 * receive_push, so no HTTP leaves the box and no remote central is touched.
 *
 * Drives the full home-authorship loop (doc §5/§7/§8/§9.1/§13 step 4):
 *  1. Preflight: fixture national course + assignment; stamp cm/grade-item UUIDs;
 *     assert stamped + idempotent; build/publish the identity map.
 *  2. Capture (school, push_v2=1): grade + activity completion + course completion
 *     facts, with rostergen stamped and natural keys keyed on the stamped UUIDs.
 *  3. Apply (central): the grade lands as an OVERRIDDEN leaf finalgrade a full
 *     regrade leaves; no category/course-total grade is authored; the activity
 *     completion latches (overrideby set); the course completion latches.
 *  4. Supersession: a higher factversion updates the grade; an older factversion
 *     settles stale; the value never regresses.
 *  5. Tenure: a fact from a non-home origin is rejected to conflicts; a legit
 *     in-tenure months-old (low rostergen) fact still applies.
 *  6. Echo suppression: the appliers' own grade/completion writes mint zero facts.
 *  7. Dual-stack: push_v2=0 keeps a grade 100% on the legacy queue.
 *
 * Single-site seams (documented at each use): the school's grade/completion rows
 * ARE central's, so before each authoritative apply the target row is sentinelled
 * (grade) or deleted (completion) to make the applier's own write provable, and
 * central's schoolid is set distinct from the fact origin so the own-origin echo
 * skip does not fire. Disposable fixtures (prefix itestha); re-runnable; cleans up
 * even on failure, restoring mode/push_v2/schoolid/rostergen, the self epoch row,
 * dataroot marker and every v2/legacy/tenure table row it added.
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
require_once($CFG->libdir . '/grade/grade_grade.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/completion/completion_completion.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

use local_syncqueue\capture;
use local_syncqueue\epoch_store;
use local_syncqueue\fact_identity;
use local_syncqueue\fact_ledger;
use local_syncqueue\ingest_manager;
use local_syncqueue\item_identity;
use local_syncqueue\outbox\publisher;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\tenure;
use local_syncqueue\task\apply_ingest;

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'keep' => false,
], [
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    cli_writeln("ELMS Sync v2 step 4 home-authorship integration test (single-site both-roles).

Creates disposable itestha fixtures (a national course with an assignment, a
manual make-up item, an SDMS-linked student) and drives the full stamp ->
capture -> sequence -> push -> apply loop, asserting overridden-grade authorship,
regrade survival, no-total-authored, activity/course completion latches,
factversion supersession, home-tenure accept/reject, echo suppression and
dual-stack. Flips mode/push_v2/schoolid/rostergen during the run and restores
them, restores the self epoch row + dataroot marker, and deletes every fixture
and table row it added, even on failure.

Options:
  -h, --help    Print this help.
  --keep        Skip cleanup (leaves fixtures, configs and table rows).

Example:
  php local/syncqueue/cli/spikes/integration_home_authorship.php");
    exit(0);
}

// Fixture identifiers (all prefixed itestha for unambiguous purge/cleanup).
const HA_SCHOOL_A = 'itestha_A';
const HA_SCHOOL_B = 'itestha_B';
const HA_CENTRAL = 'itestha_central';
const HA_SDMS = 'ITESTHA_SDMS1';
const HA_COURSE_SHORT = 'itestha_course';
const HA_COURSE_IDN = 'itestha_courseidn';
const HA_USERNAME = 'itestha_student';
const HA_MAKEUP_NAME = 'itestha_makeup';

$hafailures = [];
$fatal = null;

/**
 * Print one evidence line and record failures.
 *
 * @param string $name Check name.
 * @param bool $ok Whether the check passed.
 * @param string $detail Evidence detail.
 */
function ha_check(string $name, bool $ok, string $detail): void {
    global $hafailures;
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
    if (!$ok) {
        $hafailures[] = $name;
    }
}

/**
 * Restore a local_syncqueue config value saved with get_config (false = unset).
 *
 * @param string $name Config name.
 * @param mixed $value Saved value (false when it was unset).
 */
function ha_restore_config(string $name, $value): void {
    if ($value === false) {
        unset_config($name, 'local_syncqueue');
    } else {
        set_config($name, $value, 'local_syncqueue');
    }
}

/**
 * Set the school/central role configs in one call.
 *
 * @param string $mode school|central.
 * @param int $enabled 0|1.
 * @param int $pushv2 0|1.
 * @param string $schoolid This instance's schoolid (origin when school; self when central).
 */
function ha_role(string $mode, int $enabled, int $pushv2, string $schoolid): void {
    set_config('mode', $mode, 'local_syncqueue');
    set_config('enabled', $enabled, 'local_syncqueue');
    set_config('push_v2', $pushv2, 'local_syncqueue');
    set_config('schoolid', $schoolid, 'local_syncqueue');
}

/**
 * Give the fixture student a RAW grade on a mod item (overridden stays 0), firing
 * a real user_graded event the school observer captures under push_v2.
 *
 * @param int $itemid Grade item id.
 * @param int $userid User id.
 * @param float $grade Raw/final grade.
 */
function ha_grade_raw(int $itemid, int $userid, float $grade): void {
    $gi = \grade_item::fetch(['id' => $itemid]);
    $gi->update_raw_grade($userid, $grade, 'itestha');
}

/**
 * Set the fixture student's finalgrade on an item, firing user_graded. On a mod
 * item this is an override; on a manual item it is the authoritative value.
 *
 * @param int $itemid Grade item id.
 * @param int $userid User id.
 * @param float $grade Final grade.
 */
function ha_grade_final(int $itemid, int $userid, float $grade): void {
    $gi = \grade_item::fetch(['id' => $itemid]);
    $gi->update_final_grade($userid, $grade, 'itestha');
}

/**
 * The grade_grades row for an item/user straight from the DB, or null.
 *
 * @param int $itemid Grade item id.
 * @param int $userid User id.
 * @return \stdClass|null
 */
function ha_grade_row(int $itemid, int $userid): ?\stdClass {
    global $DB;
    return $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $userid]) ?: null;
}

/**
 * Map an outbox fact row to a v2 push wire item (payload is already canonical JSON).
 *
 * @param \stdClass $row Outbox row.
 * @return array Wire item.
 */
function ha_row_to_item(\stdClass $row): array {
    return [
        'school_seq' => (int) $row->seq,
        'factuuid' => (string) $row->factuuid,
        'lineageuuid' => (string) $row->lineageuuid,
        'factversion' => (int) $row->factversion,
        'facttype' => (string) $row->entitytype,
        'action' => (string) $row->action,
        'entitykey' => (string) $row->entitykey,
        'payload' => $row->payload,
        'payloadhash' => (string) $row->payloadhash,
        'rostergen' => $row->rostergen !== null ? (int) $row->rostergen : null,
        'kind' => 'fact',
        'reason' => '',
    ];
}

/**
 * Sequence, then push every not-yet-buffered fact outbox row THIS RUN created into
 * central's ingest buffer via the direct in-process seam. Returns the pushed
 * factuuids.
 *
 * Scoped to id > $minoutboxid (the outbox high-water snapshotted at spike start)
 * so a pre-existing or unrelated fact row on the dev box is never swept into the
 * test; only facts this run captured are pushed.
 *
 * @param string $schoolid Pushing school id.
 * @param string $epoch School self epoch.
 * @param int $minoutboxid Outbox id high-water at spike start.
 * @return string[] Factuuids pushed this call.
 */
function ha_push_facts(string $schoolid, string $epoch, int $minoutboxid): array {
    global $DB;

    sequencer::assign();
    $rows = $DB->get_records_select('local_syncqueue_outbox',
        'id > :minid AND lineageuuid IS NOT NULL AND seq IS NOT NULL AND factuuid IS NOT NULL',
        ['minid' => $minoutboxid], 'seq ASC');
    $items = [];
    $pushed = [];
    foreach ($rows as $row) {
        if ($DB->record_exists('local_syncqueue_ingest', ['factuuid' => $row->factuuid])) {
            continue; // Already buffered on an earlier push (benign replay avoided).
        }
        $items[] = ha_row_to_item($row);
        $pushed[] = (string) $row->factuuid;
    }
    if ($items) {
        $headseq = 0;
        foreach ($items as $item) {
            $headseq = max($headseq, (int) $item['school_seq']);
        }
        ingest_manager::receive_push($schoolid, $epoch, $headseq, $items);
    }
    return $pushed;
}

/**
 * Build a grade-fact payload in the exact build_payload() shape the central
 * appliers consume, for a synthetic (hand-authored) fact.
 *
 * @param int $userlocalid Local user id (payload localid).
 * @param string $sdms Learner SDMS code.
 * @param string $giuuid Stamped grade-item UUID idnumber.
 * @param string $itemtype 'mod' or 'manual'.
 * @param string $itemname Grade item name.
 * @param float $finalgrade Final grade the fact asserts.
 * @return array Deterministic payload.
 */
function ha_grade_payload(int $userlocalid, string $sdms, string $giuuid,
        string $itemtype, string $itemname, float $finalgrade): array {
    return [
        'event' => [
            'eventname' => '\\core\\event\\user_graded',
            'component' => 'core', 'action' => 'graded', 'target' => 'user',
            'objecttable' => 'grade_grades', 'objectid' => 0, 'relateduserid' => $userlocalid,
            'userid' => $userlocalid, 'courseid' => 0, 'other' => null,
        ],
        'context' => [
            'user' => ['localid' => $userlocalid, 'sdms_id' => $sdms],
            'course' => ['idnumber' => HA_COURSE_IDN, 'shortname' => HA_COURSE_SHORT],
            'object' => [
                'table' => 'grade_grades', 'localid' => 0, 'userid' => $userlocalid,
                'rawgrade' => $finalgrade, 'finalgrade' => $finalgrade, 'feedback' => null,
                'item' => ['itemtype' => $itemtype, 'idnumber' => $giuuid, 'itemname' => $itemname],
            ],
        ],
        'school' => ['id' => HA_SCHOOL_A],
    ];
}

/**
 * Push one synthetic grade fact (controlled origin/epoch/rostergen) into the
 * ingest buffer, then return its factuuid.
 *
 * @param string $origin Authoring school id.
 * @param string $epoch Authoring epoch.
 * @param int $schoolseq Origin sequence.
 * @param int $rostergen Roster generation stamped on the fact.
 * @param array $payload build_payload-shaped grade payload.
 * @param string $giuuid Grade-item UUID (drives the lineage natural key).
 * @param string $sdms Learner SDMS.
 * @return string Factuuid.
 */
function ha_push_synthetic_grade(string $origin, string $epoch, int $schoolseq, int $rostergen,
        array $payload, string $giuuid, string $sdms): string {
    $lineage = fact_identity::lineage_uuid($origin, 'grade',
        fact_identity::natural_key([$giuuid, $sdms]));
    $factuuid = fact_identity::fact_uuid($lineage, 1);
    $json = publisher::canonical_json($payload);
    $item = [
        'school_seq' => $schoolseq,
        'factuuid' => $factuuid,
        'lineageuuid' => $lineage,
        'factversion' => 1,
        'facttype' => 'grade',
        'action' => 'upsert',
        'entitykey' => $lineage,
        'payload' => $json,
        'payloadhash' => publisher::hash_payload($payload),
        'rostergen' => $rostergen,
        'kind' => 'fact',
        'reason' => '',
    ];
    ingest_manager::receive_push($origin, $epoch, $schoolseq, [$item]);
    return $factuuid;
}

/**
 * Counts of the two v2 upstream capture tables, for echo/dual-stack deltas.
 *
 * @return array [ledger, outbox]
 */
function ha_v2_counts(): array {
    global $DB;
    return [$DB->count_records('local_syncqueue_ledger'), $DB->count_records('local_syncqueue_outbox')];
}

/**
 * Delete leftover fixtures from a previous crashed/--keep run.
 */
function ha_purge_leftovers(): void {
    global $DB;
    while ($course = $DB->get_record('course', ['shortname' => HA_COURSE_SHORT])) {
        cli_writeln("INFO purging leftover course {$course->id} from a previous run");
        delete_course($course->id, false);
    }
    // Live fixture users are soft-deleted via the API (deleted = 0 only: delete_user()
    // on an already-deleted user throws). Their course is purged above, so the
    // tombstone left behind is inert — but hard-remove it too so runs do not
    // accumulate one dead itestha user each (true zero residue).
    foreach ($DB->get_records_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 0',
            ['u' => 'itestha\\_%']) as $u) {
        cli_writeln("INFO purging leftover fixture user {$u->id} from a previous run");
        delete_user($u);
    }
    $DB->delete_records_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 1',
        ['u' => 'itestha\\_%']);
    $DB->delete_records_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTHA%']);
    $DB->delete_records_select('local_syncqueue_tenure', $DB->sql_like('sdms', ':s'), ['s' => 'ITESTHA%']);
    $DB->delete_records_select('local_syncqueue_conflicts', $DB->sql_like('sdms', ':s'), ['s' => 'ITESTHA%']);
    foreach ([HA_SCHOOL_A, HA_SCHOOL_B] as $origin) {
        $DB->delete_records('local_syncqueue_ags', ['origin' => $origin]);
    }
}

$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());
$admin = get_admin();

// ---------------------------------------------------------------------------
// Setup: config snapshot, table high-water marks, epoch/marker snapshot, purge.
// ---------------------------------------------------------------------------

$confignames = ['mode', 'enabled', 'schoolid', 'push_v2', 'pull_v2',
    'reincarnate_required', 'rostergen', 'central_rostergen', 'tenure_enforce', 'ingest_maxretries'];
$savedconfig = [];
foreach ($confignames as $name) {
    $savedconfig[$name] = get_config('local_syncqueue', $name);
}
cli_writeln('INFO saved configs: mode=' . var_export($savedconfig['mode'], true)
    . ' schoolid=' . var_export($savedconfig['schoolid'], true)
    . ' push_v2=' . var_export($savedconfig['push_v2'], true)
    . ' rostergen=' . var_export($savedconfig['rostergen'], true));

ha_purge_leftovers();

$snapshottables = [
    'local_syncqueue_outbox',
    'local_syncqueue_ledger',
    'local_syncqueue_ingest',
    'local_syncqueue_epoch',
    'local_syncqueue_cursor',
    'local_syncqueue_seq',
    'local_syncqueue_tenure',
    'local_syncqueue_ags',
    'local_syncqueue_conflicts',
    'local_syncqueue_items',
];
$startmax = [];
$startcount = [];
foreach ($snapshottables as $table) {
    $startmax[$table] = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {' . $table . '}');
    $startcount[$table] = $DB->count_records($table);
}
// Small cross-run-stateful tables: snapshot full rows so pre-existing rows we
// mutate (self epoch, seq counter, cursors) are restored to their exact values.
$epochsnapshot = $DB->get_records('local_syncqueue_epoch');
$cursorsnapshot = $DB->get_records('local_syncqueue_cursor');
$seqsnapshot = $DB->get_records('local_syncqueue_seq');

$markerpath = epoch_store::marker_path();
$markerbytes = is_readable($markerpath) ? file_get_contents($markerpath) : null;

$fixtureuserid = 0;
$fixturecourseid = 0;

// ---------------------------------------------------------------------------
// Cleanup (runs even on failure, unless --keep).
// ---------------------------------------------------------------------------

$cleanup = function() use ($DB, $confignames, $savedconfig, $snapshottables, $startmax,
        $epochsnapshot, $cursorsnapshot, $seqsnapshot, $markerpath, $markerbytes) {
    $step = function(string $label, callable $fn) {
        global $hafailures;
        try {
            $fn();
        } catch (Throwable $e) {
            cli_writeln("INFO cleanup step '{$label}' failed: " . $e->getMessage());
            $hafailures[] = 'cleanup';
        }
    };

    // Restore configs first so no observer/task fires against a remote central.
    $step('configs', function() use ($confignames, $savedconfig) {
        foreach ($confignames as $name) {
            ha_restore_config($name, $savedconfig[$name]);
        }
    });
    $step('courses', function() use ($DB) {
        while ($course = $DB->get_record('course', ['shortname' => HA_COURSE_SHORT])) {
            delete_course($course->id, false);
        }
    });
    $step('users', function() use ($DB) {
        foreach ($DB->get_records_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 0',
                ['u' => 'itestha\\_%']) as $u) {
            delete_user($u);
        }
        $DB->delete_records_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTHA%']);
    });
    // Delete every row we appended to the sync/tenure/legacy tables.
    $step('sync tables', function() use ($DB, $snapshottables, $startmax) {
        foreach ($snapshottables as $table) {
            $DB->delete_records_select($table, 'id > :startid', ['startid' => $startmax[$table]]);
        }
    });
    // Restore pre-existing rows we mutated (self epoch, seq counter, cursors).
    $step('epoch/cursor/seq rows', function() use ($DB, $epochsnapshot, $cursorsnapshot, $seqsnapshot) {
        foreach ($epochsnapshot as $row) {
            if ($DB->record_exists('local_syncqueue_epoch', ['id' => $row->id])) {
                $DB->update_record('local_syncqueue_epoch', $row);
            }
        }
        foreach ($cursorsnapshot as $row) {
            if ($DB->record_exists('local_syncqueue_cursor', ['id' => $row->id])) {
                $DB->update_record('local_syncqueue_cursor', $row);
            }
        }
        foreach ($seqsnapshot as $row) {
            if ($DB->record_exists('local_syncqueue_seq', ['id' => $row->id])) {
                $DB->update_record('local_syncqueue_seq', $row);
            }
        }
    });
    // Restore the dataroot marker to the exact original bytes (or absence).
    $step('dataroot marker', function() use ($markerpath, $markerbytes) {
        if ($markerbytes === null) {
            if (file_exists($markerpath)) {
                @unlink($markerpath);
            }
        } else {
            file_put_contents($markerpath, $markerbytes);
        }
    });
    // Belt-and-braces: clear the request-scoped echo-suppression flag.
    $step('suppress flag', function() {
        capture::suppress(false);
    });
};

try {
    // =======================================================================
    // Phase 0 — fixtures (school mode, capture OFF so no observer noise queues).
    // =======================================================================
    cli_writeln('--- Phase 0: fixtures ---');
    ha_role('school', 1, 0, HA_SCHOOL_A);

    $selfepoch = epoch_store::ensure_self()->epoch;
    cli_writeln('INFO self epoch = ' . $selfepoch);

    if (empty($CFG->enablecompletion)) {
        throw new moodle_exception('generalexceptionmessage', 'error', '',
            'site enablecompletion is off; step-4 completion latches cannot be exercised');
    }

    $cat = core_course_category::get_default();
    $course = create_course((object) [
        'fullname' => 'ITEST HA Course',
        'shortname' => HA_COURSE_SHORT,
        'category' => $cat->id,
        'summary' => '',
        'format' => 'topics',
        'visible' => 1,
        'idnumber' => HA_COURSE_IDN,
        'enablecompletion' => 1,
    ]);
    $fixturecourseid = (int) $course->id;

    // One self-completion criterion so the course-completion latch has a criteria
    // row to mark (spike c) besides the course_completions row.
    $criterion = new completion_criteria_self();
    $criterion->course = $fixturecourseid;
    $criterion->insert();

    $u = new stdClass();
    $u->username = HA_USERNAME;
    $u->auth = 'manual';
    $u->confirmed = 1;
    $u->mnethostid = $CFG->mnet_localhost_id;
    $u->email = HA_USERNAME . '@example.invalid';
    $u->firstname = 'ITest';
    $u->lastname = 'HAStudent';
    $u->idnumber = '';
    $fixtureuserid = (int) user_create_user($u, false, false);

    // SDMS link (both capture and central find_user resolve on sdms_id).
    $DB->insert_record('elby_sdms_users', (object) [
        'userid' => $fixtureuserid,
        'sdms_id' => HA_SDMS,
        'schoolid' => null,
        'user_type' => 'student',
        'academic_year' => '2026',
        'sdms_status' => 'active',
        'sync_status' => 1,
        'sync_error' => null,
        'last_synced' => time(),
        'timecreated' => time(),
        'timemodified' => time(),
    ]);

    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    if (!enrol_try_internal_enrol($fixturecourseid, $fixtureuserid, $studentrole->id)) {
        throw new moodle_exception('generalexceptionmessage', 'error', '', 'student enrolment failed');
    }

    // The assignment: cm + a mod grade item (the national assessment). Completion
    // is view-required so completion is decoupled from grading.
    $mi = create_module((object) [
        'modulename' => 'assign', 'course' => $fixturecourseid, 'section' => 0, 'visible' => 1,
        'name' => 'ITEST HA Assign',
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
    $assigngiid = (int) $DB->get_field('grade_items',
        'id', ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assignid, 'itemnumber' => 0]);

    // A course-level manual make-up item: a fresh lineage for the late in-tenure
    // fact so factversion supersession never masks the tenure-accept assertion.
    $makeupgi = new \grade_item(['courseid' => $fixturecourseid, 'itemtype' => 'manual',
        'itemname' => HA_MAKEUP_NAME], false);
    $makeupgi->insert();
    $makeupgiid = (int) $makeupgi->id;

    ha_check('fixtures_ready',
        $fixtureuserid > 0 && $fixturecourseid > 0 && $assigncmid > 0 && $assigngiid > 0 && $makeupgiid > 0
            && $DB->record_exists('elby_sdms_users', ['userid' => $fixtureuserid, 'sdms_id' => HA_SDMS])
            && (string) $DB->get_field('course', 'idnumber', ['id' => $fixturecourseid]) === HA_COURSE_IDN,
        "user {$fixtureuserid}, course {$fixturecourseid} (idnumber " . HA_COURSE_IDN . "), assign cm {$assigncmid}/gi "
            . "{$assigngiid}, makeup gi {$makeupgiid}, SDMS " . HA_SDMS . ' linked + enrolled');

    // =======================================================================
    // Phase 1 — preflight: stamp cm/grade-item UUIDs; assert stamped + idempotent.
    // =======================================================================
    cli_writeln('--- Phase 1: preflight stamping ---');
    $report1 = item_identity::stamp_course($fixturecourseid, true);

    $assigncmidn = (string) $DB->get_field('course_modules', 'idnumber', ['id' => $assigncmid]);
    $assigngiidn = (string) $DB->get_field('grade_items', 'idnumber', ['id' => $assigngiid]);
    $makeupidn = (string) $DB->get_field('grade_items', 'idnumber', ['id' => $makeupgiid]);
    $courseitem = $DB->get_record('grade_items', ['courseid' => $fixturecourseid, 'itemtype' => 'course']);
    $totalidn = $courseitem ? (string) $courseitem->idnumber : '';

    ha_check('preflight_stamped',
        item_identity::is_uuid($assigncmidn) && item_identity::is_uuid($assigngiidn)
            && $assigngiidn === $assigncmidn && item_identity::is_uuid($makeupidn) && $totalidn === '',
        'assign cm=' . $assigncmidn . ' gi=' . $assigngiidn . ' (gi==cm for itemnumber-0), makeup='
            . $makeupidn . ', course-total idnumber empty (never stamped)');

    // Idempotent + re-runnable: a second stamp is a pure no-op.
    $report2 = item_identity::stamp_course($fixturecourseid, true);
    ha_check('preflight_idempotent',
        (int) $report2->stampedcms === 0 && (int) $report2->stampedgis === 0
            && (string) $DB->get_field('course_modules', 'idnumber', ['id' => $assigncmid]) === $assigncmidn
            && (string) $DB->get_field('grade_items', 'idnumber', ['id' => $assigngiid]) === $assigngiidn,
        're-run stamped 0 cms / 0 gis; existing UUID idnumbers unchanged (first run stamped '
            . $report1->stampedcms . ' cms / ' . $report1->stampedgis . ' gis)');

    // Identity map: build + publish on the downstream channel (school appliers
    // back-stamp already-distributed copies; the school round-trip is
    // identity_map_applier's own scope).
    $map = item_identity::build_map($fixturecourseid);
    $assignentry = null;
    foreach (($map['modules'] ?? []) as $entry) {
        if (($entry['cm_uuid'] ?? '') === $assigncmidn) {
            $assignentry = $entry;
        }
    }
    $mapok = $assignentry !== null;
    $mapgiok = false;
    if ($assignentry) {
        foreach (($assignentry['items'] ?? []) as $it) {
            if (($it['gi_uuid'] ?? '') === $assigngiidn) {
                $mapgiok = true;
            }
        }
    }
    $preoutbox = $DB->count_records('local_syncqueue_outbox');
    $mapoutboxid = item_identity::publish_map($fixturecourseid);
    $maprow = $DB->get_record('local_syncqueue_outbox', ['id' => $mapoutboxid]);
    ha_check('preflight_identity_map',
        $mapok && $mapgiok && $maprow !== null && $maprow->entitytype === 'identity_map'
            && $maprow->entitykey === 'identitymap:' . $fixturecourseid
            && $DB->count_records('local_syncqueue_outbox') === $preoutbox + 1,
        'build_map carries assign cm_uuid + gi_uuid; publish_map wrote outbox row ' . $mapoutboxid
            . ' entitytype identity_map key identitymap:' . $fixturecourseid);

    // =======================================================================
    // Phase 2 — capture (school, push_v2=1, rostergen=3).
    // =======================================================================
    cli_writeln('--- Phase 2: capture ---');
    ha_role('school', 1, 1, HA_SCHOOL_A);
    set_config('rostergen', 3, 'local_syncqueue');

    // Grade the assignment (raw -> overridden stays 0 on the school side).
    ha_grade_raw($assigngiid, $fixtureuserid, 60.0);
    // Complete the activity (view required) and the course.
    $coursefull = get_course($fixturecourseid);
    $completioninfo = new completion_info($coursefull);
    $assigncm = get_fast_modinfo($coursefull)->get_cm($assigncmid);
    $completioninfo->set_module_viewed($assigncm, $fixtureuserid);
    (new completion_completion(['course' => $fixturecourseid, 'userid' => $fixtureuserid]))
        ->mark_complete(time() - WEEKSECS);

    $gradelineage = fact_identity::lineage_uuid(HA_SCHOOL_A, 'grade',
        fact_identity::natural_key([$assigngiidn, HA_SDMS]));
    $actcomplineage = fact_identity::lineage_uuid(HA_SCHOOL_A, 'completion',
        fact_identity::natural_key([$assigncmidn, HA_SDMS]));
    $coursecomplineage = fact_identity::lineage_uuid(HA_SCHOOL_A, 'completion',
        fact_identity::natural_key(['course:' . HA_COURSE_IDN, HA_SDMS]));

    $gledger = $DB->get_records_select('local_syncqueue_ledger', 'lineageuuid = :lu',
        ['lu' => $gradelineage], 'id ASC');
    $gledger = $gledger ? reset($gledger) : null;
    ha_check('capture_grade_fact',
        $gledger !== null && $gledger->facttype === 'grade' && $gledger->origin === HA_SCHOOL_A
            && (int) $gledger->rostergen === 3 && $gledger->factversion === null
            && $gledger->sourcetable === 'grade_grades',
        'grade fact captured on the STAMPED gi-UUID natural key (lineage ' . substr($gradelineage, 0, 8)
            . '), rostergen 3, factversion NULL (pre-sequence), origin ' . HA_SCHOOL_A);

    $aledger = $DB->get_records_select('local_syncqueue_ledger', 'lineageuuid = :lu',
        ['lu' => $actcomplineage], 'id ASC');
    $aledger = $aledger ? reset($aledger) : null;
    ha_check('capture_activity_completion_fact',
        $aledger !== null && $aledger->facttype === 'completion' && (int) $aledger->rostergen === 3
            && $aledger->sourcetable === 'course_modules_completion',
        'activity completion fact captured on the STAMPED cm-UUID natural key (lineage '
            . substr($actcomplineage, 0, 8) . '), rostergen 3');

    $cledger = $DB->get_records_select('local_syncqueue_ledger', 'lineageuuid = :lu',
        ['lu' => $coursecomplineage], 'id ASC');
    $cledger = $cledger ? reset($cledger) : null;
    ha_check('capture_course_completion_fact',
        $cledger !== null && $cledger->facttype === 'completion' && (int) $cledger->rostergen === 3
            && $cledger->sourcetable === 'course_completions',
        'course completion fact captured on the course natural key (lineage '
            . substr($coursecomplineage, 0, 8) . '), rostergen 3');

    // =======================================================================
    // Phase 3 — apply (central): overridden grade + no-total + latches.
    // =======================================================================
    cli_writeln('--- Phase 3: apply (central) ---');

    // Central records home tenure for the learner: school A home from generation 1
    // (open). Facts stamped at gen 3 are in force; enforced because tenure is now
    // known for this SDMS.
    tenure::record_tenure(HA_SDMS, HA_SCHOOL_A, 1);

    // Sequence + push all captured facts into central's ingest buffer.
    $pushed = ha_push_facts(HA_SCHOOL_A, $selfepoch, $startmax['local_syncqueue_outbox']);
    $gfactuuid = fact_identity::fact_uuid($gradelineage, 1);
    $gingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $gfactuuid]);
    ha_check('apply_pushed_buffered',
        $gingest !== null && $gingest->status === 'buffered' && (int) $gingest->rostergen === 3
            && $gingest->schoolid === HA_SCHOOL_A && (int) $gingest->factversion === 1,
        'grade fact sequenced (factversion 1) + buffered into ingest, rostergen 3, origin ' . HA_SCHOOL_A
            . ' (pushed ' . count($pushed) . ' fact(s) incl. the aggregate-total fact central will refuse)');

    // Single-site seam: the school's grade row IS central's, so sentinel the leaf
    // grade (finalgrade below the fact value, overridden 0) to make the applier's
    // own override provable; delete the completion rows so the latches are provable.
    $DB->set_field('grade_grades', 'finalgrade', 1.0, ['itemid' => $assigngiid, 'userid' => $fixtureuserid]);
    $DB->set_field('grade_grades', 'overridden', 0, ['itemid' => $assigngiid, 'userid' => $fixtureuserid]);
    $DB->delete_records('course_modules_completion', ['coursemoduleid' => $assigncmid, 'userid' => $fixtureuserid]);
    $DB->delete_records('course_completion_crit_compl', ['course' => $fixturecourseid, 'userid' => $fixtureuserid]);
    $DB->delete_records('course_completions', ['course' => $fixturecourseid, 'userid' => $fixtureuserid]);
    \cache::make('core', 'completion')->purge();
    \cache::make('core', 'coursecompletion')->purge();
    $preoverridden = (int) ($DB->get_field('grade_grades', 'overridden',
        ['itemid' => $assigngiid, 'userid' => $fixtureuserid]) ?: 0);

    // Central role: schoolid distinct from the fact origin so the own-origin echo
    // skip does not fire on this single site.
    ha_role('central', 1, 0, HA_CENTRAL);

    // Echo-suppression baseline: the appliers fire grade/completion events; assert
    // they mint zero upstream facts.
    [$ledgerbefore, $outboxbefore] = ha_v2_counts();

    (new apply_ingest())->execute();

    $gingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $gfactuuid], '*', MUST_EXIST);
    $grow = ha_grade_row($assigngiid, $fixtureuserid);
    ha_check('apply_grade_overridden',
        $gingest->status === 'applied' && $grow !== null && (int) $grow->overridden > 0
            && !grade_floats_different((float) $grow->finalgrade, 60.0),
        'grade fact applied as an OVERRIDDEN leaf finalgrade: overridden ' . $preoverridden . ' -> '
            . $grow->overridden . ' (>0), finalgrade sentinel 1 -> ' . $grow->finalgrade . ' (== fact value 60)');

    // A full course regrade must LEAVE the overridden leaf grade (spike a).
    grade_regrade_final_grades($fixturecourseid);
    $growafter = ha_grade_row($assigngiid, $fixtureuserid);
    ha_check('apply_grade_survives_regrade',
        $growafter !== null && (int) $growafter->overridden > 0
            && !grade_floats_different((float) $growafter->finalgrade, 60.0),
        'after grade_regrade_final_grades the leaf grade is still overridden=' . $growafter->overridden
            . ' finalgrade=' . $growafter->finalgrade . ' (a regrade cannot touch an overridden grade)');

    // NO category/course-total grade authored: the aggregate is locally recomputed
    // (overridden 0), never written by the applier.
    $totalrow = $DB->get_record('grade_grades', ['itemid' => $courseitem->id, 'userid' => $fixtureuserid]);
    ha_check('apply_no_total_authored',
        $totalrow === false || (int) $totalrow->overridden === 0,
        $totalrow === false
            ? 'no course-total grade row authored at all'
            : 'course-total grade is locally aggregated (finalgrade=' . $totalrow->finalgrade
                . ') and NOT overridden (overridden=0); the aggregate-total fact was refused by the leaf guard');

    // Activity completion latched: override-to-COMPLETE with overrideby stamped.
    $actrow = $DB->get_record('course_modules_completion',
        ['coursemoduleid' => $assigncmid, 'userid' => $fixtureuserid]);
    ha_check('apply_activity_completion_latched',
        $actrow !== null && (int) $actrow->completionstate === COMPLETION_COMPLETE
            && $actrow->overrideby !== null && (int) $actrow->overrideby === (int) $admin->id,
        'activity completion latched: state=' . ($actrow ? $actrow->completionstate : 'none')
            . ' overrideby=' . ($actrow ? var_export($actrow->overrideby, true) : 'none')
            . ' (== apply actor ' . $admin->id . ')');

    // Course completion latched: mark_complete + criteria rows.
    $ccrow = $DB->get_record('course_completions', ['course' => $fixturecourseid, 'userid' => $fixtureuserid]);
    $critcount = $DB->count_records('course_completion_crit_compl',
        ['course' => $fixturecourseid, 'userid' => $fixtureuserid]);
    ha_check('apply_course_completion_latched',
        $ccrow !== null && (int) $ccrow->timecompleted > 0 && $critcount >= 1,
        'course completion latched: timecompleted=' . ($ccrow ? $ccrow->timecompleted : 'none')
            . ' (>0), criteria-completion rows=' . $critcount);

    // Echo suppression (doc §8.2): the applier writes fired user_graded /
    // completion events but minted ZERO upstream facts.
    [$ledgerafter, $outboxafter] = ha_v2_counts();
    ha_check('echo_suppression_apply_delta',
        $ledgerafter === $ledgerbefore && $outboxafter === $outboxbefore,
        'applying grade + activity + course completion added 0 ledger rows (' . $ledgerbefore . '->'
            . $ledgerafter . ') and 0 outbox rows (' . $outboxbefore . '->' . $outboxafter
            . ') despite the applier writes firing grade/completion events');

    // =======================================================================
    // Phase 4 — supersession: higher factversion updates; older settles stale.
    // =======================================================================
    cli_writeln('--- Phase 4: supersession ---');
    ha_role('school', 1, 1, HA_SCHOOL_A);
    set_config('rostergen', 3, 'local_syncqueue');

    // Re-grade to 85 (finalgrade change fires user_graded -> captured as factversion 2).
    ha_grade_final($assigngiid, $fixtureuserid, 85.0);
    ha_push_facts(HA_SCHOOL_A, $selfepoch, $startmax['local_syncqueue_outbox']);

    $v2factuuid = fact_identity::fact_uuid($gradelineage, 2);
    $v2outbox = $DB->get_record('local_syncqueue_outbox', ['factuuid' => $v2factuuid]);
    $v2ingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v2factuuid]);
    ha_check('supersession_factversion2',
        $v2outbox !== null && (int) $v2outbox->factversion === 2 && $v2outbox->factuuid !== $gfactuuid
            && $v2ingest !== null && $v2ingest->status === 'buffered',
        'superseding capture is factversion 2 (new factuuid in the SAME lineage), buffered');

    // Sentinel below the incoming value so the applier's overwrite is provable.
    $DB->set_field('grade_grades', 'finalgrade', 1.0, ['itemid' => $assigngiid, 'userid' => $fixtureuserid]);
    $DB->set_field('grade_grades', 'overridden', 0, ['itemid' => $assigngiid, 'userid' => $fixtureuserid]);
    ha_role('central', 1, 0, HA_CENTRAL);
    (new apply_ingest())->execute();

    $v2ingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v2factuuid], '*', MUST_EXIST);
    $grow2 = ha_grade_row($assigngiid, $fixtureuserid);
    ha_check('supersession_applied_v2',
        $v2ingest->status === 'applied' && $grow2 !== null && (int) $grow2->overridden > 0
            && !grade_floats_different((float) $grow2->finalgrade, 85.0),
        'higher factversion 2 applied: overridden leaf finalgrade sentinel 1 -> ' . $grow2->finalgrade
            . ' (85), overridden ' . $grow2->overridden);

    // Re-buffer the older factversion 1; the supersession pre-check settles it
    // stale without touching the grade (value never regresses).
    $DB->set_field('local_syncqueue_ingest', 'status', 'buffered', ['factuuid' => $gfactuuid]);
    (new apply_ingest())->execute();
    $v1ingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $gfactuuid], '*', MUST_EXIST);
    $grow3 = ha_grade_row($assigngiid, $fixtureuserid);
    ha_check('supersession_older_settles_stale',
        $v1ingest->status === 'stale' && $grow3 !== null
            && !grade_floats_different((float) $grow3->finalgrade, 85.0),
        'older factversion 1 re-buffered settled stale (a higher applied version exists); grade stays 85 ('
            . $grow3->finalgrade . '), never regressed');

    // =======================================================================
    // Phase 5 — tenure: non-home origin rejected; in-tenure months-old applies.
    // =======================================================================
    cli_writeln('--- Phase 5: tenure ---');

    // Enforcement is a deliberate operator switch (Option B): turn it on now that
    // this spike has the fleet stamping facts in central's generation space.
    set_config('tenure_enforce', 1, 'local_syncqueue');

    // Home change: learner moves to school B at generation 5. This closes A at 5
    // ([1,5)) and opens B ([5, open)).
    tenure::record_tenure(HA_SDMS, HA_SCHOOL_B, 5);
    ha_check('tenure_intervals',
        tenure::in_force(HA_SDMS, HA_SCHOOL_A, 3) && !tenure::in_force(HA_SDMS, HA_SCHOOL_A, 6)
            && !tenure::in_force(HA_SDMS, HA_SCHOOL_B, 3) && tenure::in_force(HA_SDMS, HA_SCHOOL_B, 6),
        'half-open intervals judged at generation G: A home@3=Y A home@6=N (moved), B home@3=N (pre-move) B home@6=Y');

    // 5a: a fact from school B at generation 3 (B was NOT home then) targeting the
    // assign leaf. It must be rejected to conflicts, never applied (grade stays 85).
    $preconflicts = $DB->count_records('local_syncqueue_conflicts');
    $rejpayload = ha_grade_payload($fixtureuserid, HA_SDMS, $assigngiidn, 'mod', 'ITEST HA Assign', 99.0);
    $rejfactuuid = ha_push_synthetic_grade(HA_SCHOOL_B, 'itestha-B-epoch', 1, 3,
        $rejpayload, $assigngiidn, HA_SDMS);
    ha_role('central', 1, 0, HA_CENTRAL);
    (new apply_ingest())->execute();

    $rejingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $rejfactuuid], '*', MUST_EXIST);
    $conflict = $DB->get_record('local_syncqueue_conflicts', ['origin' => HA_SCHOOL_B]);
    $growrej = ha_grade_row($assigngiid, $fixtureuserid);
    ha_check('tenure_reject_nontenured',
        $rejingest->status === 'stale' && $conflict !== null
            && $conflict->reason === 'tenure_not_in_force' && (string) $conflict->sdms === HA_SDMS
            && $DB->count_records('local_syncqueue_conflicts') === $preconflicts + 1
            && $growrej !== null && !grade_floats_different((float) $growrej->finalgrade, 85.0),
        'non-home origin ' . HA_SCHOOL_B . ' fact (gen 3) rejected: ingest stale, conflict row recorded (reason '
            . ($conflict ? $conflict->reason : 'none') . '), grade unchanged at ' . $growrej->finalgrade
            . ' (NOT the rejected 99)');

    // 5b: a legit in-tenure months-old fact from school A at generation 1 (A was
    // home then), on the fresh make-up lineage. Judged at G, it still applies.
    $latefactuuid = ha_push_synthetic_grade(HA_SCHOOL_A, 'itestha-A-late-epoch', 1, 1,
        ha_grade_payload($fixtureuserid, HA_SDMS, $makeupidn, 'manual', HA_MAKEUP_NAME, 70.0),
        $makeupidn, HA_SDMS);
    (new apply_ingest())->execute();
    $lateingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $latefactuuid], '*', MUST_EXIST);
    $makeuprow = ha_grade_row($makeupgiid, $fixtureuserid);
    ha_check('tenure_accept_intenure_old',
        $lateingest->status === 'applied' && $makeuprow !== null
            && !grade_floats_different((float) $makeuprow->finalgrade, 70.0),
        'in-tenure months-old fact (school A, gen 1, in force at gen 1) applied: make-up finalgrade='
            . ($makeuprow ? $makeuprow->finalgrade : 'none') . ' (70); a late fact judged at its own generation lands');

    // =======================================================================
    // Phase 6 — echo suppression: the request-scoped flag gates capture.
    // =======================================================================
    cli_writeln('--- Phase 6: echo suppression (capture::suppress seam) ---');
    ha_role('school', 1, 1, HA_SCHOOL_A);
    set_config('rostergen', 3, 'local_syncqueue');

    [$ledgerpre] = ha_v2_counts();
    capture::suppress(true);
    try {
        ha_grade_final($makeupgiid, $fixtureuserid, 55.0); // fires user_graded while suppressed
    } finally {
        capture::suppress(false);
    }
    [$ledgersuppressed] = ha_v2_counts();

    ha_grade_final($makeupgiid, $fixtureuserid, 56.0); // fires user_graded, NOT suppressed -> captured
    [$ledgeropen] = ha_v2_counts();

    ha_check('echo_suppression_flag',
        $ledgersuppressed === $ledgerpre && $ledgeropen > $ledgersuppressed,
        'capture::suppress(true) blocked a grade event from minting a fact (ledger ' . $ledgerpre . '->'
            . $ledgersuppressed . '); with the flag cleared the next grade event captured (ledger '
            . $ledgersuppressed . '->' . $ledgeropen . ')');

    // =======================================================================
    // Phase 7 — dual-stack: push_v2=0 keeps a grade on the legacy queue only.
    // =======================================================================
    cli_writeln('--- Phase 7: dual-stack (push_v2=0) ---');
    ha_role('school', 1, 0, HA_SCHOOL_A);

    $preitems = $DB->count_records('local_syncqueue_items');
    [$ledgerd, $outboxd] = ha_v2_counts();
    $preingest = $DB->count_records('local_syncqueue_ingest');
    ha_grade_final($makeupgiid, $fixtureuserid, 44.0); // legacy path
    [$ledgerd2, $outboxd2] = ha_v2_counts();
    ha_check('dualstack_legacy_only',
        $DB->count_records('local_syncqueue_items') >= $preitems + 1
            && $ledgerd2 === $ledgerd && $outboxd2 === $outboxd
            && $DB->count_records('local_syncqueue_ingest') === $preingest,
        'push_v2=0 grade queued on legacy local_syncqueue_items (+'
            . ($DB->count_records('local_syncqueue_items') - $preitems)
            . ') with ZERO v2 ledger/outbox/ingest side effects');
} catch (Throwable $e) {
    $fatal = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal);
    $hafailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup and restoration proof.
// ---------------------------------------------------------------------------
if ($options['keep']) {
    cli_writeln('INFO --keep set: leaving fixtures, configs and table rows in place');
} else {
    $cleanup();

    $restored = true;
    $detail = [];
    foreach ($snapshottables as $table) {
        $now = $DB->count_records($table);
        if ($now != $startcount[$table]) {
            $restored = false;
            $detail[] = "{$table} {$startcount[$table]} -> {$now}";
        }
    }
    foreach ($confignames as $name) {
        if (get_config('local_syncqueue', $name) !== $savedconfig[$name]) {
            $restored = false;
            $detail[] = "config {$name} not restored";
        }
    }
    $self = epoch_store::get(epoch_store::SCOPE_SELF);
    if (isset($selfepoch) && (!$self || $self->epoch !== $selfepoch)) {
        $restored = false;
        $detail[] = 'self epoch not restored';
    }
    if (epoch_store::marker_status() !== 'ok') {
        $restored = false;
        $detail[] = 'dataroot marker not restored (' . epoch_store::marker_status() . ')';
    }
    if ($DB->record_exists('course', ['shortname' => HA_COURSE_SHORT])
            || $DB->record_exists_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 0',
                ['u' => 'itestha\\_%'])
            || $DB->record_exists_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTHA%'])) {
        $restored = false;
        $detail[] = 'fixtures remain';
    }
    ha_check('cleanup_restored', $restored,
        $restored ? 'all sync/tenure tables, fixtures, configs, self epoch and dataroot marker back to start'
            : implode('; ', $detail));
}

if (empty($hafailures)) {
    cli_writeln('SPIKE RESULT: PASS - full single-site home-authorship loop verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($hafailures))
    . ($fatal ? " ({$fatal})" : ''));
exit(1);
