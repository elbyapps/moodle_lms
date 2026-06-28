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
 * TMIS / NIDA citizen lookup client for local_elby_dashboard.
 *
 * Server-side HTTP client for the TMIS citizen endpoint, used to validate a
 * RISE learner's National ID against NIDA records. TMIS uses cookie-based auth:
 * we log in with the configured credentials to obtain a session cookie, then
 * call the citizen endpoint with it. Credentials are read from plugin config
 * and never leave the server.
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
 * TMIS citizen lookup client.
 */
class tmis_client {

    /** @var int Max attempts for the citizen lookup (TMIS proxies a flaky NIDA upstream). */
    private const MAX_RETRIES = 3;

    /** @var string TMIS API base URL (no trailing slash). */
    private string $baseurl;

    /** @var string Login identifier (sent as the "email" field). */
    private string $username;

    /** @var string Login password. */
    private string $password;

    /** @var int HTTP request timeout in seconds. */
    private int $timeout;

    /**
     * Constructor. Loads configuration from plugin settings.
     *
     * @throws \moodle_exception If the URL or credentials are not configured.
     */
    public function __construct() {
        $this->baseurl = rtrim(get_config('local_elby_dashboard', 'tmis_api_url') ?: '', '/');
        $this->username = (string) (get_config('local_elby_dashboard', 'tmis_username') ?: '');
        $this->password = (string) (get_config('local_elby_dashboard', 'tmis_password') ?: '');
        $this->timeout = (int) (get_config('local_elby_dashboard', 'tmis_timeout') ?: 30);

        if (empty($this->baseurl) || empty($this->username) || empty($this->password)) {
            throw new \moodle_exception('tmisnotconfigured', 'local_elby_dashboard');
        }
    }

    /**
     * Look up a citizen record by National ID.
     *
     * @param string $nid National ID number.
     * @return array Decoded citizen payload (flat: firstName, lastName, dateOfBirth, gender, ...).
     * @throws \moodle_exception On auth failure, HTTP error, or invalid JSON.
     */
    public function get_citizen(string $nid): array {
        $cookiefile = tempnam(sys_get_temp_dir(), 'tmis_');
        try {
            $this->login($cookiefile);
            return $this->fetch_citizen($nid, $cookiefile);
        } finally {
            if ($cookiefile !== false) {
                @unlink($cookiefile);
            }
        }
    }

    /**
     * Authenticate against TMIS, storing the session cookie in the given jar.
     *
     * @param string $cookiefile Path to the cookie jar file.
     * @throws \moodle_exception On authentication failure.
     */
    private function login(string $cookiefile): void {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_COOKIEJAR' => $cookiefile,
            'CURLOPT_COOKIEFILE' => $cookiefile,
        ]);
        $curl->setHeader(['Accept: application/json', 'Content-Type: application/json']);

        $payload = json_encode(['email' => $this->username, 'password' => $this->password]);
        $curl->post($this->baseurl . '/auth/logins', $payload);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

        if ($httpcode !== 200) {
            throw new \moodle_exception('tmisauthfailed', 'local_elby_dashboard', '', 'HTTP ' . $httpcode);
        }
    }

    /**
     * Fetch the citizen record using an authenticated cookie jar, retrying on
     * transient upstream failures.
     *
     * @param string $nid National ID number.
     * @param string $cookiefile Path to the authenticated cookie jar.
     * @return array Decoded citizen payload.
     * @throws \moodle_exception On HTTP error or invalid JSON.
     */
    private function fetch_citizen(string $nid, string $cookiefile): array {
        $url = $this->baseurl . '/users/citizen/' . rawurlencode($nid);
        $lasterror = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $curl = new \curl();
            $curl->setopt([
                'CURLOPT_TIMEOUT' => $this->timeout,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_COOKIEJAR' => $cookiefile,
                'CURLOPT_COOKIEFILE' => $cookiefile,
            ]);
            $curl->setHeader(['Accept: application/json']);

            $response = $curl->get($url);
            $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

            if ($httpcode === 200) {
                $data = json_decode($response, true);
                if (!is_array($data)) {
                    throw new \moodle_exception('tmiserror', 'local_elby_dashboard', '', 'Invalid JSON from TMIS.');
                }
                return $data;
            }

            if ($httpcode === 404) {
                throw new \moodle_exception('tmisnotfound', 'local_elby_dashboard');
            }

            // TMIS proxies NIDA, which intermittently times out with a 500; retry those.
            if ($httpcode === 500 || $httpcode === 0) {
                $lasterror = 'HTTP ' . $httpcode . ' ' . trim((string) $response);
                if ($attempt < self::MAX_RETRIES) {
                    continue;
                }
                break;
            }

            $lasterror = 'HTTP ' . $httpcode;
            break;
        }

        throw new \moodle_exception('tmiserror', 'local_elby_dashboard', '', $lasterror);
    }
}
