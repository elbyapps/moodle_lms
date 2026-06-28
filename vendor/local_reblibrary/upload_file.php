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
 * File upload endpoint for REB Library resources.
 *
 * Accepts multipart file uploads (PDF/video + optional cover image) and stores them in S3.
 * This avoids the base64-over-AJAX approach which cannot handle large files.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/reblibrary:manageresources', $context);

header('Content-Type: application/json; charset=utf-8');

// Allowed MIME types per media type.
$allowedmimetypes = [
    'text' => ['application/pdf' => 'pdf'],
    'video' => ['video/mp4' => 'mp4', 'video/webm' => 'webm'],
];

// Max file size per media type.
$maxfilesizes = [
    'text' => 200 * 1024 * 1024,   // 200MB
    'video' => 500 * 1024 * 1024,   // 500MB
];

try {
    // Validate request method.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('invalidrequest', 'error', '', null, 'POST method required');
    }

    // Get media type (default to 'text' for backward compatibility).
    $mediatype = optional_param('media_type', 'text', PARAM_ALPHA);
    if (!isset($allowedmimetypes[$mediatype])) {
        throw new invalid_parameter_exception('Invalid media type. Allowed: ' . implode(', ', array_keys($allowedmimetypes)));
    }

    // Get and validate file hash (accept 'file_hash' or 'pdf_hash' for backward compat).
    $filehash = optional_param('file_hash', '', PARAM_ALPHANUM);
    if (empty($filehash)) {
        $filehash = required_param('pdf_hash', PARAM_ALPHANUM);
    }
    if (!preg_match('/^[a-f0-9]{64}$/i', $filehash)) {
        throw new invalid_parameter_exception('Invalid file hash format. Expected SHA-256 (64 hex characters).');
    }

    // Validate uploaded file (accept 'file' or 'pdf' for backward compat).
    $filefield = !empty($_FILES['file']) ? 'file' : 'pdf';
    if (empty($_FILES[$filefield]) || $_FILES[$filefield]['error'] !== UPLOAD_ERR_OK) {
        $errorcode = $_FILES[$filefield]['error'] ?? -1;
        $errormsg = match ($errorcode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            default => 'File upload error (code: ' . $errorcode . ')',
        };
        throw new moodle_exception('uploadfailed', 'local_reblibrary', '', null, $errormsg);
    }

    // Cover image is required for text/PDF, optional for video.
    $hascover = !empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK;
    if ($mediatype === 'text' && !$hascover) {
        throw new moodle_exception('uploadfailed', 'local_reblibrary', '', null, 'Cover image upload failed');
    }

    $filetmppath = $_FILES[$filefield]['tmp_name'];
    $filesize = $_FILES[$filefield]['size'];
    $filemimetype = $_FILES[$filefield]['type'];

    // Validate MIME type for the given media type.
    if (!isset($allowedmimetypes[$mediatype][$filemimetype])) {
        $allowed = implode(', ', array_keys($allowedmimetypes[$mediatype]));
        throw new invalid_parameter_exception("Invalid file type '{$filemimetype}'. Allowed for {$mediatype}: {$allowed}");
    }
    $fileextension = $allowedmimetypes[$mediatype][$filemimetype];

    // Validate file size.
    $maxsize = $maxfilesizes[$mediatype];
    if ($filesize > $maxsize) {
        $maxmb = $maxsize / (1024 * 1024);
        throw new invalid_parameter_exception("File exceeds {$maxmb}MB limit");
    }

    if ($hascover) {
        $covertmppath = $_FILES['cover']['tmp_name'];
        $coversize = $_FILES['cover']['size'];
        if ($coversize > 10 * 1024 * 1024) {
            throw new invalid_parameter_exception('Cover image exceeds 10MB limit');
        }
    }

    // Verify file hash matches uploaded file content.
    $computedhash = hash_file('sha256', $filetmppath);
    if ($computedhash !== strtolower($filehash)) {
        throw new invalid_parameter_exception('File hash mismatch. Computed: ' . $computedhash);
    }

    // Initialize S3 client.
    $s3 = new local_reblibrary\s3_client();

    // Use reflection to access the underlying AWS S3 client.
    $reflection = new ReflectionClass($s3);
    $property = $reflection->getProperty('s3client');
    $property->setAccessible(true);
    $awsclient = $property->getValue($s3);

    // File path: content-addressed by hash.
    $filekey = 'resources/' . $filehash . '/file.' . $fileextension;

    // Check if file already exists (deduplication).
    $fileexists = $s3->object_exists($filekey);

    // Upload file only if it doesn't exist.
    if (!$fileexists) {
        $awsclient->putObject([
            'Bucket' => $s3->get_bucket(),
            'Key' => $filekey,
            'SourceFile' => $filetmppath,
            'ContentType' => $filemimetype,
            'ACL' => 'public-read',
        ]);
    }

    // Handle cover image upload.
    $coverurl = '';
    $coverkey = '';
    $coversize = 0;
    if ($hascover) {
        // Cover path: unique per upload (UUID v4).
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $coveruuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        $covermimetype = $_FILES['cover']['type'];
        $coverext = (strpos($covermimetype, 'png') !== false) ? 'png' : 'jpg';
        $coverkey = 'resources/' . $filehash . '/covers/' . $coveruuid . '.' . $coverext;
        $coversize = $_FILES['cover']['size'];

        $awsclient->putObject([
            'Bucket' => $s3->get_bucket(),
            'Key' => $coverkey,
            'SourceFile' => $_FILES['cover']['tmp_name'],
            'ContentType' => $covermimetype,
            'ACL' => 'public-read',
        ]);

        $coverurl = $CFG->wwwroot . '/local/reblibrary/download.php?key=' . urlencode($coverkey);
    }

    // Generate proxy URLs.
    $fileurl = $CFG->wwwroot . '/local/reblibrary/download.php?key=' . urlencode($filekey);

    echo json_encode([
        'success' => true,
        'file_hash' => $filehash,
        'file_exists' => $fileexists,
        'file_key' => $filekey,
        'file_url' => $fileurl,
        'cover_key' => $coverkey,
        'cover_url' => $coverurl,
        'file_size' => $filesize,
        'cover_size' => $coversize,
        // Backward compatibility aliases.
        'pdf_hash' => $filehash,
        'pdf_exists' => $fileexists,
        'pdf_key' => $filekey,
        'pdf_url' => $fileurl,
        'pdf_size' => $filesize,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
