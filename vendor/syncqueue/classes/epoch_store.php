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
 * Epoch registry accessor (ELMS Sync v2 §4.5).
 *
 * An epoch is a UUID identifying one database incarnation. It survives a config
 * restore (which would restore an old epoch) because it is mirrored in a
 * dataroot marker file: a DB restored without its matching dataroot shows a
 * marker/DB mismatch, which the detection layer (built in the upstream-protocol
 * phase) treats as a re-incarnation trigger.
 *
 * This accessor only stores and reads epoch state. It deliberately does NOT
 * decide to freeze or re-incarnate — that policy lives with the push/pull
 * protocol so the storage primitives stay reusable and side-effect free.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class epoch_store {

    /** @var string Registry scopes. */
    public const SCOPE_SELF = 'self';
    public const SCOPE_CENTRAL = 'central';
    public const SCOPE_SCHOOL = 'school';

    /** @var string Marker file basename in dataroot. */
    protected const MARKER_FILE = 'syncqueue_epoch.json';

    /**
     * Get this instance's own epoch, creating it (DB row + dataroot marker) on
     * first use. Does not act on a marker mismatch — see marker_status().
     *
     * @return stdClass The scope='self' epoch row.
     */
    public static function ensure_self(): stdClass {
        global $DB;

        $row = self::get(self::SCOPE_SELF);
        if ($row) {
            // Heal a missing marker (e.g. first run after upgrade) without
            // treating it as a re-incarnation; genuine mismatches are reported
            // by marker_status() for the detection layer to handle.
            if (self::read_marker() === null) {
                self::write_marker($row->epoch, (int) $row->bootcount);
            }
            return $row;
        }

        $now = time();
        $epoch = \core\uuid::generate();
        $row = new stdClass();
        $row->scope = self::SCOPE_SELF;
        $row->schoolid = '';
        $row->epoch = $epoch;
        $row->headseq = 0;
        $row->bootcount = 1;
        $row->status = 'active';
        $row->timecreated = $now;
        $row->timemodified = $now;
        $row->id = $DB->insert_record('local_syncqueue_epoch', $row);

        self::write_marker($epoch, 1);

        return $row;
    }

    /**
     * Compare the stored self epoch against the dataroot marker.
     *
     * @return string 'ok' (match), 'missing' (no marker yet), 'uninitialised'
     *         (no self row yet), or 'mismatch' (marker epoch != DB epoch — a DB
     *         restore/clone signal).
     */
    public static function marker_status(): string {
        $self = self::get(self::SCOPE_SELF);
        if (!$self) {
            return 'uninitialised';
        }
        $marker = self::read_marker();
        if ($marker === null) {
            return 'missing';
        }
        return (($marker['epoch'] ?? null) === $self->epoch) ? 'ok' : 'mismatch';
    }

    /**
     * Fetch a registry row.
     *
     * @param string $scope One of the SCOPE_* constants.
     * @param string $schoolid Peer school id for SCOPE_SCHOOL; '' otherwise.
     * @return stdClass|null
     */
    public static function get(string $scope, string $schoolid = ''): ?stdClass {
        global $DB;
        $row = $DB->get_record('local_syncqueue_epoch', ['scope' => $scope, 'schoolid' => $schoolid]);
        return $row ?: null;
    }

    /**
     * Upsert a registry row's epoch and (monotonically) its head-seq high-water.
     *
     * headseq only ever rises here; a lower value is ignored so an out-of-order
     * update cannot lower a high-water. A changed epoch resets the stored row's
     * epoch and headseq to the supplied values (a new incarnation starts fresh).
     *
     * @param string $scope One of the SCOPE_* constants.
     * @param string $schoolid Peer school id for SCOPE_SCHOOL; '' otherwise.
     * @param string $epoch Epoch UUID.
     * @param int $headseq Observed head seq / high-water.
     * @return stdClass The stored row.
     */
    public static function observe(string $scope, string $schoolid, string $epoch, int $headseq): stdClass {
        global $DB;

        $now = time();
        $row = self::get($scope, $schoolid);
        if (!$row) {
            $row = new stdClass();
            $row->scope = $scope;
            $row->schoolid = $schoolid;
            $row->epoch = $epoch;
            $row->headseq = $headseq;
            $row->bootcount = 0;
            $row->status = 'active';
            $row->timecreated = $now;
            $row->timemodified = $now;
            $row->id = $DB->insert_record('local_syncqueue_epoch', $row);
            return $row;
        }

        $changed = false;
        if ($row->epoch !== $epoch) {
            // New incarnation of the peer: adopt its epoch and reset the high-water.
            $row->epoch = $epoch;
            $row->headseq = $headseq;
            $changed = true;
        } else if ($headseq > (int) $row->headseq) {
            $row->headseq = $headseq;
            $changed = true;
        }
        if ($changed) {
            $row->timemodified = $now;
            $DB->update_record('local_syncqueue_epoch', $row);
        }
        return $row;
    }

    /**
     * Absolute path of the dataroot epoch marker.
     *
     * @return string
     */
    public static function marker_path(): string {
        global $CFG;
        return $CFG->dataroot . '/' . self::MARKER_FILE;
    }

    /**
     * Read and decode the dataroot marker, or null if absent/unreadable.
     *
     * @return array|null ['epoch'=>string, 'bootcount'=>int, 'written'=>int]
     */
    public static function read_marker(): ?array {
        $path = self::marker_path();
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['epoch']) ? $data : null;
    }

    /**
     * Write the dataroot marker atomically (temp file + rename).
     *
     * @param string $epoch Epoch UUID.
     * @param int $bootcount Boot counter.
     */
    public static function write_marker(string $epoch, int $bootcount): void {
        $path = self::marker_path();
        $data = json_encode([
            'epoch' => $epoch,
            'bootcount' => $bootcount,
            'written' => time(),
        ], JSON_PRETTY_PRINT);

        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $data) !== false) {
            @rename($tmp, $path);
        }
    }
}
