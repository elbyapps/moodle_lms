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
use moodle_exception;

/**
 * Client for communicating with the central sync server.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_client {

    /** @var string Central server URL */
    protected string $serverurl;

    /** @var string Web service token for Moodle authentication */
    protected string $wstoken;

    /** @var string School API key for school-level authentication */
    protected string $apikey;

    /** @var string School identifier */
    protected string $schoolid;

    /** @var int Connection timeout in seconds */
    protected int $timeout = 30;

    /**
     * Constructor.
     *
     * @throws moodle_exception If not properly configured.
     */
    public function __construct() {
        global $CFG;
        // The Moodle \curl class lives in lib/filelib.php, which is not guaranteed
        // to be loaded in cron/CLI contexts (unlike web requests). Without this,
        // sync tasks intermittently fail with "Class curl not found".
        require_once($CFG->libdir . '/filelib.php');

        $this->serverurl = get_config('local_syncqueue', 'centralserver');
        $this->wstoken = get_config('local_syncqueue', 'wstoken');
        $this->apikey = get_config('local_syncqueue', 'apikey');
        $this->schoolid = get_config('local_syncqueue', 'schoolid');

        if (empty($this->serverurl) || empty($this->schoolid)) {
            throw new moodle_exception('error_notconfigured', 'local_syncqueue');
        }

        // Use apikey as wstoken if wstoken not set (backwards compatibility).
        if (empty($this->wstoken)) {
            $this->wstoken = $this->apikey;
        }

        if (empty($this->wstoken)) {
            throw new moodle_exception('error_notconfigured', 'local_syncqueue');
        }
    }

    /**
     * Check if the central server is reachable.
     *
     * @return bool True if server is reachable.
     */
    public function check_connection(): bool {
        $result = $this->check_connection_detailed();
        return $result['success'];
    }

    /**
     * Check connection with detailed result.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function check_connection_detailed(): array {
        try {
            $response = $this->request('GET', '/webservice/rest/server.php', [
                'wstoken' => $this->wstoken,
                'wsfunction' => 'local_syncqueue_status',
                'moodlewsrestformat' => 'json',
                'schoolid' => $this->schoolid,
                'apikey' => $this->apikey,
            ]);

            if (isset($response['status']) && $response['status'] === 'ok') {
                return [
                    'success' => true,
                    'message' => $response['message'] ?? 'Connected successfully',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $response['message'] ?? 'Unknown error from server',
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload queue items to the central server.
     *
     * @param array $items Array of queue items to upload.
     * @return array Response from server with results per item.
     * @throws moodle_exception On communication failure.
     */
    public function upload(array $items): array {
        if (empty($items)) {
            return [];
        }

        $payload = [
            'schoolid' => $this->schoolid,
            'timestamp' => time(),
            'items' => [],
        ];

        foreach ($items as $item) {
            $payload['items'][] = [
                'id' => $item->id,
                'eventtype' => $item->eventtype,
                'eventname' => $item->eventname,
                'payload' => json_decode($item->payload, true),
                'priority' => $item->priority,
                'timecreated' => $item->timecreated,
            ];
        }

        $response = $this->request('POST', '/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_upload',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'data' => json_encode($payload),
        ]);

        return $response['results'] ?? [];
    }

    /**
     * Upload a file to the central server.
     *
     * @param stdClass $filerecord File queue record.
     * @param \stored_file $file The file to upload.
     * @return bool True on success.
     * @throws moodle_exception On failure.
     */
    public function upload_file(stdClass $filerecord, \stored_file $file): bool {
        $url = $this->serverurl . '/webservice/upload.php';

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 120, // Longer timeout for file uploads.
            'CURLOPT_CONNECTTIMEOUT' => $this->timeout,
        ]);

        // Create temporary file for upload.
        $tempdir = make_temp_directory('syncqueue');
        $tempfile = $tempdir . '/' . $file->get_contenthash();
        $file->copy_content_to($tempfile);

        try {
            $params = [
                'token' => $this->apikey,
                'schoolid' => $this->schoolid,
                'contenthash' => $file->get_contenthash(),
                'filename' => $file->get_filename(),
                'file' => new \CURLFile($tempfile, $file->get_mimetype(), $file->get_filename()),
            ];

            $response = $curl->post($url, $params);
            $result = json_decode($response, true);

            return isset($result['success']) && $result['success'];
        } finally {
            @unlink($tempfile);
        }
    }

    /**
     * Download updates from the central server.
     *
     * @param int $since Timestamp to get updates since.
     * @return array Updates from the server.
     * @throws moodle_exception On communication failure.
     */
    public function download(int $since = 0): array {
        $response = $this->request('GET', '/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_download',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'since' => $since,
        ]);

        return $response['updates'] ?? [];
    }

    /**
     * Report sync completion to the central server.
     *
     * @param array $results Results of the sync operation.
     */
    public function report_sync(array $results): void {
        $this->request('POST', '/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_report',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'results' => json_encode($results),
        ]);
    }

    /**
     * Download a backup file from central server.
     *
     * @param string $filename Backup filename.
     * @param string $destpath Destination path to save the file.
     * @return bool True on success.
     */
    public function download_backup(string $filename, string $destpath): bool {
        $url = rtrim($this->serverurl, '/') . '/local/syncqueue/backup_download.php';

        $params = [
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'file' => $filename,
        ];

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 600, // 10 minutes for large files.
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        // Download content to memory first, then write to file.
        $content = $curl->get($url, $params);

        $info = $curl->get_info();
        $errno = $curl->get_errno();

        if ($errno || ($info['http_code'] ?? 0) !== 200 || empty($content)) {
            return false;
        }

        // Write content to file.
        $written = file_put_contents($destpath, $content);
        if ($written === false) {
            return false;
        }

        return file_exists($destpath) && filesize($destpath) > 0;
    }

    /**
     * Fetch the course catalog available to this school (F3).
     *
     * @return array ['onlyselected'=>int, 'courses'=>[...]]
     * @throws moodle_exception On communication failure.
     */
    public function get_catalog(): array {
        $response = $this->request('GET', '/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_catalog',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
        ]);
        return [
            'onlyselected' => (int) ($response['onlyselected'] ?? 0),
            'courses' => $response['courses'] ?? [],
        ];
    }

    /**
     * Send this school's course pull preferences to central (F3).
     *
     * @param bool $onlyselected Only pull selected courses.
     * @param array $prefs List of ['courseid'=>int,'selected'=>bool,'weight'=>int].
     * @return array Server response.
     * @throws moodle_exception On communication failure.
     */
    public function upload_priorities(bool $onlyselected, array $prefs): array {
        return $this->request('POST', '/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_upload_priorities',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'onlyselected' => $onlyselected ? 1 : 0,
            'priorities' => json_encode(array_values($prefs)),
        ]);
    }

    /**
     * Resolve a single TDMP record through the central proxy.
     *
     * Schools do not hold the TDMP API key; central runs the real lookup and
     * returns the canonical record.
     *
     * @param string $code TDMP identifier (student/staff/school/trade code).
     * @param string $type Lookup type: student, teacher, staff, school or trade.
     * @return object|null Canonical record, or null if not found.
     * @throws moodle_exception On communication failure.
     */
    public function tdmp_lookup(string $code, string $type): ?object {
        $response = $this->request('GET', '/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_tdmp_lookup',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'sdms_code' => $code,
            'user_type' => $type,
        ]);

        if (empty($response['found']) || empty($response['data'])) {
            return null;
        }

        $data = json_decode($response['data']);
        return is_object($data) ? $data : null;
    }

    /**
     * Make an HTTP request to the central server.
     *
     * @param string $method HTTP method.
     * @param string $endpoint API endpoint.
     * @param array $params Request parameters.
     * @return array Decoded response.
     * @throws moodle_exception On failure.
     */
    protected function request(string $method, string $endpoint, array $params): array {
        $url = rtrim($this->serverurl, '/') . $endpoint;

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        if ($method === 'GET') {
            $response = $curl->get($url, $params);
        } else {
            $response = $curl->post($url, $params);
        }

        $info = $curl->get_info();
        $errno = $curl->get_errno();
        $error = $curl->error;

        // Check for curl errors or blocked URLs.
        if ($errno || $response === false) {
            throw new moodle_exception('error_noconnection', 'local_syncqueue', '', $error ?: 'Connection failed');
        }

        // Check HTTP status code (handle case where it might not be set).
        $httpcode = $info['http_code'] ?? 0;
        if ($httpcode !== 200) {
            if ($httpcode === 0) {
                throw new moodle_exception('error_noconnection', 'local_syncqueue', '', 'URL may be blocked or unreachable');
            }
            throw new moodle_exception('error_invalidresponse', 'local_syncqueue', '', $httpcode);
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            // Include part of the response for debugging.
            $preview = substr($response, 0, 200);
            throw new moodle_exception('error_invalidresponse', 'local_syncqueue', '', 'Response: ' . $preview);
        }

        if (isset($decoded['exception'])) {
            throw new moodle_exception('error_syncfailed', 'local_syncqueue', '', $decoded['message'] ?? 'Unknown error');
        }

        return $decoded;
    }
}
