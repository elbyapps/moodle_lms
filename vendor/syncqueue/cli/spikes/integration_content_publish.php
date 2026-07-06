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
 * ELMS Sync v2 step 7 (part 1) integration spike: versioned course-content publication.
 *
 * Proves the central publish act + the change-scan (doc §7):
 *   1. publish_course_version builds a fresh .mbz, stamps cm UUIDs, and appends a
 *      course (upsert) + course_content (publish, contentversion 1) outbox pair.
 *   2. Re-publishing unchanged content is a no-op ('unchanged'), no new content row.
 *   3. A content edit is detected as drift (is_stale / stale_courses) and flagged
 *      by the change-scan into the content_stale_courses config.
 *   4. Re-publishing after drift bumps the content version (new .mbz filename) and
 *      clears the stale flag on the next scan.
 *
 * Builds a real .mbz (slow-ish). Restores every touched config/table/file.
 *
 * Usage:  php integration_content_publish.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_syncqueue\content_publisher;
use local_syncqueue\item_identity;
use local_syncqueue\task\content_change_scan;

const CP_SHORT = 'itestcp_course';
const CP_IDN = 'itestcp_idn';

$cpfailures = [];

/** Record + print one assertion. */
function cp_check(string $name, bool $ok, string $detail = ''): void {
    global $cpfailures;
    if (!$ok) {
        $cpfailures[] = $name;
    }
    cli_writeln('CHECK ' . $name . ': ' . ($ok ? 'OK' : 'FAIL') . ' ' . $detail);
}

/** Count course_content outbox rows for a course. */
function cp_content_rows(int $courseid): int {
    global $DB;
    return $DB->count_records('local_syncqueue_outbox',
        ['entitytype' => 'course_content', 'entitykey' => 'coursecontent:' . $courseid]);
}

global $DB, $CFG;

$confignames = ['mode', 'enabled', 'content_stale_courses', 'content_scan_lastrun', 'identity_map_autostamp'];
$saved = [];
foreach ($confignames as $n) {
    $saved[$n] = get_config('local_syncqueue', $n);
}

$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());

$courseid = 0;
$fatal = null;
try {
    set_config('mode', 'central', 'local_syncqueue');
    set_config('enabled', 1, 'local_syncqueue');

    // -----------------------------------------------------------------------
    // Phase 0 — fixture: a course with one graded module (a cm to stamp).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 0: fixture ---');
    $cat = \core_course_category::get_default();
    $course = create_course((object) [
        'fullname' => 'ITEST CP Course', 'shortname' => CP_SHORT, 'category' => $cat->id,
        'summary' => '', 'format' => 'topics', 'visible' => 1, 'idnumber' => CP_IDN,
    ]);
    $courseid = (int) $course->id;

    $mi = create_module((object) [
        'modulename' => 'page', 'course' => $courseid, 'section' => 0, 'visible' => 1,
        'name' => 'ITEST CP Page',
        'introeditor' => ['text' => 'intro', 'format' => FORMAT_HTML, 'itemid' => 0],
        'page' => ['text' => 'body v1', 'format' => FORMAT_HTML, 'itemid' => 0],
        'contenteditor' => ['text' => 'body v1', 'format' => FORMAT_HTML, 'itemid' => 0],
        'display' => 5, 'printheading' => 1, 'printintro' => 0, 'cmidnumber' => '',
    ]);
    $pagecmid = (int) $mi->coursemodule;
    $pageinstanceid = (int) $mi->instance;

    // -----------------------------------------------------------------------
    // Phase 1 — first publish.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 1: first publish ---');
    $r1 = content_publisher::publish_course_version($courseid);
    cp_check('publish_fresh', ($r1['status'] ?? '') === 'published' && (int) ($r1['contentversion'] ?? 0) === 1,
        'first publish -> status published, content v1 (' . json_encode($r1) . ')');

    $courserow = $DB->get_record('local_syncqueue_outbox',
        ['entitytype' => 'course', 'entitykey' => 'course:' . $courseid], '*', IGNORE_MULTIPLE);
    $contentrow = $DB->get_record('local_syncqueue_outbox',
        ['entitytype' => 'course_content', 'entitykey' => 'coursecontent:' . $courseid], '*', IGNORE_MULTIPLE);
    cp_check('rows_appended', $courserow && $contentrow,
        'a course (upsert) and a course_content (publish) row were appended');

    $cpayload = $contentrow ? json_decode((string) $contentrow->payload, true) : [];
    cp_check('content_row_shape',
        $contentrow && $contentrow->action === 'publish' && (int) $contentrow->contentversion === 1
            && !empty($cpayload['backup']['filename']) && isset($cpayload['content_mtime'])
            && $contentrow->partitionkey === 'content:course:course:' . $courseid,
        'content row: action publish, v1, carries backup.filename + content_mtime, right partition');

    $mbzpath = $CFG->dataroot . '/local_syncqueue_backups/' . ($cpayload['backup']['filename'] ?? 'none');
    cp_check('mbz_built', is_file($mbzpath), 'a real .mbz artifact was written (' . basename($mbzpath) . ')');

    $cmidnumber = (string) $DB->get_field('course_modules', 'idnumber', ['id' => $pagecmid]);
    cp_check('cm_uuid_stamped', item_identity::is_uuid($cmidnumber),
        'the course module was stamped with a UUID idnumber (' . $cmidnumber . ')');

    // -----------------------------------------------------------------------
    // Phase 2 — idempotent no-op on unchanged content.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 2: unchanged re-publish ---');
    $before = cp_content_rows($courseid);
    $r2 = content_publisher::publish_course_version($courseid);
    cp_check('idempotent_unchanged',
        ($r2['status'] ?? '') === 'unchanged' && cp_content_rows($courseid) === $before,
        'unchanged content -> no-op, no new content row (' . json_encode($r2) . ')');

    // -----------------------------------------------------------------------
    // Phase 3 — drift detection + change-scan flag.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 3: content drift ---');
    // Simulate an edit to the page's content strictly after the publish watermark
    // (the page instance carries timemodified; course_modules does not).
    $future = time() + 1000;
    $DB->set_field('page', 'timemodified', $future, ['id' => $pageinstanceid]);

    cp_check('drift_detected', content_publisher::is_stale($courseid),
        'a module content edit past the published watermark reads as stale');
    $stale = content_publisher::stale_courses();
    $staleids = array_map(static fn($s) => (int) $s['courseid'], $stale);
    cp_check('stale_courses_lists', in_array($courseid, $staleids, true),
        'stale_courses() includes the drifted course');

    (new content_change_scan())->execute();
    $flagged = json_decode((string) get_config('local_syncqueue', 'content_stale_courses'), true) ?: [];
    cp_check('changescan_flags', in_array($courseid, array_map('intval', $flagged), true),
        'the change-scan recorded the course in content_stale_courses');

    // -----------------------------------------------------------------------
    // Phase 4 — republish bumps the version and clears the flag.
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 4: republish after drift ---');
    sleep(1); // Guarantee a distinct .mbz filename (course_<id>_<time>.mbz, second-granular).
    $r4 = content_publisher::publish_course_version($courseid);
    cp_check('drift_bump',
        ($r4['status'] ?? '') === 'published' && (int) ($r4['contentversion'] ?? 0) === 2
            && cp_content_rows($courseid) === 2,
        'republish after drift -> status published, content v2, a second content row (' . json_encode($r4) . ')');

    (new content_change_scan())->execute();
    $flagged2 = json_decode((string) get_config('local_syncqueue', 'content_stale_courses'), true) ?: [];
    cp_check('flag_cleared', !in_array($courseid, array_map('intval', $flagged2), true),
        'after republish the change-scan no longer flags the course');

    // -----------------------------------------------------------------------
    // Phase 5 — STRUCTURAL drift: deleting a module lowers max(timemodified) but
    // must still be detected (the must-fix: mtime alone would hide the removal).
    // -----------------------------------------------------------------------
    cli_writeln('--- Phase 5: structural drift (module deletion) ---');
    $lastwatermark = (int) content_publisher::last_published_mtime($courseid);
    course_delete_module($pagecmid);
    $livemtime = content_publisher::content_max_mtime($courseid);
    cp_check('deletion_lowers_mtime', $livemtime <= $lastwatermark,
        "deleting the page did NOT advance max-mtime ({$livemtime} <= watermark {$lastwatermark}) — "
        . 'an mtime-only check would miss it');
    cp_check('deletion_detected', content_publisher::is_stale($courseid),
        'the structural signature flags the module deletion as drift');

} catch (\Throwable $e) {
    $fatal = $e->getMessage();
    cli_writeln('CHECK script_completed: FAIL ' . $fatal . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $cpfailures[] = 'script_completed';
}

// ---------------------------------------------------------------------------
// Cleanup and restoration proof.
// ---------------------------------------------------------------------------
$restored = true;
$detail = [];
if ($courseid) {
    // Outbox + applied-state rows for this course's entitykeys.
    foreach (['course:' . $courseid, 'coursecontent:' . $courseid] as $ek) {
        $DB->delete_records('local_syncqueue_outbox', ['entitykey' => $ek]);
        $DB->delete_records('local_syncqueue_applied', ['entitykey' => $ek]);
    }
    // .mbz artifacts for this course.
    foreach (glob($CFG->dataroot . '/local_syncqueue_backups/course_' . $courseid . '_*.mbz') ?: [] as $p) {
        @unlink($p);
    }
    // The fixture course.
    try {
        delete_course($courseid, false);
    } catch (\Throwable $e) {
        $restored = false;
        $detail[] = 'course delete failed: ' . $e->getMessage();
    }
}
foreach ($confignames as $n) {
    if ($saved[$n] === false) {
        unset_config($n, 'local_syncqueue');
    } else {
        set_config($n, $saved[$n], 'local_syncqueue');
    }
}
if ($courseid && ($DB->record_exists('course', ['shortname' => CP_SHORT])
        || $DB->record_exists('local_syncqueue_outbox', ['entitykey' => 'coursecontent:' . $courseid]))) {
    $restored = false;
    $detail[] = 'fixture rows remain';
}
cp_check('cleanup_restored', $restored,
    $restored ? 'fixture course, outbox/applied rows, .mbz files and configs all removed'
        : implode('; ', $detail));

if (empty($cpfailures)) {
    cli_writeln('SPIKE RESULT: PASS - versioned content publication verified');
    exit(0);
}
cli_writeln('SPIKE RESULT: FAIL - failed checks: ' . implode(', ', array_unique($cpfailures)));
exit(1);
