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
 * RISE API client for local_elby_dashboard.
 *
 * Server-side HTTP client for the external RISE (Resilience In Secondary
 * Education) recruitment API. The API key is read from plugin config and never
 * leaves the server, so the browser only ever talks to Moodle web services.
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
 * RISE API client.
 */
class rise_client {

    /** @var int Maximum retry attempts for transient (connection) failures. */
    private const MAX_RETRIES = 3;

    /** @var string RISE API base URL (no trailing slash). */
    private string $baseurl;

    /** @var string RISE API key sent in the X-API-KEY header. */
    private string $apikey;

    /** @var int HTTP request timeout in seconds. */
    private int $timeout;

    /**
     * Constructor. Loads configuration from plugin settings.
     *
     * @throws \moodle_exception If the API URL or key is not configured.
     */
    public function __construct() {
        $this->baseurl = rtrim(get_config('local_elby_dashboard', 'rise_api_url') ?: '', '/');
        $this->apikey = (string) (get_config('local_elby_dashboard', 'rise_api_key') ?: '');
        $this->timeout = (int) (get_config('local_elby_dashboard', 'rise_timeout') ?: 30);

        if (empty($this->baseurl) || empty($this->apikey)) {
            throw new \moodle_exception('riseapinotconfigured', 'local_elby_dashboard');
        }
    }

    /**
     * Fetch the list of campaigns.
     *
     * @return array Decoded response: ['campaigns' => [...]].
     */
    public function get_campaigns(): array {
        return $this->get('/campaigns');
    }

    /**
     * Fetch a paginated, filtered list of applicants for a campaign.
     *
     * @param string $campaignid Campaign id (Mongo _id).
     * @param array $filters Optional filters: status, provinceCode, district, gender, page, limit.
     * @return array Decoded response: ['applicants' => [...], 'pagination' => [...]].
     */
    public function get_applicants(string $campaignid, array $filters = []): array {
        // Only forward known, non-empty filters.
        $allowed = ['status', 'provinceCode', 'district', 'gender', 'page', 'limit'];
        $query = [];
        foreach ($allowed as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }
        $path = '/campaigns/' . rawurlencode($campaignid) . '/applicants';
        if (!empty($query)) {
            // Force a raw '&' separator. Moodle/PHP may set arg_separator.output to '&amp;',
            // which is correct for HTML but breaks API query strings (limit/page are ignored).
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $this->get($path);
    }

    /**
     * Execute a GET request against the RISE API.
     *
     * @param string $path Path beginning with '/', relative to the base URL.
     * @return array Decoded JSON response as an associative array.
     * @throws \moodle_exception On HTTP error or invalid JSON.
     */
    private function get(string $path): array {
        $url = $this->baseurl . $path;
        $lasterror = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_TIMEOUT' => $this->timeout,
                'CURLOPT_RETURNTRANSFER' => true,
            ]);
            $curl->setHeader(['Accept: application/json', 'X-API-KEY: ' . $this->apikey]);

            $response = $curl->get($url);
            $info = $curl->get_info();
            $httpcode = (int) ($info['http_code'] ?? 0);

            if ($httpcode === 200) {
                $data = json_decode($response, true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new \moodle_exception('riseapierror', 'local_elby_dashboard', '',
                        'Invalid JSON from RISE API.');
                }
                return is_array($data) ? $data : [];
            }

            // Transient connection failure — retry with exponential backoff.
            if ($httpcode === 0) {
                $lasterror = 'Connection error: ' . $curl->get_errno() . ' ' . ($curl->error ?? '');
                if ($attempt < self::MAX_RETRIES) {
                    sleep($attempt);
                    continue;
                }
                break;
            }

            // Any other HTTP status is non-retryable.
            $lasterror = 'HTTP ' . $httpcode;
            break;
        }

        throw new \moodle_exception('riseapierror', 'local_elby_dashboard', '', $lasterror);
    }
}
