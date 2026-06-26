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
 * Backup file download endpoint for sync.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');

$schoolid = required_param('schoolid', PARAM_ALPHANUMEXT);
$apikey = required_param('apikey', PARAM_RAW);
$filename = required_param('file', PARAM_FILE);

// Check if running in central mode.
$mode = get_config('local_syncqueue', 'mode');
if ($mode !== 'central') {
    http_response_code(403);
    die('Not in central mode');
}

// Check if enabled.
if (!get_config('local_syncqueue', 'enabled')) {
    http_response_code(403);
    die('Sync queue disabled');
}

// Validate school and API key.
$schoolmanager = new \local_syncqueue\school_manager();
if (!$schoolmanager->verify_apikey($schoolid, $apikey)) {
    http_response_code(401);
    die('Authentication failed');
}

$school = $schoolmanager->get_school($schoolid);
if (!$school || $school->status !== 'active') {
    http_response_code(403);
    die('School not active');
}

// Get backup file.
$backupmanager = new \local_syncqueue\backup_manager();
$filepath = $backupmanager->get_backup_path($filename);

if (!$filepath || !file_exists($filepath)) {
    http_response_code(404);
    die('Backup file not found');
}

if (!is_readable($filepath)) {
    http_response_code(500);
    die('Backup file not readable');
}

// Serve the file.
$filesize = filesize($filepath);

// Clear any output buffers that Moodle might have started.
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Output file in chunks to handle large files.
$handle = fopen($filepath, 'rb');
if (!$handle) {
    http_response_code(500);
    die('Failed to open backup file');
}

while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);

exit;
