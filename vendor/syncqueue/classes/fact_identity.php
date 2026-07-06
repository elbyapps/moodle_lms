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

/**
 * Deterministic two-level fact identity (ELMS Sync v2 §9.1).
 *
 * A fact's identity is derived purely from its content, never from local
 * autoincrement ids, epochs, or the survival of any row, so the same fact
 * regenerated from source tables after a restore mints byte-identical UUIDs
 * and dedups exactly against whatever central still holds:
 *
 *   lineageuuid = UUIDv5(sync-ns, origin | facttype | naturalkey)
 *                 names the fact across its whole life;
 *   factuuid    = UUIDv5(lineageuuid, factversion)
 *                 names one exact version.
 *
 * A superseding version is a new factuuid in the SAME lineage, so it can
 * never masquerade as a fork.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fact_identity {

    /**
     * Root namespace UUID for the whole sync system. This is a constant of the
     * protocol: it must NEVER change, or every lineage uuid across the fleet
     * would shift and dedup/supersession would break. (A fixed, arbitrary v4
     * UUID minted once for this purpose.)
     */
    public const SYNC_NAMESPACE = '7f9a2e14-6c3b-4d58-9a21-0e5b8c7d4f36';

    /**
     * Compute the lineage UUID that names a fact across its whole life.
     *
     * @param string $origin Authoring instance id (school id).
     * @param string $facttype Fact type, e.g. 'grade', 'quiz_attempt'.
     * @param string $naturalkey Stable natural key (cm/item identity + SDMS + ordinal).
     * @return string Lowercase canonical UUID.
     */
    public static function lineage_uuid(string $origin, string $facttype, string $naturalkey): string {
        return self::uuid_v5(self::SYNC_NAMESPACE, $origin . '|' . $facttype . '|' . $naturalkey);
    }

    /**
     * Compute the fact UUID naming one exact version within a lineage.
     *
     * The lineage UUID itself is the namespace, so factuuid = f(lineage, version)
     * with no dependence on any shared salt beyond the lineage.
     *
     * @param string $lineageuuid The lineage UUID.
     * @param int $factversion Monotonic per-lineage version (>= 1).
     * @return string Lowercase canonical UUID.
     */
    public static function fact_uuid(string $lineageuuid, int $factversion): string {
        return self::uuid_v5($lineageuuid, (string) $factversion);
    }

    /**
     * Compose a natural key from ordered components.
     *
     * Components are joined with a delimiter that cannot occur inside an
     * individual component here (all callers pass identifiers/ints), keeping
     * the key unambiguous. Null/empty components are rendered as an empty
     * segment so the arity stays fixed per fact type.
     *
     * @param array $parts Ordered key components (scalars).
     * @return string
     */
    public static function natural_key(array $parts): string {
        $clean = [];
        foreach ($parts as $p) {
            $clean[] = ($p === null) ? '' : (string) $p;
        }
        return implode('~', $clean);
    }

    /**
     * RFC 4122 version 5 (SHA-1, name-based) UUID.
     *
     * Deterministic: the same (namespace, name) always yields the same UUID on
     * any platform and PHP version. Implemented directly because Moodle core
     * only ships a v4 (random) generator.
     *
     * @param string $namespace Namespace UUID (canonical string form).
     * @param string $name Name to hash within the namespace.
     * @return string Lowercase canonical UUID.
     */
    public static function uuid_v5(string $namespace, string $name): string {
        // Pack the namespace UUID into its 16 raw bytes.
        $hex = preg_replace('/[^0-9a-f]/', '', strtolower($namespace));
        if (strlen($hex) !== 32) {
            // Should never happen with protocol-generated namespaces; fail loud.
            throw new \coding_exception('fact_identity: invalid namespace UUID: ' . $namespace);
        }
        $nsbytes = '';
        for ($i = 0; $i < 32; $i += 2) {
            $nsbytes .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }

        $hash = sha1($nsbytes . $name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            // 32 bits for "time_low".
            substr($hash, 0, 8),
            // 16 bits for "time_mid".
            substr($hash, 8, 4),
            // 16 bits for "time_hi_and_version", version 5.
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            // 16 bits, 8 bits for "clk_seq_hi_res" and "clk_seq_low", variant 10x.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            // 48 bits for "node".
            substr($hash, 20, 12)
        );
    }
}
