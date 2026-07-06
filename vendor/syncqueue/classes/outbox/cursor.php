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

namespace local_syncqueue\outbox;

/**
 * Per-peer per-direction stream cursors (ELMS Sync v2 step 1).
 *
 * Cursors are advanced strictly after durable apply (apply-then-checkpoint)
 * and never move backward.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cursor {

    /**
     * Last applied sequence number for a peer/direction stream.
     *
     * @param string $peer 'central' on schools; schoolid on central.
     * @param string $direction 'down' or 'up'.
     * @return int 0 when the cursor does not exist yet.
     */
    public static function get(string $peer, string $direction): int {
        global $DB;
        return (int)$DB->get_field('local_syncqueue_cursor', 'lastappliedseq',
            ['peer' => $peer, 'direction' => $direction]);
    }

    /**
     * Advance a cursor to $seq. Monotonic: a lower value is silently ignored.
     * Creates the cursor row on first use.
     *
     * @param string $peer
     * @param string $direction
     * @param int $seq
     */
    public static function advance(string $peer, string $direction, int $seq): void {
        global $DB;

        if (!$DB->record_exists('local_syncqueue_cursor', ['peer' => $peer, 'direction' => $direction])) {
            $record = new \stdClass();
            $record->peer = $peer;
            $record->direction = $direction;
            $record->epoch = null;
            $record->lastappliedseq = max(0, $seq);
            $record->timemodified = time();
            try {
                $DB->insert_record('local_syncqueue_cursor', $record);
                return;
            } catch (\dml_write_exception $e) {
                // Concurrent creator won the unique index race; fall through to update.
            }
        }

        // Monotonic guard enforced in SQL so concurrent advances cannot regress.
        $DB->execute('UPDATE {local_syncqueue_cursor}
                         SET lastappliedseq = :seq1, timemodified = :now
                       WHERE peer = :peer AND direction = :direction AND lastappliedseq < :seq2',
            ['seq1' => $seq, 'now' => time(), 'peer' => $peer, 'direction' => $direction, 'seq2' => $seq]);
    }

    /**
     * Authoritatively SET a cursor to $seq, bypassing the monotonic guard — including
     * BACKWARD. Only for a snapshot re-bootstrap: it has just loaded the full head state
     * as of $seq, so the cursor legitimately jumps to it even from a higher value (a
     * central restore left the cursor past central's now-lower head). Never use on the
     * ordinary apply path, where advance() must stay monotonic.
     *
     * @param string $peer
     * @param string $direction
     * @param int $seq
     */
    public static function reset(string $peer, string $direction, int $seq): void {
        global $DB;

        if (!$DB->record_exists('local_syncqueue_cursor', ['peer' => $peer, 'direction' => $direction])) {
            self::advance($peer, $direction, $seq);
            return;
        }
        $DB->execute('UPDATE {local_syncqueue_cursor} SET lastappliedseq = :seq, timemodified = :now
                       WHERE peer = :peer AND direction = :direction',
            ['seq' => max(0, $seq), 'now' => time(), 'peer' => $peer, 'direction' => $direction]);
    }
}
