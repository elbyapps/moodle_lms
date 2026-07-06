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
 * ELMS Sync v2 integration spike: assignment submission facts (capture + apply).
 *
 * Locks in the submission-fact review fixes (doc §8.1):
 *   1. CAPTURE: a plugin submission event (assignsubmission_file::submission_created)
 *      captures a v2 fact whose payload context.object is POPULATED — resolved via
 *      other['submissionid'] -> assign_submission, not the plugin objecttable which
 *      get_object_data cannot shape (which left central rejecting "Missing submission
 *      data"). The object carries groupid, attemptnumber and latest.
 *   2. APPLY fidelity: two attempts for one learner/assignment produce two DISTINCT
 *      central assign_submission rows (keyed assignment,userid,groupid,attemptnumber),
 *      never aliased onto one.
 *   3. APPLY latest: `latest` is recomputed deterministically from the max attemptnumber
 *      central holds — so applying an OLDER attempt LAST (out-of-order retry) still
 *      leaves `latest` on the newest attempt, not the one that happened to apply last.
 *
 * Single-site: the same box captures (school mode) and applies (central mode).
 * Self-cleaning: every fixture row/config is removed; asserts zero residue.
 *
 * Usage:  php integration_submission_v2.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_syncqueue\central_processor;
use local_syncqueue\observer;

$failures = 0;
function sub_check(string $name, bool $ok, string $detail): void {
    global $failures;
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK ' : 'FAIL ') . $detail);
    if (!$ok) { $failures++; }
}

$suffix = substr(md5(uniqid('itestsub', true)), 0, 6);
$school = 'itestsubschool';
$sdmsid = 'ITESTSUB' . strtoupper($suffix);

// Config snapshot.
$saved = [];
foreach (['mode', 'enabled', 'push_v2', 'schoolid'] as $k) {
    $saved[$k] = get_config('local_syncqueue', $k);
}

$fixt = [];
try {
    // ---- Fixtures ------------------------------------------------------------
    $cat = (int) $DB->get_field_sql('SELECT MIN(id) FROM {course_categories}') ?: 1;
    $course = create_course((object) [
        'fullname' => 'ITEST Sub ' . $suffix, 'shortname' => 'itestsub_' . $suffix,
        'idnumber' => 'central_' . (990000 + (hexdec(substr($suffix, 0, 4)) % 9000)), 'category' => $cat,
    ]);
    $fixt['course'] = (int) $course->id;
    $centralid = (int) str_replace('central_', '', (string) $course->idnumber);
    $assignmoduleid = (int) $DB->get_field('modules', 'id', ['name' => 'assign']);
    $assignid = (int) $DB->insert_record('assign', (object) [
        'course' => (int) $course->id, 'name' => 'ITEST Sub Assign', 'intro' => '', 'introformat' => 1]);
    $section = (int) $DB->get_field('course_sections', 'id', ['course' => (int) $course->id, 'section' => 0]);
    $cmid = (int) $DB->insert_record('course_modules', (object) [
        'course' => (int) $course->id, 'module' => $assignmoduleid, 'instance' => $assignid,
        'section' => $section, 'added' => time()]);
    $ctx = context_module::instance($cmid);
    $user = create_user_record('itestsub_' . $suffix, 'x');
    $fixt['user'] = (int) $user->id;
    $linkid = (int) $DB->insert_record('elby_sdms_users', (object) [
        'userid' => (int) $user->id, 'sdms_id' => $sdmsid, 'user_type' => 'student',
        'sync_status' => 1, 'timecreated' => time(), 'timemodified' => time()]);

    // ---- Phase 1: capture a plugin submission event (school mode) -------------
    cli_writeln('--- Phase 1: capture (populated object) ---');
    set_config('mode', 'school', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');
    set_config('push_v2', 1, 'local_syncqueue');
    set_config('schoolid', $school, 'local_syncqueue');

    // A real assign_submission the fact refers to.
    $subid = (int) $DB->insert_record('assign_submission', (object) [
        'assignment' => $assignid, 'userid' => (int) $user->id, 'groupid' => 0,
        'attemptnumber' => 0, 'latest' => 1, 'status' => 'submitted',
        'timecreated' => time(), 'timemodified' => time()]);

    $event = \assignsubmission_file\event\submission_created::create([
        'context' => $ctx, 'objectid' => $subid, 'relateduserid' => (int) $user->id,
        'other' => ['submissionid' => $subid, 'submissionattempt' => 0,
            'submissionstatus' => 'submitted', 'filesubmissioncount' => 1]]);
    observer::file_submission_created($event);

    $factrows = $DB->get_records_select('local_syncqueue_outbox',
        'partitionkey = :pk AND entitytype = :et',
        ['pk' => 'learner:school:' . $school, 'et' => 'submission']);
    $factrow = $factrows ? reset($factrows) : null;
    $object = $factrow ? (json_decode((string) $factrow->payload, true)['context']['object'] ?? []) : [];
    sub_check('capture_populated_object',
        $factrow !== null && !empty($object)
            && (int) ($object['assignment'] ?? 0) === $assignid
            && (int) ($object['userid'] ?? -1) === (int) $user->id
            && array_key_exists('groupid', $object) && array_key_exists('attemptnumber', $object)
            && array_key_exists('latest', $object),
        'plugin file-submission event captured a fact with a populated object'
        . ' (keys: ' . implode(',', array_keys($object)) . ')');

    // Clean the captured fact + ledger so Phase 2's central apply starts clean.
    foreach ($factrows as $r) {
        if ($r->ledgerid) { $DB->delete_records('local_syncqueue_ledger', ['id' => (int) $r->ledgerid]); }
    }
    $DB->delete_records('local_syncqueue_outbox', ['partitionkey' => 'learner:school:' . $school]);
    // Remove the capture-side submission row so the central apply builds its own.
    $DB->delete_records('assign_submission', ['id' => $subid]);

    // ---- Phase 2: apply two attempts out-of-order (central mode) --------------
    cli_writeln('--- Phase 2: apply fidelity + deterministic latest ---');
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');

    $mkpayload = function (int $attempt, int $localid) use ($centralid, $sdmsid, $assignid) {
        return ['eventtype' => 'submission', 'payload' => ['context' => [
            'user' => ['sdms_id' => $sdmsid, 'localid' => 111],
            'course' => ['idnumber' => 'central_' . $centralid, 'localid' => 222],
            'object' => [
                'table' => 'assign_submission', 'localid' => $localid, 'assignment' => 888,
                'userid' => 111, 'status' => 'submitted', 'timemodified' => time(),
                'assignname' => 'ITEST Sub Assign', 'assignidnumber' => '',
                'groupid' => 0, 'attemptnumber' => $attempt, 'latest' => 1,
            ],
        ], 'event' => []]];
    };

    $cp = new central_processor();
    $cp->set_authoritative(true);
    // Apply the NEWER attempt (1) first, then the OLDER attempt (0) last.
    $r1 = $cp->process_item($school, $mkpayload(1, 7001));
    $r0 = $cp->process_item($school, $mkpayload(0, 7000));

    $rows = $DB->get_records('assign_submission',
        ['assignment' => $assignid, 'userid' => (int) $user->id], 'attemptnumber ASC');
    $byattempt = [];
    foreach ($rows as $r) { $byattempt[(int) $r->attemptnumber] = $r; }

    sub_check('apply_distinct_attempts',
        ($r1['status'] ?? '') === 'success' && ($r0['status'] ?? '') === 'success'
            && count($rows) === 2 && isset($byattempt[0], $byattempt[1]),
        'two attempts produced two DISTINCT central rows (not aliased)');
    sub_check('apply_latest_out_of_order',
        isset($byattempt[0], $byattempt[1])
            && (int) $byattempt[1]->latest === 1 && (int) $byattempt[0]->latest === 0,
        'latest stayed on the newest attempt (1) even though attempt 0 applied LAST'
        . ' (a1.latest=' . ($byattempt[1]->latest ?? '?') . ', a0.latest=' . ($byattempt[0]->latest ?? '?') . ')');
} catch (\Throwable $e) {
    sub_check('no_exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    // ---- Teardown ------------------------------------------------------------
    if (isset($assignid)) {
        $DB->delete_records('assign_submission', ['assignment' => $assignid]);
        $DB->delete_records('assign', ['id' => $assignid]);
    }
    if (isset($linkid)) { $DB->delete_records('elby_sdms_users', ['id' => $linkid]); }
    if (!empty($fixt['user'])) { $DB->delete_records('user', ['id' => $fixt['user']]); }
    if (!empty($fixt['course'])) { delete_course($fixt['course'], false); }
    $DB->delete_records('local_syncqueue_outbox', ['partitionkey' => 'learner:school:' . $school]);
    $DB->delete_records('local_syncqueue_ledger', ['origin' => $school]);
    $DB->delete_records('local_syncqueue_idmap', ['schoolid' => $school]);
    foreach ($saved as $k => $v) {
        if ($v === false) { unset_config($k, 'local_syncqueue'); } else { set_config($k, $v, 'local_syncqueue'); }
    }
}

$residue = $DB->count_records_select('user', "username LIKE :u AND deleted = 0", ['u' => 'itestsub_%'])
    + $DB->count_records_select('course', $DB->sql_like('shortname', ':s'), ['s' => 'itestsub_%'])
    + $DB->count_records('local_syncqueue_outbox', ['partitionkey' => 'learner:school:' . $school]);
sub_check('cleanup_zero_residue', $residue === 0, "fixture rows/config removed (residue={$residue})");

cli_writeln('');
if ($failures === 0) {
    cli_writeln('SPIKE RESULT: PASS - v2 assignment submission capture + apply verified');
    exit(0);
}
cli_writeln("SPIKE RESULT: FAIL ({$failures} check(s))");
exit(1);
