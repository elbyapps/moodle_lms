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

namespace local_syncqueue;

use local_syncqueue\outbox\publisher;
use local_syncqueue\outbox\sequencer;
use stdClass;

/**
 * Versioned course-content publication (ELMS Sync v2 step 7, doc §7, central side).
 *
 * The explicit publish act: builds a FRESH .mbz capturing the course's current
 * content, stamps cm/grade-item UUIDs (§5 identity, so restores preserve item
 * identity across versions), bumps the content publication version, and appends
 * the course + course_content outbox rows. Schools re-restore alongside and
 * migrate grades/completion by cm-UUID ({@see update_processor} content bump).
 *
 * A weekly change-scan ({@see \local_syncqueue\task\content_change_scan}) flags
 * published courses whose live content has drifted past the last published
 * version — bounded staleness surfaced for an explicit human publish act, never
 * an automatic re-backup storm.
 *
 * The per-course publish here and the full-fleet cutover snapshot
 * ({@see \local_syncqueue\task\publish_school_state}) share the payload
 * builders below so both emit byte-identical row shapes.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_publisher {

    /**
     * Publish a fresh content version of a course.
     *
     * Idempotent-by-supersession: each call appends a new entityversion, so a
     * re-run harmlessly supersedes. When the live content has NOT drifted since
     * the last published version, this is a no-op unless $force is set (avoids
     * re-backing-up unchanged courses on every operator click / scan sweep).
     *
     * @param int $courseid Local (central) course id.
     * @param bool $force Publish even when content has not drifted.
     * @return array{status:string, contentversion?:int, filename?:string, stampedcms?:int,
     *     stampedgis?:int, sequenced?:int, reason?:string}
     */
    public static function publish_course_version(int $courseid, bool $force = false): array {
        global $DB;

        if (get_config('local_syncqueue', 'mode') !== 'central' || !get_config('local_syncqueue', 'enabled')) {
            return ['status' => 'skipped', 'reason' => 'not an enabled central instance'];
        }
        if ($courseid == SITEID) {
            return ['status' => 'skipped', 'reason' => 'site course is never published'];
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return ['status' => 'skipped', 'reason' => "no such course {$courseid}"];
        }

        // Serialize publishes for THIS course. Version allocation is an unlocked
        // MAX(contentversion)+1 (next_contentversion), so two concurrent publishes
        // could stamp the SAME contentversion onto DIFFERENT .mbz payloads; the school
        // would then restore one and silently record the other's hash as applied,
        // reporting false digest convergence with the newer backup never restored. The
        // named lock spans the whole {stale check → backup build → version allocation →
        // outbox append → sequence}, so the MAX+1 read is consistent. The per-school
        // cutover (publish_school_state) takes the SAME lock resource.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_syncqueue');
        $lock = $lockfactory->get_lock('contentpublish:course:' . $courseid, 10);
        if (!$lock) {
            return ['status' => 'skipped', 'reason' => 'another publish is in progress for this course'];
        }
        try {
            // Nothing to do when content is already current (unless forced): the
            // change-scan calls this only for drifted courses, but an operator may
            // click publish on a fresh course.
            $currentmtime = self::content_max_mtime($courseid);
            if (!$force && !self::is_stale($courseid, $currentmtime)) {
                return ['status' => 'unchanged', 'contentversion' => self::current_contentversion($courseid)];
            }

            // 1. Stamp cm/grade-item UUID idnumbers first, so the .mbz built next
            //    carries stable item identity (backup/restore preserves idnumbers);
            //    grades/completion key on those UUIDs across version bumps. Idempotent.
            $stamp = item_identity::stamp_course($courseid, true);

            // 2. Build a FRESH .mbz capturing the course's CURRENT content — never
            //    reuse an older artifact here (that is the cutover path's job).
            $filename = (new backup_manager())->create_course_backup($courseid, (int) get_admin()->id);
            if ($filename === null) {
                return ['status' => 'failed', 'reason' => 'backup build produced no artifact'];
            }

            // 3. Assemble the shared payload + the content watermark the scan reads.
            $categories = $DB->get_records('course_categories', null, 'depth ASC, id ASC');
            $payload = self::course_payload($course, $categories);
            $payload['backup'] = ['filename' => $filename, 'has_backup' => true];
            $payload['content_mtime'] = $currentmtime;
            // Structural signature (module set + section ordering): drift the change-scan
            // must catch even when it advances no timemodified — deleting or reordering an
            // activity LOWERS max(timemodified), which an mtime-only watermark misses.
            $payload['content_sig'] = self::content_signature($courseid);

            $entitykey = 'course:' . $courseid;
            $partitionkey = 'content:course:' . $entitykey;

            // 4. Metadata row (in-place upsert on the school) + content row (triggers
            //    the school's re-restore when its contentversion advances).
            publisher::publish('course', $entitykey, 'upsert', $payload, $partitionkey);
            $contentversion = self::next_contentversion($courseid, $filename);
            publisher::publish('course_content', 'coursecontent:' . $courseid, 'publish',
                $payload, $partitionkey, $contentversion);

            $sequenced = sequencer::assign();

            return ['status' => 'published', 'contentversion' => $contentversion, 'filename' => $filename,
                'stampedcms' => (int) ($stamp->stampedcms ?? 0), 'stampedgis' => (int) ($stamp->stampedgis ?? 0),
                'sequenced' => $sequenced];
        } finally {
            $lock->release();
        }
    }

    /**
     * Max content timemodified across the course row, its sections and its module
     * instances. The change-scan compares this live value against the last
     * published watermark to flag drift.
     *
     * course_modules itself has no timemodified (only `added`), so structural
     * changes are read from course_sections.timemodified, and edits to an existing
     * activity's content from that module instance's own timemodified where it has
     * one. This is a HEURISTIC: content living in sub-tables or files (quiz
     * questions, book chapters, lesson pages, a swapped resource file) does not
     * always bump the main instance row, so this can miss such edits — the
     * structural signature ({@see content_signature}) covers add/delete/reorder,
     * and an operator can always force a republish. It intentionally errs toward
     * NOT flagging rather than churning; it is a review nudge, not a proof.
     *
     * @param int $courseid
     * @return int Unix time (0 for an empty/absent course).
     */
    public static function content_max_mtime(int $courseid): int {
        global $DB;

        $max = (int) $DB->get_field('course', 'timemodified', ['id' => $courseid]);
        $max = max($max, (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(timemodified), 0) FROM {course_sections} WHERE course = :c', ['c' => $courseid]));
        // `added` catches a newly created (or re-added) module even though the
        // module row itself is not otherwise time-stamped.
        $max = max($max, (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(added), 0) FROM {course_modules} WHERE course = :c', ['c' => $courseid]));

        // Edits to an existing activity's content update that instance's own
        // timemodified; iterate each module type present in the course.
        $dbman = $DB->get_manager();
        $modnames = $DB->get_fieldset_sql(
            'SELECT DISTINCT m.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :c', ['c' => $courseid]);
        foreach ($modnames as $modname) {
            // $modname is a Moodle-controlled module name, never user input.
            if (!$dbman->table_exists($modname) || !$dbman->field_exists($modname, 'timemodified')
                    || !$dbman->field_exists($modname, 'course')) {
                continue;
            }
            $max = max($max, (int) $DB->get_field_sql(
                "SELECT COALESCE(MAX(timemodified), 0) FROM {" . $modname . "} WHERE course = :c",
                ['c' => $courseid]));
        }
        return $max;
    }

    /**
     * The content watermark of the last published version: the content_mtime
     * recorded at publish, falling back to the .mbz build timestamp encoded in
     * the artifact filename (cutover-published courses carry no content_mtime).
     * Null when the course has never had a content row published.
     *
     * @param int $courseid
     * @return int|null
     */
    public static function last_published_mtime(int $courseid): ?int {
        global $DB;

        $rows = $DB->get_records('local_syncqueue_outbox',
            ['entitytype' => 'course_content', 'entitykey' => 'coursecontent:' . $courseid],
            'id DESC', 'id, payload', 0, 1);
        if (!$rows) {
            return null;
        }
        $payload = json_decode((string) reset($rows)->payload, true);
        if (isset($payload['content_mtime'])) {
            return (int) $payload['content_mtime'];
        }
        // Fallback: the artifact filename is course_<id>_<buildtime>.mbz.
        $filename = (string) ($payload['backup']['filename'] ?? '');
        if (preg_match('/^course_' . $courseid . '_(\d+)\.mbz$/', $filename, $m)) {
            return (int) $m[1];
        }
        // A content row exists but carries no readable watermark: treat as
        // unknown (never-versioned) so the scan flags it for an explicit publish.
        return null;
    }

    /**
     * Whether a course's live content has drifted past its last published version.
     * A course with a published content row but no readable watermark, or none at
     * all, is stale (it needs an explicit versioned publish).
     *
     * Two drift signals: an advanced max-timemodified (an in-place content edit),
     * OR a changed structural signature (a module added / deleted / reordered — such
     * a change can LOWER max-timemodified, which an mtime-only check would miss).
     *
     * @param int $courseid
     * @param int|null $currentmtime Pre-computed live mtime (recomputed if null).
     * @return bool
     */
    public static function is_stale(int $courseid, ?int $currentmtime = null): bool {
        $lastmtime = self::last_published_mtime($courseid);
        if ($lastmtime === null) {
            return true;
        }
        $currentmtime = $currentmtime ?? self::content_max_mtime($courseid);
        if ($currentmtime > $lastmtime) {
            return true;
        }
        // A structural change may advance no timemodified: compare the signature
        // too (only when the last version recorded one; cutover rows do not).
        $lastsig = self::last_published_sig($courseid);
        return $lastsig !== null && self::content_signature($courseid) !== $lastsig;
    }

    /**
     * A stable structural signature of a course: the sorted set of module UUID
     * idnumbers plus each section's ordered module sequence. Changes on any
     * module add / delete / move / reorder, independent of timemodified.
     *
     * @param int $courseid
     * @return string sha256 hex
     */
    public static function content_signature(int $courseid): string {
        global $DB;

        $cms = $DB->get_records('course_modules', ['course' => $courseid], 'id ASC', 'id, idnumber');
        $modtokens = [];
        foreach ($cms as $cm) {
            // Prefer the stable UUID; fall back to the local id so an unstamped
            // course still gets a signature that moves on add/delete.
            $idn = (string) $cm->idnumber;
            $modtokens[] = ($idn !== '' ? $idn : 'cm#' . (int) $cm->id);
        }
        sort($modtokens);

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC',
            'id, section, sequence');
        $sectokens = [];
        foreach ($sections as $s) {
            $sectokens[] = (int) $s->section . '=' . (string) $s->sequence;
        }

        return hash('sha256', json_encode(['mods' => $modtokens, 'sections' => $sectokens]));
    }

    /**
     * The structural signature recorded with the last published version, or null
     * when none was recorded (e.g. a cutover-only course_content row).
     *
     * @param int $courseid
     * @return string|null
     */
    public static function last_published_sig(int $courseid): ?string {
        global $DB;
        $rows = $DB->get_records('local_syncqueue_outbox',
            ['entitytype' => 'course_content', 'entitykey' => 'coursecontent:' . $courseid],
            'id DESC', 'id, payload', 0, 1);
        if (!$rows) {
            return null;
        }
        $payload = json_decode((string) reset($rows)->payload, true);
        return isset($payload['content_sig']) ? (string) $payload['content_sig'] : null;
    }

    /**
     * Published courses whose live content has drifted since their last version.
     * Scans only courses that already have a content row (an unpublished course
     * is not "stale", just unpublished).
     *
     * @return array<int, array{courseid:int, driftseconds:int, contentversion:int}>
     */
    public static function stale_courses(): array {
        global $DB;

        // Distinct course ids that have a course_content row.
        $keys = $DB->get_fieldset_sql(
            "SELECT DISTINCT entitykey FROM {local_syncqueue_outbox} WHERE entitytype = 'course_content'");
        $stale = [];
        foreach ($keys as $entitykey) {
            if (!preg_match('/^coursecontent:(\d+)$/', (string) $entitykey, $m)) {
                continue;
            }
            $courseid = (int) $m[1];
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                continue; // Course deleted locally; nothing to republish.
            }
            $live = self::content_max_mtime($courseid);
            if (self::is_stale($courseid, $live)) {
                $last = self::last_published_mtime($courseid);
                $stale[] = ['courseid' => $courseid, 'driftseconds' => $last === null ? -1 : max(0, $live - $last),
                    'contentversion' => self::current_contentversion($courseid)];
            }
        }
        return $stale;
    }

    /**
     * Current (max) published content version for a course, 0 if none.
     *
     * @param int $courseid
     * @return int
     */
    public static function current_contentversion(int $courseid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(contentversion), 0)
               FROM {local_syncqueue_outbox}
              WHERE entitytype = :t AND entitykey = :k',
            ['t' => 'course_content', 'k' => 'coursecontent:' . $courseid]);
    }

    /**
     * Content publication version for a course_content publish row.
     *
     * Republishing the same .mbz artifact keeps the version (schools that
     * already fetched it need not re-download); a new artifact bumps it. Shared
     * with the cutover snapshot task.
     *
     * @param int $courseid
     * @param string|null $filename Backup filename being published, if any.
     * @return int
     */
    public static function next_contentversion(int $courseid, ?string $filename): int {
        global $DB;

        $entitykey = 'coursecontent:' . $courseid;

        if ($filename !== null) {
            $latest = $DB->get_records('local_syncqueue_outbox',
                ['entitytype' => 'course_content', 'entitykey' => $entitykey],
                'id DESC', 'id, contentversion, payload', 0, 1);
            if ($latest) {
                $latest = reset($latest);
                $decoded = json_decode((string) $latest->payload, true);
                if (($decoded['backup']['filename'] ?? null) === $filename) {
                    return max(1, (int) $latest->contentversion);
                }
            }
        }

        $max = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(contentversion), 0)
               FROM {local_syncqueue_outbox}
              WHERE entitytype = :entitytype AND entitykey = :entitykey',
            ['entitytype' => 'course_content', 'entitykey' => $entitykey]);
        return $max + 1;
    }

    /**
     * Full metadata payload for a course (legacy update_manager field shape).
     * Shared by the cutover snapshot and the versioned publish act.
     *
     * @param stdClass $course Course row.
     * @param stdClass[] $categories All categories keyed by id (path resolution).
     * @return array
     */
    public static function course_payload(stdClass $course, array $categories): array {
        global $DB;

        // numsections lives in course_format_options, not the course row.
        $numsections = $DB->get_field('course_format_options', 'value', [
            'courseid' => $course->id,
            'format' => $course->format,
            'name' => 'numsections',
            'sectionid' => 0,
        ]);

        return [
            'table' => 'course',
            'id' => (int) $course->id,
            'shortname' => $course->shortname,
            'fullname' => $course->fullname,
            'idnumber' => $course->idnumber ?? '',
            'summary' => $course->summary ?? '',
            'summaryformat' => (int) ($course->summaryformat ?? FORMAT_HTML),
            'format' => $course->format ?? 'topics',
            'numsections' => ($numsections !== false) ? (int) $numsections : 10,
            'visible' => (int) $course->visible,
            'startdate' => (int) $course->startdate,
            'enddate' => (int) $course->enddate,
            'category' => [
                'id' => (int) $course->category,
                'path' => self::category_path((int) $course->category, $categories),
            ],
        ];
    }

    /**
     * Full metadata payload for a category. Shared with the cutover snapshot.
     *
     * @param stdClass $category course_categories row.
     * @param stdClass[] $categories All categories keyed by id (path resolution).
     * @return array
     */
    public static function category_payload(stdClass $category, array $categories): array {
        return [
            'table' => 'course_categories',
            'id' => (int) $category->id,
            'name' => $category->name,
            'idnumber' => $category->idnumber ?? '',
            'description' => $category->description ?? '',
            'descriptionformat' => (int) ($category->descriptionformat ?? FORMAT_HTML),
            'parent' => (int) $category->parent,
            'visible' => (int) $category->visible,
            'path' => self::category_path((int) $category->id, $categories),
        ];
    }

    /**
     * Category path root -> leaf as [{id, name, idnumber}].
     *
     * @param int $categoryid
     * @param stdClass[] $categories All categories keyed by id.
     * @return array
     */
    public static function category_path(int $categoryid, array $categories): array {
        $leaf = $categories[$categoryid] ?? null;
        if (!$leaf) {
            return [];
        }
        $path = [];
        foreach (explode('/', trim((string) $leaf->path, '/')) as $ancestorid) {
            $ancestor = $categories[(int) $ancestorid] ?? null;
            if ($ancestor) {
                $path[] = [
                    'id' => (int) $ancestor->id,
                    'name' => $ancestor->name,
                    'idnumber' => $ancestor->idnumber ?? '',
                ];
            }
        }
        return $path;
    }
}
