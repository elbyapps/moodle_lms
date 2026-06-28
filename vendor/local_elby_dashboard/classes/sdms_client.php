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
 * SDMS API client for local_elby_dashboard.
 *
 * HTTP client for the external Student Data Management System API.
 * Uses IP whitelist authentication (no auth header needed).
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * SDMS API client.
 *
 * Provides methods to fetch student, staff, and school records from the SDMS API.
 * All requests are logged to the elby_sync_log table.
 */
class sdms_client {

    /** @var int Maximum retry attempts for failed requests. */
    private const MAX_RETRIES = 3;

    /** @var string SDMS API base URL. */
    private string $baseurl;

    /** @var int HTTP request timeout in seconds. */
    private int $timeout;

    /**
     * Constructor.
     *
     * Loads configuration from Moodle admin settings.
     *
     * @throws \moodle_exception If SDMS API URL is not configured.
     */
    public function __construct() {
        $this->baseurl = rtrim(get_config('local_elby_dashboard', 'sdms_api_url') ?: '', '/');
        $this->timeout = (int) (get_config('local_elby_dashboard', 'sdms_timeout') ?: 30);

        if (empty($this->baseurl)) {
            throw new \moodle_exception('sdmsapierror', 'local_elby_dashboard', '',
                'SDMS API URL is not configured. Please contact your administrator.');
        }
    }

    /**
     * Fetch a student record from SDMS.
     *
     * @param string $code The student code (studentNumber).
     * @return object|null Student data object, or null if not found.
     */
    public function get_student(string $code): ?object {
        $url = $this->baseurl . '/student?studentCode=' . urlencode($code);
        return $this->make_request($url, 'student', $code);
    }

    /**
     * Fetch a staff record from SDMS.
     *
     * @param string $id The staff number (staffId).
     * @return object|null Staff data object, or null if not found.
     */
    public function get_staff(string $id): ?object {
        $url = $this->baseurl . '/staff?staffNumber=' . urlencode($id);
        return $this->make_request($url, 'staff', $id);
    }

    /**
     * Fetch a school record from SDMS.
     *
     * @param string $code The school code.
     * @return object|null School data object, or null if not found.
     */
    public function get_school(string $code): ?object {
        $url = $this->baseurl . '/school?schoolCode=' . urlencode($code);
        return $this->make_request($url, 'school', $code);
    }

    /**
     * Execute an HTTP GET request with retry logic.
     *
     * @param string $url Full request URL.
     * @param string $entitytype Entity type for logging (student, staff, school).
     * @param string $entityid Entity identifier for logging.
     * @return object|null Parsed JSON response, or null if 404/not found.
     * @throws \moodle_exception On persistent failure after retries.
     */
    private function make_request(string $url, string $entitytype, string $entityid): ?object {
        $lasterror = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $starttime = microtime(true);

            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_TIMEOUT' => $this->timeout,
                'CURLOPT_RETURNTRANSFER' => true,
            ]);
            $curl->setHeader(['Accept: application/json']);

            $response = $curl->get($url);
            $responsetime = (int) ((microtime(true) - $starttime) * 1000);
            $info = $curl->get_info();
            $httpcode = (int) ($info['http_code'] ?? 0);

            // Success.
            if ($httpcode === 200) {
                $data = json_decode($response);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    $lasterror = 'Invalid JSON response';
                    $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid, $lasterror);
                    throw new \moodle_exception('sdmsapierror', 'local_elby_dashboard', '',
                        'SDMS returned an invalid response. Please try again later.', $lasterror);
                }

                // API may return an array — unwrap the first element.
                if (is_array($data)) {
                    $data = !empty($data) ? $data[0] : null;
                }

                // Empty response or empty object — treat as not found.
                if (empty($data) || (is_object($data) && empty((array) $data))) {
                    $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid, 'Empty response');
                    return null;
                }

                // SDMS returns HTTP 200 with error body for server-side bugs.
                // Detect: {"status": 500, "message": "..."} pattern.
                if (is_object($data) && isset($data->status) && (int) $data->status >= 400) {
                    $errmsg = $data->message ?? 'Unknown error';
                    $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid, 'SDMS error: ' . $errmsg);
                    $usermsg = 'SDMS server error for this ' . $entitytype .
                        '. This is a known issue with the SDMS system.' .
                        ' Please try again later or contact the SDMS team.';
                    throw new \moodle_exception('sdmsapierror', 'local_elby_dashboard', '', $usermsg, $errmsg);
                }

                $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid);
                return $data;
            }

            // Not found.
            if ($httpcode === 404) {
                $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid, 'Not found');
                return null;
            }

            // Connection error (httpcode = 0) — retry with exponential backoff.
            if ($httpcode === 0) {
                $curlerror = $curl->get_errno() . ': ' . ($curl->error ?? 'Connection failed');
                $lasterror = "Connection error: {$curlerror}";
                $this->log_request($url, 0, $responsetime, $entitytype, $entityid, $lasterror);
                if ($attempt < self::MAX_RETRIES) {
                    sleep(pow(2, $attempt));
                    continue;
                }
            }

            // All other HTTP errors (including 5xx) — do not retry.
            if ($httpcode > 0) {
                $lasterror = "HTTP {$httpcode}";
                $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid, $lasterror);
                break;
            }
        }

        throw new \moodle_exception('sdmsapierror', 'local_elby_dashboard', '',
            'SDMS API is currently unavailable. Please try again later.',
            "SDMS API error after {$attempt} attempt(s): {$lasterror}");
    }

    /**
     * Log an API request to the elby_sync_log table.
     *
     * @param string $url Request URL.
     * @param int $responsecode HTTP response code.
     * @param int $responsetimems Response time in milliseconds.
     * @param string $entitytype Entity type (student, staff, school).
     * @param string $entityid Entity identifier.
     * @param string|null $error Error message, if any.
     */
    private function log_request(
        string $url,
        int $responsecode,
        int $responsetimems,
        string $entitytype,
        string $entityid,
        ?string $error = null
    ): void {
        global $DB, $USER;

        $record = new \stdClass();
        $record->sync_type = $entitytype;
        $record->entity_id = $entityid;
        $record->userid = $USER->id ?? 0;
        $record->operation = $error ? 'error' : 'fetch';
        $record->request_url = $url;
        $record->response_code = $responsecode;
        $record->response_time_ms = $responsetimems;
        $record->error_message = $error;
        $record->triggered_by = 'api';
        $record->timecreated = time();

        try {
            $DB->insert_record('elby_sync_log', $record);
        } catch (\Exception $e) {
            debugging('Failed to log SDMS request: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
