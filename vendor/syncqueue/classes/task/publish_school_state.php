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

namespace local_syncqueue\task;

use core\task\adhoc_task;
use local_syncqueue\backup_manager;
use local_syncqueue\content_publisher;
use local_syncqueue\outbox\publisher;
use local_syncqueue\outbox\sequencer;
use stdClass;

/**
 * Adhoc task: republish a school's full entitled state at the outbox tail
 * (ELMS Sync v2 step 1, central side).
 *
 * This is the "minimal cutover snapshot": queued at a school's v2 cutover
 * (after its adoption pass), it appends fresh full-state rows for every
 * category and every entitled course, so the school starts from cursor 0
 * and still receives complete state. Cursors must NEVER be initialised to
 * MAX(seq) instead of running this.
 *
 * Row contract matches the capture path (update_manager::publish_to_outbox):
 * category/course metadata rows use action 'upsert' (the only full-state
 * action the school appliers accept — 'publish' is reserved for versioned
 * content artifacts), the course row embeds the backup block so an absent
 * course restores full content from it, and the course_content 'publish' row
 * carries the same full payload so it stays standalone-restorable.
 *
 * Republishing is idempotent by supersession: every row gets a new
 * entityversion, so older rows for the same entitykey are skipped at read
 * time and re-running the task (or running it for a second school on
 * shared partitions) is harmless.
 *
 * Queue with:
 *   $task = new \local_syncqueue\task\publish_school_state();
 *   $task->set_custom_data(['schoolid' => $schoolid]);
 *   \core\task\manager::queue_adhoc_task($task);
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publish_school_state extends adhoc_task {

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        if (get_config('local_syncqueue', 'mode') !== 'central') {
            mtrace('publish_school_state: not a central instance, skipping.');
            return;
        }

        $data = (array) $this->get_custom_data();
        $schoolid = trim((string) ($data['schoolid'] ?? ''));
        if ($schoolid === '') {
            mtrace('publish_school_state: missing schoolid in customdata, skipping.');
            return;
        }

        $school = $DB->get_record('local_syncqueue_schools', ['schoolid' => $schoolid]);
        if (!$school) {
            mtrace("publish_school_state: unknown school '{$schoolid}', skipping.");
            return;
        }
        if ($school->status !== 'active') {
            // Same gate as every other central-side surface: a snapshot for an
            // inactive school would only churn the shared partitions fleet-wide.
            mtrace("publish_school_state: school '{$schoolid}' status is '{$school->status}', skipping.");
            return;
        }

        // Categories first: lower outbox ids sequence first, so the school
        // applies parent categories before the courses that live in them.
        $categories = $DB->get_records('course_categories', null, 'depth ASC, id ASC');
        foreach ($categories as $category) {
            publisher::publish('category', 'category:' . $category->id, 'upsert',
                $this->category_payload($category, $categories), 'content:global');
        }
        mtrace('publish_school_state: published ' . count($categories) . ' categories.');

        $published = 0;
        $failed = 0;
        $skipped = 0;
        $nobackup = 0;
        foreach ($this->get_entitled_courses($school) as $course) {
            $entitykey = 'course:' . $course->id;
            $partitionkey = 'content:course:' . $entitykey;
            // Serialize against a concurrent operator publish for the same course
            // (same lock resource as content_publisher::publish_course_version) so
            // contentversion allocation cannot collide.
            $clock = \core\lock\lock_config::get_lock_factory('local_syncqueue')
                ->get_lock('contentpublish:course:' . $course->id, 10);
            if (!$clock) {
                $skipped++;
                mtrace("publish_school_state: course {$course->id} skipped (publish lock busy)");
                continue;
            }
            try {
                $payload = $this->course_payload($course, $categories);

                // Resolve the .mbz before the course row goes out: the course
                // row carries the backup block so a school where the course is
                // absent restores full content from it (capture-path contract).
                $filename = $this->resolve_backup((int) $course->id);
                if ($filename !== null) {
                    $payload['backup'] = [
                        'filename' => $filename,
                        'has_backup' => true,
                    ];
                }

                publisher::publish('course', $entitykey, 'upsert', $payload, $partitionkey);

                if ($filename !== null) {
                    publisher::publish('course_content', 'coursecontent:' . $course->id, 'publish',
                        $payload, $partitionkey, $this->next_contentversion((int) $course->id, $filename));
                } else {
                    // No artifact: a content row without a restorable backup
                    // could only wedge in the school's deadletter replay loop.
                    $nobackup++;
                }
                $published++;
            } catch (\Throwable $e) {
                // One bad course must not abort the school's snapshot.
                $failed++;
                mtrace("publish_school_state: course {$course->id} failed: " . $e->getMessage());
            } finally {
                $clock->release();
            }
        }

        $sequenced = sequencer::assign();
        mtrace("publish_school_state: school {$schoolid}: {$published} courses published"
            . ($nobackup ? " ({$nobackup} metadata-only, no content backup)" : '')
            . ", {$failed} failed"
            . ($skipped ? ", {$skipped} skipped (publish lock busy)" : '')
            . ", {$sequenced} outbox rows sequenced.");
    }

    /**
     * Courses this school is entitled to.
     *
     * Legacy entitlement semantics: schools with onlyselected = 1 receive only
     * their selected courses (local_syncqueue_course_prefs); everyone else
     * receives the full catalog (minus the site course).
     *
     * @param stdClass $school local_syncqueue_schools row.
     * @return stdClass[] Course records.
     */
    protected function get_entitled_courses(stdClass $school): array {
        global $DB;

        if (!empty($school->onlyselected)) {
            $sql = "SELECT c.*
                      FROM {course} c
                      JOIN {local_syncqueue_course_prefs} cp
                           ON cp.courseid = c.id AND cp.schoolid = :schoolid AND cp.selected = 1
                     WHERE c.id <> :siteid
                  ORDER BY c.id ASC";
            return $DB->get_records_sql($sql, ['schoolid' => $school->schoolid, 'siteid' => SITEID]);
        }

        return $DB->get_records_select('course', 'id <> :siteid', ['siteid' => SITEID], 'id ASC');
    }

    /**
     * Full metadata payload for a category.
     *
     * @param stdClass $category course_categories row.
     * @param stdClass[] $categories All categories keyed by id (path resolution).
     * @return array
     */
    protected function category_payload(stdClass $category, array $categories): array {
        return content_publisher::category_payload($category, $categories);
    }

    /**
     * Full metadata payload for a course (legacy update_manager field shape).
     *
     * @param stdClass $course Course row.
     * @param stdClass[] $categories All categories keyed by id (path resolution).
     * @return array
     */
    protected function course_payload(stdClass $course, array $categories): array {
        return content_publisher::course_payload($course, $categories);
    }

    /**
     * Category path root -> leaf as [{id, name, idnumber}].
     *
     * @param int $categoryid
     * @param stdClass[] $categories All categories keyed by id.
     * @return array
     */
    protected function category_path(int $categoryid, array $categories): array {
        return content_publisher::category_path($categoryid, $categories);
    }

    /**
     * Resolve the .mbz artifact for a course: newest existing sync backup, or
     * generate one now. Null when generation fails — the course then ships as
     * a metadata-only row and a later capture/snapshot re-run delivers content.
     *
     * @param int $courseid
     * @return string|null Backup filename, or null when none could be produced.
     */
    protected function resolve_backup(int $courseid): ?string {
        $filename = $this->find_latest_backup($courseid);
        if ($filename !== null) {
            return $filename;
        }
        return (new backup_manager())->create_course_backup($courseid, (int) get_admin()->id);
    }

    /**
     * Newest existing sync backup filename for a course, or null.
     *
     * Files are named course_<id>_<time>.mbz in dataroot/local_syncqueue_backups
     * (backup_manager's directory; its BACKUP_DIR const is protected).
     *
     * @param int $courseid
     * @return string|null
     */
    protected function find_latest_backup(int $courseid): ?string {
        global $CFG;

        $best = null;
        $besttime = -1;
        $pattern = $CFG->dataroot . '/local_syncqueue_backups/course_' . $courseid . '_*.mbz';
        foreach (glob($pattern) ?: [] as $path) {
            if (preg_match('/^course_' . $courseid . '_(\d+)\.mbz$/', basename($path), $matches)
                    && (int) $matches[1] > $besttime) {
                $besttime = (int) $matches[1];
                $best = basename($path);
            }
        }
        return $best;
    }

    /**
     * Content publication version for a course_content publish row.
     *
     * Republishing the same .mbz artifact keeps the version (schools that
     * already fetched it need not re-download); a new artifact bumps it.
     *
     * @param int $courseid
     * @param string|null $filename Backup filename being published, if any.
     * @return int
     */
    protected function next_contentversion(int $courseid, ?string $filename): int {
        return content_publisher::next_contentversion($courseid, $filename);
    }
}
