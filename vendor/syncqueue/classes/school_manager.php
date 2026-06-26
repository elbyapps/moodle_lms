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

namespace local_syncqueue;

use stdClass;

/**
 * Manager for registered schools (Central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class school_manager {

    /** @var string Table name */
    protected const TABLE = 'local_syncqueue_schools';

    /**
     * Check if a school exists.
     *
     * @param string $schoolid School identifier.
     * @return bool
     */
    public function school_exists(string $schoolid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, ['schoolid' => $schoolid]);
    }

    /**
     * Get a school by ID.
     *
     * @param string $schoolid School identifier.
     * @return stdClass|null
     */
    public function get_school(string $schoolid): ?stdClass {
        global $DB;
        $school = $DB->get_record(self::TABLE, ['schoolid' => $schoolid]);
        return $school ?: null;
    }

    /**
     * Get all registered schools.
     *
     * @param string $status Optional status filter.
     * @return array
     */
    public function get_all_schools(string $status = ''): array {
        global $DB;

        $params = [];
        if (!empty($status)) {
            $params['status'] = $status;
        }

        return $DB->get_records(self::TABLE, $params, 'name ASC');
    }

    /**
     * Register a new school.
     *
     * @param string $schoolid School identifier.
     * @param string $name School name.
     * @param string $contactemail Contact email.
     * @param string $description Description.
     * @return string The generated API key (unhashed).
     */
    public function register_school(
        string $schoolid,
        string $name,
        string $contactemail = '',
        string $description = ''
    ): string {
        global $DB;

        // Generate API key.
        $apikey = $this->generate_apikey();
        $apikeyhash = $this->hash_apikey($apikey);

        $now = time();
        $school = new stdClass();
        $school->schoolid = $schoolid;
        $school->name = $name;
        $school->description = $description;
        $school->apikey = $apikeyhash;
        $school->status = 'active';
        $school->contactemail = $contactemail;
        $school->totalsynced = 0;
        $school->timecreated = $now;
        $school->timemodified = $now;

        $DB->insert_record(self::TABLE, $school);

        return $apikey; // Return unhashed key (only time it's available).
    }

    /**
     * Verify an API key for a school.
     *
     * @param string $schoolid School identifier.
     * @param string $apikey API key to verify.
     * @return bool
     */
    public function verify_apikey(string $schoolid, string $apikey): bool {
        global $DB;

        $school = $this->get_school($schoolid);
        if (!$school) {
            return false;
        }

        return $this->check_apikey($apikey, $school->apikey);
    }

    /**
     * Regenerate API key for a school.
     *
     * @param string $schoolid School identifier.
     * @return string New API key (unhashed).
     */
    public function regenerate_apikey(string $schoolid): string {
        global $DB;

        $apikey = $this->generate_apikey();
        $apikeyhash = $this->hash_apikey($apikey);

        $DB->set_field(self::TABLE, 'apikey', $apikeyhash, ['schoolid' => $schoolid]);
        $DB->set_field(self::TABLE, 'timemodified', time(), ['schoolid' => $schoolid]);

        return $apikey;
    }

    /**
     * Update school status.
     *
     * @param string $schoolid School identifier.
     * @param string $status New status.
     */
    public function update_status(string $schoolid, string $status): void {
        global $DB;

        $validstatuses = ['active', 'suspended', 'pending'];
        if (!in_array($status, $validstatuses)) {
            throw new \invalid_parameter_exception('Invalid status: ' . $status);
        }

        $DB->set_field(self::TABLE, 'status', $status, ['schoolid' => $schoolid]);
        $DB->set_field(self::TABLE, 'timemodified', time(), ['schoolid' => $schoolid]);
    }

    /**
     * Update sync statistics after a sync.
     *
     * @param string $schoolid School identifier.
     * @param int $itemcount Number of items synced.
     */
    public function update_sync_stats(string $schoolid, int $itemcount): void {
        global $DB;

        $school = $this->get_school($schoolid);
        if (!$school) {
            return;
        }

        $school->lastsynced = time();
        $school->lastsyncitems = $itemcount;
        $school->totalsynced = $school->totalsynced + $itemcount;
        $school->timemodified = time();

        $DB->update_record(self::TABLE, $school);
    }

    /**
     * Delete a school registration.
     *
     * @param string $schoolid School identifier.
     */
    public function delete_school(string $schoolid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['schoolid' => $schoolid]);
    }

    /**
     * Get schools that haven't synced recently.
     *
     * @param int $threshold Seconds since last sync.
     * @return array Schools that are overdue for sync.
     */
    public function get_overdue_schools(int $threshold = 86400): array {
        global $DB;

        $cutoff = time() - $threshold;

        return $DB->get_records_select(
            self::TABLE,
            'status = :status AND (lastsynced IS NULL OR lastsynced < :cutoff)',
            ['status' => 'active', 'cutoff' => $cutoff],
            'lastsynced ASC'
        );
    }

    /**
     * Get a school's trade/level priorities (drives download ordering).
     *
     * @param string $schoolid School identifier.
     * @return array Rows from local_syncqueue_school_trades.
     */
    public function get_school_trades(string $schoolid): array {
        global $DB;
        return $DB->get_records('local_syncqueue_school_trades', ['schoolid' => $schoolid],
            'weight ASC, tradecode ASC, level ASC');
    }

    /**
     * Auto-derive a school's trade/level priorities from the courses its
     * students are enrolled in (via elby_dashboard enrichment metadata).
     *
     * Replaces previously auto-derived rows; manual rows are preserved.
     *
     * @param string $schoolid School identifier (matches elby_schools.school_code).
     * @return int Number of auto rows written.
     */
    public function derive_school_trades(string $schoolid): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('elby_course_meta')) {
            return 0;
        }

        $sql = "SELECT DISTINCT cm.trade_code AS tradecode, cm.level
                  FROM {elby_sdms_users} su
                  JOIN {elby_schools} sch ON sch.id = su.schoolid AND sch.school_code = :schoolcode
                  JOIN {user_enrolments} ue ON ue.userid = su.userid
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {elby_course_meta} cm ON cm.courseid = e.courseid
                 WHERE su.user_type = 'student'
                   AND cm.trade_code IS NOT NULL AND cm.trade_code <> ''";
        $rows = $DB->get_records_sql($sql, ['schoolcode' => $schoolid]);

        // Replace only auto rows; keep manual ones.
        $DB->delete_records('local_syncqueue_school_trades', ['schoolid' => $schoolid, 'source' => 'auto']);

        $count = 0;
        $now = time();
        foreach ($rows as $r) {
            $level = ($r->level !== null && $r->level !== '') ? $r->level : null;
            // Skip if a manual row already covers this trade/level.
            $exists = $DB->record_exists_select('local_syncqueue_school_trades',
                'schoolid = :s AND tradecode = :t AND ' . ($level === null ? 'level IS NULL' : 'level = :l'),
                array_filter(['s' => $schoolid, 't' => $r->tradecode, 'l' => $level], function($v) {
                    return $v !== null;
                }));
            if ($exists) {
                continue;
            }
            $DB->insert_record('local_syncqueue_school_trades', (object) [
                'schoolid' => $schoolid,
                'tradecode' => $r->tradecode,
                'level' => $level,
                'weight' => 0,
                'source' => 'auto',
                'timemodified' => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Store a school's per-course pull preferences (F3).
     *
     * Replaces all of the school's existing preferences and sets its
     * "only deliver selected" filter flag.
     *
     * @param string $schoolid School identifier.
     * @param array $prefs List of ['courseid'=>int,'selected'=>bool,'weight'=>int].
     * @param bool $onlyselected Only deliver selected courses on download.
     * @return int Number of preference rows stored.
     */
    public function set_course_prefs(string $schoolid, array $prefs, bool $onlyselected): int {
        global $DB;

        $DB->delete_records('local_syncqueue_course_prefs', ['schoolid' => $schoolid]);

        $now = time();
        $count = 0;
        foreach ($prefs as $p) {
            $courseid = (int) ($p['courseid'] ?? 0);
            if ($courseid <= 0) {
                continue;
            }
            $DB->insert_record('local_syncqueue_course_prefs', (object) [
                'schoolid' => $schoolid,
                'courseid' => $courseid,
                'selected' => !empty($p['selected']) ? 1 : 0,
                'weight' => (int) ($p['weight'] ?? 0),
                'timemodified' => $now,
            ]);
            $count++;
        }

        $DB->set_field(self::TABLE, 'onlyselected', $onlyselected ? 1 : 0, ['schoolid' => $schoolid]);
        $DB->set_field(self::TABLE, 'timemodified', $now, ['schoolid' => $schoolid]);
        return $count;
    }

    /**
     * Generate a new API key.
     *
     * @return string 64 character hex string.
     */
    protected function generate_apikey(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash an API key for storage.
     *
     * @param string $apikey Plain API key.
     * @return string Hashed key.
     */
    protected function hash_apikey(string $apikey): string {
        return hash('sha256', $apikey);
    }

    /**
     * Check if an API key matches a hash.
     *
     * @param string $apikey Plain API key.
     * @param string $hash Stored hash.
     * @return bool
     */
    protected function check_apikey(string $apikey, string $hash): bool {
        return hash_equals($hash, $this->hash_apikey($apikey));
    }
}
