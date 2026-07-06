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

namespace local_syncqueue\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_syncqueue\school_manager;

/**
 * Content-addressed submission-blob receive endpoint (ELMS Sync v2 step 7, doc §9.1).
 *
 * A school's ship_files task uploads each pending submission blob here by its Moodle
 * contenthash (sha1). Central verifies the bytes hash to the claimed contenthash and
 * stores them ONCE in a content-addressed system filearea, then confirms receipt — so
 * the school can mark the blob synced and (later) prune its local copy knowing the
 * evidence is held centrally. Idempotent + deduplicated: a contenthash central already
 * holds confirms immediately without re-storing (many learners submit identical files;
 * a re-run after a lost ack must not double-store).
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_file extends external_api {

    /** @var string Content-addressed filearea on central. */
    const FILEAREA = 'receivedfiles';

    /** @var int Reject a single blob larger than this (chunked transfer is a follow-up). */
    const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'schoolid' => new external_value(PARAM_ALPHANUMEXT, 'School identifier'),
            'apikey' => new external_value(PARAM_RAW, 'API key'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'Moodle file contenthash (sha1)'),
            'filename' => new external_value(PARAM_FILE, 'Original file name (for reference only)'),
            'content' => new external_value(PARAM_RAW, 'Base64-encoded file bytes'),
        ]);
    }

    /**
     * Receive one content-addressed blob.
     *
     * @param string $schoolid
     * @param string $apikey
     * @param string $contenthash
     * @param string $filename
     * @param string $content Base64 bytes.
     * @return array{received:bool, dedup:bool, contenthash:string, error:string}
     */
    public static function execute(string $schoolid, string $apikey, string $contenthash,
            string $filename, string $content): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'schoolid' => $schoolid, 'apikey' => $apikey, 'contenthash' => $contenthash,
            'filename' => $filename, 'content' => $content,
        ]);

        if (get_config('local_syncqueue', 'mode') !== 'central') {
            throw new \moodle_exception('error_notcentral', 'local_syncqueue');
        }
        if (!get_config('local_syncqueue', 'enabled')) {
            throw new \moodle_exception('error_disabled', 'local_syncqueue');
        }
        $schoolmanager = new school_manager();
        if (!$schoolmanager->verify_apikey($params['schoolid'], $params['apikey'])) {
            throw new \moodle_exception('error_authfailed', 'local_syncqueue');
        }

        $hash = strtolower($params['contenthash']);
        $syscontext = \context_system::instance();
        $fs = get_file_storage();

        // Already held (dedup / idempotent re-run after a lost ack): confirm, don't re-store.
        if ($fs->get_file($syscontext->id, 'local_syncqueue', self::FILEAREA, 0, '/', $hash)) {
            return ['received' => true, 'dedup' => true, 'contenthash' => $hash, 'error' => ''];
        }

        // Bound the ENCODED body before decoding (base64 is ~4/3 of the raw size): decoding
        // an oversize payload first would let an authenticated school OOM the PHP worker.
        if (strlen($params['content']) > (int) (self::MAX_BYTES * 4 / 3) + 1024) {
            return ['received' => false, 'dedup' => false, 'contenthash' => $hash,
                'error' => 'blob exceeds ' . self::MAX_BYTES . ' bytes (chunked transfer not yet supported)'];
        }
        $bytes = base64_decode($params['content'], true);
        if ($bytes === false) {
            return ['received' => false, 'dedup' => false, 'contenthash' => $hash,
                'error' => 'content is not valid base64'];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['received' => false, 'dedup' => false, 'contenthash' => $hash,
                'error' => 'blob exceeds ' . self::MAX_BYTES . ' bytes (chunked transfer not yet supported)'];
        }
        // Content-addressing is the integrity guarantee: the bytes MUST hash to the
        // claimed contenthash (Moodle contenthash is sha1), or we reject.
        if (sha1($bytes) !== $hash) {
            return ['received' => false, 'dedup' => false, 'contenthash' => $hash,
                'error' => 'bytes do not match the claimed contenthash'];
        }

        try {
            $fs->create_file_from_string([
                'contextid' => $syscontext->id, 'component' => 'local_syncqueue', 'filearea' => self::FILEAREA,
                'itemid' => 0, 'filepath' => '/', 'filename' => $hash,
            ], $bytes);
        } catch (\Throwable $e) {
            // A concurrent uploader of the same hash won the create race: that is a
            // successful outcome (the blob is now held), not an error.
            if (!$fs->get_file($syscontext->id, 'local_syncqueue', self::FILEAREA, 0, '/', $hash)) {
                return ['received' => false, 'dedup' => false, 'contenthash' => $hash,
                    'error' => 'store failed: ' . $e->getMessage()];
            }
        }
        return ['received' => true, 'dedup' => false, 'contenthash' => $hash, 'error' => ''];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'received' => new external_value(PARAM_BOOL, 'Whether the blob is now held centrally'),
            'dedup' => new external_value(PARAM_BOOL, 'True when central already held this contenthash'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'The stored contenthash'),
            'error' => new external_value(PARAM_TEXT, 'Failure detail when received is false'),
        ]);
    }
}
