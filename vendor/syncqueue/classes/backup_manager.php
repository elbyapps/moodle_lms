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

use backup;
use backup_controller;
use restore_controller;
use restore_dbops;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Manager for course backup and restore operations.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_manager {

    /** @var string Directory for sync backups */
    protected const BACKUP_DIR = 'local_syncqueue_backups';

    /**
     * Create a backup of a course for sync.
     *
     * @param int $courseid Course ID.
     * @param int $userid User ID performing the backup.
     * @return string|null Path to backup file, or null on failure.
     */
    public function create_course_backup(int $courseid, int $userid): ?string {
        global $CFG;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        try {
            // Create backup controller.
            $bc = new backup_controller(
                backup::TYPE_1COURSE,
                $courseid,
                backup::FORMAT_MOODLE,
                backup::INTERACTIVE_NO,
                backup::MODE_GENERAL,
                $userid
            );

            // Configure backup settings for full course.
            $plan = $bc->get_plan();

            // Set settings for a complete backup.
            $settings = [
                'users' => false,           // Don't include user data.
                'anonymize' => false,
                'role_assignments' => false,
                'activities' => true,       // Include activities.
                'blocks' => true,           // Include blocks.
                'filters' => true,          // Include filters.
                'comments' => false,        // No comments.
                'badges' => false,          // No badges.
                'calendarevents' => false,  // No calendar events.
                'userscompletion' => false, // No completion data.
                'logs' => false,            // No logs.
                'grade_histories' => false, // No grade history.
                'questionbank' => true,     // Include question bank.
                'groups' => false,          // No groups.
                'competencies' => false,    // No competencies.
                'contentbankcontent' => true, // Include content bank.
                'legacyfiles' => true,      // Include legacy files.
            ];

            foreach ($settings as $name => $value) {
                if ($plan->setting_exists($name)) {
                    $setting = $plan->get_setting($name);
                    if ($setting->get_status() == \base_setting::NOT_LOCKED) {
                        $setting->set_value($value);
                    }
                }
            }

            // Execute backup.
            $bc->execute_plan();

            // Get the backup file.
            $results = $bc->get_results();
            $file = $results['backup_destination'] ?? null;

            if (!$file) {
                $bc->destroy();
                return null;
            }

            // Move to our sync directory.
            $backupdir = $CFG->dataroot . '/' . self::BACKUP_DIR;
            if (!is_dir($backupdir)) {
                mkdir($backupdir, 0777, true);
            }

            $filename = 'course_' . $courseid . '_' . time() . '.mbz';
            $filepath = $backupdir . '/' . $filename;

            // Copy file content to our directory.
            $file->copy_content_to($filepath);

            $bc->destroy();

            return $filename;

        } catch (\Exception $e) {
            debugging('Backup failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Get the full path to a backup file.
     *
     * @param string $filename Backup filename.
     * @return string|null Full path or null if not exists.
     */
    public function get_backup_path(string $filename): ?string {
        global $CFG;

        $filepath = $CFG->dataroot . '/' . self::BACKUP_DIR . '/' . $filename;

        if (file_exists($filepath)) {
            return $filepath;
        }

        return null;
    }

    /**
     * Restore a course from a backup file.
     *
     * @param string $backuppath Path to backup file.
     * @param int $categoryid Target category ID.
     * @param int $userid User ID performing the restore.
     * @return int|null New course ID, or null on failure.
     */
    public function restore_course(string $backuppath, int $categoryid, int $userid): ?int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        try {
            // Extract backup to temp directory.
            $tempdir = restore_controller::get_tempdir_name();
            $fb = get_file_packer('application/vnd.moodle.backup');

            $fb->extract_to_pathname($backuppath, $CFG->tempdir . '/backup/' . $tempdir);

            // Create new course ID placeholder.
            $newcourseid = restore_dbops::create_new_course('', '', $categoryid);

            // Create restore controller.
            $rc = new restore_controller(
                $tempdir,
                $newcourseid,
                backup::INTERACTIVE_NO,
                backup::MODE_GENERAL,
                $userid,
                backup::TARGET_NEW_COURSE
            );

            // Configure restore settings.
            $plan = $rc->get_plan();

            $settings = [
                'users' => false,
                'role_assignments' => false,
                'activities' => true,
                'blocks' => true,
                'filters' => true,
                'comments' => false,
                'badges' => false,
                'calendarevents' => false,
                'userscompletion' => false,
                'logs' => false,
                'grade_histories' => false,
                'groups' => false,
                'competencies' => false,
            ];

            foreach ($settings as $name => $value) {
                if ($plan->setting_exists($name)) {
                    $setting = $plan->get_setting($name);
                    if ($setting->get_status() == \base_setting::NOT_LOCKED) {
                        $setting->set_value($value);
                    }
                }
            }

            // Execute precheck.
            if (!$rc->execute_precheck()) {
                $rc->destroy();
                // Clean up the empty course.
                delete_course($newcourseid, false);
                return null;
            }

            // Execute restore.
            $rc->execute_plan();

            // Get the actual course ID (might have changed).
            $newcourseid = $rc->get_courseid();

            $rc->destroy();

            // Clean up temp files.
            fulldelete($CFG->tempdir . '/backup/' . $tempdir);

            return $newcourseid;

        } catch (\Exception $e) {
            debugging('Restore failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Delete old backup files.
     *
     * @param int $maxage Maximum age in seconds (default 7 days).
     * @return int Number of files deleted.
     */
    public function cleanup_old_backups(int $maxage = 604800): int {
        global $CFG;

        $backupdir = $CFG->dataroot . '/' . self::BACKUP_DIR;
        $count = 0;

        if (!is_dir($backupdir)) {
            return 0;
        }

        $cutoff = time() - $maxage;
        $files = glob($backupdir . '/*.mbz');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $count++;
            }
        }

        return $count;
    }
}
