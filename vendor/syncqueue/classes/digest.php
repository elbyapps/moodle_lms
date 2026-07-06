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
 * Applied-state digest primitives for anti-entropy (ELMS Sync v2 step 6, doc §9).
 *
 * A weekly two-level exchange converges REPLICATED downstream state: what central
 * published for a school vs what the school actually applied. Digests are computed
 * over the applied-state payloadhashes (never re-derived Moodle-row hashes, which
 * legitimately diverge — schools suffix shortnames, renumber attempts), so the only
 * differences a digest surfaces are genuine missing/stale deliveries, healed by
 * re-fetch.
 *
 * Two source maps, both entitytype -> {entitykey => payloadhash}, over the SAME set
 * of central-owned content entitytypes:
 *  - {@see local_applied_map} (school): its applied-state, the head it holds.
 *  - {@see central_expected_map} (central): the head (max entityversion) published to
 *    the school's subscribed content partitions — what the school SHOULD hold.
 *
 * The exchange buckets each map by crc32(entitykey) % BUCKETS and compares per-bucket
 * hashes; only divergent buckets are drilled into (key+hash lists), so it is bounded
 * and incremental. digest_version guards the canonicalization: a version mismatch
 * skips cleanly rather than triggering a fleet-wide repair storm after a release.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class digest {

    /** @var int Canonicalization version; a mismatch skips the exchange. */
    const VERSION = 1;

    /** @var int Number of crc buckets in the second level. */
    const BUCKETS = 64;

    /**
     * @var string[] Central-owned content entitytypes the digest converges. Excludes
     * step-5 seed_grade/seed_completion (per-learner, ephemeral, re-seeded on move) and
     * all upstream fact types (converged by the upstream digest / capture-scan).
     */
    const ENTITYTYPES = ['category', 'course', 'course_content', 'identity_map'];

    /**
     * The bucket an entitykey falls in — identical on both peers regardless of platform.
     *
     * Uses the low 24 bits of a sha256 prefix rather than crc32: crc32 is a SIGNED int
     * on 32-bit PHP, so crc32(k) % 64 would bucket the same key differently on a 32- vs
     * 64-bit peer and force a needless detail round every week. The sha256 prefix (a
     * hex string) parses to the same integer everywhere.
     *
     * @param string $entitykey
     * @return int 0..BUCKETS-1
     */
    public static function bucket(string $entitykey): int {
        return (int) hexdec(substr(hash('sha256', $entitykey), 0, 6)) % self::BUCKETS;
    }

    /**
     * Per-(entitytype, bucket) hash over a source map.
     *
     * Each bucket hash is the sha256 of its "entitykey\x1fpayloadhash" lines sorted
     * lexicographically, so it is order-independent and any missing/extra/changed key
     * flips exactly its own bucket. Empty buckets are omitted.
     *
     * @param array $map entitytype => [entitykey => payloadhash]
     * @return array entitytype => [bucket => hash]
     */
    public static function summary(array $map): array {
        $summary = [];
        foreach ($map as $entitytype => $keyhashes) {
            $buckets = [];
            foreach ($keyhashes as $entitykey => $payloadhash) {
                $buckets[self::bucket((string) $entitykey)][] = (string) $entitykey . "\x1f" . (string) $payloadhash;
            }
            foreach ($buckets as $bucket => $lines) {
                sort($lines, SORT_STRING);
                $summary[$entitytype][$bucket] = hash('sha256', implode("\n", $lines));
            }
        }
        return $summary;
    }

    /**
     * The (entitytype, bucket) pairs where two summaries differ (either side, incl.
     * one-sided). These are the only buckets the detail round drills into.
     *
     * @param array $mine entitytype => [bucket => hash]
     * @param array $theirs entitytype => [bucket => hash]
     * @return array list of ['entitytype' => string, 'bucket' => int]
     */
    public static function divergent_buckets(array $mine, array $theirs): array {
        $out = [];
        $types = array_unique(array_merge(array_keys($mine), array_keys($theirs)));
        foreach ($types as $entitytype) {
            $mb = $mine[$entitytype] ?? [];
            $tb = $theirs[$entitytype] ?? [];
            $buckets = array_unique(array_merge(array_keys($mb), array_keys($tb)));
            foreach ($buckets as $bucket) {
                if (($mb[$bucket] ?? null) !== ($tb[$bucket] ?? null)) {
                    $out[] = ['entitytype' => (string) $entitytype, 'bucket' => (int) $bucket];
                }
            }
        }
        return $out;
    }

    /**
     * The school's applied head per content entitykey (its digest source).
     *
     * @return array entitytype => [entitykey => payloadhash]
     */
    public static function local_applied_map(): array {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal(self::ENTITYTYPES, SQL_PARAMS_NAMED, 'et');
        $rows = $DB->get_recordset_select('local_syncqueue_applied',
            "entitytype $insql", $inparams, '', 'entitytype, entitykey, payloadhash');
        $map = [];
        foreach ($rows as $r) {
            $map[$r->entitytype][$r->entitykey] = (string) $r->payloadhash;
        }
        $rows->close();
        return $map;
    }

    /**
     * Central's expected head per content entitykey for a school (what it should hold).
     *
     * The head is the max-entityversion outbox row per (entitytype, entitykey) in the
     * school's subscribed content partitions — mirroring pull.php's subscription scope
     * and read-time supersession, so a school is never told it is "missing" a course it
     * never subscribed to.
     *
     * @param string $schoolid
     * @param int $onlyselected The school's onlyselected flag.
     * @return array entitytype => [entitykey => payloadhash]
     */
    public static function central_expected_map(string $schoolid, int $onlyselected): array {
        global $DB;

        [$partsql, $partparams] = self::content_partition_sql($schoolid, $onlyselected);
        [$etsql, $etparams] = $DB->get_in_or_equal(self::ENTITYTYPES, SQL_PARAMS_NAMED, 'et');
        $sql = "SELECT o.id, o.entitytype, o.entitykey, o.entityversion, o.payloadhash
                  FROM {local_syncqueue_outbox} o
                 WHERE o.seq IS NOT NULL AND o.entitytype $etsql AND $partsql";
        $rows = $DB->get_recordset_sql($sql, $etparams + $partparams);
        $head = [];
        foreach ($rows as $r) {
            $k = $r->entitytype . '|' . $r->entitykey;
            if (!isset($head[$k]) || (int) $r->entityversion > $head[$k]['v']) {
                $head[$k] = ['v' => (int) $r->entityversion, 'h' => (string) $r->payloadhash,
                    'type' => $r->entitytype, 'key' => $r->entitykey];
            }
        }
        $rows->close();
        $map = [];
        foreach ($head as $e) {
            $map[$e['type']][$e['key']] = $e['h'];
        }
        return $map;
    }

    /**
     * The head outbox row for an entity (its full current payload), or null.
     *
     * Used by the detail round to return the entities a school must re-fetch.
     *
     * @param string $entitytype
     * @param string $entitykey
     * @return \stdClass|null
     */
    public static function central_head_row(string $entitytype, string $entitykey): ?\stdClass {
        global $DB;

        $rows = $DB->get_records_select('local_syncqueue_outbox',
            'seq IS NOT NULL AND entitytype = :et AND entitykey = :ek',
            ['et' => $entitytype, 'ek' => $entitykey], 'entityversion DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * Fetch the current head rows for an explicit list of content keys (snapshot
     * bootstrap + digest fetch). Scoped to the school's expected set, so a school can
     * only ever fetch content it is entitled to — an out-of-subscription key is skipped.
     *
     * @param array $keys list of ['entitytype' => string, 'entitykey' => string]
     * @param string $schoolid
     * @param int $onlyselected
     * @param int $max Cap on rows returned.
     * @return array list of entity rows (same shape the pull/detail returns)
     */
    public static function fetch_rows(array $keys, string $schoolid, int $onlyselected, int $max): array {
        $expected = self::central_expected_map($schoolid, $onlyselected);
        $out = [];
        foreach ($keys as $k) {
            if (!is_array($k)) {
                continue;
            }
            $type = (string) ($k['entitytype'] ?? '');
            $key = (string) ($k['entitykey'] ?? '');
            if ($type === '' || $key === '' || !isset($expected[$type][$key])) {
                continue;
            }
            $row = self::central_head_row($type, $key);
            if ($row === null) {
                continue;
            }
            $out[] = [
                'entitytype' => $row->entitytype,
                'entitykey' => $row->entitykey,
                'entityversion' => (int) $row->entityversion,
                'action' => $row->action,
                'payload' => $row->payload,
                'payloadhash' => $row->payloadhash,
                'contentversion' => $row->contentversion === null ? null : (int) $row->contentversion,
            ];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    // --- Upstream direction: the school's authored facts vs central's ingest ----

    /**
     * The school's authored-fact head per lineage (its upstream digest source).
     *
     * Only facts the school has actually pushed (status EXPORTED/ACKED, finalized
     * factversion) — a still-CAPTURED fact is pending push, not a divergence central
     * should already hold. Keyed by facttype so the exchange reuses the same per-type
     * bucketing as downstream: facttype => [lineageuuid => head payloadhash].
     *
     * @param string $schoolid This school's origin id.
     * @return array
     */
    public static function school_authored_map(string $schoolid): array {
        global $DB;

        if ($schoolid === '' || !$DB->get_manager()->table_exists('local_syncqueue_ledger')) {
            return [];
        }
        $rows = $DB->get_recordset_select('local_syncqueue_ledger',
            'origin = :origin AND factversion IS NOT NULL AND status IN (:exported, :acked)',
            ['origin' => $schoolid, 'exported' => fact_ledger::STATUS_EXPORTED, 'acked' => fact_ledger::STATUS_ACKED],
            '', 'id, facttype, lineageuuid, factversion, payloadhash');
        return self::head_map($rows);
    }

    /**
     * Central's received-fact head per lineage for a school (what central holds).
     *
     * Any ingest row for the school means central received that fact; a lineage absent
     * from central's ingest (e.g. a central restore lost it) or held at an older version
     * is what the upstream repair re-pushes. facttype => [lineageuuid => head payloadhash].
     *
     * Synthetic non-authored markers (hole and dedup slot-fillers, both factversion 0)
     * are excluded: they never appear in the school's ledger-derived authored map, so
     * counting them here would show as phantom divergent lineages and keep
     * upstream_anti_entropy off its converged fast path forever. Real facts start at
     * version 1, so factversion &gt; 0 keeps every real fact — including dead-lettered
     * ones — while dropping only the markers.
     *
     * @param string $schoolid The school whose pushed facts to summarise.
     * @return array
     */
    public static function central_received_map(string $schoolid): array {
        global $DB;

        if ($schoolid === '' || !$DB->get_manager()->table_exists('local_syncqueue_ingest')) {
            return [];
        }
        $rows = $DB->get_recordset_select('local_syncqueue_ingest',
            'schoolid = :schoolid AND factversion > 0', ['schoolid' => $schoolid],
            '', 'id, facttype, lineageuuid, factversion, payloadhash');
        return self::head_map($rows);
    }

    /**
     * Reduce a recordset of (facttype, lineageuuid, factversion, payloadhash) to the head
     * (max factversion) payloadhash per (facttype, lineageuuid). Closes the recordset.
     *
     * @param \moodle_recordset $rows
     * @return array facttype => [lineageuuid => payloadhash]
     */
    protected static function head_map(\moodle_recordset $rows): array {
        $head = [];
        foreach ($rows as $r) {
            if ($r->lineageuuid === null || $r->lineageuuid === '') {
                continue;
            }
            $k = $r->facttype . '|' . $r->lineageuuid;
            if (!isset($head[$k]) || (int) $r->factversion > $head[$k]['v']) {
                $head[$k] = ['v' => (int) $r->factversion, 'h' => (string) $r->payloadhash,
                    't' => (string) $r->facttype, 'k' => (string) $r->lineageuuid];
            }
        }
        $rows->close();
        $map = [];
        foreach ($head as $e) {
            $map[$e['t']][$e['k']] = $e['h'];
        }
        return $map;
    }

    /**
     * The content-partition SQL fragment for a school (mirrors pull.php scoping).
     *
     * @param string $schoolid
     * @param int $onlyselected
     * @return array [sql, params]
     */
    protected static function content_partition_sql(string $schoolid, int $onlyselected): array {
        global $DB;

        if (!$onlyselected) {
            return [
                '(o.partitionkey = :pg OR ' . $DB->sql_like('o.partitionkey', ':pc') . ')',
                ['pg' => 'content:global', 'pc' => 'content:course:%'],
            ];
        }
        $partitions = ['content:global'];
        $courseids = $DB->get_fieldset_select('local_syncqueue_course_prefs', 'courseid',
            'schoolid = :s AND selected = 1', ['s' => $schoolid]);
        foreach ($courseids as $cid) {
            $partitions[] = 'content:course:course:' . (int) $cid;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($partitions, SQL_PARAMS_NAMED, 'p');
        return ['o.partitionkey ' . $insql, $inparams];
    }
}
