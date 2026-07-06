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

use local_syncqueue\outbox\applied_state;
use local_syncqueue\outbox\publisher;
use stdClass;

/**
 * Manager for outgoing updates to schools (Central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_manager {

    /** @var string Updates table */
    protected const TABLE = 'local_syncqueue_updates';

    /** @var string School updates tracking table */
    protected const TRACKING_TABLE = 'local_syncqueue_school_updates';

    /**
     * Queue an update for all schools.
     *
     * @param string $type Update type (course, user, enrolment, content).
     * @param string $action Action (create, update, delete).
     * @param array $data Update data.
     * @param int $priority Priority 1-10.
     * @return int Update ID.
     */
    public function queue_update(
        string $type,
        string $action,
        array $data,
        int $priority = 5,
        ?string $tradecode = null,
        ?string $level = null
    ): int {
        return $this->queue_update_for_school(null, $type, $action, $data, $priority, $tradecode, $level);
    }

    /**
     * Queue an update for a specific school.
     *
     * @param string|null $schoolid School ID (null for all schools).
     * @param string $type Update type.
     * @param string $action Action.
     * @param array $data Update data.
     * @param int $priority Priority.
     * @return int Update ID.
     */
    public function queue_update_for_school(
        ?string $schoolid,
        string $type,
        string $action,
        array $data,
        int $priority = 5,
        ?string $tradecode = null,
        ?string $level = null
    ): int {
        global $DB;

        $objecttable = $data['table'] ?? null;
        $objectid = $data['id'] ?? null;

        // Legacy write + v2 outbox publish must commit (or roll back) atomically
        // so the transactional-outbox guarantee holds (doc §4.1).
        $transaction = $DB->start_delegated_transaction();
        try {
            $updateid = null;

            // Dedup: if an un-downloaded update exists for the same object, update it
            // instead. LEGACY table only — v2 outbox rows are append-only and never
            // coalesced; supersession happens at pull read time instead (doc §4.2).
            if ($objectid !== null) {
                $params = [
                    'updatetype' => $type,
                    'action' => $action,
                    'objecttable' => $objecttable,
                    'objectid' => $objectid,
                ];
                if ($schoolid !== null) {
                    $params['schoolid'] = $schoolid;
                }
                $sql = "SELECT u.id
                          FROM {" . self::TABLE . "} u
                     LEFT JOIN {" . self::TRACKING_TABLE . "} t ON t.updateid = u.id
                         WHERE u.updatetype = :updatetype
                           AND u.action = :action
                           AND u.objecttable = :objecttable
                           AND u.objectid = :objectid
                           AND " . ($schoolid !== null ? "u.schoolid = :schoolid" : "u.schoolid IS NULL") . "
                           AND t.id IS NULL";
                $existing = $DB->get_record_sql($sql, $params);

                if ($existing) {
                    $DB->update_record(self::TABLE, (object) [
                        'id' => $existing->id,
                        'payload' => json_encode($data),
                        'priority' => $priority,
                        'tradecode' => $tradecode,
                        'level' => $level,
                        'timecreated' => time(),
                    ]);
                    $updateid = (int) $existing->id;
                }
            }

            if ($updateid === null) {
                $update = new stdClass();
                $update->schoolid = $schoolid;
                $update->updatetype = $type;
                $update->action = $action;
                $update->objecttable = $objecttable;
                $update->objectid = $objectid;
                $update->payload = json_encode($data);
                $update->priority = $priority;
                $update->tradecode = $tradecode;
                $update->level = $level;
                $update->timecreated = time();

                $updateid = $DB->insert_record(self::TABLE, $update);
            }

            $this->publish_to_outbox($schoolid, $type, $action, $data);

            $transaction->allow_commit();
            return $updateid;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Dual-write a queued legacy update into the v2 sequenced outbox.
     *
     * Step-1 scope: central-owned content entities only (course, category,
     * course_content) — user/enrolment updates stay legacy-only until the
     * roster stream exists. Rows are inserted with seq = NULL inside the
     * caller's transaction; the sequencer assigns seq post-commit. The v2
     * payload is the exact legacy payload so school appliers can be reused.
     *
     * @param string|null $schoolid Target school (null = broadcast).
     * @param string $type Legacy update type.
     * @param string $action Legacy action (create, update, delete).
     * @param array $data Legacy update payload.
     */
    protected function publish_to_outbox(?string $schoolid, string $type, string $action, array $data): void {
        global $DB;

        // Same central test the legacy capture surfaces use (courses.php, catalog.php).
        if (get_config('local_syncqueue', 'mode') !== 'central') {
            return;
        }
        // A box still on the pre-outbox schema keeps the legacy flow untouched.
        if (!$DB->get_manager()->table_exists('local_syncqueue_outbox')) {
            return;
        }
        if (!in_array($type, ['course', 'category', 'course_content'], true)) {
            return;
        }
        $objectid = (int) ($data['id'] ?? 0);
        if ($objectid <= 0) {
            return;
        }
        if ($schoolid !== null) {
            // Step-1 partitions (content:global / content:course:*) cannot target a
            // single school; school-scoped updates ship via the legacy channel only.
            debugging("Syncqueue outbox: school-scoped {$type} update for {$schoolid} not published to v2",
                DEBUG_DEVELOPER);
            return;
        }

        $v2action = ($action === 'delete') ? 'delete' : 'upsert';

        if ($type === 'category') {
            publisher::publish('category', 'category:' . $objectid, $v2action, $data, 'content:global');
            return;
        }

        $coursekey = 'course:' . $objectid;
        $partitionkey = 'content:course:' . $coursekey;

        if ($type === 'course') {
            publisher::publish('course', $coursekey, $v2action, $data, $partitionkey);
        }

        // A backup publication (or an explicit course_content update) also emits a
        // course_content publish row carrying the bumped .mbz publication version.
        if ($v2action !== 'delete' && ($type === 'course_content' || !empty($data['backup']['has_backup']))) {
            $contentkey = 'coursecontent:' . $objectid;
            $state = applied_state::get('course_content', $contentkey);
            // Matches the entityversion publish() assigns next: content publishes are
            // serialized per course through the push_courses adhoc task, so the
            // unlocked pre-read cannot race another publisher of the same course.
            $contentversion = $state ? ((int) $state->entityversion + 1) : 1;
            publisher::publish('course_content', $contentkey, 'publish', $data, $partitionkey, $contentversion);
        }
    }

    /**
     * Get pending updates for a school.
     *
     * @param string $schoolid School identifier.
     * @param int $since Get updates since this timestamp.
     * @param int $limit Maximum updates.
     * @return array Updates.
     */
    public function get_updates_for_school(string $schoolid, int $since = 0, int $limit = 100): array {
        global $DB;

        // Get updates that are either for this specific school or for all schools.
        // Exclude updates already downloaded by this school.
        //
        // Download ordering precedence (highest first):
        //   1. F3 — courses the school explicitly selected (per-course prefs).
        //   2. F2 — courses matching the school's trade/level priorities.
        //   3. The update's own priority, then age.
        // Everything is still delivered unless the school opted into "only selected",
        // which filters course updates down to its selection. Unmatched/unenriched
        // updates fall back to the existing priority/time order.
        $onlyselected = (int) $DB->get_field('local_syncqueue_schools', 'onlyselected', ['schoolid' => $schoolid]);

        $sql = "SELECT u.*
                FROM {" . self::TABLE . "} u
                LEFT JOIN {" . self::TRACKING_TABLE . "} t
                    ON t.updateid = u.id AND t.schoolid = :schoolid
                LEFT JOIN {local_syncqueue_school_trades} st
                    ON st.schoolid = :schoolid3
                   AND st.tradecode = u.tradecode
                   AND (st.level = u.level OR st.level IS NULL)
                LEFT JOIN {local_syncqueue_course_prefs} cp
                    ON cp.schoolid = :schoolid4
                   AND u.updatetype = 'course'
                   AND cp.courseid = u.objectid
                WHERE u.timecreated > :since
                    AND (u.schoolid IS NULL OR u.schoolid = :schoolid2)
                    AND t.id IS NULL
                    AND (:onlyselected = 0 OR u.updatetype <> 'course' OR cp.selected = 1)
                ORDER BY CASE WHEN cp.selected = 1 THEN 0 ELSE 1 END ASC,
                         COALESCE(cp.weight, 999999) ASC,
                         CASE WHEN st.id IS NOT NULL THEN 0 ELSE 1 END ASC,
                         COALESCE(st.weight, 0) ASC,
                         u.priority ASC,
                         u.timecreated ASC";

        $params = [
            'schoolid' => $schoolid,
            'schoolid2' => $schoolid,
            'schoolid3' => $schoolid,
            'schoolid4' => $schoolid,
            'since' => $since,
            'onlyselected' => $onlyselected,
        ];

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Mark an update as downloaded by a school.
     *
     * @param string $schoolid School identifier.
     * @param int $updateid Update ID.
     */
    public function mark_downloaded(string $schoolid, int $updateid): void {
        global $DB;

        // Check if already tracked.
        $exists = $DB->record_exists(self::TRACKING_TABLE, [
            'schoolid' => $schoolid,
            'updateid' => $updateid,
        ]);

        if (!$exists) {
            $tracking = new stdClass();
            $tracking->schoolid = $schoolid;
            $tracking->updateid = $updateid;
            $tracking->status = 'downloaded';
            $tracking->timedownloaded = time();

            $DB->insert_record(self::TRACKING_TABLE, $tracking);
        }
    }

    /**
     * Queue a course update.
     *
     * @param stdClass $course Course object.
     * @param string $action create, update, or delete.
     */
    public function queue_course_update(stdClass $course, string $action = 'update'): void {
        global $DB;

        // Get full category path.
        $categorypath = $this->get_category_path($course->category);

        $data = [
            'table' => 'course',
            'id' => $course->id,
            'shortname' => $course->shortname,
            'fullname' => $course->fullname,
            'idnumber' => $course->idnumber ?? '',
            'summary' => $course->summary ?? '',
            'summaryformat' => $course->summaryformat ?? FORMAT_HTML,
            'format' => $course->format ?? 'topics',
            'numsections' => $course->numsections ?? 10,
            'visible' => $course->visible,
            'startdate' => $course->startdate,
            'enddate' => $course->enddate,
            'category' => [
                'id' => $course->category,
                'path' => $categorypath,
            ],
        ];

        [$tradecode, $level] = $this->get_course_tradelevel($course->id);
        $this->queue_update('course', $action, $data, 2, $tradecode, $level);
    }

    /**
     * Queue a course update with backup file.
     *
     * @param stdClass $course Course object.
     * @param string $backupfile Backup filename.
     * @param string $action create, update, or delete.
     */
    public function queue_course_update_with_backup(stdClass $course, string $backupfile, string $action = 'create'): void {
        global $CFG;

        // Get full category path.
        $categorypath = $this->get_category_path($course->category);

        $data = [
            'table' => 'course',
            'id' => $course->id,
            'shortname' => $course->shortname,
            'fullname' => $course->fullname,
            'idnumber' => $course->idnumber ?? '',
            'summary' => $course->summary ?? '',
            'summaryformat' => $course->summaryformat ?? FORMAT_HTML,
            'format' => $course->format ?? 'topics',
            'numsections' => $course->numsections ?? 10,
            'visible' => $course->visible,
            'startdate' => $course->startdate,
            'enddate' => $course->enddate,
            'category' => [
                'id' => $course->category,
                'path' => $categorypath,
            ],
            'backup' => [
                'filename' => $backupfile,
                'has_backup' => true,
            ],
        ];

        [$tradecode, $level] = $this->get_course_tradelevel($course->id);
        $this->queue_update('course', $action, $data, 2, $tradecode, $level);
    }

    /**
     * Resolve a course's trade code and level from elby_dashboard enrichment metadata.
     *
     * Used to tag course updates so schools can prioritise their own trade/level
     * courses on download. Returns [null, null] when no enrichment exists.
     *
     * @param int $courseid Course id.
     * @return array{0:?string,1:?string} [tradecode, level]
     */
    protected function get_course_tradelevel(int $courseid): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('elby_course_meta')) {
            return [null, null];
        }
        $meta = $DB->get_record('elby_course_meta', ['courseid' => $courseid], 'trade_code, level');
        if (!$meta) {
            return [null, null];
        }
        $trade = ($meta->trade_code !== null && $meta->trade_code !== '') ? $meta->trade_code : null;
        $level = ($meta->level !== null && $meta->level !== '') ? $meta->level : null;
        return [$trade, $level];
    }

    /**
     * Get category path as array of category names.
     *
     * @param int $categoryid Category ID.
     * @return array Category path from root to leaf.
     */
    protected function get_category_path(int $categoryid): array {
        $path = [];
        $category = \core_course_category::get($categoryid, IGNORE_MISSING);

        if (!$category) {
            return $path;
        }

        // Build path from root to this category.
        $parents = $category->get_parents();
        foreach ($parents as $parentid) {
            $parent = \core_course_category::get($parentid, IGNORE_MISSING);
            if ($parent) {
                $path[] = [
                    'id' => $parent->id,
                    'name' => $parent->name,
                    'idnumber' => $parent->idnumber ?? '',
                ];
            }
        }

        // Add current category.
        $path[] = [
            'id' => $category->id,
            'name' => $category->name,
            'idnumber' => $category->idnumber ?? '',
        ];

        return $path;
    }

    /**
     * Queue a user update.
     *
     * @param stdClass $user User object.
     * @param string $action create, update, or delete.
     */
    public function queue_user_update(stdClass $user, string $action = 'update'): void {
        $data = [
            'table' => 'user',
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'idnumber' => $user->idnumber ?? '',
            'password' => $user->password,
        ];

        $this->queue_update('user', $action, $data, 2);
    }

    /**
     * Queue an enrolment update.
     *
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     * @param string $action create, update, or delete.
     * @param array $extra Extra enrolment data.
     */
    public function queue_enrolment_update(int $userid, int $courseid, string $action, array $extra = []): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, username, email, idnumber');
        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, idnumber');

        $data = [
            'table' => 'user_enrolments',
            'userid' => $userid,
            'courseid' => $courseid,
            'user' => $user ? (array) $user : [],
            'course' => $course ? (array) $course : [],
            'status' => $extra['status'] ?? 0,
            'timestart' => $extra['timestart'] ?? 0,
            'timeend' => $extra['timeend'] ?? 0,
        ];

        $this->queue_update('enrolment', $action, $data, 3);
    }

    /**
     * Build the course catalog visible to a school, with its current preferences.
     *
     * One entry per available course (deduplicated to the most recent update),
     * including the school's selection/weight so the picker can pre-fill.
     *
     * @param string $schoolid School identifier.
     * @return array List of [courseid, fullname, categorypath, tradecode, level, selected, weight].
     */
    public function get_catalog_for_school(string $schoolid): array {
        global $DB;

        $sql = "SELECT u.id, u.objectid AS courseid, u.payload, u.tradecode, u.level,
                       cp.selected, cp.weight
                  FROM {" . self::TABLE . "} u
             LEFT JOIN {local_syncqueue_course_prefs} cp
                    ON cp.schoolid = :s AND cp.courseid = u.objectid
                 WHERE u.updatetype = 'course'
                   AND (u.schoolid IS NULL OR u.schoolid = :s2)
              ORDER BY u.timecreated DESC";
        $rows = $DB->get_records_sql($sql, ['s' => $schoolid, 's2' => $schoolid]);

        $catalog = [];
        foreach ($rows as $row) {
            $courseid = (int) $row->courseid;
            if ($courseid <= 0 || isset($catalog[$courseid])) {
                continue; // Keep the most recent update per course.
            }
            $data = json_decode($row->payload, true) ?: [];
            $pathnames = [];
            if (!empty($data['category']['path']) && is_array($data['category']['path'])) {
                foreach ($data['category']['path'] as $cat) {
                    if (!empty($cat['name'])) {
                        $pathnames[] = $cat['name'];
                    }
                }
            }
            $catalog[$courseid] = [
                'courseid' => $courseid,
                'fullname' => (string) ($data['fullname'] ?? ('Course ' . $courseid)),
                'categorypath' => implode(' / ', $pathnames),
                'tradecode' => (string) ($row->tradecode ?? ''),
                'level' => (string) ($row->level ?? ''),
                'selected' => $row->selected !== null ? (bool) $row->selected : false,
                'weight' => (int) ($row->weight ?? 0),
            ];
        }
        return array_values($catalog);
    }

    /**
     * Cleanup old updates that all schools have downloaded.
     *
     * @param int $daysold Days old to consider for cleanup.
     * @return int Number of deleted updates.
     */
    public function cleanup_old_updates(int $daysold = 30): int {
        global $DB;

        $cutoff = time() - ($daysold * DAYSECS);

        // Delete updates older than cutoff that all active schools have downloaded.
        $sql = "DELETE FROM {" . self::TABLE . "}
                WHERE timecreated < :cutoff
                AND id NOT IN (
                    SELECT DISTINCT u.id
                    FROM {" . self::TABLE . "} u
                    CROSS JOIN {local_syncqueue_schools} s
                    LEFT JOIN {" . self::TRACKING_TABLE . "} t
                        ON t.updateid = u.id AND t.schoolid = s.schoolid
                    WHERE s.status = 'active'
                        AND t.id IS NULL
                        AND (u.schoolid IS NULL OR u.schoolid = s.schoolid)
                )";

        return $DB->execute($sql, ['cutoff' => $cutoff]);
    }
}
