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
 * Spike (e): prove ENROL_EXT_REMOVED_SUSPENDNOROLES semantics on cohort-member removal.
 *
 * ELMS Sync v2 step 0 pre-implementation spike. Verifies that with
 * enrol_cohort/unenrolaction = ENROL_EXT_REMOVED_SUSPENDNOROLES, removing a user
 * from a cohort suspends the enrolment, removes the role, and preserves grades;
 * and that re-adding the member restores an active enrolment + role with grades
 * intact. All fixtures are disposable (spike_e_* names) and torn down in reverse
 * order even when assertions fail.
 *
 * @package    local_syncqueue
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/cohort/locallib.php');

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'keep' => false,
], [
    'h' => 'help',
    'k' => 'keep',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOF
Spike (e) - SUSPENDNOROLES semantics on cohort-member removal.

Creates disposable fixtures (spike_e_cohort, spike_e_course, spike_e_user),
binds an enrol_cohort instance the way local_elby_dashboard's
cohort_course_linker does, then asserts that with
enrol_cohort/unenrolaction = ENROL_EXT_REMOVED_SUSPENDNOROLES a cohort-member
removal suspends the enrolment, removes the role and preserves the grade, and
that re-adding the member restores everything.

Options:
  -h, --help  Show this help message
  -k, --keep  Keep the fixtures (skip fixture deletion). Changed site config
              (enrol_cohort/unenrolaction, local_syncqueue/enabled) is ALWAYS
              restored, even with --keep.

Example:
  php spike_suspendnoroles.php

EOF;
    echo $help;
    exit(0);
}

$failures = [];

/**
 * Print an evidence line and record failures.
 *
 * @param string $name Assertion name.
 * @param bool $ok Whether the assertion passed.
 * @param string $detail Extra evidence detail.
 */
function spike_check(string $name, bool $ok, string $detail = ''): void {
    global $failures;
    if ($ok) {
        cli_writeln("CHECK {$name}: OK" . ($detail !== '' ? " {$detail}" : ''));
    } else {
        cli_writeln("CHECK {$name}: FAIL {$detail}");
        $failures[] = $name;
    }
}

// LIFO cleanup stack: [label, closure, configrestore(bool)] entries.
$cleanups = [];

// Sweep leftovers from a previous crashed run so the spike stays re-runnable.
$leftovercourse = $DB->get_record('course', ['shortname' => 'spike_e_course']);
if ($leftovercourse) {
    delete_course($leftovercourse->id, false);
    cli_writeln('NOTE: removed leftover course spike_e_course from a previous run.');
}
$leftoveruser = $DB->get_record('user', ['username' => 'spike_e_user', 'deleted' => 0]);
if ($leftoveruser) {
    delete_user($leftoveruser);
    cli_writeln('NOTE: removed leftover user spike_e_user from a previous run.');
}
$leftovercohort = $DB->get_record('cohort', ['idnumber' => 'spike_e_cohort']);
if ($leftovercohort) {
    cohort_delete_cohort($leftovercohort);
    cli_writeln('NOTE: removed leftover cohort spike_e_cohort from a previous run.');
}

$exitcode = 1;
$failreason = '';

try {
    // Save + register restore of every config we touch BEFORE changing anything.
    $origunenrolaction = get_config('enrol_cohort', 'unenrolaction');
    $cleanups[] = ['restore enrol_cohort/unenrolaction', function() use ($origunenrolaction) {
        if ($origunenrolaction === false) {
            unset_config('unenrolaction', 'enrol_cohort');
        } else {
            set_config('unenrolaction', $origunenrolaction, 'enrol_cohort');
        }
    }, true];

    // Silence local_syncqueue observers (user_enrolment_*, user_graded) so the
    // spike leaves no rows in the sync queue on this school-mode dev stack.
    $origsyncenabled = get_config('local_syncqueue', 'enabled');
    if ($origsyncenabled) {
        set_config('enabled', 0, 'local_syncqueue');
        $cleanups[] = ['restore local_syncqueue/enabled', function() use ($origsyncenabled) {
            set_config('enabled', $origsyncenabled, 'local_syncqueue');
        }, true];
    }

    if (!enrol_is_enabled('cohort')) {
        $origenrolenabled = $CFG->enrol_plugins_enabled;
        set_config('enrol_plugins_enabled', $origenrolenabled . ',cohort');
        $cleanups[] = ['restore enrol_plugins_enabled', function() use ($origenrolenabled) {
            set_config('enrol_plugins_enabled', $origenrolenabled);
        }, true];
    }

    $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    $trace = new \core\output\progress_trace\null_progress_trace();

    // Fixture 1: system-context cohort (idnumber has no tdmp: prefix, so the
    // elby_dashboard linker leaves it alone).
    $cohort = new stdClass();
    $cohort->name = 'spike_e_cohort';
    $cohort->idnumber = 'spike_e_cohort';
    $cohort->contextid = context_system::instance()->id;
    $cohort->description = 'Disposable ELMS Sync v2 spike fixture';
    $cohortid = cohort_add_cohort($cohort);
    $cleanups[] = ['delete cohort spike_e_cohort', function() use ($DB, $cohortid) {
        if ($record = $DB->get_record('cohort', ['id' => $cohortid])) {
            cohort_delete_cohort($record);
        }
        // The cohort_created observer queued a link_cohort_adhoc task; drop it.
        $DB->delete_records('task_adhoc', [
            'classname' => '\local_elby_dashboard\task\link_cohort_adhoc',
            'customdata' => json_encode(['cohortid' => $cohortid]),
        ]);
    }, false];

    // Fixture 2: user.
    $user = new stdClass();
    $user->username = 'spike_e_user';
    $user->firstname = 'Spike';
    $user->lastname = 'SuspendNoRoles';
    $user->email = 'spike_e_user@example.invalid';
    $user->auth = 'manual';
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $userid = user_create_user($user, false, false);
    $cleanups[] = ['delete user spike_e_user', function() use ($DB, $userid) {
        if ($record = $DB->get_record('user', ['id' => $userid, 'deleted' => 0])) {
            delete_user($record);
        }
    }, false];

    // Fixture 3: course.
    $course = create_course((object) [
        'fullname' => 'spike_e_course',
        'shortname' => 'spike_e_course',
        'category' => core_course_category::get_default()->id,
        'visible' => 1,
    ]);
    $cleanups[] = ['delete course spike_e_course', function() use ($course) {
        delete_course($course->id, false);
    }, false];

    // Enrol instance bound to the cohort, created exactly the way
    // local_elby_dashboard\cohort_course_linker::ensure_instance() does.
    $plugin = enrol_get_plugin('cohort');
    if (!$plugin) {
        throw new moodle_exception('generalexceptionmessage', 'error', '', 'enrol_cohort plugin missing');
    }
    $instanceid = $plugin->add_instance($course, [
        'name' => 'spike_e_instance',
        'customint1' => $cohortid,
        'customint2' => 0,
        'roleid' => $studentroleid,
    ]); // The enrol instance is removed with the course in cleanup.
    spike_check('enrol_instance_created', (bool) $instanceid, "enrol id={$instanceid}");

    $coursecontext = context_course::instance($course->id);
    $uefields = 'id, status';
    $raparams = [
        'userid' => $userid,
        'contextid' => $coursecontext->id,
        'component' => 'enrol_cohort',
        'itemid' => $instanceid,
        'roleid' => $studentroleid,
    ];

    // Phase 1: membership -> active enrolment + student role.
    cohort_add_member($cohortid, $userid);
    enrol_cohort_sync($trace, $course->id);

    $ue = $DB->get_record('user_enrolments', ['enrolid' => $instanceid, 'userid' => $userid], $uefields);
    spike_check('active_enrolment_after_add', $ue && (int) $ue->status === ENROL_USER_ACTIVE,
        'user_enrolments.status=' . ($ue ? $ue->status : 'missing'));
    spike_check('student_role_after_add', $DB->record_exists('role_assignments', $raparams),
        'role_assignments component=enrol_cohort itemid=' . $instanceid);

    // Grade the user via a manual grade item.
    $gradeitem = new grade_item([
        'courseid' => $course->id,
        'itemtype' => 'manual',
        'itemname' => 'spike_e_grade_item',
        'gradetype' => GRADE_TYPE_VALUE,
        'grademin' => 0,
        'grademax' => 100,
    ], false);
    $gradeitem->insert('local_syncqueue');
    $gradeitem->update_final_grade($userid, 77.5, 'local_syncqueue');

    $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
    spike_check('grade_written', $grade && abs((float) $grade->finalgrade - 77.5) < 0.001,
        'grade_grades.finalgrade=' . ($grade ? $grade->finalgrade : 'missing'));

    // Phase 2: pin SUSPENDNOROLES, remove the member, sync.
    set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES, 'enrol_cohort');
    cohort_remove_member($cohortid, $userid);
    enrol_cohort_sync($trace, $course->id);

    $ue = $DB->get_record('user_enrolments', ['enrolid' => $instanceid, 'userid' => $userid], $uefields);
    spike_check('suspended_after_remove', $ue && (int) $ue->status === ENROL_USER_SUSPENDED,
        'user_enrolments.status=' . ($ue ? $ue->status : 'missing') . ' (expected ' . ENROL_USER_SUSPENDED . ')');
    spike_check('role_removed_after_remove', !$DB->record_exists('role_assignments', $raparams),
        'role_assignments row ' . ($DB->record_exists('role_assignments', $raparams) ? 'still present' : 'gone'));

    $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
    spike_check('grade_preserved_after_remove', $grade && abs((float) $grade->finalgrade - 77.5) < 0.001,
        'grade_grades.finalgrade=' . ($grade ? $grade->finalgrade : 'missing'));

    // Phase 3: re-add the member, sync -> active + role + grade intact.
    cohort_add_member($cohortid, $userid);
    enrol_cohort_sync($trace, $course->id);

    $ue = $DB->get_record('user_enrolments', ['enrolid' => $instanceid, 'userid' => $userid], $uefields);
    spike_check('active_enrolment_after_readd', $ue && (int) $ue->status === ENROL_USER_ACTIVE,
        'user_enrolments.status=' . ($ue ? $ue->status : 'missing'));
    spike_check('student_role_after_readd', $DB->record_exists('role_assignments', $raparams),
        'role_assignments component=enrol_cohort itemid=' . $instanceid);

    $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
    spike_check('grade_preserved_after_readd', $grade && abs((float) $grade->finalgrade - 77.5) < 0.001,
        'grade_grades.finalgrade=' . ($grade ? $grade->finalgrade : 'missing'));

    $exitcode = empty($failures) ? 0 : 1;
    if (!empty($failures)) {
        $failreason = 'failed checks: ' . implode(', ', $failures);
    }
} catch (Throwable $e) {
    $failreason = 'unexpected exception: ' . $e->getMessage();
    cli_writeln('EXCEPTION: ' . $e->getMessage());
    cli_writeln($e->getTraceAsString());
}

// Teardown in reverse creation order; config restores run even with --keep.
foreach (array_reverse($cleanups) as [$label, $closure, $isconfigrestore]) {
    if ($options['keep'] && !$isconfigrestore) {
        cli_writeln("KEEP: skipped cleanup '{$label}'");
        continue;
    }
    try {
        $closure();
    } catch (Throwable $e) {
        cli_writeln("CLEANUP WARNING: '{$label}' failed: " . $e->getMessage());
    }
}

if ($exitcode === 0) {
    cli_writeln('SPIKE RESULT: PASS');
} else {
    cli_writeln('SPIKE RESULT: FAIL - ' . ($failreason ?: 'unknown failure'));
}
exit($exitcode);
