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
 * Spike (b): activity-completion override survives completion_info::update_state() recomputation.
 *
 * Proves the ELMS Sync v2 assumption (architecture doc section 8.2) that an activity
 * completion state written with overrideby set (an admin/teacher override) is NOT
 * recomputed away by the normal update_state() calls that activity events fire.
 * Also records whether a normal (non-override) state change clears overrideby.
 *
 * Disposable fixtures (prefix spike_b_); re-runnable; cleans up after itself.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

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
    cli_writeln("Spike (b): activity completion override (overrideby) survives update_state recomputation.

Creates a disposable course (spike_b_course) with an automatic-completion page
activity (view required) and one enrolled user (spike_b_user), overrides the
activity completion to COMPLETE as admin, then re-runs the normal (non-override)
update_state recomputation paths and asserts the override latches. Cleans up all
fixtures afterwards, even on failure.

Options:
  -h, --help    Print this help.
  --keep        Skip fixture cleanup (leaves spike_b_* course/user in place).

Example:
  php local/syncqueue/cli/spikes/spike_completion_override.php");
    exit(0);
}

$prefix = 'spike_b';
$failures = [];
// Teardown closures, registered as fixtures are created, executed in reverse.
$teardowns = [];

/**
 * Print one evidence line.
 *
 * @param string $name Check name.
 * @param bool $ok Whether the check passed.
 * @param string $detail Evidence detail.
 * @param string[] $failures Accumulated failure list (by ref).
 */
function spike_check(string $name, bool $ok, string $detail, array &$failures): void {
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
    if (!$ok) {
        $failures[] = $name;
    }
}

/**
 * Read the raw completion row for a cm/user pair straight from the DB.
 *
 * @param int $cmid Course module id.
 * @param int $userid User id.
 * @return stdClass Object with completionstate, overrideby, hasrow.
 */
function spike_read_row(int $cmid, int $userid): stdClass {
    global $DB;
    $row = $DB->get_record('course_modules_completion',
        ['coursemoduleid' => $cmid, 'userid' => $userid]);
    return (object) [
        'hasrow' => (bool) $row,
        'completionstate' => $row ? (int) $row->completionstate : COMPLETION_INCOMPLETE,
        'overrideby' => ($row && $row->overrideby !== null) ? (int) $row->overrideby : null,
    ];
}

/**
 * Format a completion row for evidence output.
 *
 * @param stdClass $row Result of spike_read_row().
 * @return string Human-readable summary.
 */
function spike_fmt(stdClass $row): string {
    return 'state=' . $row->completionstate
        . ' overrideby=' . ($row->overrideby === null ? 'null' : $row->overrideby)
        . ($row->hasrow ? '' : ' (no db row)');
}

/**
 * Delete any leftover fixtures from a previous run (crash or --keep).
 *
 * @param string $prefix Fixture name prefix.
 */
function spike_purge_leftovers(string $prefix): void {
    global $DB;
    if ($oldcourse = $DB->get_record('course', ['shortname' => $prefix . '_course'])) {
        cli_writeln("INFO purging leftover course {$oldcourse->id} from a previous run");
        delete_course($oldcourse->id, false);
    }
    if ($olduser = $DB->get_record('user', ['username' => $prefix . '_user', 'deleted' => 0])) {
        cli_writeln("INFO purging leftover user {$olduser->id} from a previous run");
        delete_user($olduser);
    }
}

// Run as admin: completion overrides require moodle/course:overridecompletion and
// update_state() records $USER->id as overrideby.
\core\session\manager::set_user(get_admin());
$admin = get_admin();

$courseid = 0;
$userid = 0;
$fatal = '';

try {
    spike_purge_leftovers($prefix);

    // Site-wide completion must be on for completion_info::is_enabled().
    if (empty($CFG->enablecompletion)) {
        $oldenable = $CFG->enablecompletion;
        set_config('enablecompletion', 1);
        $teardowns[] = function() use ($oldenable) {
            set_config('enablecompletion', $oldenable);
            cli_writeln('INFO cleanup: restored enablecompletion=' . $oldenable);
        };
    }

    // Observers (local_syncqueue in school mode) enqueue rows for the events this
    // spike fires; purge those last so course/user deletion events are caught too.
    $teardowns[] = function() use (&$courseid, &$userid) {
        global $DB;
        $n = 0;
        if ($courseid) {
            $n += $DB->count_records('local_syncqueue_items', ['courseid' => $courseid]);
            $DB->delete_records('local_syncqueue_items', ['courseid' => $courseid]);
        }
        if ($userid) {
            $n += $DB->count_records('local_syncqueue_items', ['relateduserid' => $userid]);
            $DB->delete_records('local_syncqueue_items', ['relateduserid' => $userid]);
        }
        cli_writeln("INFO cleanup: removed {$n} observer-queued local_syncqueue_items rows");
    };

    // Fixture: course with completion enabled.
    $course = create_course((object) [
        'fullname' => $prefix . '_course',
        'shortname' => $prefix . '_course',
        'category' => \core_course_category::get_default()->id,
        'summary' => 'Disposable spike fixture, safe to delete.',
        'summaryformat' => FORMAT_HTML,
        'visible' => 1,
        'enablecompletion' => 1,
    ]);
    $courseid = (int) $course->id;
    $teardowns[] = function() use (&$courseid) {
        delete_course($courseid, false);
        cli_writeln("INFO cleanup: deleted course {$courseid}");
    };

    // Fixture: user.
    $userid = user_create_user((object) [
        'username' => $prefix . '_user',
        'firstname' => 'Spike',
        'lastname' => 'BUser',
        'email' => $prefix . '_user@example.com',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
    ], false, false);
    $teardowns[] = function() use (&$userid) {
        global $DB;
        if ($user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0])) {
            delete_user($user);
            cli_writeln("INFO cleanup: deleted user {$userid}");
        }
    };

    // Fixture: enrol as student (manual enrol).
    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    if (!enrol_try_internal_enrol($courseid, $userid, $studentrole->id)) {
        throw new moodle_exception('generalexceptionmessage', 'error', '',
            'manual enrolment of spike user failed');
    }

    // Fixture: page activity with automatic completion, view required.
    $moduleinfo = create_module((object) [
        'modulename' => 'page',
        'course' => $courseid,
        'section' => 0,
        'visible' => 1,
        'name' => $prefix . '_page',
        'introeditor' => ['text' => 'spike fixture', 'format' => FORMAT_HTML, 'itemid' => 0],
        'content' => '<p>spike fixture</p>',
        'contentformat' => FORMAT_HTML,
        'display' => 0,
        'printintro' => 0,
        'printlastmodified' => 0,
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'cmidnumber' => '',
    ]);
    $cmid = (int) $moduleinfo->coursemodule;

    $course = get_course($courseid);
    $completion = new completion_info($course);
    $cm = get_fast_modinfo($course)->get_cm($cmid);

    spike_check('fixtures_created',
        $completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC
            && (int) $cm->completionview === COMPLETION_VIEW_REQUIRED,
        "course={$courseid} cm={$cmid} user={$userid} tracking=" . $cm->completion
            . ' completionview=' . $cm->completionview, $failures);

    $row = spike_read_row($cmid, $userid);
    spike_check('initial_incomplete',
        $row->completionstate === COMPLETION_INCOMPLETE && $row->overrideby === null,
        spike_fmt($row), $failures);

    // Override to COMPLETE as admin (the sync-applier write from doc section 8.2).
    $completion->update_state($cm, COMPLETION_COMPLETE, $userid, true);
    $row = spike_read_row($cmid, $userid);
    spike_check('override_set_complete',
        $row->completionstate === COMPLETION_COMPLETE && $row->overrideby === (int) $admin->id,
        spike_fmt($row) . " (expected overrideby={$admin->id})", $failures);

    // Sanity: the criteria alone would compute INCOMPLETE (user never viewed),
    // so any COMPLETE state we observe below is held by the override, not the criteria.
    $current = $completion->get_data($cm, false, $userid);
    $recomputed = $completion->internal_get_state($cm, $userid, $current);
    spike_check('criteria_would_compute_incomplete',
        $recomputed === COMPLETION_INCOMPLETE,
        "internal_get_state={$recomputed}", $failures);

    // Normal recompute, as any activity event fires it (no override).
    $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
    $row = spike_read_row($cmid, $userid);
    spike_check('override_survives_unknown_recompute',
        $row->completionstate === COMPLETION_COMPLETE && $row->overrideby === (int) $admin->id,
        spike_fmt($row), $failures);

    // Normal event pushing towards INCOMPLETE (e.g. evidence deleted).
    $completion->update_state($cm, COMPLETION_INCOMPLETE, $userid);
    $row = spike_read_row($cmid, $userid);
    spike_check('override_survives_incomplete_push',
        $row->completionstate === COMPLETION_COMPLETE && $row->overrideby === (int) $admin->id,
        spike_fmt($row), $failures);

    // Recompute again with the completion cache purged: the latch must come from
    // the DB row, not a warm cache.
    cache::make('core', 'completion')->purge();
    $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
    $row = spike_read_row($cmid, $userid);
    spike_check('override_survives_recompute_after_cache_purge',
        $row->completionstate === COMPLETION_COMPLETE && $row->overrideby === (int) $admin->id,
        spike_fmt($row), $failures);

    // Informational: reset_all_state() (fired when an activity's completion settings
    // are edited) deletes all state and recomputes fresh — record what it does to
    // the override. Not a doc-8.2 assertion; the hourly reconciler re-asserts latches.
    $completion->reset_all_state($cm);
    $row = spike_read_row($cmid, $userid);
    $preserved = $row->completionstate === COMPLETION_COMPLETE && $row->overrideby !== null;
    cli_writeln('INFO reset_all_state_preserves_override: ' . ($preserved ? 'yes' : 'no')
        . ' (' . spike_fmt($row) . ')');

    // Second question: does a normal (non-override) state change clear overrideby?
    // Override-to-COMPLETE latches, so the only observable path is from an
    // overridden-INCOMPLETE state. Build it: view normally (state -> COMPLETE),
    // override to INCOMPLETE, then let a normal recompute flip it back.
    $completion->set_module_viewed($cm, $userid);
    $row = spike_read_row($cmid, $userid);
    spike_check('normal_view_completes_without_override',
        $row->completionstate === COMPLETION_COMPLETE && $row->overrideby === null,
        spike_fmt($row), $failures);

    $completion->update_state($cm, COMPLETION_INCOMPLETE, $userid, true);
    $row = spike_read_row($cmid, $userid);
    spike_check('override_set_incomplete',
        $row->completionstate === COMPLETION_INCOMPLETE && $row->overrideby === (int) $admin->id,
        spike_fmt($row), $failures);

    $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
    $row = spike_read_row($cmid, $userid);
    spike_check('overridden_incomplete_not_latched',
        $row->completionstate === COMPLETION_COMPLETE,
        spike_fmt($row) . ' (override-to-INCOMPLETE must stay recomputable per core)', $failures);
    cli_writeln('INFO normal_change_clears_overrideby: '
        . ($row->overrideby === null ? 'yes' : 'no') . ' (' . spike_fmt($row) . ')');
} catch (Throwable $e) {
    $fatal = get_class($e) . ': ' . $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal);
    $failures[] = 'script_completed';
}

// Cleanup always runs (unless --keep), in reverse creation order.
if ($options['keep']) {
    cli_writeln("INFO --keep set: leaving fixtures in place (course={$courseid} user={$userid})");
} else {
    foreach (array_reverse($teardowns) as $teardown) {
        try {
            $teardown();
        } catch (Throwable $e) {
            cli_writeln('INFO cleanup step failed: ' . $e->getMessage());
            $failures[] = 'cleanup';
        }
    }
}

if (empty($failures)) {
    cli_writeln('SPIKE RESULT: PASS');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($failures))
    . ($fatal ? " ({$fatal})" : ''));
exit(1);
