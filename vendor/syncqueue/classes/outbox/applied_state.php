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
 * Per-entity applied state (ELMS Sync v2 step 1).
 *
 * On schools: what was last applied per entitykey plus the local id it
 * resolved to (replaces idnumber scans). On central: doubles as the
 * entityversion registry for central-owned entities.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class applied_state {

    /**
     * Applied-state row for an entity, or null when never seen.
     *
     * @param string $entitytype
     * @param string $entitykey
     * @return \stdClass|null
     */
    public static function get(string $entitytype, string $entitykey): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_syncqueue_applied',
            ['entitytype' => $entitytype, 'entitykey' => $entitykey]);
        return $record ?: null;
    }

    /**
     * Record the applied version/hash (and local id resolution) for an entity.
     *
     * @param string $entitytype
     * @param string $entitykey
     * @param int $entityversion
     * @param string $payloadhash
     * @param int|null $localid Local record id the entitykey resolves to.
     */
    public static function upsert(string $entitytype, string $entitykey, int $entityversion,
            string $payloadhash, ?int $localid): void {
        global $DB;

        $record = $DB->get_record('local_syncqueue_applied',
            ['entitytype' => $entitytype, 'entitykey' => $entitykey]);
        if (!$record) {
            $record = new \stdClass();
            $record->entitytype = $entitytype;
            $record->entitykey = $entitykey;
            $record->entityversion = $entityversion;
            $record->payloadhash = $payloadhash;
            $record->localid = $localid;
            $record->timemodified = time();
            try {
                $DB->insert_record('local_syncqueue_applied', $record);
                return;
            } catch (\dml_write_exception $e) {
                // Concurrent creator won the unique index race; update instead.
                $record = $DB->get_record('local_syncqueue_applied',
                    ['entitytype' => $entitytype, 'entitykey' => $entitykey], '*', MUST_EXIST);
            }
        }

        $record->entityversion = $entityversion;
        $record->payloadhash = $payloadhash;
        $record->localid = $localid;
        $record->timemodified = time();
        $DB->update_record('local_syncqueue_applied', $record);
    }

    /**
     * Re-point an entity's resolved local id (step 7 content swap): after a content
     * bump the entitykey must resolve to the new course copy. Creates the row if it
     * does not exist yet (version 0), so resolution is authoritative immediately.
     *
     * @param string $entitytype
     * @param string $entitykey
     * @param int $localid
     */
    public static function set_localid(string $entitytype, string $entitykey, int $localid): void {
        global $DB;
        $record = $DB->get_record('local_syncqueue_applied',
            ['entitytype' => $entitytype, 'entitykey' => $entitykey]);
        if ($record) {
            $DB->set_field('local_syncqueue_applied', 'localid', $localid, ['id' => $record->id]);
            $DB->set_field('local_syncqueue_applied', 'timemodified', time(), ['id' => $record->id]);
            return;
        }
        $DB->insert_record('local_syncqueue_applied', (object) [
            'entitytype' => $entitytype, 'entitykey' => $entitykey, 'entityversion' => 0,
            'payloadhash' => '', 'localid' => $localid, 'timemodified' => time(),
        ]);
    }

    /**
     * The applied .mbz content version for a course_content entity, or 0 when
     * none has been recorded (step 7). Read to detect a content bump on apply.
     *
     * @param string $entitytype
     * @param string $entitykey
     * @return int
     */
    public static function get_contentversion(string $entitytype, string $entitykey): int {
        global $DB;
        return (int) $DB->get_field('local_syncqueue_applied', 'contentversion',
            ['entitytype' => $entitytype, 'entitykey' => $entitykey]);
    }

    /**
     * Record the applied content version for an entity (step 7). The applied-state
     * row must already exist (upsert it first); a no-op when it does not.
     *
     * @param string $entitytype
     * @param string $entitykey
     * @param int $contentversion
     */
    public static function set_contentversion(string $entitytype, string $entitykey, int $contentversion): void {
        global $DB;
        $id = $DB->get_field('local_syncqueue_applied', 'id',
            ['entitytype' => $entitytype, 'entitykey' => $entitykey]);
        if ($id) {
            $DB->set_field('local_syncqueue_applied', 'contentversion', $contentversion, ['id' => $id]);
        }
    }

    /**
     * Bump and return the next entityversion for an entity, under a row lock.
     *
     * Creates the registry row on first use (first version is 1). Safe inside
     * an open transaction: the row lock then holds until the outer commit, so
     * racing publishers of the same entity serialize on it and a rolled-back
     * business write also rolls the bump back (transactional outbox).
     *
     * @param string $entitytype
     * @param string $entitykey
     * @return int
     */
    public static function next_version(string $entitytype, string $entitykey): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $params = ['entitytype' => $entitytype, 'entitykey' => $entitykey];
            $record = $DB->get_record_sql(
                'SELECT * FROM {local_syncqueue_applied}
                  WHERE entitytype = :entitytype AND entitykey = :entitykey FOR UPDATE', $params);

            if (!$record) {
                $record = new \stdClass();
                $record->entitytype = $entitytype;
                $record->entitykey = $entitykey;
                $record->entityversion = 0;
                $record->payloadhash = '';
                $record->localid = null;
                $record->timemodified = time();
                try {
                    $record->id = $DB->insert_record('local_syncqueue_applied', $record);
                } catch (\dml_write_exception $e) {
                    // Concurrent creator won the unique index race; lock its row.
                    $record = $DB->get_record_sql(
                        'SELECT * FROM {local_syncqueue_applied}
                          WHERE entitytype = :entitytype AND entitykey = :entitykey FOR UPDATE',
                        $params, MUST_EXIST);
                }
            }

            $next = (int)$record->entityversion + 1;
            $DB->set_field('local_syncqueue_applied', 'entityversion', $next, ['id' => $record->id]);
            $DB->set_field('local_syncqueue_applied', 'timemodified', time(), ['id' => $record->id]);

            $transaction->allow_commit();
            return $next;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }
}
