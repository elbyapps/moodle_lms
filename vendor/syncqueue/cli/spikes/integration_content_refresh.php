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
 * ELMS Sync v2 step 7 (part 2) integration spike: crash-safe content re-restore.
 *
 * Proves the content bump apply path (doc §7), using a test-double processor whose
 * restore_content_copy restores a pre-built .mbz locally (no HTTP download):
 *   1. A contentversion bump restores the new .mbz ALONGSIDE, migrates the learner's
 *      overridden grade + completion latch old->new by cm-UUID, retires the old copy
 *      (archived idnumber, disabled enrol_cohort instance, hidden), and promotes the
 *      new copy so the entitykey now resolves to it.
 *   2. Re-applying the same version is a no-op (no third copy) — idempotent.
 *   3. A dangling 'restoring' restorelog + its marker corpse is cleaned and retried
 *      (crash-safety) — no duplicate.
 *
 * Builds/restores real .mbz artifacts (slow-ish). Restores every touched row/config/file.
 *
 * Usage:  php integration_content_refresh.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

use local_syncqueue\backup_manager;
use local_syncqueue\item_identity;
use local_syncqueue\outbox\applied_state;
use local_syncqueue\update_processor;

const CR_SDMS = 'ITESTCR001';
const CR_USER = 'itestcr_student';

/** Test double: local restore of a pre-built .mbz instead of an HTTP download. */
class itest_cr_processor extends update_processor {
    public string $mbzpath = '';
    public int $restorecalls = 0;
    public function restore_content_copy(array $payload, int $centralid, string $marker): ?int {
        global $DB;
        $this->restorecalls++;
        $categoryid = $this->get_or_create_category_from_path($payload['category'] ?? null);
        $newid = (new backup_manager())->restore_course($this->mbzpath, $categoryid, (int) get_admin()->id);
        if (!$newid) {
            return null;
        }
        // Mirror the real method's crash-safety stamping (marker idnumber, hidden).
        $course = $DB->get_record('course', ['id' => $newid]);
        $course->shortname = $this->ensure_unique_shortname(
            (string) ($payload['shortname'] ?? $course->shortname), (int) $newid);
        $course->idnumber = $marker;
        $course->visible = 0;
        $DB->update_record('course', $course);
        return (int) $newid;
    }
}

$crfailures = [];
function cr_check(string $name, bool $ok, string $detail = ''): void {
    global $crfailures;
    if (!$ok) {
        $crfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

global $DB, $CFG;
$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());

$saved = ['mode' => get_config('local_syncqueue', 'mode'), 'enabled' => get_config('local_syncqueue', 'enabled')];
$courseids = [];
$userid = 0;
$cohortid = 0;
$fatal = null;

/** Build the course_content outbox-shaped row for apply. */
function cr_content_row(int $centralid, int $entityversion, int $contentversion, array $payload): \stdClass {
    return (object) [
        'entitytype' => 'course_content', 'entitykey' => 'coursecontent:' . $centralid,
        'entityversion' => $entityversion, 'action' => 'publish',
        'payload' => json_encode($payload), 'payloadhash' => hash('sha256', json_encode($payload)),
        'contentversion' => $contentversion, 'partitionkey' => 'content:course:course:' . $centralid,
        'seq' => null,
    ];
}

try {
    if (empty($CFG->enablecompletion)) {
        throw new \moodle_exception('generalexceptionmessage', 'error', '', 'site enablecompletion is off');
    }
    set_config('mode', 'school', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');

    // -----------------------------------------------------------------------
    // Phase 0 — template course, UUID-stamped, backed up (central's artifact).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 0: template + .mbz ---');
    $cat = \core_course_category::get_default();
    $template = create_course((object) [
        'fullname' => 'ITEST CR Template', 'shortname' => 'itestcr_tmpl', 'category' => $cat->id,
        'summary' => '', 'format' => 'topics', 'visible' => 1, 'enablecompletion' => 1,
    ]);
    $courseids[] = (int) $template->id;
    $tmi = create_module((object) [
        'modulename' => 'assign', 'course' => (int) $template->id, 'section' => 0, 'visible' => 1,
        'name' => 'ITEST CR Assign',
        'introeditor' => ['text' => 'a', 'format' => FORMAT_HTML, 'itemid' => 0],
        'grade' => 100, 'gradingduedate' => 0, 'duedate' => 0, 'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0,
        'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0,
        'assignsubmission_file_maxfiles' => 1, 'assignsubmission_file_maxsizebytes' => 1024,
        'assignsubmission_comments_enabled' => 0, 'assignfeedback_comments_enabled' => 0,
        'submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0,
        'sendlatenotifications' => 0, 'sendstudentnotifications' => 0, 'teamsubmission' => 0,
        'requireallteammemberssubmit' => 0, 'blindmarking' => 0, 'attemptreopenmethod' => 'none',
        'maxattempts' => -1, 'markingworkflow' => 0, 'markingallocation' => 0,
        'completion' => COMPLETION_TRACKING_MANUAL, 'cmidnumber' => '',
    ]);
    item_identity::stamp_course((int) $template->id, true);
    $assignuuid = (string) $DB->get_field('course_modules', 'idnumber', ['id' => (int) $tmi->coursemodule]);

    $mbzfile = (new backup_manager())->create_course_backup((int) $template->id, (int) get_admin()->id);
    $mbzpath = $CFG->dataroot . '/local_syncqueue_backups/' . $mbzfile;
    cr_check('template_ready', item_identity::is_uuid($assignuuid) && is_file($mbzpath),
        'template stamped (assign UUID ' . $assignuuid . ') and backed up');

    $u = (object) ['username' => CR_USER, 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'email' => CR_USER . '@example.invalid',
        'firstname' => 'ITest', 'lastname' => 'CR'];
    $userid = (int) user_create_user($u, false, false);

    // The central course id the entitykey refers to (the template stands in for it).
    $centralid = (int) $template->id;
    $coursekey = 'course:' . $centralid;

    // -----------------------------------------------------------------------
    // Phase 1 — the "old" school copy (v1), with a graded + completed learner.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: old copy v1 with learner outcomes ---');
    $oldid = (new backup_manager())->restore_course($mbzpath, $cat->id, (int) get_admin()->id);
    $courseids[] = $oldid;
    $DB->set_field('course', 'idnumber', 'central_' . $centralid, ['id' => $oldid]);
    $oldcourse = $DB->get_record('course', ['id' => $oldid], '*', MUST_EXIST);

    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    enrol_try_internal_enrol($oldid, $userid, $studentrole->id);

    // Overridden grade 85 on the assign (matched later by its UUID).
    $oldgiid = (int) $DB->get_field('grade_items', 'id',
        ['courseid' => $oldid, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'itemnumber' => 0]);
    $oldgi = \grade_item::fetch(['id' => $oldgiid]);
    $oldgi->update_final_grade($userid, 85.0, 'itest');

    // Complete the assign for the learner.
    $oldcmid = (int) $DB->get_field_sql(
        "SELECT cm.id FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = ? AND m.name = 'assign'", [$oldid]);
    $oldcompletion = new \completion_info($oldcourse);
    $oldcmobj = get_fast_modinfo($oldcourse)->get_cm($oldcmid);
    $oldcompletion->update_state($oldcmobj, COMPLETION_COMPLETE, $userid, true);

    // An integration-OWNED cohort-sync enrol instance on the old copy (name = the
    // reconciler's INSTANCE_NAME): must be disabled on retirement.
    $cohortid = cohort_add_cohort((object) ['contextid' => \context_system::instance()->id,
        'name' => 'ITEST CR Cohort', 'idnumber' => 'itestcr_cohort']);
    $enrolid = (int) $DB->insert_record('enrol', (object) [
        'enrol' => 'cohort', 'name' => 'TDMP auto cohort sync',
        'status' => ENROL_INSTANCE_ENABLED, 'courseid' => $oldid,
        'customint1' => $cohortid, 'roleid' => (int) $studentrole->id, 'sortorder' => 0,
        'timecreated' => time(), 'timemodified' => time(),
    ]);
    // An admin's OWN (non-integration) cohort enrol instance on the same copy:
    // retirement must NOT touch it (ownership scope guard).
    $admincohortid = cohort_add_cohort((object) ['contextid' => \context_system::instance()->id,
        'name' => 'ITEST CR Admin Cohort', 'idnumber' => 'itestcr_admin_cohort']);
    $adminenrolid = (int) $DB->insert_record('enrol', (object) [
        'enrol' => 'cohort', 'name' => 'Manual admin cohort sync',
        'status' => ENROL_INSTANCE_ENABLED, 'courseid' => $oldid,
        'customint1' => $admincohortid, 'roleid' => (int) $studentrole->id, 'sortorder' => 1,
        'timecreated' => time(), 'timemodified' => time(),
    ]);

    // Resolution + applied state as if v1 was applied here.
    applied_state::set_localid('course', $coursekey, $oldid);
    applied_state::upsert('course_content', 'coursecontent:' . $centralid, 1,
        hash('sha256', 'v1'), $oldid);
    applied_state::set_contentversion('course_content', 'coursecontent:' . $centralid, 1);

    $oldgrade = $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $oldgiid, 'userid' => $userid]);
    cr_check('old_copy_ready', abs((float) $oldgrade - 85.0) < 0.01,
        'old copy holds the learner overridden grade 85 (' . $oldgrade . ')');

    // -----------------------------------------------------------------------
    // Phase 2 — the content BUMP to v2.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: apply content bump v2 ---');
    $payload = [
        'table' => 'course', 'id' => $centralid, 'shortname' => 'itestcr_tmpl', 'fullname' => 'ITEST CR Template',
        'idnumber' => 'central_' . $centralid, 'summary' => '', 'summaryformat' => FORMAT_HTML,
        'format' => 'topics', 'numsections' => 3, 'visible' => 1, 'startdate' => time(), 'enddate' => 0,
        'category' => ['id' => $cat->id, 'path' => [['id' => $cat->id, 'name' => $cat->name, 'idnumber' => '']]],
        'backup' => ['filename' => $mbzfile, 'has_backup' => true], 'content_mtime' => time(), 'content_sig' => 'x',
    ];
    $proc = new itest_cr_processor();
    $proc->mbzpath = $mbzpath;
    $newid = $proc->apply_outbox_row(cr_content_row($centralid, 2, 2, $payload));
    $courseids[] = $newid;

    cr_check('restored_alongside', $newid > 0 && $newid !== $oldid,
        "a NEW copy was restored alongside (old {$oldid}, new {$newid})");
    cr_check('resolution_swapped',
        (int) applied_state::get('course', $coursekey)->localid === $newid,
        'the entitykey now resolves to the new copy');

    $old = $DB->get_record('course', ['id' => $oldid]);
    cr_check('old_archived',
        $old && strpos((string) $old->idnumber, '#archived-v1') !== false && (int) $old->visible === 0,
        'old copy retired: archived idnumber (' . ($old->idnumber ?? '?') . '), hidden');
    cr_check('old_instance_disabled',
        (int) $DB->get_field('enrol', 'status', ['id' => $enrolid]) === ENROL_INSTANCE_DISABLED,
        'the old copy OWNED cohort-sync enrol instance was disabled (not deleted)');
    cr_check('admin_instance_untouched',
        (int) $DB->get_field('enrol', 'status', ['id' => $adminenrolid]) === ENROL_INSTANCE_ENABLED,
        'a non-owned (admin) cohort enrol instance on the old copy was left enabled');

    // Grade migrated to the new copy's matching (UUID) grade item, overridden.
    $newgi = $DB->get_record('grade_items',
        ['courseid' => $newid, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'idnumber' => $assignuuid]);
    $newgrade = $newgi ? $DB->get_record('grade_grades', ['itemid' => $newgi->id, 'userid' => $userid]) : null;
    cr_check('grade_migrated',
        $newgrade && abs((float) $newgrade->finalgrade - 85.0) < 0.01 && (int) $newgrade->overridden > 0,
        'the 85 grade migrated to the new copy as an overridden grade (' .
        ($newgrade->finalgrade ?? 'none') . ')');

    // Completion migrated to the new copy's matching module.
    $newcmid = (int) $DB->get_field('course_modules', 'id', ['course' => $newid, 'idnumber' => $assignuuid]);
    $newcompl = $DB->get_record('course_modules_completion', ['coursemoduleid' => $newcmid, 'userid' => $userid]);
    cr_check('completion_migrated',
        $newcompl && (int) $newcompl->completionstate !== COMPLETION_INCOMPLETE,
        'the activity completion latched on the new copy');

    cr_check('applied_cv_bumped',
        applied_state::get_contentversion('course_content', 'coursecontent:' . $centralid) === 2,
        'applied content version is now 2');
    cr_check('restorelog_done',
        $DB->record_exists('local_syncqueue_restorelog',
            ['entitykey' => $coursekey, 'contentversion' => 2, 'status' => 'done']),
        'the restorelog row is marked done');

    // -----------------------------------------------------------------------
    // Phase 3 — idempotent re-apply of v2 (no third copy).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: idempotent re-apply ---');
    $before = $DB->count_records_select('course', 'id > 1');
    $again = $proc->apply_outbox_row(cr_content_row($centralid, 3, 2, $payload));
    cr_check('idempotent_reapply',
        $again === $newid && $DB->count_records_select('course', 'id > 1') === $before,
        're-applying content v2 is a no-op (resolves to the same copy, no new course)');

    // -----------------------------------------------------------------------
    // Phase 4 — crash-safety: a dangling 'restoring' log + marker corpse.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 4: crash recovery ---');
    $marker3 = 'syncrestore:' . $centralid . ':v3';
    $corpseid = (new backup_manager())->restore_course($mbzpath, $cat->id, (int) get_admin()->id);
    $DB->set_field('course', 'idnumber', $marker3, ['id' => $corpseid]);
    $DB->set_field('course', 'visible', 0, ['id' => $corpseid]);
    $DB->insert_record('local_syncqueue_restorelog', (object) [
        'entitykey' => $coursekey, 'centralcourseid' => $centralid, 'contentversion' => 3,
        'oldlocalid' => $newid, 'newlocalid' => $corpseid, 'marker' => $marker3, 'status' => 'restoring',
        'attempts' => 1, 'error' => null, 'timecreated' => time(), 'timemodified' => time(),
    ]);
    $v3 = $proc->apply_outbox_row(cr_content_row($centralid, 4, 3, $payload));
    $courseids[] = $v3;
    cr_check('corpse_cleaned', !$DB->record_exists('course', ['id' => $corpseid]),
        "the crashed attempt's marker corpse ({$corpseid}) was deleted");
    cr_check('crash_retried',
        $v3 > 0 && $v3 !== $corpseid
            && (int) applied_state::get('course', $coursekey)->localid === $v3,
        "the v3 restore was retried into a fresh copy ({$v3}) and promoted");
    cr_check('superseded_swept',
        $DB->count_records('local_syncqueue_restorelog', ['entitykey' => $coursekey]) === 1
            && $DB->record_exists('local_syncqueue_restorelog',
                ['entitykey' => $coursekey, 'contentversion' => 3, 'status' => 'done']),
        'lower-version restorelog rows were swept once v3 completed (only the v3 row remains)');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $crfailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup — every fixture course (template + all restored copies) shares the
// template fullname, so one sweep catches copies whose ids we never captured.
// ---------------------------------------------------------------------------
$restored = true;
// Match "ITEST CR Template" AND the "... copy N" variants Moodle's restore appends
// to a fullname collision (the archived old copies are never renamed back).
foreach ($DB->get_records_select('course', $DB->sql_like('fullname', ':f'),
        ['f' => 'ITEST CR Template%'], '', 'id') as $c) {
    try {
        delete_course((int) $c->id, false);
    } catch (\Throwable $e) {
        $restored = false;
    }
}
if ($userid) {
    $DB->delete_records('user', ['id' => $userid]);
}
if ($cohortid) {
    $DB->delete_records('cohort', ['id' => $cohortid]);
}
if (!empty($admincohortid)) {
    $DB->delete_records('cohort', ['id' => $admincohortid]);
}
$cid0 = $centralid ?? 0;
$DB->delete_records('local_syncqueue_restorelog', ['centralcourseid' => $cid0]);
$DB->delete_records('local_syncqueue_applied', ['entitykey' => 'course:' . $cid0]);
$DB->delete_records('local_syncqueue_applied', ['entitykey' => 'coursecontent:' . $cid0]);
$DB->delete_records('local_syncqueue_idmap', ['centralid' => $cid0, 'tablename' => 'course']);
foreach (glob($CFG->dataroot . '/local_syncqueue_backups/course_' . $cid0 . '_*.mbz') ?: [] as $p) {
    @unlink($p);
}
foreach (['mode', 'enabled'] as $n) {
    if ($saved[$n] === false) {
        unset_config($n, 'local_syncqueue');
    } else {
        set_config($n, $saved[$n], 'local_syncqueue');
    }
}
$leftover = $DB->count_records_select('course', $DB->sql_like('fullname', ':f'), ['f' => 'ITEST CR Template%'])
    + $DB->count_records_select('user', 'username = :u AND deleted = 0', ['u' => CR_USER]);
cr_check('cleanup_restored', $restored && $leftover === 0,
    $restored && $leftover === 0 ? 'all fixture courses, users, cohorts, rows and files removed'
        : "residue remains (leftover={$leftover})");

if (empty($crfailures)) {
    cli_writeln('SPIKE RESULT: PASS - crash-safe content re-restore verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($crfailures)));
exit(1);
