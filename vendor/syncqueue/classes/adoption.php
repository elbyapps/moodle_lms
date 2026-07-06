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

use local_syncqueue\outbox\applied_state;
use stdClass;

/**
 * Legacy-to-v2 adoption pass (ELMS Sync v2 step 1 cutover, school side).
 *
 * Seeds local_syncqueue_applied from the legacy idmap and from courses that
 * carry the legacy fallback idnumber ('central_<id>'), so the school's first
 * v2 pull refreshes existing records in place instead of duplicating them.
 * Adopted rows are seeded at entityversion 0 with an empty payloadhash: every
 * incoming v2 row (entityversion >= 1) then supersedes them and rewrites the
 * entity from the authoritative payload.
 *
 * Zero-guess rule: any ambiguity — two local records claiming one central id,
 * a mapping pointing at a deleted record with no surviving claim, a local
 * record claiming multiple central ids, or applied-state already resolving
 * elsewhere — is quarantined in the report, never resolved heuristically.
 * A corpse claim beaten by exactly one surviving claim is not a guess: the
 * live record is adopted and the discarded corpse evidence is recorded on the
 * entry (droppedclaims) so operators can audit it.
 *
 * Deliberate deviation from the architecture doc (section 13 step 1 says
 * "rewrite fallback idnumbers (central_<id>) to real entitykeys"): the course
 * idnumber is left untouched. While the dual stack runs, the legacy download
 * path and the v2 appliers' last-resort fallback both still resolve courses
 * by that idnumber (update_processor's 'central_<id>' lookups), so rewriting
 * it would break unmigrated flows; applied_state.localid — which this pass
 * seeds — is the v2 resolution map. Cleaning up the idnumbers must wait for
 * the legacy channel's retirement.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adoption {

    /** @var string Legacy fallback idnumber pattern written by update_processor for courses applied from central. */
    const IDNUMBER_PATTERN = '/^central_(\d+)$/';

    /** @var string[] Legacy idmap tablename => v2 entitytype (step-1 scope: courses and categories only). */
    const TABLE_MAP = [
        'course' => 'course',
        'course_categories' => 'category',
        'category' => 'category',
    ];

    /**
     * Run the adoption pass.
     *
     * @param bool $execute Write applied-state rows; the default is a dry run.
     * @return stdClass Report: {execute, adopted[], alreadyadopted[], quarantined[], counts}.
     *         Each entry: {entitytype, entitykey, centralid, localid, sources,
     *         droppedclaims[], status[, reason]}.
     */
    public function adopt(bool $execute = false): stdClass {
        global $DB;

        // Claims: entitytype => centralid => localid => list of evidence sources.
        $claims = [];

        // 1. Legacy idmap mappings. Scanned across all schoolid namespaces:
        // a school instance only ever holds its own mappings, but rows written
        // before the schoolid config was fixed can sit under a stale namespace
        // (e.g. 'unknown') and must still count as evidence.
        $rs = $DB->get_recordset_select('local_syncqueue_idmap', 'centralid IS NOT NULL');
        foreach ($rs as $row) {
            $entitytype = self::TABLE_MAP[$row->tablename] ?? null;
            if ($entitytype === null) {
                continue; // Users etc. are out of step-1 scope.
            }
            $claims[$entitytype][(int) $row->centralid][(int) $row->localid][] = 'idmap';
        }
        $rs->close();

        // 2. Courses carrying the legacy fallback idnumber.
        $like = $DB->sql_like('idnumber', ':pattern');
        $courses = $DB->get_records_select('course', $like,
            ['pattern' => $DB->sql_like_escape('central_') . '%'], 'id ASC', 'id, idnumber');
        foreach ($courses as $course) {
            if (preg_match(self::IDNUMBER_PATTERN, $course->idnumber, $matches)) {
                $claims['course'][(int) $matches[1]][(int) $course->id][] = 'idnumber';
            }
        }

        // Reverse map: a local record claiming two different central ids is
        // itself ambiguous evidence and poisons every entity it claims.
        $conflictedlocals = [];
        foreach ($claims as $entitytype => $bycentral) {
            $bylocal = [];
            foreach ($bycentral as $centralid => $bylocalid) {
                foreach ($bylocalid as $localid => $sources) {
                    $bylocal[$localid][$centralid] = true;
                }
            }
            foreach ($bylocal as $localid => $centralids) {
                if (count($centralids) > 1) {
                    $conflictedlocals[$entitytype][$localid] = array_keys($centralids);
                }
            }
        }

        $report = new stdClass();
        $report->execute = $execute;
        $report->adopted = [];
        $report->alreadyadopted = [];
        $report->quarantined = [];

        ksort($claims);
        foreach ($claims as $entitytype => $bycentral) {
            ksort($bycentral);
            foreach ($bycentral as $centralid => $bylocalid) {
                $entry = $this->resolve_entity($entitytype, $centralid, $bylocalid,
                    $conflictedlocals[$entitytype] ?? [], $execute);
                if ($entry->status === 'quarantined') {
                    $report->quarantined[] = $entry;
                } else if ($entry->status === 'already-adopted') {
                    $report->alreadyadopted[] = $entry;
                } else {
                    $report->adopted[] = $entry;
                }
            }
        }

        $report->counts = (object) [
            'adopted' => count($report->adopted),
            'alreadyadopted' => count($report->alreadyadopted),
            'quarantined' => count($report->quarantined),
        ];
        return $report;
    }

    /**
     * Classify (and in execute mode adopt) a single claimed central entity.
     *
     * @param string $entitytype 'course' or 'category'.
     * @param int $centralid Central record id.
     * @param array $bylocalid localid => evidence sources for this central id.
     * @param array $conflictedlocals localid => central ids, for locals claiming several central ids.
     * @param bool $execute Write applied-state on adoption.
     * @return stdClass Report entry.
     */
    protected function resolve_entity(string $entitytype, int $centralid, array $bylocalid,
            array $conflictedlocals, bool $execute): stdClass {
        global $DB;

        $entry = new stdClass();
        $entry->entitytype = $entitytype;
        $entry->entitykey = $entitytype . ':' . $centralid;
        $entry->centralid = $centralid;
        $entry->localid = null;
        $entry->sources = '';
        $entry->droppedclaims = [];

        $table = ($entitytype === 'category') ? 'course_categories' : 'course';

        $valid = [];
        $missing = [];
        foreach ($bylocalid as $localid => $sources) {
            if ($DB->record_exists($table, ['id' => $localid])) {
                $valid[$localid] = array_unique($sources);
            } else {
                $missing[$localid] = array_unique($sources);
            }
        }

        if (!$valid) {
            $detail = [];
            foreach ($missing as $localid => $sources) {
                $detail[] = "$table $localid (" . implode(',', $sources) . ')';
            }
            return $this->quarantine($entry, 'no surviving local record: claimed by ' . implode('; ', $detail));
        }

        if (count($valid) > 1) {
            $detail = [];
            foreach ($valid as $localid => $sources) {
                $detail[] = "$table $localid (" . implode(',', $sources) . ')';
            }
            return $this->quarantine($entry, 'multiple local records claim this central id: ' . implode('; ', $detail));
        }

        $localid = (int) array_key_first($valid);
        $entry->localid = $localid;
        $entry->sources = implode(',', $valid[$localid]);

        // Corpse claims beaten by the single surviving claim are discarded,
        // but the evidence stays on the entry for operator audit.
        foreach ($missing as $deadlocalid => $sources) {
            $entry->droppedclaims[] = "$table $deadlocalid (" . implode(',', $sources) . '): record deleted';
        }

        if (isset($conflictedlocals[$localid])) {
            return $this->quarantine($entry, "$table $localid also claims central id(s) " .
                implode(', ', $conflictedlocals[$localid]));
        }

        $applied = applied_state::get($entitytype, $entry->entitykey);
        if ($applied !== null && $applied->localid !== null) {
            if ((int) $applied->localid === $localid) {
                $entry->status = 'already-adopted';
                return $entry;
            }
            return $this->quarantine($entry,
                "applied-state already maps this entitykey to $table {$applied->localid}");
        }

        if ($execute) {
            if ($applied === null) {
                applied_state::upsert($entitytype, $entry->entitykey, 0, '', $localid);
            } else {
                // Version-registry placeholder without a local resolution:
                // fill in the localid, preserving whatever version is there.
                applied_state::upsert($entitytype, $entry->entitykey,
                    (int) $applied->entityversion, $applied->payloadhash, $localid);
            }
        }
        $entry->status = 'adopted';
        return $entry;
    }

    /**
     * Mark a report entry quarantined with a reason.
     *
     * @param stdClass $entry
     * @param string $reason
     * @return stdClass
     */
    protected function quarantine(stdClass $entry, string $reason): stdClass {
        $entry->status = 'quarantined';
        $entry->reason = $reason;
        return $entry;
    }
}
