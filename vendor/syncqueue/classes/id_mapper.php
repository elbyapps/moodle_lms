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
 * ID mapper for tracking local-to-central ID relationships.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class id_mapper {

    /** @var string School ID */
    protected string $schoolid;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->schoolid = get_config('local_syncqueue', 'schoolid') ?: 'unknown';
    }

    /**
     * Get local ID for a central ID.
     *
     * @param string $tablename Table name.
     * @param int $centralid Central server ID.
     * @return int|null Local ID or null if not found.
     */
    public function get_local_id(string $tablename, int $centralid): ?int {
        global $DB;

        $record = $DB->get_record('local_syncqueue_idmap', [
            'schoolid' => $this->schoolid,
            'tablename' => $tablename,
            'centralid' => $centralid,
        ]);

        return $record ? (int) $record->localid : null;
    }

    /**
     * Get central ID for a local ID.
     *
     * @param string $tablename Table name.
     * @param int $localid Local ID.
     * @return int|null Central ID or null if not found.
     */
    public function get_central_id(string $tablename, int $localid): ?int {
        global $DB;

        $record = $DB->get_record('local_syncqueue_idmap', [
            'schoolid' => $this->schoolid,
            'tablename' => $tablename,
            'localid' => $localid,
        ]);

        return $record ? (int) $record->centralid : null;
    }

    /**
     * Set or update an ID mapping.
     *
     * @param string $tablename Table name.
     * @param int $localid Local ID.
     * @param int $centralid Central ID.
     * @param string|null $hash Optional hash for conflict detection.
     */
    public function set_mapping(string $tablename, int $localid, int $centralid, ?string $hash = null): void {
        global $DB;

        $now = time();

        $existing = $DB->get_record('local_syncqueue_idmap', [
            'schoolid' => $this->schoolid,
            'tablename' => $tablename,
            'localid' => $localid,
        ]);

        if ($existing) {
            $existing->centralid = $centralid;
            $existing->centralhash = $hash;
            $existing->timemodified = $now;
            $DB->update_record('local_syncqueue_idmap', $existing);
        } else {
            $record = new stdClass();
            $record->schoolid = $this->schoolid;
            $record->tablename = $tablename;
            $record->localid = $localid;
            $record->centralid = $centralid;
            $record->centralhash = $hash;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $DB->insert_record('local_syncqueue_idmap', $record);
        }
    }

    /**
     * Check if a record has been modified on central since last sync.
     *
     * @param string $tablename Table name.
     * @param int $localid Local ID.
     * @param string $currenthash Current hash from central.
     * @return bool True if modified.
     */
    public function is_modified(string $tablename, int $localid, string $currenthash): bool {
        global $DB;

        $record = $DB->get_record('local_syncqueue_idmap', [
            'schoolid' => $this->schoolid,
            'tablename' => $tablename,
            'localid' => $localid,
        ]);

        if (!$record || !$record->centralhash) {
            return true; // No previous hash, assume modified.
        }

        return $record->centralhash !== $currenthash;
    }

    /**
     * Get all mappings for a table.
     *
     * @param string $tablename Table name.
     * @return array Array of mapping records.
     */
    public function get_all_mappings(string $tablename): array {
        global $DB;

        return $DB->get_records('local_syncqueue_idmap', [
            'schoolid' => $this->schoolid,
            'tablename' => $tablename,
        ]);
    }

    /**
     * Delete a mapping.
     *
     * @param string $tablename Table name.
     * @param int $localid Local ID.
     */
    public function delete_mapping(string $tablename, int $localid): void {
        global $DB;

        $DB->delete_records('local_syncqueue_idmap', [
            'schoolid' => $this->schoolid,
            'tablename' => $tablename,
            'localid' => $localid,
        ]);
    }

    /**
     * Bulk import mappings.
     *
     * @param string $tablename Table name.
     * @param array $mappings Array of ['localid' => x, 'centralid' => y] pairs.
     */
    public function bulk_import(string $tablename, array $mappings): void {
        global $DB;

        $now = time();

        foreach ($mappings as $mapping) {
            $this->set_mapping($tablename, $mapping['localid'], $mapping['centralid']);
        }
    }
}
