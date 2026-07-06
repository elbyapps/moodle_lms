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
 * End-to-end integration test for ELMS Sync v2 step 1 (downstream sequenced outbox).
 *
 * Single-site both-roles test: this instance plays central (capture, sequence,
 * pull endpoint, cutover republish) and school (pull_stream apply loop,
 * adoption, DLQ) by flipping local_syncqueue/mode around each role's calls
 * (always restored). Covers: transactional capture dual-write, dense post-commit
 * sequencing, pull response shape / subscription filtering / read-time
 * supersession, apply-then-checkpoint, idempotent re-apply, legacy adoption,
 * deadletter retry->dead, and the publish_school_state cutover snapshot.
 *
 * Disposable fixtures (prefix itestv2); re-runnable; cleans up even on failure.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_syncqueue\adoption;
use local_syncqueue\external\pull;
use local_syncqueue\id_mapper;
use local_syncqueue\outbox\applied_state;
use local_syncqueue\outbox\cursor;
use local_syncqueue\outbox\publisher;
use local_syncqueue\outbox\sequencer;
use local_syncqueue\school_manager;
use local_syncqueue\task\publish_school_state;
use local_syncqueue\task\pull_stream;
use local_syncqueue\update_manager;
use local_syncqueue\update_processor;

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
    cli_writeln("ELMS Sync v2 step 1 downstream integration test (single-site both-roles).

Creates disposable itestv2 fixtures (category, courses, a registered school),
drives the full capture -> sequence -> pull -> apply -> adopt -> DLQ ->
republish loop and asserts every step. Flips local_syncqueue mode/enabled/
pull_v2 configs during the run and restores them; cleans all fixtures and all
rows it added to the v2/legacy sync tables, even on failure.

Options:
  -h, --help    Print this help.
  --keep        Skip cleanup (leaves fixtures, configs and table rows in place).

Example:
  php local/syncqueue/cli/spikes/integration_pull_v2.php");
    exit(0);
}

/**
 * Test double: pull_stream with the HTTP transport replaced by a direct call
 * to the central pull endpoint on this same site. Flips mode/enabled to
 * central for the duration of the endpoint call only (the loop itself runs
 * with the school-role configs), and exposes the protected loop internals the
 * idempotence/adoption/DLQ phases feed rows through.
 */
class itestv2_pull_stream extends pull_stream {

    /** @var string Fixture school id used for the direct endpoint call. */
    public string $itestschoolid = '';

    /** @var string Fixture school API key. */
    public string $itestapikey = '';

    /**
     * Fetch a batch by calling the external function directly as the school.
     *
     * @param int $afterseq Return rows with seq greater than this.
     * @return \stdClass Normalized pull response.
     */
    protected function pull_batch(int $afterseq): \stdClass {
        $prevmode = get_config('local_syncqueue', 'mode');
        $prevenabled = get_config('local_syncqueue', 'enabled');
        set_config('mode', 'central', 'local_syncqueue');
        set_config('enabled', 1, 'local_syncqueue');
        try {
            $response = pull::execute($this->itestschoolid, $this->itestapikey,
                $afterseq, self::BATCH_LIMIT, 2);
        } finally {
            itestv2_restore_config('mode', $prevmode);
            itestv2_restore_config('enabled', $prevenabled);
        }
        return itestv2_normalize_response($response);
    }

    /**
     * Public wrapper over process_row for direct row feeding.
     *
     * @param update_processor $processor
     * @param \stdClass $row Normalized stream row.
     * @return string 'applied', 'stale' or 'failed'.
     */
    public function itest_process_row(update_processor $processor, \stdClass $row): string {
        return $this->process_row($processor, $row);
    }

    /**
     * Public wrapper over the deadletter replay pass.
     *
     * @param update_processor $processor
     */
    public function itest_replay(update_processor $processor): void {
        $this->replay_deadletters($processor);
    }
}

/**
 * Print one evidence line.
 *
 * @param string $name Check name.
 * @param bool $ok Whether the check passed.
 * @param string $detail Evidence detail.
 */
function itestv2_check(string $name, bool $ok, string $detail): void {
    global $itestv2failures;
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
    if (!$ok) {
        $itestv2failures[] = $name;
    }
}

/**
 * Restore a local_syncqueue config value saved with get_config (false = unset).
 *
 * @param string $name Config name.
 * @param mixed $value Saved value (false when it was unset).
 */
function itestv2_restore_config(string $name, $value): void {
    if ($value === false) {
        unset_config($name, 'local_syncqueue');
    } else {
        set_config($name, $value, 'local_syncqueue');
    }
}

/**
 * Normalize a direct pull::execute() array response into the stdClass shape
 * sync_client::pull() hands the pull_stream loop.
 *
 * @param array $response Raw external function return value.
 * @return \stdClass
 */
function itestv2_normalize_response(array $response): \stdClass {
    $rows = [];
    foreach ($response['rows'] as $raw) {
        $raw = (array) $raw;
        $payload = $raw['payload'] ?? null;
        if ($payload === '') {
            $payload = null;
        }
        $rows[] = (object) [
            'seq' => (int) $raw['seq'],
            'entitytype' => (string) $raw['entitytype'],
            'entitykey' => (string) $raw['entitykey'],
            'entityversion' => (int) $raw['entityversion'],
            'action' => (string) $raw['action'],
            'payload' => $payload,
            'payloadhash' => (string) $raw['payloadhash'],
            'contentversion' => isset($raw['contentversion']) ? (int) $raw['contentversion'] : null,
            'partitionkey' => (string) $raw['partitionkey'],
        ];
    }
    return (object) [
        'protocol_version' => (int) $response['protocol_version'],
        'head_seq' => (int) $response['head_seq'],
        'min_retained_seq' => (int) $response['min_retained_seq'],
        'advance_to' => (int) $response['advance_to'],
        'rows' => $rows,
    ];
}

/**
 * Delete leftover fixtures from a previous crashed/--keep run.
 */
function itestv2_purge_leftovers(): void {
    global $DB;

    foreach (['itestv2_course_a', 'itestv2_course_b', 'itestv2_course_c'] as $shortname) {
        while ($course = $DB->get_record('course', ['shortname' => $shortname])) {
            cli_writeln("INFO purging leftover course {$course->id} ({$shortname}) from a previous run");
            delete_course($course->id, false);
        }
    }
    foreach ($DB->get_records('course_categories', ['idnumber' => 'itestv2_cat']) as $cat) {
        cli_writeln("INFO purging leftover category {$cat->id} from a previous run");
        $category = core_course_category::get($cat->id, IGNORE_MISSING, true);
        if ($category) {
            $category->delete_full(false);
        }
    }
    if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => 'itestv2school'])) {
        cli_writeln('INFO purging leftover fixture school from a previous run');
        $DB->delete_records('local_syncqueue_course_prefs', ['schoolid' => 'itestv2school']);
        $DB->delete_records('local_syncqueue_schools', ['schoolid' => 'itestv2school']);
    }
    // Poison rows and their registry/deadletter traces are unambiguous.
    $DB->delete_records('local_syncqueue_outbox', ['entitytype' => 'itestbogus']);
    $DB->delete_records('local_syncqueue_applied', ['entitytype' => 'itestbogus']);
    $DB->delete_records('local_syncqueue_deadletter', ['entitytype' => 'itestbogus']);
}

$itestv2failures = [];
$fatal = null;

\core\session\manager::set_user(get_admin());

// ---------------------------------------------------------------------------
// Setup: config snapshot, table high-water marks, leftover purge, fixtures.
// ---------------------------------------------------------------------------

$savedconfig = [];
// pull_stream now calls central_restore::observe_head(), which persists central_head_seq
// (and can flag central_restore_required); save/restore those too so this spike leaves no
// residue from the head-observation side-effect (step 6 part 5 wiring).
foreach (['mode', 'enabled', 'pull_v2',
        'central_head_seq', 'central_restore_required', 'central_restore_detected'] as $name) {
    $savedconfig[$name] = get_config('local_syncqueue', $name);
}
cli_writeln('INFO saved configs: mode=' . var_export($savedconfig['mode'], true)
    . ' enabled=' . var_export($savedconfig['enabled'], true)
    . ' pull_v2=' . var_export($savedconfig['pull_v2'], true));

itestv2_purge_leftovers();

$snapshottables = [
    'local_syncqueue_outbox',
    'local_syncqueue_seq',
    'local_syncqueue_cursor',
    'local_syncqueue_applied',
    'local_syncqueue_deadletter',
    'local_syncqueue_updates',
    'local_syncqueue_school_updates',
    'local_syncqueue_idmap',
    'task_adhoc',
];
$startmax = [];
$startcount = [];
foreach ($snapshottables as $table) {
    $startmax[$table] = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {' . $table . '}');
    $startcount[$table] = $DB->count_records($table);
}
$precursor = $DB->get_record('local_syncqueue_cursor', ['peer' => 'central', 'direction' => 'down']);
$precounter = $DB->get_record('local_syncqueue_seq', ['name' => 'outbox']);
$presynccat = $DB->record_exists('course_categories', ['idnumber' => 'syncqueue_courses']);
if ($DB->record_exists_select('local_syncqueue_outbox', 'seq IS NULL')) {
    cli_writeln('INFO WARNING: outbox already holds unsequenced rows at start; counts may be skewed');
}

$fixturecourseids = [];

// Fixtures are created under the site's normal central-mode configs; there are
// no course-event capture observers, so creation itself writes nothing to the
// sync tables.
$fixturecat = core_course_category::create((object) [
    'name' => 'ITEST v2 Category',
    'idnumber' => 'itestv2_cat',
    'parent' => 0,
]);
$coursea = create_course((object) [
    'fullname' => 'ITEST v2 Course A v1',
    'shortname' => 'itestv2_course_a',
    'category' => $fixturecat->id,
    'summary' => '',
    'format' => 'topics',
    'visible' => 1,
    'idnumber' => '', // Empty so the school copy gets the central_<id> fallback.
]);
$courseb = create_course((object) [
    'fullname' => 'ITEST v2 Course B (not entitled)',
    'shortname' => 'itestv2_course_b',
    'category' => core_course_category::get_default()->id,
    'summary' => '',
    'format' => 'topics',
    'visible' => 1,
    'idnumber' => '',
]);
$fixturecourseids[] = (int) $coursea->id;
$fixturecourseids[] = (int) $courseb->id;

$schoolmanager = new school_manager();
$schoolid = 'itestv2school';
$apikey = $schoolmanager->register_school($schoolid, 'ITEST v2 Fixture School');
$schoolmanager->set_course_prefs($schoolid, [
    ['courseid' => (int) $coursea->id, 'selected' => true, 'weight' => 1],
], true);

$coursecid = 0;          // Created in phase 8.
$schoolcopyid = 0;       // Created by the apply phase.
$copycatid = 0;

// Fixture central ids must not collide with the legacy idmap already on this
// box, or the school-side resolution would adopt a real course.
$collision = $DB->record_exists_select('local_syncqueue_idmap',
    "tablename IN ('course', 'course_categories', 'category') AND centralid IN (:a, :b)",
    ['a' => (int) $coursea->id, 'b' => (int) $courseb->id])
    || $DB->record_exists('course', ['idnumber' => 'central_' . $coursea->id]);
itestv2_check('preflight_no_id_collision', !$collision,
    "fixture course ids {$coursea->id}/{$courseb->id} unused as legacy central ids");

// ---------------------------------------------------------------------------
// Cleanup (runs even on failure, unless --keep).
// ---------------------------------------------------------------------------

$cleanup = function() use ($DB, $CFG, $savedconfig, $snapshottables, $startmax,
        $precursor, $precounter, $presynccat, $schoolid, &$fixturecourseids) {
    $step = function(string $label, callable $fn) {
        global $itestv2failures;
        try {
            $fn();
        } catch (Throwable $e) {
            cli_writeln("INFO cleanup step '{$label}' failed: " . $e->getMessage());
            $itestv2failures[] = 'cleanup';
        }
    };

    $step('courses', function() use ($DB, &$fixturecourseids) {
        foreach (['itestv2_course_a', 'itestv2_course_b', 'itestv2_course_c'] as $shortname) {
            while ($course = $DB->get_record('course', ['shortname' => $shortname])) {
                $fixturecourseids[] = (int) $course->id;
                delete_course($course->id, false);
            }
        }
        foreach ($fixturecourseids as $centralid) {
            while ($course = $DB->get_record('course', ['idnumber' => 'central_' . $centralid])) {
                delete_course($course->id, false);
            }
        }
    });
    $step('categories', function() use ($DB, $presynccat) {
        foreach ($DB->get_records('course_categories', ['idnumber' => 'itestv2_cat']) as $cat) {
            $category = core_course_category::get($cat->id, IGNORE_MISSING, true);
            if ($category) {
                $category->delete_full(false);
            }
        }
        if (!$presynccat) {
            $cat = $DB->get_record('course_categories', ['idnumber' => 'syncqueue_courses']);
            if ($cat && !$DB->record_exists('course', ['category' => $cat->id])) {
                $category = core_course_category::get($cat->id, IGNORE_MISSING, true);
                if ($category) {
                    $category->delete_full(false);
                }
            }
        }
    });
    $step('backup files', function() use ($CFG, $fixturecourseids) {
        foreach (array_unique($fixturecourseids) as $courseid) {
            $pattern = $CFG->dataroot . '/local_syncqueue_backups/course_' . (int) $courseid . '_*.mbz';
            foreach (glob($pattern) ?: [] as $path) {
                @unlink($path);
            }
        }
    });
    $step('elby meta', function() use ($DB, $fixturecourseids) {
        if ($DB->get_manager()->table_exists('elby_course_meta')) {
            foreach (array_unique($fixturecourseids) as $courseid) {
                $DB->delete_records('elby_course_meta', ['courseid' => (int) $courseid]);
            }
        }
    });
    $step('school row', function() use ($DB, $schoolid) {
        $DB->delete_records('local_syncqueue_course_prefs', ['schoolid' => $schoolid]);
        $DB->delete_records('local_syncqueue_schools', ['schoolid' => $schoolid]);
    });
    $step('adhoc tasks', function() use ($DB, $startmax) {
        $DB->delete_records_select('task_adhoc',
            'id > :startid AND ' . $DB->sql_like('classname', ':cls'),
            ['startid' => $startmax['task_adhoc'], 'cls' => '%syncqueue%']);
    });
    $step('sync tables', function() use ($DB, $snapshottables, $startmax, $precursor, $precounter) {
        foreach ($snapshottables as $table) {
            if ($table === 'task_adhoc') {
                continue; // Handled above (only syncqueue classnames).
            }
            $DB->delete_records_select($table, 'id > :startid', ['startid' => $startmax[$table]]);
        }
        if ($precursor) {
            // A pre-existing cursor row survived the id purge; put its value back.
            $DB->set_field('local_syncqueue_cursor', 'lastappliedseq', $precursor->lastappliedseq,
                ['id' => $precursor->id]);
        }
        if (!$precounter && $DB->count_records('local_syncqueue_outbox') == 0) {
            // We created the counter and no outbox rows remain: full reset.
            $DB->delete_records('local_syncqueue_seq', ['name' => 'outbox']);
        }
    });
    $step('configs', function() use ($savedconfig) {
        foreach ($savedconfig as $name => $value) {
            itestv2_restore_config($name, $value);
        }
    });
};

try {
    // -----------------------------------------------------------------------
    // Phase 1 — capture (central role): legacy dual-write into the outbox.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: capture (central) ---');
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');

    // Hold the sequencer lock so the every-minute cron sequencer cannot stamp
    // our rows between capture and the seq-IS-NULL assertions.
    $lockfactory = \core\lock\lock_config::get_lock_factory('local_syncqueue');
    $seqlock = $lockfactory->get_lock('sequencer', 10);
    if (!$seqlock) {
        cli_writeln('INFO could not take the sequencer lock; cron may race the seq-NULL checks');
    }

    $updatemanager = new update_manager();

    $catpath = [['id' => (int) $fixturecat->id, 'name' => $fixturecat->name, 'idnumber' => 'itestv2_cat']];
    $updatemanager->queue_update('category', 'update', [
        'table' => 'course_categories',
        'id' => (int) $fixturecat->id,
        'name' => $fixturecat->name,
        'idnumber' => 'itestv2_cat',
        'description' => '',
        'descriptionformat' => FORMAT_HTML,
        'parent' => 0,
        'visible' => 1,
        'path' => $catpath,
    ], 2);

    $coursearec = $DB->get_record('course', ['id' => $coursea->id], '*', MUST_EXIST);
    $updatemanager->queue_course_update($coursearec, 'update'); // course:A v1.

    $DB->set_field('course', 'fullname', 'ITEST v2 Course A v2', ['id' => $coursea->id]);
    $coursearec = $DB->get_record('course', ['id' => $coursea->id], '*', MUST_EXIST);
    $updatemanager->queue_course_update($coursearec, 'update'); // course:A v2 (supersedes v1).

    $contentfilename = 'course_' . $coursea->id . '_1.mbz'; // Never fetched: course applies before content.
    $updatemanager->queue_update('course_content', 'update', [
        'table' => 'course',
        'id' => (int) $coursea->id,
        'shortname' => $coursearec->shortname,
        'fullname' => $coursearec->fullname,
        'idnumber' => '',
        'summary' => '',
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'numsections' => 4,
        'visible' => 1,
        'startdate' => (int) $coursearec->startdate,
        'enddate' => 0,
        'category' => ['id' => (int) $fixturecat->id, 'path' => $catpath],
        'backup' => ['filename' => $contentfilename, 'has_backup' => true],
    ], 2);

    $coursebrec = $DB->get_record('course', ['id' => $courseb->id], '*', MUST_EXIST);
    $updatemanager->queue_course_update($coursebrec, 'update'); // Not entitled to the school.

    $legacydelta = $DB->count_records_select('local_syncqueue_updates', 'id > :startid',
        ['startid' => $startmax['local_syncqueue_updates']]);
    itestv2_check('capture_legacy_rows', $legacydelta == 4,
        "legacy updates delta {$legacydelta} (expected 4: category + coalesced courseA + content + courseB)");

    $outboxrows = $DB->get_records_select('local_syncqueue_outbox', 'id > :startid',
        ['startid' => $startmax['local_syncqueue_outbox']], 'id ASC');
    $unsequenced = array_filter($outboxrows, fn($r) => $r->seq === null);
    itestv2_check('capture_outbox_rows', count($outboxrows) == 5 && count($unsequenced) == 5,
        count($outboxrows) . ' outbox rows (expected 5), ' . count($unsequenced) . ' with seq NULL');

    $bykey = [];
    foreach ($outboxrows as $row) {
        $bykey[$row->entitytype . '|' . $row->entitykey][] = $row;
    }
    $courseakey = 'course|course:' . $coursea->id;
    $catkey = 'category|category:' . $fixturecat->id;
    $contentkey = 'course_content|coursecontent:' . $coursea->id;
    $coursebkey = 'course|course:' . $courseb->id;
    $versionsok = isset($bykey[$courseakey], $bykey[$catkey], $bykey[$contentkey], $bykey[$coursebkey])
        && count($bykey[$courseakey]) == 2
        && (int) $bykey[$courseakey][0]->entityversion == 1
        && (int) $bykey[$courseakey][1]->entityversion == 2
        && (int) $bykey[$catkey][0]->entityversion == 1
        && (int) $bykey[$contentkey][0]->entityversion == 1
        && (int) $bykey[$contentkey][0]->contentversion == 1
        && $bykey[$contentkey][0]->action === 'publish'
        && (int) $bykey[$coursebkey][0]->entityversion == 1;
    itestv2_check('capture_entityversions', $versionsok,
        'courseA v1+v2, category v1, content v1 (contentversion 1, action publish), courseB v1');

    $hashok = true;
    $partok = true;
    foreach ($outboxrows as $row) {
        $decoded = json_decode((string) $row->payload, true);
        if ($row->payloadhash !== publisher::hash_payload($decoded)
                || $row->payloadhash !== hash('sha256', (string) $row->payload)) {
            $hashok = false;
        }
        $expectedpart = ($row->entitytype === 'category') ? 'content:global'
            : 'content:course:course:' . ($row->entitykey === 'course:' . $courseb->id
                ? $courseb->id : $coursea->id);
        if ($row->partitionkey !== $expectedpart) {
            $partok = false;
        }
    }
    itestv2_check('capture_payloadhash_canonical', $hashok,
        'payloadhash == sha256(stored payload) == hash_payload(decoded) for all 5 rows');
    itestv2_check('capture_partitionkeys', $partok,
        'category -> content:global, course rows -> content:course:course:<id>');

    // Transactional outbox: a rolled-back business transaction must leave no
    // legacy row, no outbox row and no entityversion bump behind.
    $preprobeoutbox = $DB->count_records('local_syncqueue_outbox');
    $preprobeversion = (int) (applied_state::get('course', 'course:' . $coursea->id)->entityversion ?? 0);
    $probetx = $DB->start_delegated_transaction();
    try {
        $updatemanager->queue_course_update($coursearec, 'update');
        $probetx->rollback(new moodle_exception('generalexceptionmessage', 'error', '', 'itestv2 rollback probe'));
    } catch (moodle_exception $e) {
        // Expected: rollback() rethrows.
    }
    $postprobeversion = (int) (applied_state::get('course', 'course:' . $coursea->id)->entityversion ?? 0);
    itestv2_check('capture_transactional_rollback',
        $DB->count_records('local_syncqueue_outbox') == $preprobeoutbox
            && $postprobeversion == $preprobeversion
            && $DB->count_records_select('local_syncqueue_updates', 'id > :startid',
                ['startid' => $startmax['local_syncqueue_updates']]) == 4,
        "rollback left outbox count {$preprobeoutbox}, entityversion {$postprobeversion}, 4 legacy rows");

    if ($seqlock) {
        $seqlock->release();
    }

    // -----------------------------------------------------------------------
    // Phase 2 — sequencing: dense, unique, ordered.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: sequencer ---');
    $assigned = sequencer::assign();
    $seqs = $DB->get_fieldset_select('local_syncqueue_outbox', 'seq', 'id > :startid ORDER BY id ASC',
        ['startid' => $startmax['local_syncqueue_outbox']]);
    $seqs = array_map('intval', $seqs);
    $dense = count($seqs) == 5 && !in_array(0, $seqs, true)
        && $seqs === array_values(array_unique($seqs))
        && max($seqs) - min($seqs) == 4
        && $seqs == range(min($seqs), max($seqs)); // id order == seq order.
    itestv2_check('sequencer_assigned', $assigned == 5
        || ($assigned == 0 && $dense), // Cron sequencer can win the lock race; rows must still be stamped.
        "assign() returned {$assigned}, seqs [" . implode(',', $seqs) . ']');
    itestv2_check('sequencer_dense_unique_ordered', $dense,
        'seqs consecutive, unique, in insert order: [' . implode(',', $seqs) . ']');
    $counter = (int) $DB->get_field('local_syncqueue_seq', 'value', ['name' => 'outbox']);
    itestv2_check('sequencer_counter', $counter == max($seqs),
        "counter value {$counter} == max assigned seq " . max($seqs));
    $second = sequencer::assign();
    itestv2_check('sequencer_idempotent', $second == 0, "second assign() returned {$second}");

    $courseav1seq = min($seqs) + 1; // Capture order: category, courseA v1, courseA v2, content, courseB.

    // -----------------------------------------------------------------------
    // Phase 3 — pull endpoint (central role, direct external call as school).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: pull endpoint ---');
    $headseq = (int) $DB->get_field_sql('SELECT MAX(seq) FROM {local_syncqueue_outbox}');
    $pretracking = $DB->count_records('local_syncqueue_school_updates');
    $preoutboxmax = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_syncqueue_outbox}');

    $rejected = false;
    try {
        pull::execute($schoolid, $apikey, 0, 200, 1);
    } catch (invalid_parameter_exception $e) {
        $rejected = true;
    }
    itestv2_check('pull_protocol_rejected', $rejected, 'protocol_version=1 raised invalid_parameter_exception');

    $authrejected = false;
    try {
        pull::execute($schoolid, str_repeat('0', 64), 0, 200, 2);
    } catch (moodle_exception $e) {
        $authrejected = ($e->errorcode === 'error_authfailed');
    }
    itestv2_check('pull_auth_rejected', $authrejected, 'wrong apikey raised error_authfailed');

    $rawresponse = pull::execute($schoolid, $apikey, 0, 200, 2);
    $response = itestv2_normalize_response($rawresponse);

    itestv2_check('pull_shape',
        $response->protocol_version == 2 && $response->min_retained_seq == 1
            && $response->head_seq == $headseq && $response->advance_to == $headseq,
        "protocol 2, min_retained 1, head_seq {$response->head_seq}, advance_to {$response->advance_to}"
            . " (outbox head {$headseq})");

    $keys = array_map(fn($r) => $r->entitykey, $response->rows);
    itestv2_check('pull_subscription_filter', !in_array('course:' . $courseb->id, $keys, true),
        'non-entitled course:' . $courseb->id . ' absent from rows [' . implode(', ', $keys) . ']');

    $coursearows = array_values(array_filter($response->rows, fn($r) => $r->entitykey === 'course:' . $coursea->id));
    itestv2_check('pull_supersession',
        count($coursearows) == 1 && (int) $coursearows[0]->entityversion == 2
            && $response->advance_to >= $courseav1seq,
        'course:' . $coursea->id . ' delivered once at v' . ($coursearows[0]->entityversion ?? '-')
            . ", superseded v1 seq {$courseav1seq} omitted but covered by advance_to {$response->advance_to}");

    $catrows = array_values(array_filter($response->rows, fn($r) => $r->entitykey === 'category:' . $fixturecat->id));
    $contentrows = array_values(array_filter($response->rows,
        fn($r) => $r->entitykey === 'coursecontent:' . $coursea->id));
    $coursepayload = $coursearows ? json_decode($coursearows[0]->payload, true) : null;
    itestv2_check('pull_rows_expected',
        count($response->rows) == 3 && count($catrows) == 1 && count($contentrows) == 1
            && ($coursepayload['fullname'] ?? '') === 'ITEST v2 Course A v2'
            && $contentrows[0]->action === 'publish' && (int) $contentrows[0]->contentversion == 1,
        count($response->rows) . ' rows: category v1, course v2 (payload fullname v2), content publish cv1');

    $hashok = true;
    foreach ($response->rows as $row) {
        if ($row->payload !== null && hash('sha256', $row->payload) !== $row->payloadhash) {
            $hashok = false;
        }
    }
    itestv2_check('pull_payloadhash_integrity', $hashok, 'sha256(payload) == payloadhash for all delivered rows');

    itestv2_check('pull_pure_read',
        $DB->count_records('local_syncqueue_school_updates') == $pretracking
            && (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_syncqueue_outbox}') == $preoutboxmax,
        'no tracking rows written, no outbox rows added at read time');

    // -----------------------------------------------------------------------
    // Phase 4 — apply (school role): pull_stream loop against the local endpoint.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 4: school apply (pull_stream) ---');
    // School-role configs. enabled=0 keeps the box's cron school tasks
    // (download/process against the real test central) out of the window; the
    // v2 loop under test gates only on pull_v2.
    set_config('mode', 'school', 'local_syncqueue');
    set_config('enabled', 0, 'local_syncqueue');
    set_config('pull_v2', 1, 'local_syncqueue');

    // The school does not have these yet: remove the central-side originals.
    delete_course($coursea->id, false);
    core_course_category::get($fixturecat->id, MUST_EXIST, true)->delete_full(false);

    $stream = new itestv2_pull_stream();
    $stream->itestschoolid = $schoolid;
    $stream->itestapikey = $apikey;
    $processor = new update_processor();
    $stream->execute();

    $schoolcopy = $DB->get_record('course', ['idnumber' => 'central_' . $coursea->id]);
    $schoolcopyid = $schoolcopy ? (int) $schoolcopy->id : 0;
    if ($schoolcopyid) {
        $fixturecourseids[] = $schoolcopyid;
    }
    itestv2_check('apply_course_created',
        $schoolcopy && $schoolcopyid != (int) $coursea->id
            && $schoolcopy->fullname === 'ITEST v2 Course A v2',
        'school copy course ' . ($schoolcopyid ?: 'MISSING') . ' created (origin was ' . $coursea->id
            . '), fullname "' . ($schoolcopy->fullname ?? '-') . '" from superseding v2 payload');

    $copycat = $DB->get_record('course_categories', ['idnumber' => 'itestv2_cat']);
    $copycatid = $copycat ? (int) $copycat->id : 0;
    itestv2_check('apply_category_created',
        $copycat && $copycatid != (int) $fixturecat->id
            && $schoolcopy && (int) $schoolcopy->category == $copycatid,
        'school category ' . ($copycatid ?: 'MISSING') . ' created (origin was ' . $fixturecat->id
            . '), course filed under it');

    $catstate = applied_state::get('category', 'category:' . $fixturecat->id);
    $coursestate = applied_state::get('course', 'course:' . $coursea->id);
    $contentstate = applied_state::get('course_content', 'coursecontent:' . $coursea->id);
    itestv2_check('apply_applied_state',
        $catstate && (int) $catstate->entityversion == 1 && (int) $catstate->localid == $copycatid
            && $coursestate && (int) $coursestate->entityversion == 2 && (int) $coursestate->localid == $schoolcopyid
            && $contentstate && (int) $contentstate->entityversion == 1
            && (int) $contentstate->localid == $schoolcopyid,
        'applied rows: category v1 -> ' . ($catstate->localid ?? '-') . ', course v2 -> '
            . ($coursestate->localid ?? '-') . ', content v1 -> ' . ($contentstate->localid ?? '-'));

    $cursorafter = cursor::get('central', 'down');
    itestv2_check('apply_cursor_advanced', $cursorafter == $response->advance_to,
        "cursor {$cursorafter} == advance_to {$response->advance_to} (apply-then-checkpoint)");

    // -----------------------------------------------------------------------
    // Phase 5 — idempotence: re-feed the same response rows.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 5: idempotent re-apply ---');
    $outcomes = [];
    foreach ($response->rows as $row) {
        $outcomes[] = $stream->itest_process_row($processor, $row);
    }
    itestv2_check('idempotent_stale_skips', $outcomes === ['stale', 'stale', 'stale'],
        're-apply outcomes [' . implode(', ', $outcomes) . ']');
    itestv2_check('idempotent_no_duplicates',
        $DB->count_records('course', ['idnumber' => 'central_' . $coursea->id]) == 1
            && $DB->count_records('course_categories', ['idnumber' => 'itestv2_cat']) == 1
            && (int) applied_state::get('course', 'course:' . $coursea->id)->entityversion == 2,
        '1 course, 1 category, course applied-state still v2');

    // -----------------------------------------------------------------------
    // Phase 6 — adoption: legacy school adopts instead of duplicating.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 6: adoption ---');
    // Simulate a legacy school: no applied-state, but the legacy idmap row and
    // the central_<id> idnumber exist (the apply above created both; assert).
    $DB->delete_records('local_syncqueue_applied',
        ['entitytype' => 'course', 'entitykey' => 'course:' . $coursea->id]);
    $mapper = new id_mapper();
    itestv2_check('adoption_precondition',
        $mapper->get_local_id('course', (int) $coursea->id) === $schoolcopyid
            && $DB->record_exists('course', ['id' => $schoolcopyid, 'idnumber' => 'central_' . $coursea->id]),
        "legacy idmap + central_{$coursea->id} idnumber both point at course {$schoolcopyid}; applied row deleted");

    $report = (new adoption())->adopt(true);
    $entry = null;
    foreach ($report->adopted as $candidate) {
        if ($candidate->entitykey === 'course:' . $coursea->id) {
            $entry = $candidate;
        }
    }
    itestv2_check('adoption_entry', $entry !== null && (int) $entry->localid == $schoolcopyid,
        'adopt(true) entry for course:' . $coursea->id . ' -> localid ' . ($entry->localid ?? 'MISSING')
            . " (report: {$report->counts->adopted} adopted, {$report->counts->alreadyadopted} already,"
            . " {$report->counts->quarantined} quarantined)");

    $seeded = applied_state::get('course', 'course:' . $coursea->id);
    itestv2_check('adoption_applied_seeded',
        $seeded && (int) $seeded->entityversion == 0 && $seeded->payloadhash === ''
            && (int) $seeded->localid == $schoolcopyid,
        'applied row re-seeded at v0, empty hash, localid ' . ($seeded->localid ?? '-'));

    $outcome = $stream->itest_process_row($processor, $coursearows[0]);
    $postadopt = applied_state::get('course', 'course:' . $coursea->id);
    itestv2_check('adoption_reapply_in_place',
        $outcome === 'applied'
            && $DB->count_records('course', ['idnumber' => 'central_' . $coursea->id]) == 1
            && (int) $postadopt->localid == $schoolcopyid && (int) $postadopt->entityversion == 2,
        "re-apply outcome '{$outcome}': same course {$schoolcopyid} updated in place (v0 -> v2), no duplicate");

    // -----------------------------------------------------------------------
    // Phase 7 — deadletter: poison row goes retry -> dead, cursor never blocks.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 7: deadletter ---');
    // Central role: publish a poison row (unknown entitytype). Left unsequenced
    // on purpose — the pull endpoint's inline sequencer must stamp it.
    publisher::publish('itestbogus', 'itestbogus:1', 'upsert',
        ['poison' => true, 'note' => 'itestv2 unknown entitytype'], 'content:global');

    $cursorbefore = cursor::get('central', 'down');
    $stream->execute();

    $poisonoutbox = $DB->get_record('local_syncqueue_outbox', ['entitytype' => 'itestbogus'], '*', MUST_EXIST);
    itestv2_check('dlq_inline_sequencer', $poisonoutbox->seq !== null,
        'pull-serve-time sequencer stamped the poison row with seq ' . ($poisonoutbox->seq ?? 'NULL'));

    $dl = $DB->get_record('local_syncqueue_deadletter',
        ['entitytype' => 'itestbogus', 'entitykey' => 'itestbogus:1']);
    itestv2_check('dlq_poison_retry',
        $dl && $dl->status === 'retry' && (int) $dl->attempts == 1
            && $dl->peer === 'central' && $dl->direction === 'down',
        'deadletter row status ' . ($dl->status ?? 'MISSING') . ', attempts ' . ($dl->attempts ?? '-'));

    $cursorpoison = cursor::get('central', 'down');
    itestv2_check('dlq_cursor_advanced',
        $cursorpoison == (int) $poisonoutbox->seq && $cursorpoison > $cursorbefore,
        "cursor advanced {$cursorbefore} -> {$cursorpoison} past the failed row (never blocks the stream)");

    for ($i = 0; $i < 4; $i++) {
        $stream->itest_replay($processor);
    }
    $dl = $DB->get_record('local_syncqueue_deadletter', ['id' => $dl->id], '*', MUST_EXIST);
    itestv2_check('dlq_goes_dead', $dl->status === 'dead' && (int) $dl->attempts == 5,
        "after 5 counted attempts: status {$dl->status}, attempts {$dl->attempts}");

    // -----------------------------------------------------------------------
    // Phase 8 — cutover republish (central role): publish_school_state adhoc.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 8: cutover republish ---');
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    set_config('pull_v2', 0, 'local_syncqueue');

    $coursec = create_course((object) [
        'fullname' => 'ITEST v2 Course C (cutover)',
        'shortname' => 'itestv2_course_c',
        'category' => core_course_category::get_default()->id,
        'summary' => '',
        'format' => 'topics',
        'visible' => 1,
        'idnumber' => '',
    ]);
    $coursecid = (int) $coursec->id;
    $fixturecourseids[] = $coursecid;
    $schoolmanager->set_course_prefs($schoolid, [
        ['courseid' => $coursecid, 'selected' => true, 'weight' => 1],
    ], true);

    $prerepublishmax = (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {local_syncqueue_outbox}');
    $prerepublishhead = (int) $DB->get_field_sql('SELECT COALESCE(MAX(seq), 0) FROM {local_syncqueue_outbox}');
    $ncategories = $DB->count_records('course_categories');

    $adhoc = new publish_school_state();
    $adhoc->set_custom_data(['schoolid' => $schoolid]);
    \core\task\manager::queue_adhoc_task($adhoc);
    $queued = $DB->get_records_select('task_adhoc',
        $DB->sql_like('classname', ':cls') . ' AND ' . $DB->sql_like('customdata', ':data'),
        ['cls' => '%publish_school_state%', 'data' => '%' . $schoolid . '%'], 'id DESC');
    itestv2_check('republish_queued', !empty($queued), count($queued) . ' queued adhoc task record(s)');

    $queuedrec = reset($queued);
    $runtask = \core\task\manager::adhoc_task_from_record($queuedrec);
    // Consume the record ourselves so site cron cannot double-run it.
    $DB->delete_records('task_adhoc', ['id' => $queuedrec->id]);
    $runtask->execute();

    $tailrows = $DB->get_records_select('local_syncqueue_outbox', 'id > :preid',
        ['preid' => $prerepublishmax], 'id ASC');
    $tailseqs = array_map(fn($r) => (int) $r->seq, $tailrows);
    $tailok = count($tailrows) >= $ncategories + 1
        && !in_array(0, $tailseqs, true)
        && min($tailseqs) == $prerepublishhead + 1
        && max($tailseqs) - min($tailseqs) + 1 == count($tailrows);
    itestv2_check('republish_rows_at_tail', $tailok,
        count($tailrows) . " fresh rows ({$ncategories} categories entitled+snapshot), all sequenced densely "
            . ($tailseqs ? min($tailseqs) . '..' . max($tailseqs) : '-')
            . " continuing from head {$prerepublishhead}");

    $tailcatok = true;
    $tailcoursec = null;
    $tailcontentc = null;
    foreach ($tailrows as $row) {
        if ($row->entitytype === 'category' && $row->partitionkey !== 'content:global') {
            $tailcatok = false;
        }
        if ($row->entitytype === 'course' && $row->entitykey === 'course:' . $coursecid) {
            $tailcoursec = $row;
        }
        if ($row->entitytype === 'course_content' && $row->entitykey === 'coursecontent:' . $coursecid) {
            $tailcontentc = $row;
        }
    }
    itestv2_check('republish_course_row',
        $tailcoursec && $tailcoursec->action === 'upsert'
            && $tailcoursec->partitionkey === 'content:course:course:' . $coursecid,
        'course:' . $coursecid . ' republished (action ' . ($tailcoursec->action ?? 'MISSING')
            . ', partition ' . ($tailcoursec->partitionkey ?? '-') . ')');
    itestv2_check('republish_content_row',
        $tailcontentc && $tailcontentc->action === 'publish' && (int) $tailcontentc->contentversion >= 1
            && $tailcontentc->partitionkey === 'content:course:course:' . $coursecid,
        'coursecontent:' . $coursecid . ' publish row (contentversion '
            . ($tailcontentc->contentversion ?? 'MISSING') . ', backup-backed snapshot)');
    itestv2_check('republish_category_partitions', $tailcatok,
        'every republished category row rides content:global');
} catch (Throwable $e) {
    $fatal = get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal);
    $itestv2failures[] = 'script_completed';
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
        if ($table === 'task_adhoc') {
            continue; // Other components may queue unrelated adhocs concurrently.
        }
        $now = $DB->count_records($table);
        if ($now != $startcount[$table]) {
            $restored = false;
            $detail[] = "{$table} {$startcount[$table]} -> {$now}";
        }
    }
    if ($DB->record_exists('local_syncqueue_schools', ['schoolid' => $schoolid])
            || $DB->record_exists_select('course', $DB->sql_like('shortname', ':s'), ['s' => 'itestv2%'])
            || $DB->record_exists('course_categories', ['idnumber' => 'itestv2_cat'])) {
        $restored = false;
        $detail[] = 'fixtures remain';
    }
    foreach (['mode', 'enabled', 'pull_v2'] as $name) {
        if (get_config('local_syncqueue', $name) !== $savedconfig[$name]) {
            $restored = false;
            $detail[] = "config {$name} not restored";
        }
    }
    itestv2_check('cleanup_restored', $restored,
        $restored ? 'all sync tables, fixtures and configs back to their starting state'
            : implode('; ', $detail));
}

if (empty($itestv2failures)) {
    cli_writeln('SPIKE RESULT: PASS');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($itestv2failures))
    . ($fatal ? " ({$fatal})" : ''));
exit(1);
