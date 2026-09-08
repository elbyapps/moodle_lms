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
 * Authorised direct object-storage downloads for all REB Library assets.
 *
 * Stable library URLs remain valid: Moodle authorises and signs the request,
 * then object storage serves the bytes. There is deliberately no PHP proxy or
 * local-file fallback, including when signing or public storage is unavailable.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use local_reblibrary\download_delivery;
use local_reblibrary\s3_client;

require_login();
$context = context_system::instance();
require_capability('local/reblibrary:view', $context);

$key = required_param('key', PARAM_TEXT);
if (!download_delivery::valid_key($key)) {
    throw new moodle_exception('invalidfilekey', 'local_reblibrary');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

// No writes follow authorisation; release the session before any signing work.
\core\session\manager::write_close();
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

try {
    $s3 = new s3_client();
    $url = $s3->get_presigned_get_url($key, 600, download_delivery::response_headers($key), $method);
    if (!download_delivery::valid_url($url)) {
        throw new \UnexpectedValueException('Downloads require an HTTPS storage endpoint');
    }
    // Raw redirect: no HTML interstitial or byte streaming. GET ranges and HEAD
    // retain their methods; storage handles missing objects and range responses.
    header('Location: ' . $url, true, 302);
    exit;
} catch (\Throwable $e) {
    // SDK messages can contain credentials or signed URLs. Log only the class.
    error_log('REB Library download signing failed (' . get_class($e) . ')');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    if ($method !== 'HEAD') {
        echo get_string('downloaderror', 'local_reblibrary');
    }
    exit;
}
