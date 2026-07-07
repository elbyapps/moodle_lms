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
 * InTouch SMS gateway client for local_elby_dashboard.
 *
 * Server-side HTTP client for the InTouch SMS API (intouchsms.co.rw).
 * Credentials come from plugin config and never reach the browser.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * InTouch SMS gateway client.
 */
class sms_client {

    /** @var string SMS API endpoint URL. */
    private string $baseurl;

    /** @var string Sender id shown on the recipient's phone. */
    private string $sender;

    /** @var string Basic-auth username (server-side only). */
    private string $user;

    /** @var string Basic-auth password (server-side only). */
    private string $password;

    /** @var int HTTP request timeout in seconds. */
    private int $timeout;

    /** @var bool Whether SMS sending is enabled at all (off in dev/staging). */
    private bool $enabled;

    /**
     * Constructor. Loads configuration from plugin settings.
     */
    public function __construct() {
        $this->baseurl = get_config('local_elby_dashboard', 'sms_api_url')
            ?: 'https://www.intouchsms.co.rw/api/sendsms/.json';
        $this->sender = get_config('local_elby_dashboard', 'sms_sender') ?: 'REB';
        $this->user = (string) (get_config('local_elby_dashboard', 'sms_username') ?: '');
        $this->password = (string) (get_config('local_elby_dashboard', 'sms_password') ?: '');
        $this->timeout = (int) (get_config('local_elby_dashboard', 'sms_timeout') ?: 30);
        $this->enabled = (bool) get_config('local_elby_dashboard', 'sms_enabled');
    }

    /**
     * Whether the gateway is enabled and fully configured.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->enabled && $this->user !== '' && $this->password !== '';
    }

    /**
     * Send an SMS to a Rwandan phone number.
     *
     * @param string $phone Recipient in any common format; normalised to 07XXXXXXXX.
     * @param string $message Message body.
     * @return bool True when the gateway accepted the message.
     */
    public function send(string $phone, string $message): bool {
        if (!$this->is_configured()) {
            return false;
        }
        $to = self::normalise_rw($phone);
        if ($to === null) {
            return false;
        }

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_RETURNTRANSFER' => true,
        ]);
        $curl->setHeader(['Authorization: Basic ' . base64_encode($this->user . ':' . $this->password)]);
        $url = $this->baseurl . '?' . http_build_query(
            ['sender' => $this->sender, 'message' => $message, 'recipients' => $to],
            '', '&', PHP_QUERY_RFC3986);
        $curl->get($url);

        return ((int) ($curl->get_info()['http_code'] ?? 0)) === 200;
    }

    /**
     * Normalise a Rwandan mobile number to the local 07XXXXXXXX shape InTouch expects.
     *
     * Accepts +2507XXXXXXXX, 2507XXXXXXXX, 07XXXXXXXX and 7XXXXXXXX.
     *
     * @param string $phone Raw phone value.
     * @return string|null Normalised number, or null when it can't be a valid Rwandan mobile.
     */
    public static function normalise_rw(string $phone): ?string {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '2507')) {
            $digits = '0' . substr($digits, 3);
        } else if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '0' . $digits;
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '07')) {
            return $digits;
        }
        return null;
    }
}
