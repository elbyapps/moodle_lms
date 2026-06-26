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
 * TDMP gateway API client for local_elby_dashboard.
 *
 * HTTP client for the RTB TDMP gateway, which fronts SDMS/TMIS/CAMIS as a
 * canonical, API-key-authenticated REST API. Responses are wrapped in a
 * { "data": ..., "meta": {...} } envelope; this client returns the unwrapped
 * data payload.
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
 * TDMP gateway API client.
 *
 * Provides methods to fetch student, teacher, and school records from the
 * TDMP gateway. All requests are logged to the elby_sync_log table.
 */
class tdmp_client {

    /** @var int Maximum retry attempts for transient connection failures. */
    private const MAX_RETRIES = 3;

    /** @var string Gateway base URL (no trailing slash). */
    private string $baseurl;

    /** @var string Gateway API key, sent as the X-API-Key header. */
    private string $apikey;

    /** @var int HTTP request timeout in seconds. */
    private int $timeout;

    /**
     * Constructor.
     *
     * Loads configuration from Moodle admin settings.
     *
     * @throws \moodle_exception If the gateway URL or API key is not configured.
     */
    public function __construct() {
        $this->baseurl = rtrim(get_config('local_elby_dashboard', 'tdmp_api_url') ?: '', '/');
        $this->apikey = trim((string) (get_config('local_elby_dashboard', 'tdmp_api_key') ?: ''));
        $this->timeout = (int) (get_config('local_elby_dashboard', 'tdmp_timeout') ?: 30);

        if (empty($this->baseurl)) {
            throw new \moodle_exception('tdmsapierror', 'local_elby_dashboard', '',
                'TDMP gateway URL is not configured. Please contact your administrator.');
        }
        if (empty($this->apikey)) {
            throw new \moodle_exception('tdmsapierror', 'local_elby_dashboard', '',
                'TDMP gateway API key is not configured. Please contact your administrator.');
        }
    }

    /**
     * Fetch a student record by SDMS code (studentNumber).
     *
     * @param string $code The student code.
     * @return object|null Student data object, or null if not found.
     */
    public function get_student(string $code): ?object {
        return $this->get_entity('/students/' . rawurlencode($code), 'student', $code);
    }

    /**
     * Fetch a teacher record.
     *
     * The gateway path param is a universal resolver: it accepts the internal
     * id, SDMS staff number, NID, or TMIS staff id.
     *
     * @param string $identifier The teacher identifier.
     * @return object|null Teacher data object, or null if not found.
     */
    public function get_teacher(string $identifier): ?object {
        return $this->get_entity('/teachers/' . rawurlencode($identifier), 'teacher', $identifier);
    }

    /**
     * Fetch a school record by school code.
     *
     * @param string $code The school code.
     * @return object|null School data object, or null if not found.
     */
    public function get_school(string $code): ?object {
        return $this->get_entity('/schools/' . rawurlencode($code), 'school', $code);
    }

    /**
     * Fetch a trade by its trade code (matches a student's combinationCode).
     *
     * @param string $tradecode The trade code.
     * @return object|null Trade data object, or null if not found.
     */
    public function get_trade(string $tradecode): ?object {
        return $this->get_entity('/trades?tradeCode=' . rawurlencode($tradecode), 'trade', $tradecode);
    }

    /**
     * Fetch the full canonical list of trades (paginated).
     *
     * @return object[] Trade records (may be empty).
     */
    public function get_trades(): array {
        $all = [];
        $page = 1;
        $limit = 50;
        do {
            $envelope = $this->make_request(
                $this->baseurl . '/trades?page=' . $page . '&limit=' . $limit, 'trade', 'list:' . $page);
            if ($envelope === null || empty($envelope->data) || !is_array($envelope->data)) {
                break;
            }
            foreach ($envelope->data as $trade) {
                $all[] = $trade;
            }
            $total = (int) ($envelope->meta->page->total ?? count($all));
            $page++;
        } while (count($all) < $total && $page <= 100);
        return $all;
    }

    /**
     * Search curriculum modules (subjects) from the canonical modules report.
     *
     * Backs the searchable Module field on the course form. <code>moduleId</code> is
     * globally unique per subject; a module may belong to many combinations, so the
     * optional combination filter only narrows the list (it is not a 1:1 mapping).
     *
     * @param string $search Free-text query over subject name and code (empty = top of list).
     * @param int|null $combinationid Optional trade combination id (= /trades[].id) to scope results.
     * @param int $limit Page size (gateway caps this at 200).
     * @return object[] Module records (may be empty).
     */
    public function get_modules(string $search, ?int $combinationid = null, int $limit = 15): array {
        $limit = max(1, min($limit, 200));
        $url = $this->baseurl . '/reports/modules/teachers?limit=' . $limit;
        if ($search !== '') {
            $url .= '&search=' . rawurlencode($search);
        }
        if ($combinationid !== null && $combinationid > 0) {
            $url .= '&combinationId=' . $combinationid;
        }
        $envelope = $this->make_request($url, 'module', 'search:' . $search);
        if ($envelope === null || empty($envelope->data) || !is_array($envelope->data)) {
            return [];
        }
        return $envelope->data;
    }

    /**
     * Fetch a single entity and unwrap the gateway { data, meta } envelope.
     *
     * @param string $path API path beginning with a slash (relative to base URL).
     * @param string $entitytype Entity type for logging.
     * @param string $entityid Entity identifier for logging.
     * @return object|null Unwrapped data object, or null if not found.
     */
    private function get_entity(string $path, string $entitytype, string $entityid): ?object {
        $envelope = $this->make_request($this->baseurl . $path, $entitytype, $entityid);
        if ($envelope === null) {
            return null;
        }

        $data = $envelope->data ?? null;

        // A list-style payload — take the first element.
        if (is_array($data)) {
            $data = !empty($data) ? $data[0] : null;
        }

        if (empty($data) || (is_object($data) && empty((array) $data))) {
            return null;
        }

        return is_object($data) ? $data : null;
    }

    /**
     * Execute an authenticated HTTP GET with retry on transient failures.
     *
     * @param string $url Full request URL.
     * @param string $entitytype Entity type for logging.
     * @param string $entityid Entity identifier for logging.
     * @return object|null Decoded JSON envelope, or null on 404.
     * @throws \moodle_exception On persistent failure after retries.
     */
    private function make_request(string $url, string $entitytype, string $entityid): ?object {
        $lasterror = '';
        $attempt = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $starttime = microtime(true);

            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_TIMEOUT' => $this->timeout,
                'CURLOPT_RETURNTRANSFER' => true,
            ]);
            $curl->setHeader([
                'Accept: application/json',
                'X-API-Key: ' . $this->apikey,
            ]);

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
                    throw new \moodle_exception('tdmsapierror', 'local_elby_dashboard', '',
                        'TDMP gateway returned an invalid response. Please try again later.', $lasterror);
                }

                $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid);
                return is_object($data) ? $data : null;
            }

            // Not found — clean 404 from the gateway.
            if ($httpcode === 404) {
                $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid, 'Not found');
                return null;
            }

            // Authentication / authorization failure — do not retry.
            if ($httpcode === 401 || $httpcode === 403) {
                $lasterror = "HTTP {$httpcode}";
                $this->log_request($url, $httpcode, $responsetime, $entitytype, $entityid, $lasterror);
                throw new \moodle_exception('tdmsapierror', 'local_elby_dashboard', '',
                    'TDMP gateway rejected the request (authentication failed). Please check the API key.',
                    $lasterror);
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

        throw new \moodle_exception('tdmsapierror', 'local_elby_dashboard', '',
            'TDMP gateway is currently unavailable. Please try again later.',
            "TDMP gateway error after {$attempt} attempt(s): {$lasterror}");
    }

    /**
     * Log an API request to the elby_sync_log table.
     *
     * The API key is sent as a header and is never part of the logged URL.
     *
     * @param string $url Request URL.
     * @param int $responsecode HTTP response code.
     * @param int $responsetimems Response time in milliseconds.
     * @param string $entitytype Entity type (student, teacher, school).
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
            debugging('Failed to log TDMP request: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
