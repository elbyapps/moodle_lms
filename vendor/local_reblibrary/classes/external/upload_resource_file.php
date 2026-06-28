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
 * External API for uploading resource files to S3.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reblibrary\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_system;
use local_reblibrary\s3_client;

/**
 * External API for uploading resource files through backend.
 */
class upload_resource_file extends external_api {

    /**
     * Returns description of upload_resource_file parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'pdf_hash' => new external_value(PARAM_ALPHANUM, 'SHA-256 hash of PDF file (64 hex characters)'),
            'pdf_content' => new external_value(PARAM_RAW, 'Base64-encoded PDF file content'),
            'cover_content' => new external_value(PARAM_RAW, 'Base64-encoded cover image (JPEG) content'),
        ]);
    }

    /**
     * Upload PDF and cover image to S3 through backend.
     *
     * Accepts base64-encoded file content, decodes it, and uploads to S3.
     * Uses content-addressed storage for PDFs (by hash).
     *
     * @param string $pdfhash SHA-256 hash of PDF file
     * @param string $pdfcontent Base64-encoded PDF content
     * @param string $covercontent Base64-encoded cover image content
     * @return array Upload result with internal file keys
     * @throws \moodle_exception If validation fails or upload error occurs
     */
    public static function execute($pdfhash, $pdfcontent, $covercontent) {
        global $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'pdf_hash' => $pdfhash,
            'pdf_content' => $pdfcontent,
            'cover_content' => $covercontent,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        // Validate hash format (SHA-256 = 64 hex characters).
        if (!preg_match('/^[a-f0-9]{64}$/i', $params['pdf_hash'])) {
            throw new \invalid_parameter_exception('Invalid PDF hash format. Expected SHA-256 (64 hex characters).');
        }

        try {
            $s3 = new s3_client();

            // Decode base64 content.
            $pdfdata = base64_decode($params['pdf_content'], true);
            if ($pdfdata === false) {
                throw new \invalid_parameter_exception('Invalid base64-encoded PDF content');
            }

            $coverdata = base64_decode($params['cover_content'], true);
            if ($coverdata === false) {
                throw new \invalid_parameter_exception('Invalid base64-encoded cover content');
            }

            // Validate file sizes.
            $pdfsize = strlen($pdfdata);
            $coversize = strlen($coverdata);

            if ($pdfsize > 200 * 1024 * 1024) { // 200MB limit.
                throw new \invalid_parameter_exception('PDF file exceeds 200MB limit');
            }

            if ($coversize > 10 * 1024 * 1024) { // 10MB limit for cover.
                throw new \invalid_parameter_exception('Cover image exceeds 10MB limit');
            }

            // Verify PDF hash matches content.
            $computedhash = hash('sha256', $pdfdata);
            if ($computedhash !== strtolower($params['pdf_hash'])) {
                throw new \invalid_parameter_exception('PDF hash mismatch. Computed: ' . $computedhash);
            }

            // PDF path: content-addressed by hash.
            $pdfkey = 'resources/' . $params['pdf_hash'] . '/file.pdf';

            // Check if PDF already exists.
            $pdfexists = $s3->object_exists($pdfkey);

            // Upload PDF only if it doesn't exist (deduplication).
            if (!$pdfexists) {
                self::upload_to_minio($s3, $pdfkey, $pdfdata, 'application/pdf');
            }

            // Cover path: unique per upload (UUID v4).
            $coveruuid = self::generate_uuid_v4();
            $coverkey = 'resources/' . $params['pdf_hash'] . '/covers/' . $coveruuid . '.jpg';

            // Upload cover image.
            self::upload_to_minio($s3, $coverkey, $coverdata, 'image/jpeg');

            // Generate proxy URLs (internal references).
            // Format: /local/reblibrary/download.php?key={key}
            $wwwroot = $CFG->wwwroot;
            $pdfurl = $wwwroot . '/local/reblibrary/download.php?key=' . urlencode($pdfkey);
            $coverurl = $wwwroot . '/local/reblibrary/download.php?key=' . urlencode($coverkey);

            return [
                'success' => true,
                'pdf_hash' => $params['pdf_hash'],
                'pdf_exists' => $pdfexists,
                'pdf_key' => $pdfkey,
                'pdf_url' => $pdfurl,
                'cover_key' => $coverkey,
                'cover_url' => $coverurl,
                'pdf_size' => $pdfsize,
                'cover_size' => $coversize,
            ];

        } catch (\moodle_exception $e) {
            // Re-throw Moodle exceptions.
            throw $e;
        } catch (\Exception $e) {
            // Wrap other exceptions.
            throw new \moodle_exception('uploadfailed', 'local_reblibrary', '', null, $e->getMessage());
        }
    }

    /**
     * Upload file data to S3.
     *
     * @param s3_client $s3 S3 client instance
     * @param string $key Object key/path
     * @param string $data File content (binary)
     * @param string $contenttype MIME type
     * @return void
     * @throws \Exception If upload fails
     */
    private static function upload_to_minio($s3, $key, $data, $contenttype) {
        // Write to temporary file.
        $tempfile = tempnam(sys_get_temp_dir(), 's3_upload_');
        if ($tempfile === false) {
            throw new \Exception('Failed to create temporary file');
        }

        try {
            // Write data to temp file.
            $written = file_put_contents($tempfile, $data);
            if ($written === false) {
                throw new \Exception('Failed to write to temporary file');
            }

            // Upload to S3 using AWS SDK.
            $client = self::get_s3_client($s3);
            $result = $client->putObject([
                'Bucket' => $s3->get_bucket(),
                'Key' => $key,
                'SourceFile' => $tempfile,
                'ContentType' => $contenttype,
                'ACL' => 'public-read', // Make publicly readable.
            ]);

            // Verify upload succeeded.
            if (!isset($result['ObjectURL'])) {
                throw new \Exception('S3 upload returned no ObjectURL');
            }

        } finally {
            // Clean up temp file.
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
        }
    }

    /**
     * Get S3 client from S3 client instance.
     *
     * @param s3_client $s3 S3 client instance
     * @return \Aws\S3\S3Client
     */
    private static function get_s3_client($s3) {
        // Use reflection to access private s3client property.
        $reflection = new \ReflectionClass($s3);
        $property = $reflection->getProperty('s3client');
        $property->setAccessible(true);
        return $property->getValue($s3);
    }

    /**
     * Returns description of execute return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Upload success status'),
            'pdf_hash' => new external_value(PARAM_ALPHANUM, 'SHA-256 hash of PDF'),
            'pdf_exists' => new external_value(PARAM_BOOL, 'Whether PDF already existed in storage'),
            'pdf_key' => new external_value(PARAM_TEXT, 'S3 object key for PDF'),
            'pdf_url' => new external_value(PARAM_URL, 'Proxy URL for accessing PDF'),
            'cover_key' => new external_value(PARAM_TEXT, 'S3 object key for cover image'),
            'cover_url' => new external_value(PARAM_URL, 'Proxy URL for accessing cover image'),
            'pdf_size' => new external_value(PARAM_INT, 'PDF file size in bytes'),
            'cover_size' => new external_value(PARAM_INT, 'Cover image size in bytes'),
        ]);
    }

    /**
     * Generate UUID v4.
     *
     * @return string UUID v4 string (e.g., "550e8400-e29b-41d4-a716-446655440000")
     */
    private static function generate_uuid_v4() {
        // Generate 16 random bytes.
        $data = random_bytes(16);

        // Set version (4) and variant (RFC 4122).
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4.
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122.

        // Format as UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
