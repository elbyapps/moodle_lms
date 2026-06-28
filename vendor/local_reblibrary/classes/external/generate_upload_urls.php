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
 * External API for generating S3 upload URLs.
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
 * External API for generating presigned upload URLs.
 */
class generate_upload_urls extends external_api {

    /**
     * Returns description of generate_upload_urls parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'pdf_hash' => new external_value(PARAM_ALPHANUM, 'SHA-256 hash of PDF file (64 hex characters)'),
        ]);
    }

    /**
     * Generate presigned upload URLs for PDF and cover image.
     *
     * Uses content-addressed storage for PDFs (by hash) and UUID for covers.
     * If PDF already exists, returns existing URL and skips upload.
     *
     * @param string $pdfhash SHA-256 hash of PDF file
     * @return array Upload URLs and metadata
     * @throws \moodle_exception If validation fails or S3 error occurs
     */
    public static function execute($pdfhash) {
        global $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'pdf_hash' => $pdfhash,
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

            // PDF path: content-addressed by hash.
            $pdfkey = 'resources/' . $params['pdf_hash'] . '/file.pdf';

            // Check if PDF already exists.
            $pdfexists = $s3->object_exists($pdfkey);

            // Cover path: unique per resource (UUID v4).
            $coveruuid = self::generate_uuid_v4();
            $coverkey = 'resources/' . $params['pdf_hash'] . '/covers/' . $coveruuid . '.jpg';

            // Expiration time for presigned URLs (1 hour).
            $expiration = 3600;

            $result = [
                'pdf_hash' => $params['pdf_hash'],
                'pdf_exists' => $pdfexists,
                'pdf_public_url' => (new \moodle_url('/local/reblibrary/download.php', ['key' => $pdfkey]))->out(false),
                'cover_upload_url' => $s3->get_presigned_put_url($coverkey, $expiration, 'image/jpeg'),
                'cover_public_url' => (new \moodle_url('/local/reblibrary/download.php', ['key' => $coverkey]))->out(false),
                'expires_in' => $expiration,
            ];

            // Only generate PDF upload URL if it doesn't exist.
            if (!$pdfexists) {
                $result['pdf_upload_url'] = $s3->get_presigned_put_url($pdfkey, $expiration, 'application/pdf');
            }

            return $result;

        } catch (\moodle_exception $e) {
            // Re-throw Moodle exceptions.
            throw $e;
        } catch (\Exception $e) {
            // Wrap other exceptions.
            throw new \moodle_exception('s3uploaderror', 'local_reblibrary', '', null, $e->getMessage());
        }
    }

    /**
     * Returns description of execute return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'pdf_hash' => new external_value(PARAM_ALPHANUM, 'SHA-256 hash of PDF'),
            'pdf_exists' => new external_value(PARAM_BOOL, 'Whether PDF already exists in storage'),
            'pdf_upload_url' => new external_value(PARAM_URL, 'Presigned PUT URL for PDF (null if exists)', VALUE_OPTIONAL),
            'pdf_public_url' => new external_value(PARAM_URL, 'Public GET URL for PDF'),
            'cover_upload_url' => new external_value(PARAM_URL, 'Presigned PUT URL for cover image'),
            'cover_public_url' => new external_value(PARAM_URL, 'Public GET URL for cover image'),
            'expires_in' => new external_value(PARAM_INT, 'Presigned URL expiration time in seconds'),
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
