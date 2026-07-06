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

        // Never ship the apikey/wstoken over cleartext HTTP to a non-local central.
        // http is permitted only for loopback dev, or behind an explicit unsafe
        // override (allow_insecure_central) for a private-network deployment.
        $scheme = strtolower((string) parse_url($this->serverurl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($this->serverurl, PHP_URL_HOST));
        $isloopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || substr($host, -6) === '.local';
        if ($scheme !== 'https' && !$isloopback
                && !get_config('local_syncqueue', 'allow_insecure_central')) {
            throw new moodle_exception('error_insecurecentral', 'local_syncqueue');
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
            $response = $this->request('/webservice/rest/server.php', [
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

        $response = $this->request('/webservice/rest/server.php', [
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
        $response = $this->request('/webservice/rest/server.php', [
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
     * Pull a batch of v2 sequenced outbox rows from central (protocol 2).
     *
     * Pure read on central: no tracking writes happen at pull time, delivery
     * state lives in this school's cursor. The response is normalized so the
     * caller never touches raw REST shapes.
     *
     * @param int $afterseq Return rows with seq strictly greater than this.
     * @param int $limit Maximum rows to return (server caps at 1000).
     * @return stdClass {protocol_version, head_seq, min_retained_seq,
     *         advance_to, rows: stdClass[]} where each row carries seq,
     *         entitytype, entitykey, entityversion, action, payload (JSON
     *         string or null), payloadhash, contentversion, partitionkey.
     * @throws moodle_exception On communication failure.
     */
    public function pull(int $afterseq, int $limit = 200): stdClass {
        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_pull',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'after_seq' => $afterseq,
            'limit' => $limit,
            'protocol_version' => 2,
        ]);

        $rows = [];
        foreach (($response['rows'] ?? []) as $raw) {
            $raw = (array) $raw;
            // REST serializes a null payload as an empty string; normalize back.
            $payload = $raw['payload'] ?? null;
            if ($payload === '') {
                $payload = null;
            }
            $rows[] = (object) [
                'seq' => (int) ($raw['seq'] ?? 0),
                'entitytype' => (string) ($raw['entitytype'] ?? ''),
                'entitykey' => (string) ($raw['entitykey'] ?? ''),
                'entityversion' => (int) ($raw['entityversion'] ?? 0),
                'action' => (string) ($raw['action'] ?? ''),
                'payload' => $payload,
                'payloadhash' => (string) ($raw['payloadhash'] ?? ''),
                'contentversion' => isset($raw['contentversion']) && $raw['contentversion'] !== ''
                    ? (int) $raw['contentversion'] : null,
                'partitionkey' => (string) ($raw['partitionkey'] ?? ''),
            ];
        }

        return (object) [
            'protocol_version' => (int) ($response['protocol_version'] ?? 0),
            'head_seq' => (int) ($response['head_seq'] ?? 0),
            'min_retained_seq' => (int) ($response['min_retained_seq'] ?? 1),
            'advance_to' => (int) ($response['advance_to'] ?? $afterseq),
            'rows' => $rows,
        ];
    }

    /**
     * Push a batch of v2 upstream fact rows to central (protocol 2).
     *
     * Rows are sent in school_seq order and retained on this school until acked.
     * The response is normalized so the caller never touches raw REST shapes.
     * Each row may be an outbox record (stdClass) or an assoc array carrying
     * seq/school_seq, factuuid, lineageuuid, factversion, facttype|entitytype,
     * action, entitykey, payload (JSON string or array), payloadhash, rostergen,
     * and optionally kind ('fact'|'hole') + reason (for holes).
     *
     * @param array $rows Fact/hole rows to push, in seq order.
     * @param string $epoch This school's self epoch.
     * @param int $headseq This school's current outbox head (MAX school_seq).
     * @return stdClass {protocol_version, status, acked_through, stored: int[],
     *         stale: int[], forks: stdClass[] (school_seq, tier, detail),
     *         reincarnate_required: bool, central_epoch, central_head_seq}.
     * @throws moodle_exception On communication failure.
     */
    public function push(array $rows, string $epoch, int $headseq): stdClass {
        $items = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $payload = $row['payload'] ?? null;
            if (is_array($payload)) {
                $payload = json_encode($payload);
            }
            // A row the school abandoned (action='hole', e.g. a lineage-conflict
            // seq vacated by self-heal) is sent as kind='hole' so central stores a
            // dead-marker and its contiguous ack frontier can cross the seq.
            $action = (string) ($row['action'] ?? 'upsert');
            $ishole = ($action === 'hole') || (($row['kind'] ?? '') === 'hole');
            $items[] = [
                'school_seq' => (int) ($row['school_seq'] ?? $row['seq'] ?? 0),
                'factuuid' => (string) ($row['factuuid'] ?? ''),
                'lineageuuid' => (string) ($row['lineageuuid'] ?? ''),
                'factversion' => (int) ($row['factversion'] ?? 0),
                'facttype' => (string) ($row['facttype'] ?? $row['entitytype'] ?? ''),
                'action' => $action,
                'entitykey' => (string) ($row['entitykey'] ?? ''),
                'payload' => $ishole ? null : $payload,
                'payloadhash' => (string) ($row['payloadhash'] ?? ''),
                'rostergen' => isset($row['rostergen']) && $row['rostergen'] !== null ? (int) $row['rostergen'] : null,
                'kind' => $ishole ? 'hole' : 'fact',
                'reason' => (string) ($row['reason'] ?? ($ishole ? 'self-heal: lineage-version conflict vacated seq' : '')),
            ];
        }

        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_push',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'protocol_version' => 2,
            'epoch' => $epoch,
            'head_seq' => $headseq,
            'items' => json_encode($items),
        ]);

        $forks = [];
        foreach (($response['forks'] ?? []) as $fork) {
            $fork = (array) $fork;
            $forks[] = (object) [
                'school_seq' => (int) ($fork['school_seq'] ?? 0),
                'tier' => (string) ($fork['tier'] ?? ''),
                'detail' => (string) ($fork['detail'] ?? ''),
            ];
        }

        return (object) [
            'protocol_version' => (int) ($response['protocol_version'] ?? 0),
            'status' => (string) ($response['status'] ?? ''),
            'acked_through' => (int) ($response['acked_through'] ?? 0),
            'stored' => array_map('intval', $response['stored'] ?? []),
            'stale' => array_map('intval', $response['stale'] ?? []),
            'forks' => $forks,
            'reincarnate_required' => !empty($response['reincarnate_required']),
            'central_epoch' => (string) ($response['central_epoch'] ?? ''),
            'central_head_seq' => (int) ($response['central_head_seq'] ?? 0),
        ];
    }

    /**
     * Run the re-incarnation handshake against central (protocol 2).
     *
     * Central mints a fresh epoch seeded above every high-water it holds for this
     * school; the caller adopts it and replays un-acked facts under it.
     *
     * @param string $oldepoch The epoch this school is retiring.
     * @return stdClass {protocol_version, new_epoch, seed_seq}.
     * @throws moodle_exception On communication failure.
     */
    public function reincarnate(string $oldepoch): stdClass {
        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_reincarnate',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'protocol_version' => 2,
            'old_epoch' => $oldepoch,
        ]);

        return (object) [
            'protocol_version' => (int) ($response['protocol_version'] ?? 0),
            'new_epoch' => (string) ($response['new_epoch'] ?? ''),
            'seed_seq' => (int) ($response['seed_seq'] ?? 0),
        ];
    }

    /**
     * Report sync completion to the central server.
     *
     * @param array $results Results of the sync operation.
     */
    public function report_sync(array $results): void {
        $this->request('/webservice/rest/server.php', [
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

        // POST keeps schoolid/apikey out of central's access logs;
        // backup_download.php reads params via required_param, which accepts
        // POST on all central versions. Content is buffered to memory first,
        // then written to file.
        $content = $curl->post($url, $params);

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
        $response = $this->request('/webservice/rest/server.php', [
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
        return $this->request('/webservice/rest/server.php', [
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
        $response = $this->request('/webservice/rest/server.php', [
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
     * Pull this school's full student/teacher roster through the central proxy.
     *
     * @return array ['students' => object[], 'teachers' => object[]]
     * @throws moodle_exception On communication failure.
     */
    public function tdmp_roster(): array {
        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_tdmp_roster',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
        ]);
        $students = json_decode($response['students'] ?? '[]');
        $teachers = json_decode($response['teachers'] ?? '[]');
        return [
            'students' => is_array($students) ? $students : [],
            'teachers' => is_array($teachers) ? $teachers : [],
            // Option B: central's roster generation for this school to adopt as its
            // fact stamp; null when talking to a central that predates the producer.
            'rostergen' => isset($response['rostergen']) ? (int) $response['rostergen'] : null,
        ];
    }

    /**
     * Exchange an anti-entropy digest phase with central (ELMS Sync v2 step 6).
     *
     * @param string $phase 'summary' (get central's bucket hashes) or 'detail'
     *        (send divergent buckets + keys, get missing/stale entities).
     * @param string $payloadjson Phase input as JSON ('' for summary).
     * @return array Decoded result: {summary:...} | {entities:[...]} | {upgrade:true}.
     * @throws moodle_exception On communication failure.
     */
    public function digest(string $phase, string $payloadjson): array {
        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_digest',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'phase' => $phase,
            'payload' => $payloadjson,
            'digest_version' => \local_syncqueue\digest::VERSION,
        ]);
        $result = json_decode($response['result'] ?? '{}', true);
        return is_array($result) ? $result : [];
    }

    /**
     * Upload a content-addressed submission blob to central (ELMS Sync v2 step 7, §9.1).
     *
     * @param string $contenthash Moodle file contenthash (sha1).
     * @param string $filename Original file name (reference only).
     * @param string $contentb64 Base64-encoded file bytes.
     * @return array Decoded {received, dedup, contenthash, error} (empty on transport failure).
     */
    public function upload_syncfile(string $contenthash, string $filename, string $contentb64): array {
        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_upload_file',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'contenthash' => $contenthash,
            'filename' => $filename,
            'content' => $contentb64,
        ]);
        return is_array($response) ? $response : [];
    }

    /**
     * Rotate this school's API key (ELMS Sync v2 step 7, §4.6). Authenticates with the
     * current key; central adopts $newkey and keeps the old key valid for a grace window.
     *
     * @param string $newkey The new plaintext key to adopt.
     * @return array Decoded {rotated, current, prev_expires} (empty on transport failure).
     */
    public function rotate_key(string $newkey): array {
        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_rotate_key',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'newkey' => $newkey,
        ]);
        return is_array($response) ? $response : [];
    }

    /**
     * Fetch a snapshot bootstrap manifest chunk from central (ELMS Sync v2 step 6).
     *
     * @param string $manifestid Manifest to resume ('' to (re)materialise).
     * @param int $chunkindex Chunk to fetch.
     * @return array {manifestid, headseq, numchunks, chunkindex, entries: [{...}]}
     * @throws moodle_exception On communication failure.
     */
    public function snapshot_manifest(string $manifestid, int $chunkindex): array {
        $response = $this->request('/webservice/rest/server.php', [
            'wstoken' => $this->wstoken,
            'wsfunction' => 'local_syncqueue_snapshot_manifest',
            'moodlewsrestformat' => 'json',
            'schoolid' => $this->schoolid,
            'apikey' => $this->apikey,
            'manifestid' => $manifestid,
            'chunkindex' => $chunkindex,
        ]);
        $entries = json_decode($response['entries'] ?? '[]', true);
        return [
            'manifestid' => (string) ($response['manifestid'] ?? ''),
            'headseq' => (int) ($response['headseq'] ?? 0),
            'numchunks' => (int) ($response['numchunks'] ?? 1),
            'chunkindex' => (int) ($response['chunkindex'] ?? 0),
            'entries' => is_array($entries) ? $entries : [],
        ];
    }

    /**
     * Make an HTTP request to the central server.
     *
     * Always POSTs: params carry the wstoken/apikey, and GET query strings
     * would leak them into central's access logs. Central's REST server reads
     * GET and POST parameters identically, so this is safe against older
     * central versions too.
     *
     * @param string $endpoint API endpoint.
     * @param array $params Request parameters (sent in the POST body).
     * @return array Decoded response.
     * @throws moodle_exception On failure.
     */
    protected function request(string $endpoint, array $params): array {
        $url = rtrim($this->serverurl, '/') . $endpoint;

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);

        $response = $curl->post($url, $params);

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
