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
 * SDMS sync service for local_elby_dashboard.
 *
 * Orchestrates cache-first, sync-on-miss data flow between the SDMS API
 * and local cache tables.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * SDMS sync service.
 *
 * Provides two user sync paths:
 * - link_user(): For NEW users — takes SDMS code directly, fetches from API, creates link.
 * - refresh_user(): For EXISTING linked users — reads stored sdms_id, refreshes cache.
 *
 * Also provides sync_school() for school data with full hierarchy.
 */
class sync_service {

    /** @var sdms_client SDMS API client. */
    private sdms_client $client;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->client = new sdms_client();
    }

    /**
     * Link a new SDMS user to a Moodle account.
     *
     * Fetches user data from SDMS by their SDMS code and creates
     * the local cache records linking them to the Moodle user.
     *
     * @param int $userid Moodle user ID to link.
     * @param string $sdmscode SDMS identifier (studentNumber or staffNumber).
     * @param string $usertype User type: "student" or "staff".
     * @return bool True on success, false if not found in SDMS.
     * @throws \moodle_exception On API or database errors.
     */
    public function link_user(int $userid, string $sdmscode, string $usertype): bool {
        // Fetch from SDMS API.
        $data = $this->fetch_user_from_sdms($sdmscode, $usertype);
        if ($data === null) {
            return false;
        }

        // Cascade: sync school if present (non-fatal if school code is invalid).
        $schoolcode = $this->extract_school_code($data, $usertype);
        if (!empty($schoolcode)) {
            try {
                $this->sync_school($schoolcode);
            } catch (\Exception $e) {
                debugging('School sync failed for code ' . $schoolcode . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Upsert user records in a transaction.
        $this->upsert_user_record($userid, $data, $usertype, $sdmscode);

        // Auto-enroll student into matching courses (non-fatal).
        if ($usertype === 'student') {
            try {
                $this->auto_enroll_student($userid, $data);
            } catch (\Exception $e) {
                debugging('Auto-enrollment failed for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return true;
    }

    /**
     * Refresh cached data for an existing linked user.
     *
     * Reads the stored sdms_id from elby_sdms_users and re-fetches from SDMS.
     * Respects cache TTL unless forced.
     *
     * @param int $userid Moodle user ID.
     * @param bool $force Ignore cache freshness and always re-fetch.
     * @return bool True on success, false if user not linked or not found in SDMS.
     * @throws \moodle_exception On API or database errors.
     */
    public function refresh_user(int $userid, bool $force = false): bool {
        global $DB;

        // Check if user is linked.
        $existing = $DB->get_record('elby_sdms_users', ['userid' => $userid]);
        if (!$existing) {
            return false;
        }

        // Check cache freshness.
        if (!$force) {
            $ttl = (int) (get_config('local_elby_dashboard', 'sdms_cache_ttl') ?: 604800);
            if ((time() - $existing->last_synced) < $ttl) {
                return true; // Cache hit.
            }
        }

        // Re-fetch from SDMS using stored sdms_id and user_type.
        $data = $this->fetch_user_from_sdms($existing->sdms_id, $existing->user_type);
        if ($data === null) {
            $DB->set_field('elby_sdms_users', 'sync_status', 0, ['id' => $existing->id]);
            $DB->set_field('elby_sdms_users', 'sync_error', 'Not found in SDMS', ['id' => $existing->id]);
            $DB->set_field('elby_sdms_users', 'timemodified', time(), ['id' => $existing->id]);
            return false;
        }

        // Cascade: sync school if present (non-fatal if school code is invalid).
        $schoolcode = $this->extract_school_code($data, $existing->user_type);
        if (!empty($schoolcode)) {
            try {
                $this->sync_school($schoolcode);
            } catch (\Exception $e) {
                debugging('School sync failed for code ' . $schoolcode . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Upsert user records.
        $this->upsert_user_record($userid, $data, $existing->user_type, $existing->sdms_id);

        return true;
    }

    /**
     * Sync a school from SDMS, including its full hierarchy.
     *
     * Cache-first: returns immediately if cached data is fresh (unless forced).
     *
     * @param string $schoolcode SDMS school code.
     * @param bool $force Ignore cache freshness.
     * @return bool True on success, false if not found in SDMS.
     * @throws \moodle_exception On API or database errors.
     */
    public function sync_school(string $schoolcode, bool $force = false): bool {
        global $DB;

        // Check cache.
        $existing = $DB->get_record('elby_schools', ['school_code' => $schoolcode]);
        if ($existing && !$force) {
            $ttl = (int) (get_config('local_elby_dashboard', 'sdms_cache_ttl') ?: 604800);
            if ((time() - $existing->last_synced) < $ttl) {
                return true; // Cache hit.
            }
        }

        // Fetch from SDMS.
        $data = $this->client->get_school($schoolcode);
        if ($data === null) {
            if ($existing) {
                $DB->set_field('elby_schools', 'sync_status', 0, ['id' => $existing->id]);
                $DB->set_field('elby_schools', 'sync_error', 'Not found in SDMS', ['id' => $existing->id]);
                $DB->set_field('elby_schools', 'timemodified', time(), ['id' => $existing->id]);
            }
            return false;
        }

        // Upsert school + hierarchy in a transaction.
        $transaction = $DB->start_delegated_transaction();

        try {
            $record = new \stdClass();
            $record->school_code = $data->schoolCode ?? $schoolcode;
            $record->region_code = $data->regionCode ?? null;
            $record->school_name = $data->schoolName ?? '';
            $record->is_active = (isset($data->isActive) && $data->isActive === 'ACTIVE') ? 1 : 0;
            $record->school_status = $data->schoolStatus ?? null;
            $record->school_category = $data->schoolCategory ?? null;
            $record->academic_year = $data->academicYear ?? null;
            $record->gps_long = $data->gpsLong ?? null;
            $record->gps_lat = $data->gpsLat ?? null;
            $record->establishment_date = !empty($data->establishmentDate)
                ? strtotime($data->establishmentDate) : null;
            $record->sync_status = 1;
            $record->sync_error = null;
            $record->last_synced = time();
            $record->timemodified = time();

            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('elby_schools', $record);
                $schoolid = $existing->id;
            } else {
                $record->timecreated = time();
                $schoolid = $DB->insert_record('elby_schools', $record);
            }

            // Sync hierarchy.
            $this->upsert_school_hierarchy($schoolid, $data);

            // Update has_tvet flag.
            $hastvet = $DB->record_exists('elby_levels', [
                'schoolid' => $schoolid,
                'level_name' => 'TVET',
            ]);
            $DB->set_field('elby_schools', 'has_tvet', $hastvet ? 1 : 0, ['id' => $schoolid]);

            $transaction->allow_commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Fetch user data from SDMS API based on type.
     *
     * @param string $sdmscode SDMS identifier.
     * @param string $usertype "student" or "staff".
     * @return object|null SDMS response data, or null if not found.
     */
    private function fetch_user_from_sdms(string $sdmscode, string $usertype): ?object {
        if ($usertype === 'student') {
            return $this->client->get_student($sdmscode);
        }
        return $this->client->get_staff($sdmscode);
    }

    /**
     * Extract school code from SDMS response.
     *
     * Note: Staff endpoint has a typo — uses "schooCode" (missing 'l').
     *
     * @param object $data SDMS response data.
     * @param string $usertype "student" or "staff".
     * @return string|null School code, or null if not present.
     */
    private function extract_school_code(object $data, string $usertype): ?string {
        if ($usertype === 'student') {
            return $data->schoolCode ?? null;
        }
        // Staff endpoint has SDMS typo: "schooCode" instead of "schoolCode".
        return $data->schooCode ?? $data->schoolCode ?? null;
    }

    /**
     * Upsert user records across elby_sdms_users and type-specific tables.
     *
     * @param int $userid Moodle user ID.
     * @param object $data SDMS response data.
     * @param string $usertype "student" or "staff".
     * @param string $sdmscode The SDMS identifier used.
     */
    private function upsert_user_record(int $userid, object $data, string $usertype, string $sdmscode): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            // Resolve school FK from SDMS response.
            $schoolcode = $this->extract_school_code($data, $usertype);
            $newschoolid = null;
            if (!empty($schoolcode)) {
                $school = $DB->get_record('elby_schools', ['school_code' => $schoolcode], 'id');
                $newschoolid = $school ? $school->id : null;
            }

            // Fetch existing record early so we can preserve schoolid if SDMS returned invalid.
            $existing = $DB->get_record('elby_sdms_users', ['userid' => $userid]);

            // Only update schoolid if SDMS returned a valid school.
            // If invalid (null), preserve the existing schoolid.
            if ($newschoolid === null && $existing) {
                $newschoolid = $existing->schoolid;
            }

            // Build base record.
            // Map "staff" to "teacher" for consistent DB queries.
            $storedtype = ($usertype === 'staff') ? 'teacher' : $usertype;
            $record = new \stdClass();
            $record->userid = $userid;
            $record->sdms_id = $sdmscode;
            $record->schoolid = $newschoolid;
            $record->user_type = $storedtype;
            $record->academic_year = $data->currentAcadmicYear ?? $data->currentAcademicYear ?? $data->academicYear ?? null;
            $record->sdms_status = $data->status ?? null;
            $record->sync_status = 1;
            $record->sync_error = null;
            $record->last_synced = time();
            $record->timemodified = time();

            // Upsert base record.
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('elby_sdms_users', $record);
                $sdmsuserid = $existing->id;
            } else {
                $record->timecreated = time();
                $sdmsuserid = $DB->insert_record('elby_sdms_users', $record);
            }

            // Type-specific upsert.
            if ($usertype === 'student') {
                $this->upsert_student_data($sdmsuserid, $data);
            } else {
                $this->upsert_teacher_data($sdmsuserid, $data);
            }

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    /**
     * Upsert student-specific data.
     *
     * @param int $sdmsuserid ID in elby_sdms_users table.
     * @param object $data SDMS student response.
     */
    private function upsert_student_data(int $sdmsuserid, object $data): void {
        global $DB;

        // Resolve classgroup FK.
        $classid = null;
        $classgroupid = $data->classGroupId ?? null;
        if (!empty($classgroupid)) {
            $classgroup = $DB->get_record('elby_classgroups', ['sdms_class_id' => $classgroupid], 'id');
            $classid = $classgroup ? $classgroup->id : null;
        }

        $record = new \stdClass();
        $record->sdms_userid = $sdmsuserid;
        $record->classid = $classid;
        $record->program = $data->combination ?? null;
        $record->program_code = $data->combinationCode ?? null;
        $record->registration_date = !empty($data->registrationDate)
            ? strtotime($data->registrationDate) : null;
        $record->gender = !empty($data->gender) ? strtoupper($data->gender) : null;
        $record->date_of_birth = $data->dateOfBirth ?? null;
        $record->study_level = $data->studyLevel ?? null;
        $record->class_grade = $data->classGrade ?? null;
        $record->grade_code = $data->gradeCode ?? null;
        $record->class_group_name = $data->classGroup ?? null;
        $record->parent_guardian_name = $data->parentGardianName ?? null;
        $record->parent_guardian_nid = $data->parentGardianNationalId ?? null;
        $record->address = $data->address ?? null;
        $record->emergency_contact_person = $data->emergenceContactPerson ?? null;
        $record->emergency_contact_number = $data->emergenceContactNumber ?? null;
        $record->inactive_reason = $data->inactiveReason ?? null;
        $record->sdms_modified_since = $data->modifiedSince ?? null;
        $record->timemodified = time();

        $existing = $DB->get_record('elby_students', ['sdms_userid' => $sdmsuserid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('elby_students', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('elby_students', $record);
        }
    }

    /**
     * Upsert teacher-specific data and replace staff subjects.
     *
     * @param int $sdmsuserid ID in elby_sdms_users table.
     * @param object $data SDMS staff response.
     */
    private function upsert_teacher_data(int $sdmsuserid, object $data): void {
        global $DB;

        // Upsert teacher base record.
        $record = new \stdClass();
        $record->sdms_userid = $sdmsuserid;
        $record->position = $data->position ?? null;
        $record->gender = !empty($data->gender) ? strtoupper($data->gender) : null;
        $record->official_document_id = $data->officialDocumentId ?? null;
        $record->mobile_phone = $data->mobilePhoneNumber ?? null;
        $record->company_email = $data->companyEmail ?? null;
        $record->employment_status = $data->employmentStatus ?? null;
        $record->employment_start_date = $data->employmentStartDateTime ?? null;
        $record->employment_end_date = $data->employmentEndDate ?? null;
        $record->timemodified = time();

        $existing = $DB->get_record('elby_teachers', ['sdms_userid' => $sdmsuserid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('elby_teachers', $record);
            $teacherid = $existing->id;
        } else {
            $record->timecreated = time();
            $teacherid = $DB->insert_record('elby_teachers', $record);
        }

        // Replace staff subjects: delete old, insert new.
        // Deduplicate by subject_code since the unique key is (teacherid, subject_code)
        // and SDMS can return the same subject under multiple combinations.
        $DB->delete_records('elby_staff_subjects', ['teacherid' => $teacherid]);

        if (!empty($data->specialities) && is_array($data->specialities)) {
            $now = time();
            $seen = [];
            foreach ($data->specialities as $spec) {
                $subjectcode = $spec->subjectCode ?? '';
                if (isset($seen[$subjectcode])) {
                    continue;
                }
                $seen[$subjectcode] = true;

                $subject = new \stdClass();
                $subject->teacherid = $teacherid;
                $subject->level_id = $spec->levelId ?? '';
                $subject->level_name = $spec->levelName ?? $spec->level ?? '';
                $subject->combination_code = $spec->combinationCode ?? '';
                $subject->combination_name = $spec->combinationName ?? $spec->combination ?? '';
                $subject->subject_code = $subjectcode;
                $subject->subject_name = $spec->subjectName ?? $spec->subject ?? '';
                $subject->grade_code = $spec->gradeCode ?? null;
                $subject->grade_name = $spec->gradeName ?? null;
                $subject->class_group = $spec->classGroup ?? null;
                $subject->timecreated = $now;
                $subject->timemodified = $now;

                $DB->insert_record('elby_staff_subjects', $subject);
            }
        }
    }

    /**
     * Upsert school hierarchy: levels → combinations → grades → classgroups.
     *
     * @param int $schoolid ID in elby_schools table.
     * @param object $data SDMS school response.
     */
    private function upsert_school_hierarchy(int $schoolid, object $data): void {
        global $DB;

        if (empty($data->levels) || !is_array($data->levels)) {
            return;
        }

        $now = time();

        foreach ($data->levels as $leveldata) {
            // Upsert level.
            $level = new \stdClass();
            $level->schoolid = $schoolid;
            $level->sdms_level_id = $leveldata->levelId ?? '';
            $level->level_name = $leveldata->levelName ?? '';
            $level->level_desc = $leveldata->description ?? null;
            $level->timemodified = $now;

            $existinglevel = $DB->get_record('elby_levels', [
                'schoolid' => $schoolid,
                'sdms_level_id' => $level->sdms_level_id,
            ]);

            if ($existinglevel) {
                $level->id = $existinglevel->id;
                $DB->update_record('elby_levels', $level);
                $levelid = $existinglevel->id;
            } else {
                $level->timecreated = $now;
                $levelid = $DB->insert_record('elby_levels', $level);
            }

            if (empty($leveldata->combinations) || !is_array($leveldata->combinations)) {
                continue;
            }

            foreach ($leveldata->combinations as $combodata) {
                // Upsert combination.
                $combo = new \stdClass();
                $combo->levelid = $levelid;
                $combo->combination_code = $combodata->combinationCode ?? '';
                $combo->combination_name = $combodata->combinationName ?? '';
                $combo->combination_desc = $combodata->description ?? null;
                $combo->timemodified = $now;

                $existingcombo = $DB->get_record('elby_combinations', [
                    'levelid' => $levelid,
                    'combination_code' => $combo->combination_code,
                ]);

                if ($existingcombo) {
                    $combo->id = $existingcombo->id;
                    $DB->update_record('elby_combinations', $combo);
                    $comboid = $existingcombo->id;
                } else {
                    $combo->timecreated = $now;
                    $comboid = $DB->insert_record('elby_combinations', $combo);
                }

                if (empty($combodata->grades) || !is_array($combodata->grades)) {
                    continue;
                }

                foreach ($combodata->grades as $gradedata) {
                    // Upsert grade.
                    $grade = new \stdClass();
                    $grade->combinationid = $comboid;
                    $grade->grade_code = $gradedata->gradeCode ?? '';
                    $grade->grade_name = $gradedata->gradeName ?? '';
                    $grade->timemodified = $now;

                    $existinggrade = $DB->get_record('elby_grades', [
                        'combinationid' => $comboid,
                        'grade_code' => $grade->grade_code,
                    ]);

                    if ($existinggrade) {
                        $grade->id = $existinggrade->id;
                        $DB->update_record('elby_grades', $grade);
                        $gradeid = $existinggrade->id;
                    } else {
                        $grade->timecreated = $now;
                        $gradeid = $DB->insert_record('elby_grades', $grade);
                    }

                    if (empty($gradedata->classGroups) || !is_array($gradedata->classGroups)) {
                        continue;
                    }

                    foreach ($gradedata->classGroups as $classdata) {
                        // Upsert classgroup.
                        $class = new \stdClass();
                        $class->gradeid = $gradeid;
                        $class->sdms_class_id = $classdata->classGroupId ?? '';
                        $class->class_name = $classdata->classGroupName ?? '';
                        $class->timemodified = $now;

                        $existingclass = $DB->get_record('elby_classgroups', [
                            'sdms_class_id' => $class->sdms_class_id,
                        ]);

                        if ($existingclass) {
                            $class->id = $existingclass->id;
                            $DB->update_record('elby_classgroups', $class);
                        } else {
                            $class->timecreated = $now;
                            $DB->insert_record('elby_classgroups', $class);
                        }
                    }
                }
            }
        }
    }

    /**
     * Auto-enroll a student into courses matching their trade and level.
     *
     * Looks up Moodle course categories whose idnumber matches
     * "{combinationCode}:{levelNumber}" (e.g., "527:3") and enrolls the
     * student into all courses under those categories.
     *
     * @param int $userid Moodle user ID.
     * @param object $data SDMS student response data.
     */
    private function auto_enroll_student(int $userid, object $data): void {
        global $DB;

        // Check if auto-enrollment is enabled.
        if (!get_config('local_elby_dashboard', 'auto_enroll_enabled')) {
            return;
        }

        // Extract combination code.
        $combinationcode = $data->combinationCode ?? null;
        if (empty($combinationcode)) {
            return;
        }

        // Extract level number from classGrade (e.g., "Level 3" → "3").
        $classgrade = $data->classGrade ?? '';
        if (!preg_match('/(\d+)/', $classgrade, $matches)) {
            return;
        }
        $levelnumber = $matches[1];

        // Build lookup key.
        $lookupkey = $combinationcode . ':' . $levelnumber;

        // Find matching course categories.
        $categories = $DB->get_records('course_categories', ['idnumber' => $lookupkey], '', 'id');
        if (empty($categories)) {
            $this->log_enrollment($userid, 'skip', $lookupkey, 'No matching category found');
            return;
        }

        // Get student role.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        if (!$studentrole) {
            return;
        }

        $enrolledcount = 0;

        foreach ($categories as $cat) {
            $category = \core_course_category::get($cat->id, \IGNORE_MISSING);
            if (!$category) {
                continue;
            }

            // Get all courses under this category (recursively).
            $courseids = $category->get_courses(['recursive' => true, 'idonly' => true]);

            foreach ($courseids as $courseid) {
                $result = enrol_try_internal_enrol($courseid, $userid, $studentrole->id);
                if ($result) {
                    $enrolledcount++;
                }
            }
        }

        if ($enrolledcount > 0) {
            $this->log_enrollment($userid, 'create', $lookupkey,
                'Enrolled in ' . $enrolledcount . ' course(s)');
        } else {
            $this->log_enrollment($userid, 'skip', $lookupkey,
                'Category matched but no new enrollments');
        }
    }

    /**
     * Log an auto-enrollment operation to elby_sync_log.
     *
     * @param int $userid Moodle user ID.
     * @param string $operation Operation: 'create' or 'skip'.
     * @param string $lookupkey The trade:level lookup key.
     * @param string $details Human-readable details.
     */
    private function log_enrollment(int $userid, string $operation, string $lookupkey, string $details): void {
        global $DB;

        $log = new \stdClass();
        $log->sync_type = 'enrollment';
        $log->entity_id = $lookupkey;
        $log->userid = $userid;
        $log->operation = $operation;
        $log->details = $details;
        $log->triggered_by = 'event';
        $log->timecreated = time();

        $DB->insert_record('elby_sync_log', $log);
    }
}
