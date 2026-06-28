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
 * S3 client service for REB Library.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reblibrary;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/filelib.php');

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * S3 client wrapper class.
 *
 * Provides methods to interact with S3-compatible object storage.
 */
class s3_client {

    /** @var S3Client AWS S3 client instance for server operations */
    private $s3client;

    /** @var S3Client AWS S3 client instance for presigned URLs */
    private $publicclient;

    /** @var string S3 bucket name */
    private $bucket;

    /** @var string S3 endpoint URL (internal) */
    private $endpoint;

    /** @var string S3 public endpoint URL (browser-accessible) */
    private $publicendpoint;

    /** @var string Access key */
    private $accesskey;

    /** @var string Secret key */
    private $secretkey;

    /** @var string Region */
    private $region;

    /**
     * Constructor - initializes S3 client with configuration.
     *
     * @throws \moodle_exception If configuration is invalid
     */
    public function __construct() {
        global $CFG;

        // Get S3 configuration from plugin settings.
        $this->endpoint = get_config('local_reblibrary', 's3_endpoint');
        $this->publicendpoint = get_config('local_reblibrary', 's3_public_endpoint');
        $this->accesskey = get_config('local_reblibrary', 's3_access_key');
        $this->secretkey = get_config('local_reblibrary', 's3_secret_key');
        $this->bucket = get_config('local_reblibrary', 's3_bucket');
        $this->region = get_config('local_reblibrary', 's3_region') ?: 'us-east-1';

        // If public endpoint not set, use internal endpoint.
        if (empty($this->publicendpoint)) {
            $this->publicendpoint = $this->endpoint;
        }

        // Validate configuration.
        if (empty($this->endpoint) || empty($this->accesskey) || empty($this->secretkey) || empty($this->bucket)) {
            throw new \moodle_exception('s3configmissing', 'local_reblibrary');
        }

        // Initialize S3 client for server-side operations.
        try {
            $this->s3client = $this->create_client($this->endpoint);
        } catch (\Exception $e) {
            throw new \moodle_exception('s3connectionfailed', 'local_reblibrary', '', null, $e->getMessage());
        }
    }

    /**
     * Create an S3 client instance.
     *
     * @param string $endpoint Endpoint URL to use
     * @return S3Client
     */
    private function create_client($endpoint) {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true, // Required for S3-compatible services.
            'credentials' => [
                'key' => $this->accesskey,
                'secret' => $this->secretkey,
            ],
            // Disable SSL verification for development (localhost).
            'http' => [
                'verify' => strpos($endpoint, 'localhost') === false &&
                            strpos($endpoint, '127.0.0.1') === false,
            ],
        ]);
    }

    /**
     * Get or create the public S3 client for presigned URLs.
     *
     * @return S3Client
     */
    private function get_public_client() {
        if ($this->publicclient === null) {
            $this->publicclient = $this->create_client($this->publicendpoint);
        }
        return $this->publicclient;
    }

    /**
     * Check if an object exists in S3.
     *
     * @param string $key Object key/path
     * @return bool True if object exists
     */
    public function object_exists($key) {
        try {
            $this->s3client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (AwsException $e) {
            // 404 = object doesn't exist.
            if ($e->getStatusCode() === 404) {
                return false;
            }
            // Other errors should be logged.
            debugging('S3 headObject error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Generate a presigned PUT URL for uploading.
     *
     * @param string $key Object key/path
     * @param int $expiration Expiration time in seconds (default: 1 hour)
     * @param string $contenttype Content-Type header (optional)
     * @return string Presigned URL
     * @throws \moodle_exception If URL generation fails
     */
    public function get_presigned_put_url($key, $expiration = 3600, $contenttype = null) {
        try {
            // Use public client for presigned URLs so signature matches public endpoint.
            $client = $this->get_public_client();

            $cmd = $client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ContentType' => $contenttype,
            ]);

            $request = $client->createPresignedRequest($cmd, "+{$expiration} seconds");
            return (string) $request->getUri();
        } catch (AwsException $e) {
            throw new \moodle_exception('s3presignedfailed', 'local_reblibrary', '', null, $e->getMessage());
        }
    }

    /**
     * Generate a public GET URL for accessing an object.
     *
     * Note: This assumes the object is publicly readable.
     * Use set_object_public() to make objects public.
     *
     * @param string $key Object key/path
     * @return string Public URL
     */
    public function get_public_url($key) {
        return $this->publicendpoint . '/' . $this->bucket . '/' . $key;
    }

    /**
     * Generate a presigned GET URL for downloading (temporary access).
     *
     * @param string $key Object key/path
     * @param int $expiration Expiration time in seconds (default: 1 hour)
     * @return string Presigned URL
     * @throws \moodle_exception If URL generation fails
     */
    public function get_presigned_get_url($key, $expiration = 3600) {
        try {
            // Use public client for presigned URLs so signature matches public endpoint.
            $client = $this->get_public_client();

            $cmd = $client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $request = $client->createPresignedRequest($cmd, "+{$expiration} seconds");
            return (string) $request->getUri();
        } catch (AwsException $e) {
            throw new \moodle_exception('s3presignedfailed', 'local_reblibrary', '', null, $e->getMessage());
        }
    }

    /**
     * Set object ACL to public-read.
     *
     * @param string $key Object key/path
     * @return bool True on success
     * @throws \moodle_exception If ACL update fails
     */
    public function set_object_public($key) {
        try {
            $this->s3client->putObjectAcl([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ACL' => 'public-read',
            ]);
            return true;
        } catch (AwsException $e) {
            throw new \moodle_exception('s3aclfailed', 'local_reblibrary', '', null, $e->getMessage());
        }
    }

    /**
     * Delete an object from S3.
     *
     * @param string $key Object key/path
     * @return bool True on success
     * @throws \moodle_exception If deletion fails
     */
    public function delete_object($key) {
        try {
            $this->s3client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (AwsException $e) {
            throw new \moodle_exception('s3deletefailed', 'local_reblibrary', '', null, $e->getMessage());
        }
    }

    /**
     * Test S3 connection.
     *
     * @return array Result with 'success' boolean and optional 'message'
     */
    public function test_connection() {
        try {
            // Try to list objects (should work even if bucket is empty).
            $this->s3client->listObjects([
                'Bucket' => $this->bucket,
                'MaxKeys' => 1,
            ]);

            return [
                'success' => true,
                'message' => get_string('s3connectionok', 'local_reblibrary'),
            ];
        } catch (AwsException $e) {
            return [
                'success' => false,
                'message' => get_string('s3connectionfailed', 'local_reblibrary') . ': ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get bucket name.
     *
     * @return string Bucket name
     */
    public function get_bucket() {
        return $this->bucket;
    }

    /**
     * Get endpoint URL.
     *
     * @return string Endpoint URL
     */
    public function get_endpoint() {
        return $this->endpoint;
    }
}
