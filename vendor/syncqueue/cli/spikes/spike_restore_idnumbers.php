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
 * Spike (d): same-site backup + restore-alongside preserves idnumbers.
 *
 * Proves that backing up a course (no user data) and restoring it into a
 * brand-new course on the SAME site preserves verbatim:
 *   1. the course-module idnumber of an activity,
 *   2. the grade-item idnumber of that activity's grade item,
 *   3. the grade-item idnumber of a manual grade item,
 * while the source course - holding duplicate idnumbers - still exists.
 *
 * Feeds section 7 of the sync v2 architecture (versioned publication stamps
 * cm/grade-item UUIDs at publish; restore-alongside must keep them intact).
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/testing/generator/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'keep' => false,
], [
    'h' => 'help',
    'k' => 'keep',
]);

if ($unrecognized) {
    cli_error('Unknown option(s): ' . implode(', ', (array) $unrecognized));
}

if ($options['help']) {
    cli_writeln(
        "Spike (d): backup/restore preserves cm and grade-item idnumbers (same site).\n" .
        "\n" .
        "Creates a tiny course A (assignment with cm idnumber SPIKE-CM-UUID-1 and\n" .
        "grade-item idnumber SPIKE-GI-UUID-1, plus a manual grade item with idnumber\n" .
        "SPIKE-GI-MANUAL-1), backs it up without user data, restores it alongside as\n" .
        "a new course B, and asserts all three idnumbers survived verbatim. All\n" .
        "fixtures are prefixed spike_d_ and are deleted at the end (even on failure).\n" .
        "\n" .
        "Options:\n" .
        "  -h, --help  Show this help message\n" .
        "  -k, --keep  Skip cleanup, leave the fixture courses in place\n" .
        "\n" .
        "Example:\n" .
        "  php spike_restore_idnumbers.php\n"
    );
    exit(0);
}

$cmidnumber       = 'SPIKE-CM-UUID-1';
$activityginumber = 'SPIKE-GI-UUID-1';
$manualginumber   = 'SPIKE-GI-MANUAL-1';

\core\session\manager::set_user(get_admin());
$adminid = $USER->id;

$failures = [];
$check = function(string $name, bool $ok, string $detail) use (&$failures): bool {
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
    if (!$ok) {
        $failures[] = $name;
    }
    return $ok;
};

// Fixtures are registered as they are created and torn down in reverse order,
// even when assertions fail.
$cleanupstack = [];
$register = function(string $label, callable $fn) use (&$cleanupstack): void {
    $cleanupstack[] = [$label, $fn];
};
$runcleanup = function() use (&$cleanupstack): void {
    while ($cleanupstack) {
        list($label, $fn) = array_pop($cleanupstack);
        try {
            $fn();
            cli_writeln('CLEANUP ' . $label . ': done');
        } catch (\Throwable $e) {
            cli_writeln('CLEANUP ' . $label . ': FAILED - ' . $e->getMessage());
        }
    }
};

// Re-runnability: remove residue from earlier runs (crashes or --keep).
$leftovers = $DB->get_records_select('course', $DB->sql_like('shortname', ':short'),
    ['short' => 'spike\_d\_%'], 'id', 'id, shortname');
foreach ($leftovers as $leftover) {
    delete_course($leftover->id, false);
    cli_writeln("PRECLEAN: deleted leftover course {$leftover->id} ({$leftover->shortname})");
}

try {
    $generator = new testing_data_generator();

    // Fixture: course A with one assignment.
    $coursea = $generator->create_course([
        'shortname' => 'spike_d_course_a',
        'fullname'  => 'spike_d course A (restore idnumber spike)',
    ]);
    $register("course A {$coursea->id}", function() use ($coursea) {
        delete_course($coursea->id, false);
    });

    $assign = $generator->create_module('assign', [
        'course' => $coursea->id,
        'name'   => 'spike_d_assign',
    ], ['idnumber' => $cmidnumber]);

    $acm = get_coursemodule_from_instance('assign', $assign->id, $coursea->id, false, MUST_EXIST);
    if (!$check('fixture_cm_idnumber', $acm->idnumber === $cmidnumber,
            "course A cm {$acm->id} idnumber='{$acm->idnumber}'")) {
        throw new RuntimeException('fixture setup failed: cm idnumber not stamped');
    }

    // Diverge the activity grade-item idnumber from the cm idnumber, as the
    // publish step in the sync v2 design stamps them independently.
    $agi = grade_item::fetch(['courseid' => $coursea->id, 'itemtype' => 'mod',
        'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'itemnumber' => 0]);
    if (!$agi) {
        throw new RuntimeException('fixture setup failed: assign grade item not found in course A');
    }
    $agi->idnumber = $activityginumber;
    $agi->update('spike');
    $agi = grade_item::fetch(['id' => $agi->id]);
    if (!$check('fixture_activity_gradeitem_idnumber', $agi->idnumber === $activityginumber,
            "course A grade item {$agi->id} idnumber='{$agi->idnumber}'")) {
        throw new RuntimeException('fixture setup failed: activity grade-item idnumber not stamped');
    }

    // Fixture: manual grade item.
    $manual = new grade_item([
        'courseid'  => $coursea->id,
        'itemtype'  => 'manual',
        'itemname'  => 'spike_d_manual_item',
        'idnumber'  => $manualginumber,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => 100,
    ], false);
    $manualid = $manual->insert('spike');
    $manual = grade_item::fetch(['id' => $manualid]);
    if (!$check('fixture_manual_gradeitem_idnumber', $manual->idnumber === $manualginumber,
            "course A manual grade item {$manualid} idnumber='{$manual->idnumber}'")) {
        throw new RuntimeException('fixture setup failed: manual grade-item idnumber not stamped');
    }

    // Backup course A without user data (same pattern as backup_manager).
    $bc = new backup_controller(backup::TYPE_1COURSE, $coursea->id, backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO, backup::MODE_GENERAL, $adminid);
    $plan = $bc->get_plan();
    $backupsettings = [
        'users'           => false,
        'role_assignments'=> false,
        'activities'      => true,
        'blocks'          => false,
        'filters'         => false,
        'comments'        => false,
        'badges'          => false,
        'calendarevents'  => false,
        'userscompletion' => false,
        'logs'            => false,
        'grade_histories' => false,
        'groups'          => false,
        'competencies'    => false,
    ];
    foreach ($backupsettings as $name => $value) {
        if ($plan->setting_exists($name)) {
            $setting = $plan->get_setting($name);
            if ($setting->get_status() == \base_setting::NOT_LOCKED) {
                $setting->set_value($value);
            }
        }
    }
    $bc->execute_plan();
    $results = $bc->get_results();
    $backupfile = $results['backup_destination'] ?? null;
    if (!$backupfile) {
        $bc->destroy();
        throw new RuntimeException('backup produced no backup_destination file');
    }
    $register('backup stored file', function() use ($backupfile) {
        $backupfile->delete();
    });
    cli_writeln("INFO: backup of course A complete ({$backupfile->get_filesize()} bytes)");

    // Extract and restore into a brand-new course B, alongside A.
    $tempdir = restore_controller::get_tempdir_name($coursea->id, $adminid);
    $packer = get_file_packer('application/vnd.moodle.backup');
    $packer->extract_to_pathname($backupfile, $CFG->tempdir . '/backup/' . $tempdir);
    $register('restore temp dir', function() use ($CFG, $tempdir) {
        fulldelete($CFG->tempdir . '/backup/' . $tempdir);
    });
    $bc->destroy();

    $courseb = restore_dbops::create_new_course('spike_d course B placeholder',
        'spike_d_course_b', $coursea->category);
    $register("course B {$courseb}", function() use ($courseb) {
        delete_course($courseb, false);
    });

    $rc = new restore_controller($tempdir, $courseb, backup::INTERACTIVE_NO,
        backup::MODE_GENERAL, $adminid, backup::TARGET_NEW_COURSE);
    $plan = $rc->get_plan();
    $restoresettings = [
        'users'           => false,
        'role_assignments'=> false,
        'activities'      => true,
        'blocks'          => false,
        'filters'         => false,
        'comments'        => false,
        'badges'          => false,
        'calendarevents'  => false,
        'userscompletion' => false,
        'logs'            => false,
        'grade_histories' => false,
        'groups'          => false,
        'competencies'    => false,
    ];
    foreach ($restoresettings as $name => $value) {
        if ($plan->setting_exists($name)) {
            $setting = $plan->get_setting($name);
            if ($setting->get_status() == \base_setting::NOT_LOCKED) {
                $setting->set_value($value);
            }
        }
    }
    if (!$rc->execute_precheck()) {
        $precheck = $rc->get_precheck_results();
        $rc->destroy();
        throw new RuntimeException('restore precheck failed: ' . json_encode($precheck));
    }
    $rc->execute_plan();
    $courseb = $rc->get_courseid();
    $rc->destroy();
    cli_writeln("INFO: restored into new course B id={$courseb} (course A id={$coursea->id})");

    $check('restored_into_new_course', $courseb != $coursea->id,
        "course B id={$courseb} differs from course A id={$coursea->id}");

    // Assertion 1: cm idnumber survived verbatim.
    $bassign = $DB->get_record('assign', ['course' => $courseb], '*', MUST_EXIST);
    $bcm = get_coursemodule_from_instance('assign', $bassign->id, $courseb, false, MUST_EXIST);
    $check('restored_cm_idnumber', $bcm->idnumber === $cmidnumber,
        "course B cm {$bcm->id} idnumber='{$bcm->idnumber}' expected='{$cmidnumber}'");

    // Explicit duplicate observation for the section 7 design: course A still
    // holds the same cm idnumber on this site during the restore.
    if ($bcm->idnumber === $cmidnumber) {
        $observed = 'preserved verbatim despite duplicate in still-existing course A';
    } else if ($bcm->idnumber === '') {
        $observed = 'stripped to empty string (duplicate rejected)';
    } else {
        $observed = "mangled to '{$bcm->idnumber}'";
    }
    cli_writeln('OBSERVE duplicate_cm_idnumber: ' . $observed);

    // Assertion 2: activity grade-item idnumber survived verbatim.
    $bagi = grade_item::fetch(['courseid' => $courseb, 'itemtype' => 'mod',
        'itemmodule' => 'assign', 'iteminstance' => $bassign->id, 'itemnumber' => 0]);
    $bagidetail = $bagi ? "course B grade item {$bagi->id} idnumber='{$bagi->idnumber}'"
        : 'course B assign grade item not found';
    $check('restored_activity_gradeitem_idnumber',
        $bagi && $bagi->idnumber === $activityginumber,
        $bagidetail . " expected='{$activityginumber}'");

    // Assertion 3: manual grade-item idnumber survived verbatim.
    $bmanuals = grade_item::fetch_all(['courseid' => $courseb, 'itemtype' => 'manual']) ?: [];
    $check('restored_manual_gradeitem_count', count($bmanuals) === 1,
        'course B has ' . count($bmanuals) . ' manual grade item(s), expected 1');
    $bmanual = reset($bmanuals);
    $bmanualdetail = $bmanual ? "course B manual grade item {$bmanual->id} idnumber='{$bmanual->idnumber}'"
        : 'course B manual grade item not found';
    $check('restored_manual_gradeitem_idnumber',
        $bmanual && $bmanual->idnumber === $manualginumber,
        $bmanualdetail . " expected='{$manualginumber}'");

    // Source course A must be untouched by the restore-alongside.
    $acmafter = get_coursemodule_from_instance('assign', $assign->id, $coursea->id, false, MUST_EXIST);
    $check('source_cm_idnumber_unchanged', $acmafter->idnumber === $cmidnumber,
        "course A cm {$acmafter->id} idnumber='{$acmafter->idnumber}' expected='{$cmidnumber}'");
    $agiafter = grade_item::fetch(['id' => $agi->id]);
    $check('source_activity_gradeitem_unchanged',
        $agiafter && $agiafter->idnumber === $activityginumber,
        'course A grade item idnumber=\'' . ($agiafter ? $agiafter->idnumber : 'missing') .
        "' expected='{$activityginumber}'");
} catch (\Throwable $e) {
    $failures[] = 'exception';
    cli_writeln('CHECK no_exception: FAIL ' . get_class($e) . ': ' . $e->getMessage());
}

if ($options['keep']) {
    cli_writeln('INFO: --keep given, skipping cleanup of ' . count($cleanupstack) . ' fixture(s)');
} else {
    $runcleanup();
}

if (empty($failures)) {
    cli_writeln('SPIKE RESULT: PASS');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($failures)));
exit(1);
