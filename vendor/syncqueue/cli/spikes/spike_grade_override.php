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
 * Spike (a): prove an OVERRIDDEN final grade on a leaf activity grade item
 * survives full-course regrade and a subsequent module-side raw grade write,
 * and that the course total aggregates the overridden value.
 *
 * This is pre-implementation spike test (a) from the ELMS Sync v2
 * architecture doc, section 13 step 0 / section 8.2 (peer grade writes are
 * applied as overridden leaf-item grades).
 *
 * Disposable fixtures (all prefixed spike_a_ / category "SPIKE TESTS") are
 * created, exercised and torn down; re-runnable; use --keep to inspect.
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
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/testing/generator/lib.php');

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'keep' => false,
], [
    'h' => 'help',
    'k' => 'keep',
]);

if ($unrecognized) {
    cli_error('Unrecognized options: ' . implode(', ', $unrecognized) . '. Use --help.');
}

if ($options['help']) {
    $help = <<<EOF
Spike (a) - overridden leaf grade survives regrade + module-side grading.

Creates course "spike_a_course" (category "SPIKE TESTS"), one assignment,
one user; writes an overridden finalgrade of 77 via grade_item::update_final_grade;
then asserts the 77 survives (1) a forced full course regrade, (2) a module-side
grade_update('mod/assign', ...) with rawgrade 50 plus another regrade, and
(3) that the course total aggregated 77, not 50.

Options:
  -h, --help  Show this help message
  -k, --keep  Skip fixture cleanup (fixtures are pre-cleaned on next run)

Example:
  php spike_grade_override.php

EOF;
    echo $help;
    exit(0);
}

\core\cron::setup_user(); // Run as admin so events/capability checks have a valid user.

const SPIKE_COURSE_SHORTNAME = 'spike_a_course';
const SPIKE_USERNAME = 'spike_a_user';
const SPIKE_CATEGORY_NAME = 'SPIKE TESTS';
const SPIKE_OVERRIDE_GRADE = 77.0;
const SPIKE_MODULE_RAWGRADE = 50.0;

/** @var array[] Fixture teardowns, run in reverse creation order. */
$teardowns = [];
/** @var array[] Config restores, run even with --keep. */
$configrestores = [];
/** @var string[] Failed CHECK lines. */
$failures = [];
/** @var string[] Cleanup steps that failed (residue left behind). */
$residue = [];

/**
 * Print an evidence line and record failures.
 *
 * @param string $name assertion name
 * @param bool $ok whether the assertion passed
 * @param string $detail evidence detail
 */
function spike_check(string $name, bool $ok, string $detail): void {
    global $failures;
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
    if (!$ok) {
        $failures[] = $name;
    }
}

/**
 * Run teardown closures in reverse registration order; never throws.
 *
 * @param array $items list of [label, closure]
 */
function spike_run_teardowns(array $items): void {
    global $residue;
    foreach (array_reverse($items) as list($label, $fn)) {
        try {
            $fn();
            cli_writeln("CLEANUP {$label}: done");
        } catch (Throwable $e) {
            $residue[] = $label;
            cli_writeln("CLEANUP {$label}: FAILED " . $e->getMessage());
        }
    }
}

/**
 * Delete residue from previous runs so the spike is deterministic and re-runnable.
 */
function spike_preclean(): void {
    global $DB;
    if ($course = $DB->get_record('course', ['shortname' => SPIKE_COURSE_SHORTNAME])) {
        cli_writeln('PRECLEAN: deleting leftover course ' . SPIKE_COURSE_SHORTNAME . " (id {$course->id})");
        delete_course($course->id, false);
        fix_course_sortorder();
    }
    if ($user = $DB->get_record('user', ['username' => SPIKE_USERNAME, 'deleted' => 0])) {
        cli_writeln('PRECLEAN: deleting leftover user ' . SPIKE_USERNAME . " (id {$user->id})");
        delete_user($user);
    }
}

/**
 * Fetch the current grade_grade row for an item/user fresh from the DB.
 *
 * @param int $itemid grade item id
 * @param int $userid user id
 * @return grade_grade|false
 */
function spike_fetch_grade(int $itemid, int $userid) {
    return grade_grade::fetch(['itemid' => $itemid, 'userid' => $userid]);
}

/**
 * Format a possibly-null grade float for evidence output.
 *
 * @param float|null $value grade value
 * @return string
 */
function spike_fmt(?float $value): string {
    return $value === null ? 'NULL' : sprintf('%.5f', $value);
}

$exitreason = '';

try {
    // Silence syncqueue observers for the duration: fixture events (user_created,
    // user_enrolment_created, user_graded) must not leave rows in the sync queue.
    $oldenabled = get_config('local_syncqueue', 'enabled');
    set_config('enabled', 0, 'local_syncqueue');
    $configrestores[] = ['restore local_syncqueue/enabled', function() use ($oldenabled) {
        set_config('enabled', $oldenabled === false ? null : $oldenabled, 'local_syncqueue');
    }];

    spike_preclean();

    // Category "SPIKE TESTS" (shared with other spikes; only deleted if we created it and it ends up empty).
    $category = $DB->get_record('course_categories', ['name' => SPIKE_CATEGORY_NAME, 'parent' => 0]);
    if (!$category) {
        $categoryobj = core_course_category::create(['name' => SPIKE_CATEGORY_NAME]);
        $category = $categoryobj->get_db_record();
        $teardowns[] = ['category ' . SPIKE_CATEGORY_NAME, function() use ($category) {
            global $DB;
            if ($DB->count_records('course', ['category' => $category->id]) == 0
                    && $DB->count_records('course_categories', ['parent' => $category->id]) == 0) {
                core_course_category::get($category->id, MUST_EXIST, true)->delete_full(false);
            } else {
                cli_writeln('CLEANUP note: category not empty (another spike using it?), leaving in place');
            }
        }];
    }

    // Course.
    $course = create_course((object)[
        'fullname' => 'Spike A grade override course',
        'shortname' => SPIKE_COURSE_SHORTNAME,
        'category' => $category->id,
        'visible' => 1,
    ]);
    $teardowns[] = ['course ' . SPIKE_COURSE_SHORTNAME, function() use ($course) {
        delete_course($course->id, false);
        fix_course_sortorder();
    }];

    // User.
    $userid = user_create_user([
        'username' => SPIKE_USERNAME,
        'firstname' => 'Spike',
        'lastname' => 'GradeOverride',
        'email' => 'spike_a_user@example.invalid',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
    ], false, false);
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    $teardowns[] = ['user ' . SPIKE_USERNAME, function() use ($user) {
        delete_user($user);
    }];

    // Manual enrolment as student.
    $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    $manualinstance = null;
    foreach (enrol_get_instances($course->id, true) as $instance) {
        if ($instance->enrol === 'manual') {
            $manualinstance = $instance;
            break;
        }
    }
    if (!$manualinstance) {
        throw new moodle_exception('error', 'error', '', null, 'No enabled manual enrol instance in spike course');
    }
    $manualplugin = enrol_get_plugin('manual');
    $manualplugin->enrol_user($manualinstance, $user->id, $studentroleid);
    $teardowns[] = ['enrolment ' . SPIKE_USERNAME, function() use ($manualplugin, $manualinstance, $user) {
        $manualplugin->unenrol_user($manualinstance, $user->id);
    }];

    // Assignment (via core testing generator, same mechanism tool_generator uses on live sites).
    $generator = new testing_data_generator();
    $assign = $generator->get_plugin_generator('mod_assign')->create_instance([
        'course' => $course->id,
        'name' => 'spike_a_assign',
        'grade' => 100,
    ]);
    // The assignment is deleted with the course; no separate teardown.

    $gradeitem = grade_item::fetch([
        'courseid' => $course->id,
        'itemtype' => 'mod',
        'itemmodule' => 'assign',
        'iteminstance' => $assign->id,
        'itemnumber' => 0,
    ]);
    spike_check('fixtures_created', !empty($gradeitem),
        "course={$course->id} user={$user->id} assign={$assign->id} gradeitemid=" . ($gradeitem->id ?? 'MISSING'));
    if (!$gradeitem) {
        throw new moodle_exception('error', 'error', '', null, 'Assign grade item not found, cannot continue');
    }

    // Step 0: write the overridden final grade via the grade API (the section 8.2 peer-write mechanism).
    $writeok = $gradeitem->update_final_grade($user->id, SPIKE_OVERRIDE_GRADE, 'local_syncqueue_spike');
    $gg = spike_fetch_grade($gradeitem->id, $user->id);
    $ok = $writeok && $gg && !grade_floats_different($gg->finalgrade, SPIKE_OVERRIDE_GRADE) && $gg->overridden > 0;
    spike_check('override_written', $ok,
        'update_final_grade=' . var_export($writeok, true)
        . ' finalgrade=' . spike_fmt($gg ? $gg->finalgrade : null)
        . ' overridden=' . ($gg ? $gg->overridden : 'NULL'));

    // Step 1: forced full course regrade must not touch the overridden final grade.
    grade_force_full_regrading($course->id);
    $regraderesult = grade_regrade_final_grades($course->id);
    $gg = spike_fetch_grade($gradeitem->id, $user->id);
    $ok = ($regraderesult === true) && $gg
        && !grade_floats_different($gg->finalgrade, SPIKE_OVERRIDE_GRADE) && $gg->overridden > 0;
    spike_check('survives_full_regrade', $ok,
        'regrade=' . ($regraderesult === true ? 'true' : json_encode($regraderesult))
        . ' finalgrade=' . spike_fmt($gg ? $gg->finalgrade : null)
        . ' overridden=' . ($gg ? $gg->overridden : 'NULL'));

    // Step 2: module-side grading afterwards (what mod/assign's grade push does) writes rawgrade 50.
    $updateresult = grade_update('mod/assign', $course->id, 'mod', 'assign', $assign->id, 0, [
        'userid' => $user->id,
        'rawgrade' => SPIKE_MODULE_RAWGRADE,
    ]);
    $gg = spike_fetch_grade($gradeitem->id, $user->id);
    $ok = ($updateresult == GRADE_UPDATE_OK) && $gg
        && !grade_floats_different($gg->rawgrade, SPIKE_MODULE_RAWGRADE);
    spike_check('module_raw_write_landed', $ok,
        "grade_update={$updateresult} (OK=" . GRADE_UPDATE_OK . ')'
        . ' rawgrade=' . spike_fmt($gg ? $gg->rawgrade : null));

    grade_force_full_regrading($course->id);
    $regraderesult = grade_regrade_final_grades($course->id);
    $gg = spike_fetch_grade($gradeitem->id, $user->id);
    $ok = ($regraderesult === true) && $gg
        && !grade_floats_different($gg->finalgrade, SPIKE_OVERRIDE_GRADE) && $gg->overridden > 0;
    spike_check('override_survives_module_write', $ok,
        'regrade=' . ($regraderesult === true ? 'true' : json_encode($regraderesult))
        . ' finalgrade=' . spike_fmt($gg ? $gg->finalgrade : null)
        . ' rawgrade=' . spike_fmt($gg ? $gg->rawgrade : null)
        . ' overridden=' . ($gg ? $gg->overridden : 'NULL'));

    // Step 3: the course total must have aggregated the overridden 77, not the module's raw 50.
    $courseitem = grade_item::fetch_course_item($course->id);
    $coursegg = spike_fetch_grade($courseitem->id, $user->id);
    $ok = $coursegg && !grade_floats_different($coursegg->finalgrade, SPIKE_OVERRIDE_GRADE)
        && grade_floats_different($coursegg->finalgrade, SPIKE_MODULE_RAWGRADE);
    spike_check('course_total_uses_override', $ok,
        'coursetotal=' . spike_fmt($coursegg ? $coursegg->finalgrade : null)
        . ' (expected ' . spike_fmt(SPIKE_OVERRIDE_GRADE) . ', not ' . spike_fmt(SPIKE_MODULE_RAWGRADE) . ')'
        . " coursetotalmax=" . spike_fmt($courseitem->grademax));

} catch (Throwable $e) {
    spike_check('no_unexpected_exception', false, get_class($e) . ': ' . $e->getMessage());
    $exitreason = 'unexpected exception: ' . $e->getMessage();
}

// Cleanup always runs (reverse creation order), even when assertions failed.
if ($options['keep']) {
    cli_writeln('CLEANUP skipped (--keep): fixtures left in place, next run pre-cleans them');
} else {
    spike_run_teardowns($teardowns);
}
spike_run_teardowns($configrestores);

if ($residue) {
    cli_writeln('WARNING residue left behind: ' . implode(', ', $residue));
}

if (empty($failures)) {
    cli_writeln('SPIKE RESULT: PASS');
    exit(0);
}
if ($exitreason === '') {
    $exitreason = 'failed checks: ' . implode(', ', $failures);
}
cli_writeln('SPIKE RESULT: FAIL - ' . $exitreason);
exit(1);
