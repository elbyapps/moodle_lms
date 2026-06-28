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
 * External API for SDMS self-registration.
 *
 * Provides public (no-login-required) endpoints for users to look up
 * their SDMS data and create a Moodle account.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/user/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use context_system;

/**
 * External API for SDMS self-registration.
 */
class signup extends external_api {

    // =========================================================================
    // lookup_for_signup — Public SDMS lookup for self-registration.
    // =========================================================================

    /**
     * Parameters for lookup_for_signup.
     */
    public static function lookup_for_signup_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sdms_code' => new external_value(PARAM_TEXT, 'SDMS code (studentNumber or staffNumber)'),
            'user_type' => new external_value(PARAM_TEXT, 'User type: student or staff'),
        ]);
    }

    /**
     * Look up SDMS user for self-registration. No login required.
     *
     * @param string $sdmscode SDMS identifier.
     * @param string $usertype "student" or "staff".
     * @return array User data from SDMS with registration status.
     */
    public static function lookup_for_signup(string $sdmscode, string $usertype): array {
        global $DB, $PAGE;

        $params = self::validate_parameters(
            self::lookup_for_signup_parameters(),
            ['sdms_code' => $sdmscode, 'user_type' => $usertype]
        );
        $sdmscode = trim($params['sdms_code']);
        $usertype = $params['user_type'];

        // No-login-required: set context directly instead of validate_context()
        // which calls require_login() internally.
        $context = context_system::instance();
        $PAGE->set_context($context);

        // Rate limit check.
        self::rate_limit_check();

        if (empty($sdmscode)) {
            return self::empty_lookup_result('SDMS code is required');
        }

        if (!in_array($usertype, ['student', 'staff'])) {
            return self::empty_lookup_result('Invalid user type');
        }

        try {
            $client = new \local_elby_dashboard\sdms_client();

            if ($usertype === 'student') {
                $data = $client->get_student($sdmscode);
            } else {
                $data = $client->get_staff($sdmscode);
            }

            if ($data === null) {
                return self::empty_lookup_result(
                    get_string('sdms_not_found', 'local_elby_dashboard')
                );
            }

            // Parse names.
            $names = '';
            if ($usertype === 'student') {
                $names = $data->names ?? '';
            } else {
                $names = $data->names ?? ($data->staffName ?? '');
            }
            $parsed = self::parse_sdms_names($names);

            // Extract school code.
            $schoolcode = '';
            if ($usertype === 'student') {
                $schoolcode = $data->schoolCode ?? '';
            } else {
                // Staff endpoint has SDMS typo: "schooCode".
                $schoolcode = $data->schooCode ?? ($data->schoolCode ?? '');
            }

            // Fetch school name via school API (student endpoint only has schoolCode).
            $schoolname = $data->schoolName ?? ($data->schooName ?? '');
            if (empty($schoolname) && !empty($schoolcode)) {
                try {
                    $schooldata = $client->get_school($schoolcode);
                    if ($schooldata) {
                        $schoolname = $schooldata->schoolName ?? '';
                    }
                } catch (\Exception $e) {
                    // Non-fatal: school name just won't be displayed.
                }
            }

            // Program: try combination name, fall back to combinationCode.
            $program = '';
            $programcode = '';
            if ($usertype === 'student') {
                $program = $data->combination ?? '';
                $programcode = $data->combinationCode ?? '';
                if (empty($program) && !empty($programcode)) {
                    $program = $programcode;
                }
            }

            // Check if already registered (Moodle account with SDMS code as username).
            $alreadyregistered = $DB->record_exists('user', [
                'username' => strtolower($sdmscode),
                'deleted' => 0,
            ]);

            // Build SDMS ID.
            $sdmsid = '';
            if ($usertype === 'student') {
                $sdmsid = $data->studentNumber ?? $sdmscode;
            } else {
                $sdmsid = $data->staffId ?? $sdmscode;
            }

            // Check if SDMS code is already linked to a Moodle user.
            $alreadylinked = $DB->record_exists('elby_sdms_users', ['sdms_id' => $sdmsid]);

            return [
                'success' => true,
                'error' => '',
                'found' => true,
                'already_registered' => $alreadyregistered,
                'already_linked' => $alreadylinked,
                'sdms_id' => $sdmsid,
                'user_type' => $usertype,
                'names' => $names,
                'firstname' => $parsed['firstname'],
                'lastname' => $parsed['lastname'],
                'gender' => $data->gender ?? '',
                'school_name' => $schoolname,
                'school_code' => $schoolcode,
                'study_level' => ($usertype === 'student') ? ($data->studyLevel ?? '') : '',
                'class_grade' => ($usertype === 'student') ? ($data->classGrade ?? '') : '',
                'class_group' => ($usertype === 'student') ? ($data->classGroup ?? '') : '',
                'program' => $program,
                'program_code' => $programcode,
                'status' => $data->status ?? ($data->employmentStatus ?? ''),
                'academic_year' => $data->currentAcadmicYear
                    ?? ($data->currentAcademicYear ?? ($data->academicYear ?? '')),
                'position' => ($usertype === 'staff') ? ($data->position ?? '') : '',
            ];
        } catch (\Exception $e) {
            return self::empty_lookup_result($e->getMessage());
        }
    }

    /**
     * Return structure for lookup_for_signup.
     */
    public static function lookup_for_signup_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether lookup succeeded'),
            'error' => new external_value(PARAM_TEXT, 'Error message'),
            'found' => new external_value(PARAM_BOOL, 'Whether SDMS record was found'),
            'already_registered' => new external_value(PARAM_BOOL, 'Whether user already has a Moodle account'),
            'already_linked' => new external_value(PARAM_BOOL, 'Whether SDMS code is already linked to a Moodle user'),
            'sdms_id' => new external_value(PARAM_TEXT, 'SDMS identifier'),
            'user_type' => new external_value(PARAM_TEXT, 'User type'),
            'names' => new external_value(PARAM_TEXT, 'Full name from SDMS'),
            'firstname' => new external_value(PARAM_TEXT, 'Parsed first name'),
            'lastname' => new external_value(PARAM_TEXT, 'Parsed last name'),
            'gender' => new external_value(PARAM_TEXT, 'Gender'),
            'school_name' => new external_value(PARAM_TEXT, 'School name'),
            'school_code' => new external_value(PARAM_TEXT, 'School code'),
            'study_level' => new external_value(PARAM_TEXT, 'Study level (students)'),
            'class_grade' => new external_value(PARAM_TEXT, 'Class grade (students)'),
            'class_group' => new external_value(PARAM_TEXT, 'Class group (students)'),
            'program' => new external_value(PARAM_TEXT, 'Program name (students)'),
            'program_code' => new external_value(PARAM_TEXT, 'Program code (students)'),
            'status' => new external_value(PARAM_TEXT, 'SDMS status'),
            'academic_year' => new external_value(PARAM_TEXT, 'Academic year'),
            'position' => new external_value(PARAM_TEXT, 'Position (staff)'),
        ]);
    }

    // =========================================================================
    // register_sdms_user — Public registration from SDMS data.
    // =========================================================================

    /**
     * Parameters for register_sdms_user.
     */
    public static function register_sdms_user_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sdms_code' => new external_value(PARAM_TEXT, 'SDMS code'),
            'user_type' => new external_value(PARAM_TEXT, 'User type: student or staff'),
            'password' => new external_value(PARAM_RAW, 'User password'),
            'username' => new external_value(PARAM_TEXT, 'Desired username (defaults to SDMS code)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Register a new Moodle user from SDMS data. No login required.
     *
     * @param string $sdmscode SDMS identifier.
     * @param string $usertype "student" or "staff".
     * @param string $password User's chosen password.
     * @param string $username Desired username (defaults to SDMS code).
     * @return array Result with userid and username.
     */
    public static function register_sdms_user(string $sdmscode, string $usertype, string $password, string $username = ''): array {
        global $DB, $CFG, $PAGE;

        $params = self::validate_parameters(
            self::register_sdms_user_parameters(),
            ['sdms_code' => $sdmscode, 'user_type' => $usertype, 'password' => $password, 'username' => $username]
        );
        $sdmscode = trim($params['sdms_code']);
        $usertype = $params['user_type'];
        $password = $params['password'];
        $username = trim($params['username']);

        // No-login-required: set context directly instead of validate_context()
        // which calls require_login() internally.
        $context = context_system::instance();
        $PAGE->set_context($context);

        // Rate limit check.
        self::rate_limit_check();

        if (empty($sdmscode)) {
            return ['success' => false, 'error' => 'SDMS code is required', 'userid' => 0, 'username' => ''];
        }

        if (!in_array($usertype, ['student', 'staff'])) {
            return ['success' => false, 'error' => 'Invalid user type', 'userid' => 0, 'username' => ''];
        }

        // Use provided username or fall back to SDMS code.
        $username = !empty($username) ? strtolower($username) : strtolower($sdmscode);

        // Check username uniqueness.
        if ($DB->record_exists('user', ['username' => $username, 'deleted' => 0])) {
            return [
                'success' => false,
                'error' => get_string('sdms_already_registered', 'local_elby_dashboard'),
                'userid' => 0,
                'username' => '',
            ];
        }

        // Validate password policy.
        $errmsg = '';
        if (!check_password_policy($password, $errmsg)) {
            // Strip HTML tags — check_password_policy returns <div>-wrapped messages.
            return ['success' => false, 'error' => strip_tags($errmsg), 'userid' => 0, 'username' => ''];
        }

        // Re-fetch from SDMS (don't trust client data).
        try {
            $client = new \local_elby_dashboard\sdms_client();
            if ($usertype === 'student') {
                $data = $client->get_student($sdmscode);
            } else {
                $data = $client->get_staff($sdmscode);
            }

            if ($data === null) {
                return [
                    'success' => false,
                    'error' => get_string('sdms_not_found', 'local_elby_dashboard'),
                    'userid' => 0,
                    'username' => '',
                ];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'userid' => 0, 'username' => ''];
        }

        // Parse names.
        $names = '';
        if ($usertype === 'student') {
            $names = $data->names ?? '';
        } else {
            $names = $data->names ?? ($data->staffName ?? '');
        }
        $parsed = self::parse_sdms_names($names);

        // Generate email.
        $emaildomain = get_config('local_elby_dashboard', 'sdms_signup_email_domain') ?: 'rtb.ac.rw';
        $email = strtolower($sdmscode) . '@' . $emaildomain;

        // Create Moodle user.
        try {
            $user = new \stdClass();
            $user->username = $username;
            $user->password = $password;
            $user->firstname = $parsed['firstname'];
            $user->lastname = $parsed['lastname'];
            $user->email = $email;
            $user->auth = 'manual';
            $user->confirmed = 1;
            $user->mnethostid = $CFG->mnet_localhost_id;

            $userid = user_create_user($user, true, false);

            // Link to SDMS via sync_service.
            try {
                $syncservice = new \local_elby_dashboard\sync_service();
                $syncservice->link_user($userid, $sdmscode, $usertype);
            } catch (\Exception $e) {
                // User is created but SDMS link failed — log but don't fail registration.
                debugging('SDMS link failed during signup: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            return [
                'success' => true,
                'error' => '',
                'userid' => $userid,
                'username' => $username,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'userid' => 0, 'username' => ''];
        }
    }

    /**
     * Return structure for register_sdms_user.
     */
    public static function register_sdms_user_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether registration succeeded'),
            'error' => new external_value(PARAM_TEXT, 'Error message'),
            'userid' => new external_value(PARAM_INT, 'New Moodle user ID'),
            'username' => new external_value(PARAM_TEXT, 'Username (SDMS code)'),
        ]);
    }

    // =========================================================================
    // Helper methods.
    // =========================================================================

    /**
     * Parse SDMS names field into firstname and lastname.
     *
     * Rwandan naming convention: first word = lastname, rest = firstname.
     * Example: "NIYONZIMA BRUNO AMAN" → lastname: "Niyonzima", firstname: "Bruno Aman"
     *
     * @param string $names Full name string from SDMS.
     * @return array ['firstname' => string, 'lastname' => string]
     */
    private static function parse_sdms_names(string $names): array {
        $names = trim($names);
        if (empty($names)) {
            return ['firstname' => '', 'lastname' => ''];
        }

        $parts = preg_split('/\s+/', $names);

        $lastname = ucfirst(strtolower(array_shift($parts)));
        $firstname = '';
        if (!empty($parts)) {
            $firstname = implode(' ', array_map(function($part) {
                return ucfirst(strtolower($part));
            }, $parts));
        }

        return ['firstname' => $firstname, 'lastname' => $lastname];
    }

    /**
     * IP-based rate limiting using Moodle cache API.
     *
     * @throws \moodle_exception If rate limit exceeded.
     */
    private static function rate_limit_check(): void {
        $cache = \cache::make('local_elby_dashboard', 'signup_ratelimit');
        $ip = getremoteaddr();
        $key = 'signup_' . md5($ip);

        $attempts = $cache->get($key);
        if ($attempts === false) {
            $attempts = 0;
        }

        if ($attempts >= 10) {
            throw new \moodle_exception('sdms_rate_limited', 'local_elby_dashboard');
        }

        $cache->set($key, $attempts + 1);
    }

    /**
     * Build an empty lookup result with an error message.
     *
     * @param string $error Error message.
     * @return array Empty result structure.
     */
    private static function empty_lookup_result(string $error): array {
        return [
            'success' => false,
            'error' => $error,
            'found' => false,
            'already_registered' => false,
            'already_linked' => false,
            'sdms_id' => '',
            'user_type' => '',
            'names' => '',
            'firstname' => '',
            'lastname' => '',
            'gender' => '',
            'school_name' => '',
            'school_code' => '',
            'study_level' => '',
            'class_grade' => '',
            'class_group' => '',
            'program' => '',
            'program_code' => '',
            'status' => '',
            'academic_year' => '',
            'position' => '',
        ];
    }
}
