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
 * Shared IP-based rate limiter for public (no-login) plugin endpoints.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * IP-based rate limiting using the Moodle cache API.
 *
 * Distinct actions ('signup', 'rise_action', 'rise_setpassword', ...) count
 * independently; the window is the cache definition's TTL (5 minutes).
 */
class rate_limiter {

    /**
     * Count one attempt for this IP + action and throw once the limit is exceeded.
     *
     * @param string $action Short action key, e.g. 'rise_action'.
     * @param int $limit Maximum attempts per IP within the cache TTL window.
     * @throws \moodle_exception When the rate limit is exceeded.
     */
    public static function check(string $action, int $limit = 10): void {
        $cache = \cache::make('local_elby_dashboard', 'signup_ratelimit');
        $key = $action . '_' . md5(getremoteaddr());

        $attempts = $cache->get($key);
        if ($attempts === false) {
            $attempts = 0;
        }

        if ($attempts >= $limit) {
            throw new \moodle_exception('sdms_rate_limited', 'local_elby_dashboard');
        }

        $cache->set($key, $attempts + 1);
    }
}
