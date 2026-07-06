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
 * End-to-end integration test for ELMS Sync v2 step 2 (upstream rework).
 *
 * Single-site both-roles test: this instance plays school (internal-observer
 * capture, sequencer fact finalization, retained-until-ack push loop, self-heal,
 * re-incarnation adoption) and central (ingest buffer + dedup + two-tier fork
 * detection, async apply) by driving the real classes in-process. The network
 * transport is replaced by two documented test seams — push_stream::push_batch
 * (calls ingest_manager::receive_push directly) and epoch_guard::make_client
 * (serves reincarnate() from central_issue_epoch directly) — so no HTTP ever
 * leaves the box and the remote production central is never touched.
 *
 * Covers: internal-observer atomic capture (real user_graded event) + SDMS gate,
 * ledger+outbox dual write, sequencer seq/factversion/factuuid finalization,
 * push buffering + acked_through + retained-until-ack prune + ledger acked,
 * async apply through the step-0 central_processor, benign-replay dedup,
 * supersession (factversion 2, older-version not reapplied), hole + contiguous
 * ack, incarnation fork -> re-incarnation handshake (new epoch, reseed, requeue,
 * re-push under the new epoch), dataroot marker mismatch, and dual-stack
 * (push_v2 = 0 keeps grades 100% on the legacy queue with zero v2 side effects).
 *
 * Disposable fixtures (prefix itestpush); re-runnable; cleans up even on failure,
 * including restoring mode/push_v2, the real self epoch row and dataroot marker.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_syncqueue\capture;
use local_syncqueue\central_processor;
use local_syncqueue\epoch_guard;
use local_syncqueue\epoch_store;
use local_syncqueue\external\push;
use local_syncqueue\fact_identity;
use local_syncqueue\fact_ledger;
use local_syncqueue\ingest_manager;
use local_syncqueue\outbox\cursor;
use local_syncqueue\outbox\publisher;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\school_manager;
use local_syncqueue\sync_client;
use local_syncqueue\task\apply_ingest;
use local_syncqueue\task\push_stream;

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
    cli_writeln("ELMS Sync v2 step 2 upstream integration test (single-site both-roles).

Creates disposable itestpush fixtures (three schools, a course, an SDMS-linked
student, manual grade items) and drives the full capture -> sequence -> push ->
ack/prune -> apply -> dedup -> supersession -> hole -> fork/re-incarnation ->
marker-mismatch -> dual-stack loop, asserting every step. Flips mode/enabled/
push_v2/schoolid during the run and restores them, restores the real self epoch
row + dataroot marker, and deletes every fixture and v2/legacy table row it
added, even on failure.

Options:
  -h, --help    Print this help.
  --keep        Skip cleanup (leaves fixtures, configs, epoch and table rows).

Example:
  php local/syncqueue/cli/spikes/integration_push_v2.php");
    exit(0);
}

// Fixture identifiers (all prefixed itestpush for unambiguous purge/cleanup).
const ITP_SCHOOL = 'itestpushschool';
const ITP_HOLES = 'itestpushholes';
const ITP_WS = 'itestpushws';
const ITP_SDMS = 'ITESTPUSHSDMS1';
const ITP_COURSE_SHORT = 'itestpush_course';
const ITP_COURSE_IDN = 'itestpush_courseidn';
const ITP_CAT_IDN = 'itestpush_cat';
const ITP_USERNAME = 'itestpush_student1';
const ITP_ITEM_IDN = 'itestpush_item1';
const ITP_ITEM2_IDN = 'itestpush_item2';

/**
 * push_stream with the HTTP transport replaced by a direct in-process call to
 * the central ingest buffer on this same site. Records every response so the
 * test can assert acked_through / stored / reincarnate_required.
 */
class itestpush_push_stream extends push_stream {

    /** @var \stdClass[] Normalized responses from every push_batch call. */
    public array $itestresponses = [];

    /**
     * Push one batch by calling ingest_manager::receive_push directly.
     *
     * @param array $rows Outbox rows to push, in seq order.
     * @param string $epoch This school's self epoch.
     * @param int $headseq This school's outbox head.
     * @return \stdClass Normalized push response.
     */
    protected function push_batch(array $rows, string $epoch, int $headseq): \stdClass {
        $items = [];
        foreach ($rows as $row) {
            $items[] = itestpush_row_to_item($row);
        }
        $schoolid = (string) get_config('local_syncqueue', 'schoolid');
        $response = ingest_manager::receive_push($schoolid, $epoch, $headseq, $items);
        $normalized = itestpush_normalize_response($response);
        $this->itestresponses[] = $normalized;
        return $normalized;
    }

    /**
     * Last recorded push response, or null when none.
     *
     * @return \stdClass|null
     */
    public function last_response(): ?\stdClass {
        return empty($this->itestresponses) ? null : end($this->itestresponses);
    }
}

/**
 * sync_client whose reincarnate() serves central's side in-process (single-site)
 * instead of over HTTP. The parent constructor is skipped deliberately: no
 * central URL / token is needed and none may be dialed.
 */
class itestpush_fake_client extends sync_client {

    // phpcs:ignore moodle.Commenting.MissingDocblock.Constructor
    public function __construct() {
        // Intentionally does not call parent::__construct(): this fake never
        // makes a network request and needs no central config.
    }

    /**
     * Serve the re-incarnation handshake locally via central_issue_epoch.
     *
     * @param string $oldepoch The epoch this school is retiring.
     * @return \stdClass {protocol_version, new_epoch, seed_seq}.
     */
    public function reincarnate(string $oldepoch): \stdClass {
        $schoolid = (string) get_config('local_syncqueue', 'schoolid');
        $issued = epoch_guard::central_issue_epoch($schoolid, $oldepoch);
        return (object) [
            'protocol_version' => 2,
            'new_epoch' => (string) $issued['new_epoch'],
            'seed_seq' => (int) $issued['seed_seq'],
        ];
    }
}

/**
 * epoch_guard whose make_client() seam returns the in-process fake client, so
 * run_reincarnation_handshake() drives the full school-side adoption (epoch row,
 * seq reseed, un-acked requeue, marker write) without any HTTP.
 */
class itestpush_epoch_guard extends epoch_guard {

    /** @var sync_client|null Injected in-process client. */
    public static ?sync_client $client = null;

    /**
     * Return the injected in-process client.
     *
     * @return sync_client
     */
    protected static function make_client(): sync_client {
        return self::$client;
    }
}

/**
 * Map an outbox row (stdClass) to a v2 push wire item, exactly as
 * sync_client::push() does (payload is already canonical JSON on the row).
 *
 * @param \stdClass $row Outbox row.
 * @return array Wire item.
 */
function itestpush_row_to_item(\stdClass $row): array {
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
 * Normalize an ingest_manager::receive_push() array into the stdClass shape the
 * push_stream loop expects from sync_client::push().
 *
 * @param array $response Raw receive_push return.
 * @return \stdClass
 */
function itestpush_normalize_response(array $response): \stdClass {
    $forks = [];
    foreach (($response['forks'] ?? []) as $fork) {
        $fork = (array) $fork;
        $forks[] = (object) [
            'school_seq' => (int) ($fork['school_seq'] ?? 0),
            'tier' => (string) ($fork['tier'] ?? ''),
            'detail' => (string) ($fork['detail'] ?? ''),
        ];
    }
    return (object) [
        'protocol_version' => (int) ($response['protocol_version'] ?? 0),
        'status' => (string) ($response['status'] ?? ''),
        'acked_through' => (int) ($response['acked_through'] ?? 0),
        'stored' => array_map('intval', $response['stored'] ?? []),
        'stale' => array_map('intval', $response['stale'] ?? []),
        'forks' => $forks,
        'reincarnate_required' => !empty($response['reincarnate_required']),
        'central_epoch' => (string) ($response['central_epoch'] ?? ''),
        'central_head_seq' => (int) ($response['central_head_seq'] ?? 0),
    ];
}

/**
 * Print one evidence line and record failures.
 *
 * @param string $name Check name.
 * @param bool $ok Whether the check passed.
 * @param string $detail Evidence detail.
 */
function itestpush_check(string $name, bool $ok, string $detail): void {
    global $itestpushfailures;
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
    if (!$ok) {
        $itestpushfailures[] = $name;
    }
}

/**
 * Restore a local_syncqueue config value saved with get_config (false = unset).
 *
 * @param string $name Config name.
 * @param mixed $value Saved value (false when it was unset).
 */
function itestpush_restore_config(string $name, $value): void {
    if ($value === false) {
        unset_config($name, 'local_syncqueue');
    } else {
        set_config($name, $value, 'local_syncqueue');
    }
}

/**
 * Set the four local_syncqueue role configs in one call.
 *
 * @param string $mode school|central.
 * @param int $enabled 0|1.
 * @param int $pushv2 0|1.
 */
function itestpush_role(string $mode, int $enabled, int $pushv2): void {
    set_config('mode', $mode, 'local_syncqueue');
    set_config('enabled', $enabled, 'local_syncqueue');
    set_config('push_v2', $pushv2, 'local_syncqueue');
}

/**
 * Grade the fixture student on a manual grade item, firing a real user_graded
 * event (which the internal observer captures under push_v2). Returns the
 * grade_grades row id.
 *
 * @param int $itemid Grade item id.
 * @param int $userid User id.
 * @param float $grade Final grade.
 * @return int grade_grades.id
 */
function itestpush_grade(int $itemid, int $userid, float $grade): int {
    global $DB;
    $gi = new \grade_item(['id' => $itemid], true);
    $gi->update_final_grade($userid, $grade, 'itestpush');
    return (int) $DB->get_field('grade_grades', 'id', ['itemid' => $itemid, 'userid' => $userid]);
}

/**
 * Delete leftover fixtures from a previous crashed/--keep run.
 */
function itestpush_purge_leftovers(): void {
    global $DB;

    while ($course = $DB->get_record('course', ['shortname' => ITP_COURSE_SHORT])) {
        cli_writeln("INFO purging leftover course {$course->id} from a previous run");
        delete_course($course->id, false);
    }
    foreach ($DB->get_records('course_categories', ['idnumber' => ITP_CAT_IDN]) as $cat) {
        $category = core_course_category::get($cat->id, IGNORE_MISSING, true);
        if ($category) {
            cli_writeln("INFO purging leftover category {$cat->id} from a previous run");
            $category->delete_full(false);
        }
    }
    // Live fixture users are soft-deleted through the API (fires the events the rest
    // of cleanup relies on). Already-tombstoned ones from a prior run — delete_user
    // renamed them to the email + timestamp, which STILL matches the prefix — are
    // hard-removed: calling delete_user() on an already-deleted row throws
    // "Can't find data record", which is exactly the crash that used to accumulate
    // one dead fixture user per run.
    foreach ($DB->get_records_select('user',
            $DB->sql_like('username', ':u') . ' AND deleted = 0', ['u' => 'itestpush\\_%']) as $u) {
        cli_writeln("INFO purging leftover fixture user {$u->id} from a previous run");
        delete_user($u);
    }
    $DB->delete_records_select('user',
        $DB->sql_like('username', ':u') . ' AND deleted = 1', ['u' => 'itestpush\\_%']);
    $DB->delete_records_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTPUSH%']);
    foreach ([ITP_SCHOOL, ITP_HOLES, ITP_WS] as $sid) {
        if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => $sid])) {
            cli_writeln("INFO purging leftover fixture school {$sid} from a previous run");
            $DB->delete_records('local_syncqueue_course_prefs', ['schoolid' => $sid]);
            $DB->delete_records('local_syncqueue_schools', ['schoolid' => $sid]);
        }
    }
}

$itestpushfailures = [];
$fatal = null;

\core\session\manager::set_user(get_admin());

// ---------------------------------------------------------------------------
// Setup: config snapshot, table high-water marks, epoch/marker snapshot, purge.
// ---------------------------------------------------------------------------

$confignames = ['mode', 'enabled', 'schoolid', 'push_v2', 'pull_v2',
    'reincarnate_required', 'central_restore_detected', 'rostergen', 'ingest_maxretries'];
$savedconfig = [];
foreach ($confignames as $name) {
    $savedconfig[$name] = get_config('local_syncqueue', $name);
}
cli_writeln('INFO saved configs: mode=' . var_export($savedconfig['mode'], true)
    . ' enabled=' . var_export($savedconfig['enabled'], true)
    . ' schoolid=' . var_export($savedconfig['schoolid'], true)
    . ' push_v2=' . var_export($savedconfig['push_v2'], true));

itestpush_purge_leftovers();

$snapshottables = [
    'local_syncqueue_outbox',
    'local_syncqueue_ledger',
    'local_syncqueue_ingest',
    'local_syncqueue_epoch',
    'local_syncqueue_cursor',
    'local_syncqueue_seq',
    'local_syncqueue_applied',
    'local_syncqueue_deadletter',
    'local_syncqueue_items',
    'local_syncqueue_schools',
    'local_syncqueue_course_prefs',
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

// Dataroot epoch marker: snapshot the raw bytes (or null when absent).
$markerpath = epoch_store::marker_path();
$markerbytes = is_readable($markerpath) ? file_get_contents($markerpath) : null;

if ($DB->record_exists_select('local_syncqueue_outbox', 'seq IS NULL')) {
    cli_writeln('INFO WARNING: outbox already holds unsequenced rows at start; the sequencer may stamp them');
}

$fixtureuserid = 0;
$fixturecourseid = 0;
// Grade lineage is scoped by course identity (the fixture course carries
// idnumber ITP_COURSE_IDN, so course_identity() returns it), matching capture's
// course-scoped grade natural key that stops cross-course item-idnumber collision.
$gradelineage = fact_identity::lineage_uuid(ITP_SCHOOL, 'grade',
    fact_identity::natural_key(['course:' . ITP_COURSE_IDN, ITP_ITEM_IDN, ITP_SDMS]));

// ---------------------------------------------------------------------------
// Cleanup (runs even on failure, unless --keep).
// ---------------------------------------------------------------------------

$cleanup = function() use ($DB, $confignames, $savedconfig, $snapshottables, $startmax,
        $epochsnapshot, $cursorsnapshot, $seqsnapshot, $markerpath, $markerbytes) {
    $step = function(string $label, callable $fn) {
        global $itestpushfailures;
        try {
            $fn();
        } catch (Throwable $e) {
            cli_writeln("INFO cleanup step '{$label}' failed: " . $e->getMessage());
            $itestpushfailures[] = 'cleanup';
        }
    };

    // Restore configs first so no observer/task can fire against the remote
    // central while the rest of cleanup runs.
    $step('configs', function() use ($confignames, $savedconfig) {
        foreach ($confignames as $name) {
            itestpush_restore_config($name, $savedconfig[$name]);
        }
    });
    $step('courses', function() use ($DB) {
        while ($course = $DB->get_record('course', ['shortname' => ITP_COURSE_SHORT])) {
            delete_course($course->id, false);
        }
    });
    $step('categories', function() use ($DB) {
        foreach ($DB->get_records('course_categories', ['idnumber' => ITP_CAT_IDN]) as $cat) {
            $category = core_course_category::get($cat->id, IGNORE_MISSING, true);
            if ($category) {
                $category->delete_full(false);
            }
        }
    });
    $step('users', function() use ($DB) {
        foreach ($DB->get_records_select('user', $DB->sql_like('username', ':u'),
                ['u' => 'itestpush\\_%']) as $u) {
            delete_user($u);
        }
        $DB->delete_records_select('elby_sdms_users', $DB->sql_like('sdms_id', ':s'), ['s' => 'ITESTPUSH%']);
    });
    $step('schools', function() use ($DB) {
        foreach ([ITP_SCHOOL, ITP_HOLES, ITP_WS] as $sid) {
            $DB->delete_records('local_syncqueue_course_prefs', ['schoolid' => $sid]);
            $DB->delete_records('local_syncqueue_schools', ['schoolid' => $sid]);
        }
    });
    // Delete every row we appended to the sync tables.
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
};

try {
    // -----------------------------------------------------------------------
    // Fixtures (created with capture disabled so no observer noise is queued).
    // -----------------------------------------------------------------------
    cli_writeln('--- Fixtures ---');
    itestpush_role('school', 0, 0);
    set_config('schoolid', ITP_SCHOOL, 'local_syncqueue');

    $selfepoch = epoch_store::ensure_self()->epoch;
    cli_writeln('INFO self epoch (E0) = ' . $selfepoch);

    $cat = core_course_category::create((object) [
        'name' => 'ITEST Push Category',
        'idnumber' => ITP_CAT_IDN,
        'parent' => 0,
    ]);
    $course = create_course((object) [
        'fullname' => 'ITEST Push Course',
        'shortname' => ITP_COURSE_SHORT,
        'category' => $cat->id,
        'summary' => '',
        'format' => 'topics',
        'visible' => 1,
        'idnumber' => ITP_COURSE_IDN,
    ]);
    $fixturecourseid = (int) $course->id;

    $u = new stdClass();
    $u->username = ITP_USERNAME;
    $u->auth = 'manual';
    $u->confirmed = 1;
    $u->mnethostid = $CFG->mnet_localhost_id;
    $u->email = ITP_USERNAME . '@example.invalid';
    $u->firstname = 'ITest';
    $u->lastname = 'PushStudent';
    $u->idnumber = '';
    $fixtureuserid = (int) user_create_user($u, false, false);

    // elby_sdms_users.schoolid and .sync_status are integer columns; the v2
    // resolution paths key on (userid -> sdms_id) only, so schoolid is left null.
    $DB->insert_record('elby_sdms_users', (object) [
        'userid' => $fixtureuserid,
        'sdms_id' => ITP_SDMS,
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

    // Two manual grade items with stable idnumbers (v2 natural key + central
    // find_grade_item both resolve on idnumber).
    $gi1 = new \grade_item(['courseid' => $fixturecourseid, 'itemtype' => 'manual',
        'itemname' => 'ITEST Push Item 1'], false);
    $gi1->insert();
    $DB->set_field('grade_items', 'idnumber', ITP_ITEM_IDN, ['id' => $gi1->id]);
    $item1id = (int) $gi1->id;

    $gi2 = new \grade_item(['courseid' => $fixturecourseid, 'itemtype' => 'manual',
        'itemname' => 'ITEST Push Item 2'], false);
    $gi2->insert();
    $DB->set_field('grade_items', 'idnumber', ITP_ITEM2_IDN, ['id' => $gi2->id]);
    $item2id = (int) $gi2->id;

    // Register both fixture schools so the central-side auth/state paths accept
    // pushes from this school id.
    $schoolmanager = new school_manager();
    $apikey = $schoolmanager->register_school(ITP_SCHOOL, 'ITEST Push Fixture School');
    $schoolmanager->register_school(ITP_HOLES, 'ITEST Push Holes School');
    $wsapikey = $schoolmanager->register_school(ITP_WS, 'ITEST Push WS School');

    itestpush_check('fixtures_ready',
        $fixtureuserid > 0 && $fixturecourseid > 0 && $item1id > 0 && $item2id > 0
            && $DB->record_exists('elby_sdms_users', ['userid' => $fixtureuserid, 'sdms_id' => ITP_SDMS])
            && (string) $DB->get_field('course', 'idnumber', ['id' => $fixturecourseid]) === ITP_COURSE_IDN
            && (string) $DB->get_field('grade_items', 'idnumber', ['id' => $item1id]) === ITP_ITEM_IDN,
        "user {$fixtureuserid}, course {$fixturecourseid} (idnumber " . ITP_COURSE_IDN . "), items {$item1id}/{$item2id}, SDMS "
            . ITP_SDMS . " linked");

    // =======================================================================
    // Phase 1 — capture (school, push_v2=1): real user_graded event.
    // =======================================================================
    cli_writeln('--- Phase 1: capture ---');
    itestpush_role('school', 1, 1);

    // Hold the sequencer lock so the every-minute cron sequencer cannot stamp
    // the fresh fact between capture and the seq-IS-NULL assertion.
    $lockfactory = \core\lock\lock_config::get_lock_factory('local_syncqueue');
    $seqlock = $lockfactory->get_lock('sequencer', 10);
    if (!$seqlock) {
        cli_writeln('INFO could not take the sequencer lock; cron may race the seq-NULL check');
    }

    $preledger = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_syncqueue_ledger}');
    $preitems = $DB->count_records('local_syncqueue_items');
    $grade1id = itestpush_grade($item1id, $fixtureuserid, 60.0);

    $ledgerrows = $DB->get_records_select('local_syncqueue_ledger',
        'lineageuuid = :lu', ['lu' => $gradelineage], 'id ASC');
    $ledgerrow = $ledgerrows ? reset($ledgerrows) : null;
    itestpush_check('capture_ledger_row',
        $ledgerrow !== null && $ledgerrow->status === fact_ledger::STATUS_CAPTURED
            && $ledgerrow->factversion === null && (int) $ledgerrow->sourceid === $grade1id
            && $ledgerrow->sourcetable === 'grade_grades' && $ledgerrow->origin === ITP_SCHOOL,
        'ledger row ' . ($ledgerrow->id ?? 'MISSING') . ' captured (factversion NULL, source grade_grades:'
            . $grade1id . ', origin ' . ITP_SCHOOL . ')');

    $outboxrows = $DB->get_records_select('local_syncqueue_outbox',
        'entitytype = :t AND lineageuuid = :lu', ['t' => 'grade', 'lu' => $gradelineage], 'id ASC');
    $v1outbox = $outboxrows ? reset($outboxrows) : null;
    itestpush_check('capture_outbox_row',
        $v1outbox !== null && $v1outbox->seq === null && (int) $v1outbox->ledgerid === (int) $ledgerrow->id
            && $v1outbox->partitionkey === 'learner:school:' . ITP_SCHOOL
            && $v1outbox->action === 'upsert' && $v1outbox->factversion === null && $v1outbox->factuuid === null,
        'outbox row ' . ($v1outbox->id ?? 'MISSING') . ' seq NULL, ledgerid linked, partition learner:school:'
            . ITP_SCHOOL . ', action upsert, factversion/factuuid NULL');

    $decoded = $v1outbox ? json_decode((string) $v1outbox->payload, true) : null;
    itestpush_check('capture_payload_canonical',
        $v1outbox !== null
            && $v1outbox->payloadhash === publisher::hash_payload($decoded)
            && $v1outbox->payloadhash === hash('sha256', (string) $v1outbox->payload)
            && $ledgerrow->payloadhash === $v1outbox->payloadhash
            && !isset($decoded['event']['timecreated']) && !isset($decoded['school']['timestamp'])
            && (float) ($decoded['context']['object']['finalgrade'] ?? -1) === 60.0,
        'payloadhash == sha256(stored payload) == hash_payload(decoded) == ledger hash; dispatch clock stripped; object.finalgrade 60');

    $legacydelta = $DB->count_records('local_syncqueue_items') - $preitems;
    itestpush_check('capture_no_legacy_dualsend', $legacydelta === 0,
        "push_v2 grade did NOT also hit the legacy queue (local_syncqueue_items delta {$legacydelta})");

    // SDMS gate: an unlinked user's grade must NOT capture to v2 (legacy owns it).
    $tmpuser = new stdClass();
    $tmpuser->username = 'itestpush_unlinked';
    $tmpuser->auth = 'manual';
    $tmpuser->confirmed = 1;
    $tmpuser->mnethostid = $CFG->mnet_localhost_id;
    $tmpuser->email = 'itestpush_unlinked@example.invalid';
    $tmpuser->firstname = 'ITest';
    $tmpuser->lastname = 'Unlinked';
    $tmpuser->idnumber = '';
    $unlinkedid = (int) user_create_user($tmpuser, false, false);
    $preoutboxall = $DB->count_records('local_syncqueue_outbox');
    $preitems2 = $DB->count_records('local_syncqueue_items');
    itestpush_grade($item1id, $unlinkedid, 42.0);
    itestpush_check('capture_sdms_gate',
        $DB->count_records('local_syncqueue_outbox') === $preoutboxall
            && $DB->count_records('local_syncqueue_items') > $preitems2,
        'grade for an unlinked (no SDMS) user produced no v2 outbox row and fell back to the legacy queue');

    if ($seqlock) {
        $seqlock->release();
    }

    // =======================================================================
    // Phase 2 — sequence + finalize.
    // =======================================================================
    cli_writeln('--- Phase 2: sequence + finalize ---');
    sequencer::assign();

    $v1outbox = $DB->get_record('local_syncqueue_outbox', ['id' => $v1outbox->id], '*', MUST_EXIST);
    $expectedfactuuid = fact_identity::fact_uuid($gradelineage, 1);
    itestpush_check('sequence_finalized_outbox',
        $v1outbox->seq !== null && (int) $v1outbox->factversion === 1
            && $v1outbox->factuuid === $expectedfactuuid && (int) $v1outbox->entityversion === 1,
        'outbox seq ' . $v1outbox->seq . ', factversion 1, factuuid ' . $v1outbox->factuuid
            . ' == fact_uuid(lineage,1), entityversion 1');

    $ledgerrow = $DB->get_record('local_syncqueue_ledger', ['id' => $ledgerrow->id], '*', MUST_EXIST);
    itestpush_check('sequence_finalized_ledger',
        (int) $ledgerrow->factversion === 1 && $ledgerrow->factuuid === $expectedfactuuid
            && $ledgerrow->status === fact_ledger::STATUS_EXPORTED
            && (int) $ledgerrow->lastexportedseq === (int) $v1outbox->seq,
        'ledger finalized factversion 1, factuuid stamped, status exported, lastexportedseq ' . $v1outbox->seq);
    $v1seq = (int) $v1outbox->seq;
    $v1factuuid = $v1outbox->factuuid;

    // =======================================================================
    // Phase 3 — push (retained-until-ack) via the school push loop.
    // =======================================================================
    cli_writeln('--- Phase 3: push + ack + prune ---');
    itestpush_role('school', 1, 1);

    // This dev box is a school whose GLOBAL outbox seq counter is pre-advanced by
    // unrelated rows, so the fixture fact lands at a high seq. A real fresh v2
    // school's learner facts are dense from 1 (its outbox holds only facts).
    // Seed central's per-school ack baseline to just below the first pushed fact
    // (the v2-cutover semantics) so the contiguous frontier can advance.
    cursor::advance(ITP_SCHOOL, 'up', $v1seq - 1);

    $stream = new itestpush_push_stream();
    $stream->execute();
    $resp3 = $stream->last_response();

    $v1ingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v1factuuid]);
    itestpush_check('push_ingest_buffered',
        $v1ingest !== null && $v1ingest->status === 'buffered' && (int) $v1ingest->schoolseq === $v1seq
            && (int) $v1ingest->factversion === 1 && $v1ingest->schoolid === ITP_SCHOOL
            && $v1ingest->epoch === $selfepoch && $v1ingest->lineageuuid === $gradelineage,
        'ingest row buffered (schoolseq ' . $v1seq . ', factversion 1, epoch matches self, lineage matches)');

    itestpush_check('push_acked_through',
        $resp3 !== null && (int) $resp3->acked_through === $v1seq && in_array($v1seq, $resp3->stored, true)
            && (int) $resp3->protocol_version === 2 && $resp3->reincarnate_required === false,
        'response acked_through ' . ($resp3->acked_through ?? '-') . ' == pushed seq ' . $v1seq
            . ', stored contains it, protocol 2, no reincarnate');

    $prunedgone = !$DB->record_exists('local_syncqueue_outbox', ['id' => $v1outbox->id]);
    $ledgerrow = $DB->get_record('local_syncqueue_ledger', ['id' => $ledgerrow->id], '*', MUST_EXIST);
    itestpush_check('push_pruned_and_acked',
        $prunedgone && $ledgerrow->status === fact_ledger::STATUS_ACKED
            && (int) cursor::get('central', 'up') === $v1seq,
        'acked outbox row pruned (retained-until-ack), ledger status acked, school ack cursor at ' . $v1seq);

    // =======================================================================
    // Phase 3b — the push external function (REST-facing endpoint) guards.
    // Isolated fixture school + epoch so it never perturbs the E0 grade flow.
    // =======================================================================
    cli_writeln('--- Phase 3b: push WS wrapper ---');
    $wslineage = fact_identity::lineage_uuid(ITP_WS, 'grade',
        fact_identity::natural_key(['wsitem', 'WSSDMS']));
    $wspayload = ['probe' => 'ws-fact'];
    $wsfactuuid = fact_identity::fact_uuid($wslineage, 1);
    $wsitem = [
        'school_seq' => 1,
        'factuuid' => $wsfactuuid,
        'lineageuuid' => $wslineage,
        'factversion' => 1,
        'facttype' => 'grade',
        'action' => 'upsert',
        'entitykey' => $wslineage,
        'payload' => publisher::canonical_json($wspayload),
        'payloadhash' => publisher::hash_payload($wspayload),
        'rostergen' => null,
        'kind' => 'fact',
        'reason' => '',
    ];
    $wsepoch = 'itestpush-ws-epoch';
    $wsjson = json_encode([$wsitem]);

    // Mode gate: the endpoint refuses a push unless this box is central.
    $modegate = false;
    try {
        push::execute(ITP_WS, $wsapikey, 2, $wsepoch, 1, $wsjson);
    } catch (moodle_exception $e) {
        $modegate = ($e->errorcode === 'error_notcentral');
    }
    itestpush_check('ws_mode_gate', $modegate,
        'push::execute in school mode raised error_notcentral (dual-stack: central-only endpoint)');

    itestpush_role('central', 1, 0);

    $protocolreject = false;
    try {
        push::execute(ITP_WS, $wsapikey, 1, $wsepoch, 1, $wsjson);
    } catch (invalid_parameter_exception $e) {
        $protocolreject = true;
    }
    itestpush_check('ws_protocol_rejected', $protocolreject,
        'push::execute with protocol_version=1 raised invalid_parameter_exception');

    $authreject = false;
    try {
        push::execute(ITP_WS, str_repeat('0', 64), 2, $wsepoch, 1, '[]');
    } catch (moodle_exception $e) {
        $authreject = ($e->errorcode === 'error_authfailed');
    }
    itestpush_check('ws_auth_rejected', $authreject, 'push::execute with a wrong apikey raised error_authfailed');

    $wsresp = push::execute(ITP_WS, $wsapikey, 2, $wsepoch, 1, $wsjson);
    $wsbuffered = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $wsfactuuid]);
    itestpush_check('ws_push_buffers',
        (int) ($wsresp['protocol_version'] ?? 0) === 2 && ($wsresp['status'] ?? '') === 'ok'
            && (int) ($wsresp['acked_through'] ?? -1) === 1 && !empty($wsresp['central_epoch'])
            && $wsbuffered !== null && $wsbuffered->status === 'buffered' && $wsbuffered->epoch === $wsepoch,
        'valid push::execute buffered the fact (acked_through 1, status ok, protocol 2, central_epoch echoed)');

    // =======================================================================
    // Phase 4 — apply (async ingest, central).
    // =======================================================================
    cli_writeln('--- Phase 4: apply ---');
    itestpush_role('central', 1, 0);

    // Single-site: the school's grade_grades row IS central's, so the step-0
    // applier's wall-clock LWW (schooltime 0 after the v2 dispatch-clock strip)
    // would false-'conflict' any UPDATE to an existing grade. Neutralize it the
    // way step 4's applier rework will (timemodified 0) and set a sentinel so a
    // real write is provable, exactly as the pull spike deleted the central-side
    // course to simulate central not yet holding it.
    $DB->set_field('grade_grades', 'timemodified', 0, ['id' => $grade1id]);
    $DB->set_field('grade_grades', 'finalgrade', 0, ['id' => $grade1id]);

    (new apply_ingest())->execute();

    $v1ingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v1factuuid], '*', MUST_EXIST);
    $appliedgrade = (float) $DB->get_field('grade_grades', 'finalgrade', ['id' => $grade1id]);
    itestpush_check('apply_grade_applied',
        $v1ingest->status === 'applied' && $appliedgrade === 60.0 && $v1ingest->lasterror === null,
        'ingest status applied, central grade_grades.finalgrade restored from sentinel to payload value 60');

    // Idempotent re-run: a terminal (applied) row is never re-selected -> no-op.
    $beforegrade = (float) $DB->get_field('grade_grades', 'finalgrade', ['id' => $grade1id]);
    (new apply_ingest())->execute();
    $v1ingest2 = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v1factuuid], '*', MUST_EXIST);
    itestpush_check('apply_idempotent_rerun',
        $v1ingest2->status === 'applied' && (int) $v1ingest2->attempts === (int) $v1ingest->attempts
            && (float) $DB->get_field('grade_grades', 'finalgrade', ['id' => $grade1id]) === $beforegrade,
        'second apply_ingest run is a no-op (status/attempts/grade unchanged)');

    // =======================================================================
    // Phase 5 — idempotence / dedup: re-push the same fact.
    // =======================================================================
    cli_writeln('--- Phase 5: dedup (benign replay) ---');
    $preingestcount = $DB->count_records('local_syncqueue_ingest');
    // Rebuild the v1 wire item from the durable ingest row and re-submit it.
    $replayitem = [
        'school_seq' => (int) $v1ingest->schoolseq,
        'factuuid' => $v1ingest->factuuid,
        'lineageuuid' => $v1ingest->lineageuuid,
        'factversion' => (int) $v1ingest->factversion,
        'facttype' => $v1ingest->facttype,
        'action' => 'upsert',
        'entitykey' => $v1ingest->entitykey,
        'payload' => $v1ingest->payload,
        'payloadhash' => $v1ingest->payloadhash,
        'rostergen' => null,
        'kind' => 'fact',
        'reason' => '',
    ];
    $replay = ingest_manager::receive_push(ITP_SCHOOL, $selfepoch, $v1seq, [$replayitem]);
    itestpush_check('dedup_benign_replay',
        $DB->count_records('local_syncqueue_ingest') === $preingestcount
            && in_array($v1seq, array_map('intval', $replay['stored']), true)
            && (int) $replay['acked_through'] === $v1seq && empty($replay['forks']),
        'same factuuid+payloadhash re-push added no ingest row, reported stored, acked_through stable at ' . $v1seq
            . ', no forks');

    // =======================================================================
    // Phase 6 — supersession: change the grade, re-capture -> factversion 2.
    // =======================================================================
    cli_writeln('--- Phase 6: supersession ---');
    itestpush_role('school', 1, 1);
    $grade1id = itestpush_grade($item1id, $fixtureuserid, 85.0);

    sequencer::assign();
    $v2factuuid = fact_identity::fact_uuid($gradelineage, 2);
    $v2outbox = $DB->get_record('local_syncqueue_outbox', ['factuuid' => $v2factuuid], '*', MUST_EXIST);
    itestpush_check('supersession_factversion2',
        (int) $v2outbox->factversion === 2 && $v2outbox->factuuid === $v2factuuid
            && $v2outbox->factuuid !== $v1factuuid && (int) $v2outbox->seq > $v1seq,
        'superseding capture is factversion 2 (new factuuid in the SAME lineage), seq ' . (int) $v2outbox->seq);
    $v2seq = (int) $v2outbox->seq;

    // Push v2.
    $stream6 = new itestpush_push_stream();
    $stream6->execute();
    $resp6 = $stream6->last_response();
    $v2ingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v2factuuid]);
    itestpush_check('supersession_pushed',
        $v2ingest !== null && $v2ingest->status === 'buffered' && (int) $v2ingest->factversion === 2
            && $resp6 !== null && (int) $resp6->acked_through === $v2seq
            && !$DB->record_exists('local_syncqueue_outbox', ['id' => $v2outbox->id]),
        'v2 buffered (factversion 2), acked_through ' . ($resp6->acked_through ?? '-') . ', v2 outbox pruned');

    // Apply v2 -> central reflects v2 (85), superseding v1.
    itestpush_role('central', 1, 0);
    $DB->set_field('grade_grades', 'timemodified', 0, ['id' => $grade1id]);
    $DB->set_field('grade_grades', 'finalgrade', 0, ['id' => $grade1id]);
    (new apply_ingest())->execute();
    $v2ingest = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v2factuuid], '*', MUST_EXIST);
    itestpush_check('supersession_applied_v2',
        $v2ingest->status === 'applied'
            && (float) $DB->get_field('grade_grades', 'finalgrade', ['id' => $grade1id]) === 85.0,
        'v2 applied; central grade_grades.finalgrade now 85 (from sentinel 0)');

    // v1 must NOT be reapplied: re-buffer it; the AGS/factversion staleness guard
    // settles it 'stale' without touching the grade.
    $DB->set_field('local_syncqueue_ingest', 'status', 'buffered', ['factuuid' => $v1factuuid]);
    (new apply_ingest())->execute();
    $v1ingest3 = $DB->get_record('local_syncqueue_ingest', ['factuuid' => $v1factuuid], '*', MUST_EXIST);
    itestpush_check('supersession_v1_not_reapplied',
        $v1ingest3->status === 'stale'
            && (float) $DB->get_field('grade_grades', 'finalgrade', ['id' => $grade1id]) === 85.0,
        'older factversion 1 re-buffered settles stale (a higher applied version exists); grade stays 85, never clobbered');

    // =======================================================================
    // Phase 7 — hole + contiguous ack (isolated fixture school).
    // =======================================================================
    cli_writeln('--- Phase 7: hole + contiguous ack ---');
    $holesepoch = 'itestpush-holes-epoch';
    $holefloor = cursor::get(ITP_HOLES, 'up');
    $factseq = $holefloor + 2;
    $holeseq = $holefloor + 1;

    // A fact arriving at floor+2 with a gap at floor+1: acked frontier cannot cross.
    $syntheticlineage = fact_identity::lineage_uuid(ITP_HOLES, 'grade',
        fact_identity::natural_key(['holeitem', 'HOLESDMS']));
    $syntheticpayload = ['probe' => 'hole-fact', 'seq' => $factseq];
    $factitem = [
        'school_seq' => $factseq,
        'factuuid' => fact_identity::fact_uuid($syntheticlineage, 1),
        'lineageuuid' => $syntheticlineage,
        'factversion' => 1,
        'facttype' => 'grade',
        'action' => 'upsert',
        'entitykey' => $syntheticlineage,
        'payload' => publisher::canonical_json($syntheticpayload),
        'payloadhash' => publisher::hash_payload($syntheticpayload),
        'rostergen' => null,
        'kind' => 'fact',
        'reason' => '',
    ];
    $gapresp = ingest_manager::receive_push(ITP_HOLES, $holesepoch, $factseq, [$factitem]);
    itestpush_check('hole_gap_holds_frontier',
        (int) $gapresp['acked_through'] === $holefloor
            && in_array($factseq, array_map('intval', $gapresp['stored']), true),
        'fact at seq ' . $factseq . ' stored but acked_through stays ' . $holefloor . ' (gap at ' . $holeseq . ')');

    // A hole record fills the gap: the frontier crosses it.
    $holeitem = [
        'school_seq' => $holeseq,
        'factuuid' => '',
        'lineageuuid' => '',
        'factversion' => 0,
        'facttype' => 'grade',
        'action' => 'upsert',
        'entitykey' => '',
        'payload' => null,
        'payloadhash' => '',
        'rostergen' => null,
        'kind' => 'hole',
        'reason' => 'itest oversized/dead-lettered',
    ];
    $holeresp = ingest_manager::receive_push(ITP_HOLES, $holesepoch, $factseq, [$holeitem]);
    $holerow = $DB->get_record('local_syncqueue_ingest',
        ['schoolid' => ITP_HOLES, 'epoch' => $holesepoch, 'schoolseq' => $holeseq]);
    itestpush_check('hole_crosses_frontier',
        (int) $holeresp['acked_through'] === $factseq
            && $holerow !== null && $holerow->status === 'dead',
        'hole dead-marker at seq ' . $holeseq . ' lets acked_through cross to ' . $factseq . ' (dead-marker stored)');

    // =======================================================================
    // Phase 7b — benign replay at a NEW school_seq must not wedge the frontier.
    // A fact re-exported under a fresh school_seq (anti-entropy regeneration, a
    // reincarnation requeue, or a concurrent duplicate-share) collides on
    // factuuid, so no content row is inserted for the new slot. Without a marker
    // that slot stays empty forever and acked_through can never cross it; the fix
    // drops a terminal dedup marker so the contiguous frontier advances.
    // =======================================================================
    cli_writeln('--- Phase 7b: dedup replay at a new school_seq crosses the frontier ---');
    $dseq = $factseq + 1;   // fact D, contiguous after Phase 7's fact.
    $dupseq = $dseq + 1;    // the SAME fact D re-exported at a fresh seq.
    $fseq = $dseq + 2;      // a genuinely new fact F after the replay.

    $dlineage = fact_identity::lineage_uuid(ITP_HOLES, 'grade',
        fact_identity::natural_key(['dedupitem', 'DEDUPSDMS']));
    $dpayload = ['probe' => 'dedup-fact', 'seq' => $dseq];
    $dfactuuid = fact_identity::fact_uuid($dlineage, 1);
    $ditem = [
        'school_seq' => $dseq,
        'factuuid' => $dfactuuid,
        'lineageuuid' => $dlineage,
        'factversion' => 1,
        'facttype' => 'grade',
        'action' => 'upsert',
        'entitykey' => $dlineage,
        'payload' => publisher::canonical_json($dpayload),
        'payloadhash' => publisher::hash_payload($dpayload),
        'rostergen' => null,
        'kind' => 'fact',
        'reason' => '',
    ];
    $dresp = ingest_manager::receive_push(ITP_HOLES, $holesepoch, $dseq, [$ditem]);
    itestpush_check('dedup_newseq_baseline',
        (int) $dresp['acked_through'] === $dseq
            && $DB->count_records('local_syncqueue_ingest', ['factuuid' => $dfactuuid]) === 1,
        'fact D stored at seq ' . $dseq . ', acked_through ' . ($dresp['acked_through'] ?? '-'));

    // Re-export D at a fresh seq (same factuuid+payloadhash), then a new fact F.
    $dupitem = array_merge($ditem, ['school_seq' => $dupseq]);
    $flineage = fact_identity::lineage_uuid(ITP_HOLES, 'grade',
        fact_identity::natural_key(['dedupitem2', 'DEDUPSDMS2']));
    $fpayload = ['probe' => 'dedup-after', 'seq' => $fseq];
    $ffactuuid = fact_identity::fact_uuid($flineage, 1);
    $fitem = [
        'school_seq' => $fseq,
        'factuuid' => $ffactuuid,
        'lineageuuid' => $flineage,
        'factversion' => 1,
        'facttype' => 'grade',
        'action' => 'upsert',
        'entitykey' => $flineage,
        'payload' => publisher::canonical_json($fpayload),
        'payloadhash' => publisher::hash_payload($fpayload),
        'rostergen' => null,
        'kind' => 'fact',
        'reason' => '',
    ];
    $predupcount = $DB->count_records('local_syncqueue_ingest', ['factuuid' => $dfactuuid]);
    $dupresp = ingest_manager::receive_push(ITP_HOLES, $holesepoch, $fseq, [$dupitem, $fitem]);
    $markerrow = $DB->get_record('local_syncqueue_ingest',
        ['schoolid' => ITP_HOLES, 'epoch' => $holesepoch, 'schoolseq' => $dupseq]);
    itestpush_check('dedup_newseq_crosses_frontier',
        (int) $dupresp['acked_through'] === $fseq
            && $markerrow !== null && $markerrow->status === 'dead' && $markerrow->facttype === 'dedup'
            && $markerrow->factuuid !== $dfactuuid
            && $DB->count_records('local_syncqueue_ingest', ['factuuid' => $dfactuuid]) === $predupcount,
        'benign replay of D at new seq ' . $dupseq . ' dropped a dead dedup marker (not a 2nd content row); '
            . 'acked_through crossed to ' . ($dupresp['acked_through'] ?? '-') . ' (fact F at ' . $fseq . ')');

    // =======================================================================
    // Phase 8 — marker mismatch (self re-incarnation signal).
    // =======================================================================
    cli_writeln('--- Phase 8: dataroot marker mismatch ---');
    itestpush_check('marker_ok_before', epoch_guard::check_self() === 'ok',
        'check_self() is ok while marker epoch matches the DB self epoch');
    // Corrupt the marker to a foreign epoch (a DB restored onto a foreign dataroot).
    epoch_store::write_marker('00000000-dead-4000-8000-000000000000', 99);
    itestpush_check('marker_mismatch_detected', epoch_guard::check_self() === 'reincarnate',
        'check_self() returns reincarnate when the dataroot marker epoch != DB self epoch');
    // Restore the real marker immediately.
    epoch_store::write_marker($selfepoch, (int) epoch_store::ensure_self()->bootcount);
    itestpush_check('marker_restored', epoch_guard::check_self() === 'ok',
        'real marker restored; check_self() ok again');

    // =======================================================================
    // Phase 9 — dual-stack: push_v2=0 keeps grades on the legacy queue.
    // =======================================================================
    cli_writeln('--- Phase 9: dual-stack (push_v2=0) ---');
    itestpush_role('school', 1, 0);
    $preitemslegacy = $DB->count_records('local_syncqueue_items');
    $preoutboxlegacy = $DB->count_records('local_syncqueue_outbox');
    $preledgerlegacy = $DB->count_records('local_syncqueue_ledger');
    $preingestlegacy = $DB->count_records('local_syncqueue_ingest');
    itestpush_grade($item2id, $fixtureuserid, 70.0);
    itestpush_check('dualstack_legacy_only',
        $DB->count_records('local_syncqueue_items') === $preitemslegacy + 1
            && $DB->count_records('local_syncqueue_outbox') === $preoutboxlegacy
            && $DB->count_records('local_syncqueue_ledger') === $preledgerlegacy
            && $DB->count_records('local_syncqueue_ingest') === $preingestlegacy,
        'push_v2=0 grade went to legacy local_syncqueue_items (+1) with ZERO outbox/ledger/ingest side effects');

    // =======================================================================
    // Phase 10 — incarnation fork + re-incarnation handshake.
    // =======================================================================
    cli_writeln('--- Phase 10: fork + re-incarnation ---');
    itestpush_role('school', 1, 1);

    // A fresh, un-acked fact (v3) so the handshake has something to re-queue.
    $grade1id = itestpush_grade($item1id, $fixtureuserid, 95.0);
    sequencer::assign();
    $v3factuuid = fact_identity::fact_uuid($gradelineage, 3);
    $v3outbox = $DB->get_record('local_syncqueue_outbox', ['factuuid' => $v3factuuid], '*', MUST_EXIST);
    $v3seq = (int) $v3outbox->seq;

    // Pre-inject a DIFFERENT fact already occupying (school, E0, v3seq): a clone /
    // rolled-back-snapshot reused this school_seq for other content.
    $boguslineage = fact_identity::lineage_uuid(ITP_SCHOOL, 'grade',
        fact_identity::natural_key(['bogusitem', 'BOGUSSDMS']));
    $DB->insert_record('local_syncqueue_ingest', (object) [
        'schoolid' => ITP_SCHOOL,
        'epoch' => $selfepoch,
        'schoolseq' => $v3seq,
        'factuuid' => fact_identity::fact_uuid($boguslineage, 1),
        'lineageuuid' => $boguslineage,
        'factversion' => 1,
        'facttype' => 'grade',
        'entitykey' => $boguslineage,
        'payload' => '{"bogus":true}',
        'payloadhash' => hash('sha256', 'itestpush-bogus'),
        'rostergen' => null,
        'status' => 'dead',
        'attempts' => 0,
        'lasterror' => null,
        'timecreated' => time(),
        'timemodified' => time(),
    ]);

    // Push v3 into the occupied slot -> incarnation fork -> reincarnate_required.
    $forkresp = ingest_manager::receive_push(ITP_SCHOOL, $selfepoch, $v3seq,
        [itestpush_row_to_item($v3outbox)]);
    $forkincarnation = false;
    foreach ($forkresp['forks'] as $fork) {
        if (($fork['tier'] ?? '') === 'incarnation' && (int) $fork['school_seq'] === $v3seq) {
            $forkincarnation = true;
        }
    }
    itestpush_check('fork_incarnation_detected',
        !empty($forkresp['reincarnate_required']) && $forkincarnation
            && !$DB->record_exists('local_syncqueue_ingest', ['factuuid' => $v3factuuid]),
        'slot collision with a different payload -> reincarnate_required + incarnation-tier fork; v3 NOT buffered');

    // Run the handshake with the in-process client seam (no HTTP).
    itestpush_epoch_guard::$client = new itestpush_fake_client();
    $priorbootcount = (int) epoch_store::ensure_self()->bootcount;
    set_config('reincarnate_required', 1, 'local_syncqueue');
    itestpush_epoch_guard::run_reincarnation_handshake();

    $newself = epoch_store::ensure_self();
    $reseededcounter = (int) $DB->get_field('local_syncqueue_seq', 'value', ['name' => sequencer::COUNTER]);
    $v3requeued = $DB->get_record('local_syncqueue_outbox', ['id' => $v3outbox->id]);
    $schoolhw = epoch_store::get(epoch_store::SCOPE_SCHOOL, ITP_SCHOOL);
    // seed_seq = max(ingest high-water, SCOPE_SCHOOL head high-water) + 1.
    $expectedseed = max((int) $DB->get_field_sql(
        'SELECT MAX(schoolseq) FROM {local_syncqueue_ingest} WHERE schoolid = :s', ['s' => ITP_SCHOOL]),
        (int) ($schoolhw->headseq ?? 0)) + 1;
    itestpush_check('reincarnation_adopted',
        $newself->epoch !== $selfepoch && (int) $newself->bootcount === $priorbootcount + 1
            && get_config('local_syncqueue', 'reincarnate_required') === false
            && $reseededcounter >= $expectedseed - 1,
        'school adopted a NEW self epoch (was E0), bootcount +1, reincarnate_required cleared, seq counter reseeded to '
            . $reseededcounter . ' (>= seed-1 ' . ($expectedseed - 1) . ')');

    itestpush_check('reincarnation_requeued_unacked',
        $v3requeued !== null && $v3requeued->seq === null && $v3requeued->factversion === null
            && $v3requeued->factuuid === null && (int) $v3requeued->ledgerid === (int) $v3outbox->ledgerid,
        'un-acked v3 outbox row re-queued (seq/factversion/factuuid cleared, ledgerid kept) for replay under the new epoch');

    // Re-push under the new epoch: the school loop re-sequences and pushes v3,
    // which now lands in a fresh slot and is acked.
    $newepoch = $newself->epoch;
    $stream10 = new itestpush_push_stream();
    $stream10->execute();
    $resp10 = $stream10->last_response();
    $v3ingest = $DB->get_record('local_syncqueue_ingest',
        ['factuuid' => $v3factuuid, 'epoch' => $newepoch]);
    $v3repushedexists = $DB->record_exists_select('local_syncqueue_outbox',
        'lineageuuid = :lu AND seq IS NOT NULL', ['lu' => $gradelineage]);
    itestpush_check('reincarnation_repush_succeeds',
        $v3ingest !== null && $v3ingest->status === 'buffered' && (int) $v3ingest->factversion === 3
            && $resp10 !== null && $resp10->reincarnate_required === false
            && in_array((int) $v3ingest->schoolseq, $resp10->stored, true)
            && (int) $resp10->acked_through >= (int) $v3ingest->schoolseq
            && !$v3repushedexists,
        're-push under new epoch ' . substr($newepoch, 0, 8) . ' stored v3 (factversion 3) at seq '
            . ($v3ingest->schoolseq ?? '-') . ', acked_through ' . ($resp10->acked_through ?? '-')
            . ', outbox drained (retained rows all acked)');
} catch (Throwable $e) {
    $fatal = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal);
    $itestpushfailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup and restoration proof.
// ---------------------------------------------------------------------------
if ($options['keep']) {
    cli_writeln('INFO --keep set: leaving fixtures, configs, epoch and table rows in place');
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
    if (!$self || $self->epoch !== $selfepoch) {
        $restored = false;
        $detail[] = 'self epoch not restored';
    }
    if (epoch_store::marker_status() !== 'ok') {
        $restored = false;
        $detail[] = 'dataroot marker not restored (' . epoch_store::marker_status() . ')';
    }
    if ($DB->record_exists('course', ['shortname' => ITP_COURSE_SHORT])
            || $DB->record_exists('local_syncqueue_schools', ['schoolid' => ITP_SCHOOL])
            || $DB->record_exists_select('user', $DB->sql_like('username', ':u') . ' AND deleted = 0',
                ['u' => 'itestpush\\_%'])) {
        $restored = false;
        $detail[] = 'fixtures remain';
    }
    itestpush_check('cleanup_restored', $restored,
        $restored ? 'all sync tables, fixtures, configs, self epoch and dataroot marker back to their starting state'
            : implode('; ', $detail));
}

if (empty($itestpushfailures)) {
    cli_writeln('SPIKE RESULT: PASS - full single-site upstream loop verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($itestpushfailures))
    . ($fatal ? " ({$fatal})" : ''));
exit(1);
