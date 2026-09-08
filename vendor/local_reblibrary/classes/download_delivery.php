<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is distributed under the GNU General Public License v3 or later.

namespace local_reblibrary;

defined('MOODLE_INTERNAL') || die();

/**
 * Download delivery policy, shared by the controller and regression tests.
 *
 * @package local_reblibrary
 * @copyright 2026 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class download_delivery {
    /**
     * Accept only library keys, without path traversal or header injection.
     * @param string $key Decoded object key
     * @return bool
     */
    public static function valid_key(string $key): bool {
        if (!str_starts_with($key, 'resources/') || strlen($key) <= strlen('resources/')) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $key)) {
            return false;
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Never send a bearer URL over plaintext or accept header injection.
     * Reachability and CORS require deployment preflight, not request-time probes.
     * @param string $url Signed storage URL
     * @return bool
     */
    public static function valid_url(string $url): bool {
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) &&
            !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    /**
     * Signed response overrides. Never make authorised content publicly cacheable.
     * @param string $key Validated object key
     * @return array
     */
    public static function response_headers(string $key): array {
        $filename = basename($key);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        // Keep active content (HTML, SVG, etc.) as an attachment on storage.
        $inline = in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif',
            'mp4', 'webm', 'ogv', 'mov'], true);
        return [
            'ResponseCacheControl' => 'private, max-age=600',
            'ResponseContentDisposition' => ($inline ? 'inline' : 'attachment') .
                "; filename*=UTF-8''" . rawurlencode($filename),
        ];
    }
}
