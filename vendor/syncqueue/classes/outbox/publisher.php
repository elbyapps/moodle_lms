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
 * Transactional outbox publisher (ELMS Sync v2 step 1).
 *
 * Inserts unsequenced (seq = NULL) rows into the outbox. Safe to call inside
 * an open DB transaction: the row commits (or rolls back) atomically with the
 * business write, and the sequencer only ever touches committed rows.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class publisher {

    /** @var string[] Valid outbox actions. */
    const ACTIONS = ['upsert', 'delete', 'publish'];

    /**
     * Publish an entity change to the outbox.
     *
     * The row is inserted with seq = NULL; sequencer::assign() later gives it
     * a dense sequence number once the surrounding transaction has committed.
     *
     * @param string $entitytype Entity type, e.g. 'course', 'category', 'course_content'.
     * @param string $entitykey Stable entity identity, e.g. 'course:123'.
     * @param string $action One of 'upsert', 'delete', 'publish'.
     * @param array|null $payload Payload data, stored as canonical JSON.
     * @param string $partitionkey Delivery partition, e.g. 'content:global'.
     * @param int|null $contentversion Content publication version for course_content rows.
     * @return int Outbox row id.
     */
    public static function publish(string $entitytype, string $entitykey, string $action,
            ?array $payload, string $partitionkey, ?int $contentversion = null): int {
        global $DB;

        if (!in_array($action, self::ACTIONS, true)) {
            throw new \coding_exception("Invalid outbox action '{$action}'");
        }

        $record = new \stdClass();
        $record->seq = null;
        $record->entitytype = $entitytype;
        $record->entitykey = $entitykey;
        $record->entityversion = applied_state::next_version($entitytype, $entitykey);
        $record->action = $action;
        $record->payload = ($payload === null) ? null : self::canonical_json($payload);
        $record->payloadhash = self::hash_payload($payload);
        $record->contentversion = $contentversion;
        $record->partitionkey = $partitionkey;
        $record->lineageuuid = null;
        $record->factversion = null;
        $record->factuuid = null;
        $record->rostergen = null;
        $record->timecreated = time();

        return $DB->insert_record('local_syncqueue_outbox', $record);
    }

    /**
     * SHA256 of the canonical JSON encoding of a payload.
     *
     * A null payload hashes the JSON literal 'null'. Appliers and tests use
     * this to verify payload integrity end to end.
     *
     * @param array|null $payload
     * @return string 64-char hex hash.
     */
    public static function hash_payload(?array $payload): string {
        return hash('sha256', self::canonical_json($payload));
    }

    /**
     * Canonical JSON: keys sorted recursively, unescaped slashes and unicode.
     *
     * @param array|null $payload
     * @return string
     */
    public static function canonical_json(?array $payload): string {
        return json_encode(self::sort_keys($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively sort array keys so encoding is deterministic.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sort_keys($value) {
        if ($value instanceof \stdClass) {
            $value = (array)$value;
        }
        if (is_array($value)) {
            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = self::sort_keys($item);
            }
        }
        return $value;
    }
}
