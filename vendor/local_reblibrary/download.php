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
 * File download proxy for REB Library resources.
 *
 * Streams files from S3 storage to browser with proper headers.
 * Supports HTTP range requests for PDF streaming.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use local_reblibrary\s3_client;

// Require login and check capability.
require_login();

$context = context_system::instance();
require_capability('local/reblibrary:view', $context);

// Get file key parameter.
$key = required_param('key', PARAM_TEXT);

// Validate key format (must start with 'resources/').
if (strpos($key, 'resources/') !== 0) {
    throw new moodle_exception('invalidfilekey', 'local_reblibrary');
}

try {
    $s3 = new s3_client();

    // Get S3 client using reflection.
    $reflection = new ReflectionClass($s3);
    $property = $reflection->getProperty('s3client');
    $property->setAccessible(true);
    $s3client = $property->getValue($s3);

    // Get object metadata.
    try {
        $result = $s3client->headObject([
            'Bucket' => $s3->get_bucket(),
            'Key' => $key,
        ]);
    } catch (Aws\S3\Exception\S3Exception $e) {
        if ($e->getStatusCode() === 404) {
            throw new moodle_exception('filenotfound', 'local_reblibrary');
        }
        throw $e;
    }

    $contentlength = $result['ContentLength'];
    $contenttype = $result['ContentType'];
    $etag = $result['ETag'];
    $filename = basename($key);

    // Check for Range header.
    $rangeheader = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;
    $rangestart = 0;
    $rangeend = $contentlength - 1;
    $ispartial = false;

    if ($rangeheader) {
        if (preg_match('/bytes=(\d+)-(\d*)/', $rangeheader, $matches)) {
            $rangestart = intval($matches[1]);
            $rangeend = !empty($matches[2]) ? intval($matches[2]) : $contentlength - 1;
            $ispartial = true;

            if ($rangestart > $rangeend || $rangestart >= $contentlength) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $contentlength);
                exit;
            }
        }
    }

    if ($ispartial) {
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $rangestart . '-' . $rangeend . '/' . $contentlength);
        $outputlength = $rangeend - $rangestart + 1;
    } else {
        header('HTTP/1.1 200 OK');
        $outputlength = $contentlength;
    }

    header('Content-Type: ' . $contenttype);
    header('Content-Length: ' . $outputlength);
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=31536000');

    if ($contenttype === 'application/pdf' || strpos($contenttype, 'image/') === 0 || strpos($contenttype, 'video/') === 0) {
        header('Content-Disposition: inline; filename="' . $filename . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }

    $getoptions = [
        'Bucket' => $s3->get_bucket(),
        'Key' => $key,
    ];

    if ($ispartial) {
        $getoptions['Range'] = 'bytes=' . $rangestart . '-' . $rangeend;
    }

    $object = $s3client->getObject($getoptions);
    $body = $object['Body'];

    if ($body->isSeekable()) {
        while (!$body->eof()) {
            echo $body->read(8192);
            flush();
        }
    } else {
        echo $body->getContents();
    }

    exit;

} catch (Exception $e) {
    debugging('S3 download error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    throw new moodle_exception('downloaderror', 'local_reblibrary');
}
