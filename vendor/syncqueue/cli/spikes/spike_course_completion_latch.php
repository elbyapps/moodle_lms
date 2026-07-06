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
 * Spike (c): course-completion latch via completion_completion::mark_complete() + criteria rows.
 *
 * Proves (ELMS Sync v2 architecture doc, section 8.2 / section 13 step 0):
 *  1. A course completion asserted with mark_complete() + a matching
 *     course_completion_crit_compl row survives the core completion cron
 *     (\core\task\completion_regular_task), because aggregate_completions()
 *     only processes rows with timecompleted IS NULL.
 *  2. Re-asserting the latch is idempotent: no duplicate rows, and core never
 *     changes an existing timecompleted ("never change a completion time").
 *  3. After a clobber (timecompleted cleared, or both rows deleted) the latch
 *     write restores the completion, and the cron itself re-latches from the
 *     surviving criteria row.
 *
 * Disposable fixtures are prefixed spike_c_ and torn down even on failure.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

list($options, $unrecognised) = cli_get_params(
    ['help' => false, 'keep' => false],
    ['h' => 'help', 'k' => 'keep']
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln("Spike (c): course-completion latch survives cron and re-asserts idempotently.

Creates a disposable course (shortname spike_c_course) with completion enabled
and a self-completion criterion, plus one enrolled user (spike_c_user), then:
  1. writes the latch (criteria row + completion_completion::mark_complete),
  2. runs \\core\\task\\completion_regular_task and asserts timecompleted survives,
  3. clears timecompleted only and asserts the cron re-latches from the criteria row,
  4. deletes both rows (full clobber) and asserts the latch write restores them
     idempotently (no duplicate rows).

Options:
  -h, --help  Print this help.
  -k, --keep  Skip fixture cleanup (leaves spike_c_* fixtures in place;
              site config restores still run).

Note: local_syncqueue capture (local_syncqueue.enabled) is disabled for the
duration of the run and restored afterwards, so real user events during the
run window are NOT captured and there is no replay. On a live site, run this
during a quiet window.

Example:
  php local/syncqueue/cli/spikes/spike_course_completion_latch.php");
    exit(0);
}

// Runtime-only guard: never send real email from spike fixtures.
$CFG->noemailever = true;

/** @var string[] Failed check descriptions. */
$failures = [];
/** @var callable[] Fixture teardown stack, run in reverse order; skipped by --keep. */
$teardowns = [];
/** @var callable[] Site-config restore stack, run in reverse order; never skipped. */
$restores = [];
/** @var string[] Cleanup problems to surface at the end. */
$cleanupwarnings = [];

/**
 * Print an evidence line and record failures.
 *
 * @param string $name Check name.
 * @param bool $ok Whether the assertion held.
 * @param string $detail Evidence detail.
 */
function spike_check(string $name, bool $ok, string $detail): void {
    global $failures;
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
    if (!$ok) {
        $failures[] = $name;
    }
}

/**
 * The latch write a sync applier would perform: criteria-completion row + course mark_complete.
 *
 * Both halves are idempotent: the criteria row is only written when missing/incomplete,
 * and completion_completion::mark_complete() never changes an existing timecompleted.
 *
 * @param int $courseid Course id.
 * @param int $userid User id.
 * @param int $criteriaid course_completion_criteria id.
 * @param int $timecompleted Completion timestamp to assert.
 */
function spike_latch_write(int $courseid, int $userid, int $criteriaid, int $timecompleted): void {
    $critcompl = new completion_criteria_completion([
        'course' => $courseid,
        'userid' => $userid,
        'criteriaid' => $criteriaid,
    ]);
    if (!$critcompl->is_complete()) {
        $critcompl->mark_complete($timecompleted);
    }

    $ccompletion = new completion_completion(['course' => $courseid, 'userid' => $userid]);
    $ccompletion->mark_complete($timecompleted);
}

/**
 * Fetch the course_completions row straight from the DB (bypassing the MUC cache).
 *
 * @param int $courseid Course id.
 * @param int $userid User id.
 * @return stdClass|false
 */
function spike_db_course_completion(int $courseid, int $userid) {
    global $DB;
    return $DB->get_record('course_completions', ['course' => $courseid, 'userid' => $userid]);
}

/**
 * Delete residue rows that fixture events created in plugin/messaging tables.
 *
 * @param int $courseid Fixture course id (0 to skip course-keyed sweeps).
 * @param int $userid Fixture user id (0 to skip user-keyed sweeps).
 */
function spike_sweep_residue(int $courseid, int $userid): void {
    global $DB;
    $dbman = $DB->get_manager();

    if ($dbman->table_exists('local_syncqueue_items')) {
        if ($courseid) {
            $DB->delete_records('local_syncqueue_items', ['courseid' => $courseid]);
        }
        if ($userid) {
            $DB->delete_records('local_syncqueue_items', ['relateduserid' => $userid]);
            $DB->delete_records('local_syncqueue_items', ['objecttable' => 'user', 'objectid' => $userid]);
        }
    }
    if ($courseid && $dbman->table_exists('elby_user_metrics')) {
        $DB->delete_records('elby_user_metrics', ['courseid' => $courseid]);
    }
    if ($userid) {
        if ($dbman->table_exists('message_popup_notifications')) {
            $DB->delete_records_select('message_popup_notifications',
                'notificationid IN (SELECT id FROM {notifications} WHERE useridto = ?)', [$userid]);
        }
        $DB->delete_records('notifications', ['useridto' => $userid]);
    }
}

$courseid = 0;
$userid = 0;

try {
    if (empty($CFG->enablecompletion)) {
        throw new moodle_exception('spike precondition failed: site enablecompletion is off');
    }

    // Silence syncqueue observers for the duration of the run (restored last)
    // so fixture events do not enqueue upload items on this school-mode site.
    $oldsyncenabled = get_config('local_syncqueue', 'enabled');
    set_config('enabled', 0, 'local_syncqueue');
    $restores[] = function() use ($oldsyncenabled) {
        if ($oldsyncenabled === false) {
            unset_config('enabled', 'local_syncqueue');
        } else {
            set_config('enabled', $oldsyncenabled, 'local_syncqueue');
        }
    };

    // Pre-clean leftovers from a previous crashed/--keep run so the spike is re-runnable.
    if ($oldcourse = $DB->get_record('course', ['shortname' => 'spike_c_course'])) {
        cli_writeln('INFO pre-clean: deleting leftover course id ' . $oldcourse->id);
        delete_course($oldcourse->id, false);
        fix_course_sortorder();
        spike_sweep_residue((int) $oldcourse->id, 0);
    }
    if ($olduser = $DB->get_record('user', ['username' => 'spike_c_user', 'deleted' => 0])) {
        cli_writeln('INFO pre-clean: deleting leftover user id ' . $olduser->id);
        delete_user($olduser);
        spike_sweep_residue(0, (int) $olduser->id);
    }

    // Fixture: course with completion enabled.
    $category = core_course_category::get_default();
    $course = create_course((object) [
        'fullname' => 'Spike C completion latch course',
        'shortname' => 'spike_c_course',
        'category' => $category->id,
        'visible' => 1,
        'enablecompletion' => 1,
    ]);
    $courseid = (int) $course->id;
    $teardowns[] = function() use ($courseid) {
        delete_course($courseid, false);
        fix_course_sortorder();
    };
    cli_writeln('INFO fixture course id ' . $courseid . ' (shortname spike_c_course, enablecompletion=1)');

    // Fixture: one course-completion criterion. Self-completion is the simplest:
    // no linked module and no cron of its own, so only our explicit writes touch it.
    $criterion = new completion_criteria_self();
    $criterion->course = $courseid;
    $criterion->insert();
    $criteriaid = (int) $criterion->id;
    cli_writeln('INFO fixture criterion id ' . $criteriaid . ' (type self, aggregation default ALL)');

    // Fixture: enrolled user.
    $userid = (int) user_create_user((object) [
        'username' => 'spike_c_user',
        'firstname' => 'Spike',
        'lastname' => 'CLatch',
        'email' => 'spike_c_user@example.invalid',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
    ], false, false);
    $teardowns[] = function() use ($userid, $DB) {
        delete_user($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
    };
    cli_writeln('INFO fixture user id ' . $userid . ' (username spike_c_user)');

    $manual = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    $manual->enrol_user($instance, $userid, $studentrole->id);
    cli_writeln('INFO enrolled user ' . $userid . ' in course ' . $courseid . ' as student');

    // A fixed timestamp in the past so every later assertion can demand exact equality.
    $latchtime = time() - DAYSECS;

    // Phase 1: initial latch write.
    spike_latch_write($courseid, $userid, $criteriaid, $latchtime);

    $ccrow = spike_db_course_completion($courseid, $userid);
    spike_check('initial_latch_course_row',
        $ccrow && (int) $ccrow->timecompleted === $latchtime,
        'course_completions.timecompleted=' . ($ccrow ? $ccrow->timecompleted : 'missing') . ' expected=' . $latchtime);

    $critrow = $DB->get_record('course_completion_crit_compl',
        ['course' => $courseid, 'userid' => $userid, 'criteriaid' => $criteriaid]);
    spike_check('initial_latch_criteria_row',
        $critrow && (int) $critrow->timecompleted === $latchtime,
        'course_completion_crit_compl.timecompleted=' . ($critrow ? $critrow->timecompleted : 'missing') . ' expected=' . $latchtime);

    // Phase 2: re-assert with a DIFFERENT time; the latch must hold and not duplicate.
    spike_latch_write($courseid, $userid, $criteriaid, $latchtime + HOURSECS);

    $cccount = $DB->count_records('course_completions', ['course' => $courseid, 'userid' => $userid]);
    $critcount = $DB->count_records('course_completion_crit_compl',
        ['course' => $courseid, 'userid' => $userid, 'criteriaid' => $criteriaid]);
    spike_check('reassert_no_duplicate_rows',
        $cccount === 1 && $critcount === 1,
        'course_completions=' . $cccount . ' crit_compl=' . $critcount . ' expected 1 each');

    $ccrow = spike_db_course_completion($courseid, $userid);
    spike_check('reassert_latch_time_unchanged',
        $ccrow && (int) $ccrow->timecompleted === $latchtime,
        'timecompleted=' . ($ccrow ? $ccrow->timecompleted : 'missing') . ' still expected original ' . $latchtime);

    // Phase 3: cron survival. Force the aggregator to look at the row (reaggregate > 0)
    // and run the real scheduled task; the WHERE timecompleted IS NULL filter must skip it.
    $DB->set_field('course_completions', 'reaggregate', time() - 10, ['course' => $courseid, 'userid' => $userid]);
    cache::make('core', 'coursecompletion')->purge();
    cli_writeln('INFO running \core\task\completion_regular_task (reaggregate forced > 0)');
    $task = new \core\task\completion_regular_task();
    $task->execute();

    $ccrow = spike_db_course_completion($courseid, $userid);
    spike_check('survives_completion_regular_task',
        $ccrow && (int) $ccrow->timecompleted === $latchtime,
        'timecompleted=' . ($ccrow ? $ccrow->timecompleted : 'missing') . ' expected=' . $latchtime
            . ' reaggregate=' . ($ccrow ? $ccrow->reaggregate : '-'));

    $critrow = $DB->get_record('course_completion_crit_compl',
        ['course' => $courseid, 'userid' => $userid, 'criteriaid' => $criteriaid]);
    spike_check('criteria_row_survives_cron',
        $critrow && (int) $critrow->timecompleted === $latchtime,
        'crit_compl.timecompleted=' . ($critrow ? $critrow->timecompleted : 'missing') . ' expected=' . $latchtime);

    // Phase 4: partial regression — timecompleted cleared locally but the criteria
    // row survives. The next cron run must re-latch from the criteria row.
    $DB->set_field('course_completions', 'timecompleted', null, ['course' => $courseid, 'userid' => $userid]);
    $DB->set_field('course_completions', 'reaggregate', time() - 10, ['course' => $courseid, 'userid' => $userid]);
    cache::make('core', 'coursecompletion')->purge();
    cli_writeln('INFO cleared timecompleted only; running completion_regular_task again');
    $task = new \core\task\completion_regular_task();
    $task->execute();

    $ccrow = spike_db_course_completion($courseid, $userid);
    spike_check('cron_relatches_cleared_timecompleted',
        $ccrow && (int) $ccrow->timecompleted === $latchtime,
        'timecompleted=' . ($ccrow ? $ccrow->timecompleted : 'missing')
            . ' expected restored from criteria row to ' . $latchtime);

    // Phase 5: full clobber — both rows deleted (core API, purges completion caches) —
    // then the latch write must restore everything idempotently.
    $completioninfo = new completion_info($DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST));
    $completioninfo->delete_course_completion_data();
    $goneok = !spike_db_course_completion($courseid, $userid)
        && !$DB->record_exists('course_completion_crit_compl', ['course' => $courseid, 'userid' => $userid]);
    cli_writeln('INFO full clobber via delete_course_completion_data(): rows deleted=' . ($goneok ? 'yes' : 'NO'));

    spike_latch_write($courseid, $userid, $criteriaid, $latchtime);

    $ccrow = spike_db_course_completion($courseid, $userid);
    $cccount = $DB->count_records('course_completions', ['course' => $courseid, 'userid' => $userid]);
    $critcount = $DB->count_records('course_completion_crit_compl',
        ['course' => $courseid, 'userid' => $userid, 'criteriaid' => $criteriaid]);
    spike_check('relatch_after_full_clobber',
        $goneok && $ccrow && (int) $ccrow->timecompleted === $latchtime && $cccount === 1 && $critcount === 1,
        'timecompleted=' . ($ccrow ? $ccrow->timecompleted : 'missing') . ' expected=' . $latchtime
            . ' rows course_completions=' . $cccount . ' crit_compl=' . $critcount);

    // Final cron pass over the restored latch, then prove no duplicates anywhere.
    $DB->set_field('course_completions', 'reaggregate', time() - 10, ['course' => $courseid, 'userid' => $userid]);
    cache::make('core', 'coursecompletion')->purge();
    $task = new \core\task\completion_regular_task();
    $task->execute();

    $ccrow = spike_db_course_completion($courseid, $userid);
    $cccount = $DB->count_records('course_completions', ['course' => $courseid, 'userid' => $userid]);
    $critcount = $DB->count_records('course_completion_crit_compl',
        ['course' => $courseid, 'userid' => $userid, 'criteriaid' => $criteriaid]);
    spike_check('final_cron_pass_no_duplicates',
        $ccrow && (int) $ccrow->timecompleted === $latchtime && $cccount === 1 && $critcount === 1,
        'timecompleted=' . ($ccrow ? $ccrow->timecompleted : 'missing') . ' expected=' . $latchtime
            . ' rows course_completions=' . $cccount . ' crit_compl=' . $critcount);

} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
    cli_writeln('CHECK unexpected_exception: FAIL ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($options['keep']) {
        cli_writeln('INFO --keep set: skipping fixture cleanup, fixtures left in place'
            . ' (course id ' . $courseid . ', user id ' . $userid . ')');
    } else {
        foreach (array_reverse($teardowns) as $i => $teardown) {
            try {
                $teardown();
            } catch (Throwable $e) {
                $cleanupwarnings[] = 'teardown #' . $i . ' failed: ' . $e->getMessage();
            }
        }
        try {
            spike_sweep_residue($courseid, $userid);
        } catch (Throwable $e) {
            $cleanupwarnings[] = 'residue sweep failed: ' . $e->getMessage();
        }
    }
    // Config restores must run even with --keep: leaving local_syncqueue.enabled=0
    // behind would silently stop all sync fact capture on a live school.
    foreach (array_reverse($restores) as $i => $restore) {
        try {
            $restore();
        } catch (Throwable $e) {
            $cleanupwarnings[] = 'config restore #' . $i . ' failed: ' . $e->getMessage();
        }
    }
    cli_writeln('INFO local_syncqueue.enabled now '
        . var_export(get_config('local_syncqueue', 'enabled'), true));
    foreach ($cleanupwarnings as $warning) {
        cli_writeln('CLEANUP WARN: ' . $warning);
    }
    if (!$cleanupwarnings && !$options['keep']) {
        cli_writeln('INFO cleanup complete: fixtures removed');
    }
}

if ($failures) {
    cli_writeln('SPIKE RESULT: FAIL - ' . implode('; ', $failures));
    exit(1);
}
cli_writeln('SPIKE RESULT: PASS');
exit(0);
