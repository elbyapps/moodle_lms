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

namespace local_syncqueue\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_syncqueue\backup_manager;
use local_syncqueue\outbox\applied_state;
use local_syncqueue\outbox\publisher;
use local_syncqueue\school_manager;

/**
 * External function: store a school's course pull preferences (F3).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_priorities extends external_api {

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key for authentication'),
            'onlyselected' => new external_value(PARAM_INT, 'Only deliver selected courses', VALUE_DEFAULT, 0),
            'priorities' => new external_value(PARAM_RAW, 'JSON: [{courseid,selected,weight}]'),
        ]);
    }

    /**
     * Store the preferences.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key.
     * @param int $onlyselected Filter flag.
     * @param string $priorities JSON array.
     * @return array
     */
    public static function execute(string $schoolid, string $apikey, int $onlyselected, string $priorities): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid,
            'apikey' => $apikey,
            'onlyselected' => $onlyselected,
            'priorities' => $priorities,
        ]);

        if (get_config('local_syncqueue', 'mode') !== 'central') {
            throw new \moodle_exception('error_notcentral', 'local_syncqueue');
        }
        if (!get_config('local_syncqueue', 'enabled')) {
            throw new \moodle_exception('error_disabled', 'local_syncqueue');
        }

        $schoolmanager = new school_manager();
        if (!$schoolmanager->verify_apikey($params['schoolid'], $params['apikey'])) {
            throw new \moodle_exception('error_authfailed', 'local_syncqueue');
        }
        $school = $schoolmanager->get_school($params['schoolid']);
        if (!$school || $school->status !== 'active') {
            throw new \moodle_exception('error_schoolinactive', 'local_syncqueue');
        }

        $prefs = json_decode($params['priorities'], true);
        if (!is_array($prefs)) {
            $prefs = [];
        }

        // Snapshot the previous selection AND the previous onlyselected flag before
        // set_course_prefs() replaces them, so v2 republish-on-subscribe can tell what
        // was just gained. ($school was fetched above, before the prefs were written.)
        $oldselected = array_map('intval', $DB->get_fieldset_select('local_syncqueue_course_prefs',
            'courseid', 'schoolid = :schoolid AND selected = 1', ['schoolid' => $params['schoolid']]));
        $oldonlyselected = (int) ($school->onlyselected ?? 0);

        $count = $schoolmanager->set_course_prefs($params['schoolid'], $prefs, (bool) $params['onlyselected']);

        $newselected = [];
        foreach ($prefs as $p) {
            $courseid = (int) ($p['courseid'] ?? 0);
            if ($courseid > 0 && !empty($p['selected'])) {
                $newselected[] = $courseid;
            }
        }
        $gained = array_diff($newselected, $oldselected);

        // Broadening from selected-only to all-courses: the selected-set diff misses
        // every previously-unselected course the school is now entitled to, and the
        // pull cursor has already advanced past their historical rows. Republish EVERY
        // non-site course so newly-entitled content lands now rather than waiting on
        // the weekly anti-entropy backstop. republish_gained_courses skips the site
        // course and dedups by entitykey at the outbox tail.
        if ($oldonlyselected === 1 && (int) $params['onlyselected'] === 0) {
            $allcourses = array_map('intval',
                $DB->get_fieldset_select('course', 'id', 'id <> :site', ['site' => SITEID]));
            $gained = array_values(array_unique(array_merge($gained, $allcourses)));
        }
        self::republish_gained_courses($gained);

        return [
            'status' => 'ok',
            'stored' => $count,
        ];
    }

    /**
     * ELMS Sync v2 republish-on-subscribe (doc section 4.2).
     *
     * Courses newly gained by the school's selection get fresh full-state rows
     * appended at the outbox tail, so they cannot hide behind a pull cursor
     * that already passed their original publish. No-op on legacy-only
     * deployments (no outbox classes/table) and never allowed to break the
     * legacy priorities flow (dual-stack).
     *
     * @param array $courseids Newly selected course ids.
     */
    private static function republish_gained_courses(array $courseids): void {
        global $DB;

        if (!$courseids
                || !class_exists('\\local_syncqueue\\outbox\\publisher')
                || !$DB->get_manager()->table_exists('local_syncqueue_outbox')) {
            return;
        }

        foreach ($courseids as $courseid) {
            try {
                $course = $DB->get_record('course', ['id' => $courseid]);
                if (!$course || (int) $course->id === SITEID) {
                    continue;
                }
                $partition = 'content:course:course:' . $course->id;
                // Resolve the newest still-on-disk legacy .mbz once: the course
                // row carries it too (mirroring queue_course_update_with_backup)
                // so a school lacking the course restores it WITH content instead
                // of an empty shell.
                $backupfilename = self::latest_backup_filename((int) $course->id);
                // Course rows use 'upsert' (never 'publish'): both the school
                // applier and update_manager::publish_to_outbox reserve
                // 'publish' for course_content rows.
                publisher::publish('course', 'course:' . $course->id, 'upsert',
                    self::course_payload($course, $backupfilename), $partition);
                // Same next-version derivation as update_manager::publish_to_outbox
                // keeps contentversion == entityversion across republishes.
                $contentkey = 'coursecontent:' . $course->id;
                $state = applied_state::get('course_content', $contentkey);
                $contentversion = $state ? ((int) $state->entityversion + 1) : 1;
                publisher::publish('course_content', $contentkey, 'publish',
                    self::course_content_payload((int) $course->id, $backupfilename), $partition,
                    $contentversion);
            } catch (\Throwable $e) {
                debugging('local_syncqueue v2 republish failed for course ' . $courseid . ': '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Full course metadata payload — the same fields the legacy download
     * update carries (update_manager::queue_course_update_with_backup()),
     * including the backup block so a school missing the course restores
     * content instead of creating an empty shell.
     *
     * @param \stdClass $course Course record.
     * @param string $backupfilename Newest restorable .mbz, '' if none.
     * @return array
     */
    private static function course_payload(\stdClass $course, string $backupfilename): array {
        return [
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
                'path' => self::category_path((int) $course->category),
            ],
            'backup' => [
                'filename' => $backupfilename,
                'has_backup' => $backupfilename !== '',
            ],
        ];
    }

    /**
     * Payload for a course_content publish row.
     *
     * @param int $courseid Central course id.
     * @param string $backupfilename Newest restorable .mbz, '' if none.
     * @return array
     */
    private static function course_content_payload(int $courseid, string $backupfilename): array {
        return [
            'table' => 'course',
            'id' => $courseid,
            'backup' => [
                'filename' => $backupfilename,
                'has_backup' => $backupfilename !== '',
            ],
        ];
    }

    /**
     * The most recent still-on-disk .mbz the legacy channel produced for this
     * course (step 1 has no v2 content pipeline; schools fetch via the legacy
     * backup download). '' when none exists.
     *
     * @param int $courseid Central course id.
     * @return string
     */
    private static function latest_backup_filename(int $courseid): string {
        global $DB;

        $updates = $DB->get_records_sql(
            "SELECT id, payload
               FROM {local_syncqueue_updates}
              WHERE updatetype = 'course' AND objectid = :courseid
           ORDER BY timecreated DESC, id DESC", ['courseid' => $courseid], 0, 20);
        $backupmanager = new backup_manager();
        foreach ($updates as $update) {
            $data = json_decode($update->payload, true);
            $candidate = (string) ($data['backup']['filename'] ?? '');
            if ($candidate !== '' && $backupmanager->get_backup_path($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Category path from root to leaf, matching the legacy payload shape
     * (update_manager::get_category_path(); hidden categories are included
     * because publication must not depend on the webservice user's view).
     *
     * @param int $categoryid Category id.
     * @return array
     */
    private static function category_path(int $categoryid): array {
        $path = [];
        $category = \core_course_category::get($categoryid, IGNORE_MISSING, true);
        if (!$category) {
            return $path;
        }

        foreach ($category->get_parents() as $parentid) {
            $parent = \core_course_category::get($parentid, IGNORE_MISSING, true);
            if ($parent) {
                $path[] = [
                    'id' => $parent->id,
                    'name' => $parent->name,
                    'idnumber' => $parent->idnumber ?? '',
                ];
            }
        }

        $path[] = [
            'id' => $category->id,
            'name' => $category->name,
            'idnumber' => $category->idnumber ?? '',
        ];

        return $path;
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'stored' => new external_value(PARAM_INT, 'Number of preferences stored'),
        ]);
    }
}
