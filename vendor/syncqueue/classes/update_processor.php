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

use stdClass;

/**
 * Processor for updates downloaded from the central server.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_processor {

    /** @var id_mapper ID mapping helper */
    protected id_mapper $mapper;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->mapper = new id_mapper();
    }

    /**
     * Process a batch of updates from the central server.
     *
     * @param array $updates Array of update records.
     * @return array Results with success/failed/skipped counts.
     */
    public function process(array $updates): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($updates as $update) {
            try {
                $processed = $this->process_update($update);
                if ($processed) {
                    $results['success']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'update' => $update,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Apply a single update (public wrapper for async per-item processing).
     *
     * @param array $update Update data.
     * @return bool True if processed, false if skipped.
     * @throws \Exception On failure.
     */
    public function apply_update(array $update): bool {
        return $this->process_update($update);
    }

    /**
     * Process a single update.
     *
     * @param array $update Update data.
     * @return bool True if processed, false if skipped.
     */
    protected function process_update(array $update): bool {
        $type = $update['type'] ?? null;

        switch ($type) {
            case 'course':
                return $this->process_course_update($update);

            case 'user':
                return $this->process_user_update($update);

            case 'enrolment':
                return $this->process_enrolment_update($update);

            case 'course_content':
                return $this->process_content_update($update);

            default:
                debugging("Unknown update type: {$type}", DEBUG_DEVELOPER);
                return false;
        }
    }

    /**
     * Process a course update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_course_update(array $update): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];
        $action = $update['action'] ?? 'update';
        $centralid = $data['id'];

        // Check if we already have this course mapped.
        $localid = $this->mapper->get_local_id('course', $centralid);

        if ($action === 'delete') {
            if ($localid) {
                delete_course($localid, false);
                $this->mapper->delete_mapping('course', $localid);
            }
            return true;
        }

        if ($localid) {
            // Update existing course.
            $course = $DB->get_record('course', ['id' => $localid]);
            if ($course) {
                $course->fullname = $data['fullname'];
                $course->shortname = $this->ensure_unique_shortname($data['shortname'], $localid);
                $course->summary = $data['summary'] ?? '';
                $course->visible = $data['visible'] ?? 1;
                $course->startdate = $data['startdate'] ?? time();
                $course->enddate = $data['enddate'] ?? 0;
                $course->timemodified = time();
                update_course($course);
                return true;
            }
        }

        // Fallback: try to find course by idnumber or shortname before creating a new one.
        $existingcourse = $DB->get_record('course', ['idnumber' => 'central_' . $centralid]);
        if (!$existingcourse && !empty($data['idnumber'])) {
            $existingcourse = $DB->get_record('course', ['idnumber' => $data['idnumber']]);
        }
        if (!$existingcourse && !empty($data['shortname'])) {
            $existingcourse = $DB->get_record('course', ['shortname' => $data['shortname']]);
        }
        if ($existingcourse) {
            // Repair the mapping and update.
            $this->mapper->set_mapping('course', $existingcourse->id, $centralid);
            $existingcourse->fullname = $data['fullname'];
            $existingcourse->shortname = $this->ensure_unique_shortname($data['shortname'], $existingcourse->id);
            $existingcourse->summary = $data['summary'] ?? '';
            $existingcourse->visible = $data['visible'] ?? 1;
            $existingcourse->startdate = $data['startdate'] ?? time();
            $existingcourse->enddate = $data['enddate'] ?? 0;
            $existingcourse->timemodified = time();
            update_course($existingcourse);
            return true;
        }

        // Course doesn't exist locally - create it.
        // Check if we have a backup to restore.
        if (!empty($data['backup']) && !empty($data['backup']['has_backup'])) {
            return $this->restore_course_from_backup($data, $centralid);
        }

        return $this->create_course_from_central($data, $centralid);
    }

    /**
     * Restore a course from a backup file.
     *
     * @param array $data Course data with backup info.
     * @param int $centralid Central course ID.
     * @return bool Success.
     */
    protected function restore_course_from_backup(array $data, int $centralid): bool {
        global $CFG, $USER;

        $backupinfo = $data['backup'];
        $filename = $backupinfo['filename'];

        // Get or create the category.
        $categoryid = $this->get_or_create_category_from_path($data['category'] ?? null);

        // Download the backup file.
        $tempdir = make_temp_directory('syncqueue_restore');
        $backuppath = $tempdir . '/' . $filename;

        try {
            $client = new sync_client();
            $downloaded = $client->download_backup($filename, $backuppath);

            if (!$downloaded) {
                // Fall back to metadata-only creation.
                return $this->create_course_from_central($data, $centralid);
            }

            // Restore the course.
            $backupmanager = new backup_manager();
            $userid = $USER->id ?: get_admin()->id;

            $newcourseid = $backupmanager->restore_course($backuppath, $categoryid, $userid);

            // Clean up temp file.
            @unlink($backuppath);

            if (!$newcourseid) {
                // Fall back to metadata-only creation.
                return $this->create_course_from_central($data, $centralid);
            }

            // Update course with correct metadata.
            global $DB;
            $course = $DB->get_record('course', ['id' => $newcourseid]);
            if ($course) {
                $course->shortname = $this->ensure_unique_shortname($data['shortname'], $newcourseid);
                $course->fullname = $data['fullname'];
                $course->idnumber = !empty($data['idnumber']) ? $data['idnumber'] : 'central_' . $centralid;
                $course->visible = $data['visible'] ?? 1;
                $DB->update_record('course', $course);
            }

            // Save mapping.
            $this->mapper->set_mapping('course', $newcourseid, $centralid);

            return true;

        } catch (\Exception $e) {
            debugging('Restore failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            @unlink($backuppath);
            // Fall back to metadata-only creation.
            return $this->create_course_from_central($data, $centralid);
        }
    }

    /**
     * Create a new course from central server data.
     *
     * @param array $data Course data from central.
     * @param int $centralid Central course ID.
     * @return bool Success.
     */
    protected function create_course_from_central(array $data, int $centralid): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        // Get or create the category (matching central's structure).
        $categoryid = $this->get_or_create_category_from_path($data['category'] ?? null);

        // Prepare course data.
        $coursedata = new stdClass();
        $coursedata->fullname = $data['fullname'];
        $coursedata->shortname = $this->ensure_unique_shortname($data['shortname']);
        $coursedata->category = $categoryid;
        $coursedata->summary = $data['summary'] ?? '';
        $coursedata->summaryformat = $data['summaryformat'] ?? FORMAT_HTML;
        $coursedata->format = $data['format'] ?? 'topics';
        $coursedata->visible = $data['visible'] ?? 1;
        $coursedata->startdate = $data['startdate'] ?? time();
        $coursedata->enddate = $data['enddate'] ?? 0;
        $coursedata->idnumber = !empty($data['idnumber']) ? $data['idnumber'] : 'central_' . $centralid;
        $coursedata->numsections = $data['numsections'] ?? 10;

        try {
            $newcourse = create_course($coursedata);

            // Save mapping.
            $this->mapper->set_mapping('course', $newcourse->id, $centralid);

            return true;
        } catch (\Exception $e) {
            debugging('Failed to create course: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Get or create category from a category path.
     *
     * @param array|null $categorydata Category data with path.
     * @return int Category ID.
     */
    protected function get_or_create_category_from_path(?array $categorydata): int {
        global $DB;

        // If no category data, use default sync category.
        if (empty($categorydata) || empty($categorydata['path'])) {
            return $this->get_sync_category();
        }

        $path = $categorydata['path'];
        $parentid = 0;
        $lastcategoryid = 0;

        foreach ($path as $catinfo) {
            $name = $catinfo['name'];
            $idnumber = $catinfo['idnumber'] ?? '';

            // Try to find existing category by idnumber first, then by name+parent.
            $category = null;
            if (!empty($idnumber)) {
                $category = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
            }
            if (!$category) {
                $category = $DB->get_record('course_categories', [
                    'name' => $name,
                    'parent' => $parentid,
                ]);
            }

            if ($category) {
                $lastcategoryid = $category->id;
                $parentid = $category->id;
            } else {
                // Create the category.
                $newcatdata = new stdClass();
                $newcatdata->name = $name;
                $newcatdata->idnumber = $idnumber ?: null;
                $newcatdata->parent = $parentid;
                $newcatdata->description = '';

                $newcategory = \core_course_category::create($newcatdata);
                $lastcategoryid = $newcategory->id;
                $parentid = $newcategory->id;
            }
        }

        return $lastcategoryid ?: $this->get_sync_category();
    }

    /**
     * Get or create the category for synced courses.
     *
     * @return int Category ID.
     */
    protected function get_sync_category(): int {
        global $DB;

        // Check for existing sync category.
        $category = $DB->get_record('course_categories', ['idnumber' => 'syncqueue_courses']);

        if ($category) {
            return $category->id;
        }

        // Create the category.
        $categorydata = new stdClass();
        $categorydata->name = get_string('syncedcourses', 'local_syncqueue');
        $categorydata->idnumber = 'syncqueue_courses';
        $categorydata->description = get_string('syncedcoursesdesc', 'local_syncqueue');
        $categorydata->parent = 0;

        $newcategory = \core_course_category::create($categorydata);

        return $newcategory->id;
    }

    /**
     * Ensure course shortname is unique.
     *
     * @param string $shortname Desired shortname.
     * @param int|null $excludeid Exclude this course ID from check.
     * @return string Unique shortname.
     */
    protected function ensure_unique_shortname(string $shortname, ?int $excludeid = null): string {
        global $DB;

        $params = ['shortname' => $shortname];
        $where = 'shortname = :shortname';

        if ($excludeid) {
            $where .= ' AND id != :excludeid';
            $params['excludeid'] = $excludeid;
        }

        if (!$DB->record_exists_select('course', $where, $params)) {
            return $shortname;
        }

        // Add suffix to make unique.
        $counter = 1;
        do {
            $newshortname = $shortname . '_' . $counter;
            $params['shortname'] = $newshortname;
            $counter++;
        } while ($DB->record_exists_select('course', $where, $params));

        return $newshortname;
    }

    /**
     * Process a user update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_user_update(array $update): bool {
        global $DB;

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];
        $centralid = $data['id'];

        // Try to find user by email or username.
        $user = $DB->get_record('user', ['email' => $data['email']]);
        if (!$user) {
            $user = $DB->get_record('user', ['username' => $data['username']]);
        }

        if ($user) {
            // Update mapping.
            $this->mapper->set_mapping('user', $user->id, $centralid);

            // Update user fields. Central server overrides school credentials.
            $user->firstname = $data['firstname'];
            $user->lastname = $data['lastname'];
            $user->idnumber = $data['idnumber'] ?? '';
            if (!empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->timemodified = time();
            $DB->update_record('user', $user);
            return true;
        }

        // Create new user.
        $newuser = new stdClass();
        $newuser->username = $data['username'];
        $newuser->email = $data['email'];
        $newuser->firstname = $data['firstname'];
        $newuser->lastname = $data['lastname'];
        $newuser->idnumber = $data['idnumber'] ?? '';
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $DB->get_field('mnet_host', 'id', ['wwwroot' => $GLOBALS['CFG']->wwwroot]);
        $newuser->password = !empty($data['password']) ? $data['password'] : hash_internal_user_password(random_string(20));
        $newuser->timecreated = time();
        $newuser->timemodified = time();

        $localid = $DB->insert_record('user', $newuser);

        // Save mapping.
        $this->mapper->set_mapping('user', $localid, $centralid);

        return true;
    }

    /**
     * Process an enrolment update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_enrolment_update(array $update): bool {
        global $DB;

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];

        // DEBUG: Log the incoming enrolment data.
        error_log('[SYNCQUEUE ENROL DEBUG] Raw data: ' . json_encode($data));
        error_log('[SYNCQUEUE ENROL DEBUG] userid=' . ($data['userid'] ?? 'NULL') . ' courseid=' . ($data['courseid'] ?? 'NULL'));
        error_log('[SYNCQUEUE ENROL DEBUG] user info: ' . json_encode($data['user'] ?? 'MISSING'));
        error_log('[SYNCQUEUE ENROL DEBUG] course info: ' . json_encode($data['course'] ?? 'MISSING'));

        // Get local IDs.
        $localuserid = $this->mapper->get_local_id('user', $data['userid']);
        $localcourseid = $this->mapper->get_local_id('course', $data['courseid']);

        error_log('[SYNCQUEUE ENROL DEBUG] Mapper results: localuserid=' . ($localuserid ?? 'NULL') . ' localcourseid=' . ($localcourseid ?? 'NULL'));

        // Fallback: look up user by email/username.
        if (!$localuserid && !empty($data['user'])) {
            $userinfo = $data['user'];
            $localuser = null;
            if (!empty($userinfo['email'])) {
                $localuser = $DB->get_record('user', ['email' => $userinfo['email']]);
                error_log('[SYNCQUEUE ENROL DEBUG] User fallback by email "' . $userinfo['email'] . '": ' . ($localuser ? 'found id=' . $localuser->id : 'NOT FOUND'));
            }
            if (!$localuser && !empty($userinfo['username'])) {
                $localuser = $DB->get_record('user', ['username' => $userinfo['username']]);
                error_log('[SYNCQUEUE ENROL DEBUG] User fallback by username "' . $userinfo['username'] . '": ' . ($localuser ? 'found id=' . $localuser->id : 'NOT FOUND'));
            }
            if ($localuser) {
                $localuserid = $localuser->id;
                $this->mapper->set_mapping('user', $localuser->id, $data['userid']);
            }
        }

        // Fallback: look up course by idnumber or shortname.
        if (!$localcourseid && !empty($data['course'])) {
            $courseinfo = $data['course'];
            $localcourse = null;
            if (!empty($courseinfo['idnumber'])) {
                $localcourse = $DB->get_record('course', ['idnumber' => $courseinfo['idnumber']]);
                error_log('[SYNCQUEUE ENROL DEBUG] Course fallback by idnumber "' . $courseinfo['idnumber'] . '": ' . ($localcourse ? 'found id=' . $localcourse->id : 'NOT FOUND'));
            }
            if (!$localcourse) {
                $centralidnumber = 'central_' . $data['courseid'];
                $localcourse = $DB->get_record('course', ['idnumber' => $centralidnumber]);
                error_log('[SYNCQUEUE ENROL DEBUG] Course fallback by central idnumber "' . $centralidnumber . '": ' . ($localcourse ? 'found id=' . $localcourse->id : 'NOT FOUND'));
            }
            if (!$localcourse && !empty($courseinfo['shortname'])) {
                $localcourse = $DB->get_record('course', ['shortname' => $courseinfo['shortname']]);
                error_log('[SYNCQUEUE ENROL DEBUG] Course fallback by shortname "' . $courseinfo['shortname'] . '": ' . ($localcourse ? 'found id=' . $localcourse->id : 'NOT FOUND'));
            }
            if ($localcourse) {
                $localcourseid = $localcourse->id;
                $this->mapper->set_mapping('course', $localcourse->id, $data['courseid']);
            }
        }

        error_log('[SYNCQUEUE ENROL DEBUG] Final: localuserid=' . ($localuserid ?? 'NULL') . ' localcourseid=' . ($localcourseid ?? 'NULL'));

        if (!$localuserid || !$localcourseid) {
            error_log('[SYNCQUEUE ENROL DEBUG] SKIPPING enrolment - missing ' . (!$localuserid ? 'user' : '') . (!$localcourseid ? ' course' : ''));
            return false; // Can't enrol without both.
        }

        // Get manual enrol instance.
        $enrol = $DB->get_record('enrol', [
            'courseid' => $localcourseid,
            'enrol' => 'manual',
        ]);

        if (!$enrol) {
            return false;
        }

        // Check if already enrolled.
        $existing = $DB->get_record('user_enrolments', [
            'enrolid' => $enrol->id,
            'userid' => $localuserid,
        ]);

        if ($existing) {
            // Update status if needed.
            if ($existing->status != $data['status']) {
                $existing->status = $data['status'];
                $existing->timemodified = time();
                $DB->update_record('user_enrolments', $existing);
            }
            return true;
        }

        // Create enrolment.
        $enrolment = new stdClass();
        $enrolment->enrolid = $enrol->id;
        $enrolment->userid = $localuserid;
        $enrolment->status = $data['status'] ?? 0;
        $enrolment->timestart = $data['timestart'] ?? 0;
        $enrolment->timeend = $data['timeend'] ?? 0;
        $enrolment->timecreated = time();
        $enrolment->timemodified = time();

        $DB->insert_record('user_enrolments', $enrolment);

        // Assign role.
        $context = \context_course::instance($localcourseid);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        if ($roleid) {
            role_assign($roleid, $localuserid, $context->id);
        }

        return true;
    }

    /**
     * Process a course content update (requires backup/restore).
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_content_update(array $update): bool {
        // Course content updates require backup/restore.
        // This is a placeholder for the more complex implementation.

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];
        $backupurl = $data['backup_url'] ?? null;

        if (!$backupurl) {
            return false;
        }

        // TODO: Download backup file and restore.
        // This requires significant implementation for:
        // 1. Download backup file from central server
        // 2. Extract and validate
        // 3. Run restore process
        // 4. Update ID mappings

        return false;
    }
}
