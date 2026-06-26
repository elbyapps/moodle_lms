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
 * TDMP sync service for local_elby_dashboard.
 *
 * Links Moodle users to TDMP gateway records and maintains the local
 * aggregate store (school identity + student/teacher demographics) used by
 * reporting. Single-record detail views read live from the gateway; this
 * service only persists what aggregate SQL reporting needs.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * TDMP sync service.
 *
 * Provides two user sync paths:
 * - link_user(): For NEW users — takes a TDMP code directly, fetches from the
 *   gateway, creates the link.
 * - refresh_user(): For EXISTING linked users — reads stored sdms_id and
 *   re-fetches live.
 *
 * Also provides sync_school() to maintain the school identity record.
 */
class sync_service {

    /** @var tdmp_client TDMP gateway client. */
    private tdmp_client $client;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->client = new tdmp_client();
    }

    /**
     * Link a new TDMP user to a Moodle account.
     *
     * Fetches user data from the gateway by their TDMP code and creates
     * the local records linking them to the Moodle user.
     *
     * @param int $userid Moodle user ID to link.
     * @param string $sdmscode TDMP identifier (studentNumber or staff identifier).
     * @param string $usertype User type: "student" or "staff".
     * @return bool True on success, false if not found in the gateway.
     * @throws \moodle_exception On API or database errors.
     */
    public function link_user(int $userid, string $sdmscode, string $usertype): bool {
        // Fetch from the gateway.
        $data = $this->fetch_user_from_gateway($sdmscode, $usertype);
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

        // Update the Moodle account name from the official gateway record (non-fatal).
        try {
            $this->update_user_names($userid, $data, $usertype);
        } catch (\Exception $e) {
            debugging('Name update failed for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Auto-enroll student into matching courses (non-fatal).
        if ($usertype === 'student') {
            try {
                $this->auto_enroll_student($userid, $data);
            } catch (\Exception $e) {
                debugging('Auto-enrollment failed for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            try {
                $this->assign_student_cohort($userid, $data);
            } catch (\Exception $e) {
                debugging('Cohort assignment failed for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Enrich courses created by this teacher (non-fatal).
        if ($usertype === 'staff') {
            try {
                course_enricher::enrich_for_teacher($userid);
            } catch (\Exception $e) {
                debugging('Course enrichment failed for teacher ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return true;
    }

    /**
     * Refresh stored data for an existing linked user.
     *
     * Reads the stored sdms_id from elby_sdms_users and re-fetches live from
     * the gateway.
     *
     * @param int $userid Moodle user ID.
     * @param bool $force Unused; retained for call-site compatibility.
     * @return bool True on success, false if user not linked or not found.
     * @throws \moodle_exception On API or database errors.
     */
    public function refresh_user(int $userid, bool $force = false): bool {
        global $DB;

        // Check if user is linked.
        $existing = $DB->get_record('elby_sdms_users', ['userid' => $userid]);
        if (!$existing) {
            return false;
        }

        // Re-fetch live from the gateway using stored sdms_id and user_type.
        $data = $this->fetch_user_from_gateway($existing->sdms_id, $existing->user_type);
        if ($data === null) {
            $DB->set_field('elby_sdms_users', 'sync_status', 0, ['id' => $existing->id]);
            $DB->set_field('elby_sdms_users', 'sync_error', 'Not found in gateway', ['id' => $existing->id]);
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

        // Refresh the Moodle account name from the official gateway record (non-fatal).
        try {
            $this->update_user_names($userid, $data, $existing->user_type);
        } catch (\Exception $e) {
            debugging('Name update failed for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Keep cohort membership current for students (e.g. new academic year).
        if ($existing->user_type === 'student') {
            try {
                $this->assign_student_cohort($userid, $data);
            } catch (\Exception $e) {
                debugging('Cohort assignment failed for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Refresh enrichment of courses created by this teacher (non-fatal).
        if ($existing->user_type === 'teacher') {
            try {
                course_enricher::enrich_for_teacher($userid);
            } catch (\Exception $e) {
                debugging('Course enrichment failed for teacher ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return true;
    }

    /**
     * Sync a school identity record from the gateway.
     *
     * @param string $schoolcode Gateway school code.
     * @param bool $force Unused; retained for call-site compatibility.
     * @return bool True on success, false if not found in the gateway.
     * @throws \moodle_exception On API or database errors.
     */
    public function sync_school(string $schoolcode, bool $force = false): bool {
        global $DB;

        // Look up any existing row so we can update it in place.
        $existing = $DB->get_record('elby_schools', ['school_code' => $schoolcode]);

        // Fetch live from the gateway (no response caching).
        $data = $this->client->get_school($schoolcode);
        if ($data === null) {
            if ($existing) {
                $DB->set_field('elby_schools', 'sync_status', 0, ['id' => $existing->id]);
                $DB->set_field('elby_schools', 'sync_error', 'Not found in gateway', ['id' => $existing->id]);
                $DB->set_field('elby_schools', 'timemodified', time(), ['id' => $existing->id]);
            }
            return false;
        }

        $record = new \stdClass();
        $record->school_code = $data->schoolCode ?? $schoolcode;
        $record->region_code = null;
        $record->school_name = $data->schoolName ?? '';
        $record->is_active = (isset($data->isActive) && $data->isActive === 'ACTIVE') ? 1 : 0;
        $record->school_status = $data->schoolStatus ?? null;
        $record->school_category = $data->schoolCategory ?? null;
        $record->academic_year = $data->academicYear ?? $data->currentAcademicYear ?? null;
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
        } else {
            $record->timecreated = time();
            $DB->insert_record('elby_schools', $record);
        }

        return true;
    }

    /**
     * Fetch user data from the gateway based on type.
     *
     * @param string $sdmscode TDMP identifier.
     * @param string $usertype "student" or "staff".
     * @return object|null Gateway response data, or null if not found.
     */
    private function fetch_user_from_gateway(string $sdmscode, string $usertype): ?object {
        if ($usertype === 'student') {
            return $this->client->get_student($sdmscode);
        }
        return $this->client->get_teacher($sdmscode);
    }

    /**
     * Extract school code from a gateway response.
     *
     * @param object $data Gateway response data.
     * @param string $usertype "student" or "staff".
     * @return string|null School code, or null if not present.
     */
    private function extract_school_code(object $data, string $usertype): ?string {
        return $data->schoolCode ?? null;
    }

    /**
     * Upsert user records across elby_sdms_users and type-specific tables.
     *
     * @param int $userid Moodle user ID.
     * @param object $data Gateway response data.
     * @param string $usertype "student" or "staff".
     * @param string $sdmscode The TDMP identifier used.
     */
    private function upsert_user_record(int $userid, object $data, string $usertype, string $sdmscode): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            // Resolve school FK from the gateway response.
            $schoolcode = $this->extract_school_code($data, $usertype);
            $newschoolid = null;
            if (!empty($schoolcode)) {
                $school = $DB->get_record('elby_schools', ['school_code' => $schoolcode], 'id');
                $newschoolid = $school ? $school->id : null;
            }

            // Fetch existing record early so we can preserve schoolid if the gateway returned invalid.
            $existing = $DB->get_record('elby_sdms_users', ['userid' => $userid]);

            // Only update schoolid if the gateway returned a valid school.
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
            $record->academic_year = $data->currentAcademicYear ?? $data->academicYear ?? null;
            $record->sdms_status = $data->registrationStatus ?? $data->employmentStatus ?? null;
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
     * @param object $data Gateway student response.
     */
    private function upsert_student_data(int $sdmsuserid, object $data): void {
        global $DB;

        $record = new \stdClass();
        $record->sdms_userid = $sdmsuserid;
        $record->program = $data->combinationName ?? null;
        $record->program_code = $data->combinationCode ?? null;
        $record->registration_date = !empty($data->registrationDate)
            ? strtotime($data->registrationDate) : null;
        $record->gender = !empty($data->gender) ? strtoupper($data->gender) : null;
        $record->date_of_birth = $data->dateOfBirth ?? null;
        $record->study_level = $data->levelName ?? null;
        $record->class_grade = $data->gradeName ?? null;
        $record->grade_code = null;
        $record->class_group_name = $data->classGroupName ?? null;
        $record->parent_guardian_name = $data->parentGuardianName ?? null;
        $record->parent_guardian_nid = $data->parentGuardianNationalId ?? null;
        $record->address = $data->address ?? null;
        $record->emergency_contact_person = $data->emergencyContactPerson ?? null;
        $record->emergency_contact_number = $data->emergencyContactNumber ?? null;
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
     * Upsert teacher-specific data.
     *
     * @param int $sdmsuserid ID in elby_sdms_users table.
     * @param object $data Gateway teacher response.
     */
    private function upsert_teacher_data(int $sdmsuserid, object $data): void {
        global $DB;

        $record = new \stdClass();
        $record->sdms_userid = $sdmsuserid;
        $record->position = $data->positionName ?? null;
        $record->gender = !empty($data->gender) ? strtoupper($data->gender) : null;
        $record->official_document_id = $data->nidNumber ?? null;
        $record->mobile_phone = $data->phoneNumber ?? null;
        $record->company_email = $data->companyEmail ?? $data->email ?? null;
        $record->employment_status = $data->employmentStatus ?? null;
        $record->employment_start_date = $data->employmentStartDate ?? null;
        $record->employment_end_date = $data->employmentEndDate ?? null;
        $record->specialities = isset($data->specialities) ? json_encode($data->specialities) : null;
        $record->timemodified = time();

        $existing = $DB->get_record('elby_teachers', ['sdms_userid' => $sdmsuserid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('elby_teachers', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('elby_teachers', $record);
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
     * @param object $data Gateway student response data.
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

        // Extract level number from gradeName (e.g., "Level 3" → "3").
        $classgrade = $data->gradeName ?? '';
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
     * Add a student to a system-level cohort for their trade + level + academic year.
     *
     * Cohort key (idnumber): tdmp:{tradeCode}:{level}:{yearSlug}. Students with no
     * trade assigned yet (no combinationCode) are skipped. The student is *moved*:
     * prior tdmp: cohort memberships are removed so they sit in exactly one.
     *
     * @param int $userid Moodle user ID.
     * @param object $data Gateway student response data.
     */
    private function assign_student_cohort(int $userid, object $data): void {
        global $CFG, $DB;

        if (!get_config('local_elby_dashboard', 'auto_cohort_enabled')) {
            return;
        }

        $tradecode = $data->combinationCode ?? null;
        if (empty($tradecode)) {
            return; // No trade assigned yet — skip.
        }

        // Level number from gradeName (e.g. "Level 3" -> 3). gradeOrder is unreliable (often 0).
        if (!preg_match('/(\d+)/', (string) ($data->gradeName ?? ''), $m)) {
            return;
        }
        $level = $m[1];

        $year = (string) ($data->currentAcademicYear ?? '');
        if ($year === '') {
            return;
        }
        $yearslug = str_replace('/', '-', $year);

        require_once($CFG->dirroot . '/cohort/lib.php');
        $systemctx = \context_system::instance();
        $idnumber = "tdmp:{$tradecode}:{$level}:{$yearslug}";

        // Resolve the full trade name from the gateway (falls back to the short name).
        $tradename = $data->combinationName ?? $tradecode;
        try {
            $trade = $this->client->get_trade((string) $tradecode);
            if ($trade && !empty($trade->tradeName)) {
                $tradename = $trade->tradeName;
            }
        } catch (\Exception $e) {
            debugging('Trade lookup failed for ' . $tradecode . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Find or create the system-context cohort.
        $cohort = $DB->get_record('cohort', ['contextid' => $systemctx->id, 'idnumber' => $idnumber]);
        if ($cohort) {
            $cohortid = (int) $cohort->id;
        } else {
            $newcohort = new \stdClass();
            $newcohort->contextid = $systemctx->id;
            $newcohort->name = "{$tradename} · " . ($data->gradeName ?? ("Level " . $level)) . " · {$year}";
            $newcohort->idnumber = $idnumber;
            $newcohort->description = "Auto-created by TDMP integration (trade {$tradecode}, level {$level}, {$year}).";
            $newcohort->descriptionformat = FORMAT_PLAIN;
            $newcohort->visible = 1;
            $cohortid = (int) cohort_add_cohort($newcohort);
        }

        // Move: drop any prior TDMP cohort memberships for this user.
        $priors = $DB->get_records_sql(
            "SELECT c.id
               FROM {cohort} c
               JOIN {cohort_members} cm ON cm.cohortid = c.id
              WHERE cm.userid = :userid
                AND c.idnumber LIKE 'tdmp:%'",
            ['userid' => $userid]
        );
        foreach ($priors as $prior) {
            if ((int) $prior->id !== $cohortid) {
                cohort_remove_member($prior->id, $userid);
            }
        }

        if (!cohort_is_member($cohortid, $userid)) {
            cohort_add_member($cohortid, $userid);
        }
    }

    /**
     * Update the Moodle account's first/last name from the gateway record.
     *
     * Teachers expose explicit firstName/lastName. Students only have a combined
     * "names" string in Rwandan order (family name first), so the first token is
     * treated as the surname and the remainder as the given name(s).
     *
     * @param int $userid Moodle user ID.
     * @param object $data Gateway response data.
     * @param string $usertype "student", "staff", or "teacher".
     */
    private function update_user_names(int $userid, object $data, string $usertype): void {
        global $DB, $CFG;

        if ($usertype !== 'student' && !empty($data->firstName) && !empty($data->lastName)) {
            $firstname = $this->title_case((string) $data->firstName);
            $lastname = $this->title_case((string) $data->lastName);
        } else {
            $names = trim((string) ($data->names ?? ''));
            if ($names === '') {
                return;
            }
            $parts = preg_split('/\s+/', $names);
            $lastname = $this->title_case((string) array_shift($parts));
            $firstname = !empty($parts) ? $this->title_case(implode(' ', $parts)) : $lastname;
        }

        if ($firstname === '' || $lastname === '') {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
        if (!$user || ($user->firstname === $firstname && $user->lastname === $lastname)) {
            return;
        }

        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object) ['id' => $userid, 'firstname' => $firstname, 'lastname' => $lastname], false, true);
    }

    /**
     * Title-case a name token sequence (e.g. "CYUZUZO" -> "Cyuzuzo").
     *
     * @param string $value
     * @return string
     */
    private function title_case(string $value): string {
        return ucwords(strtolower(trim($value)));
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
