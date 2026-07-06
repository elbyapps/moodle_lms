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
 * School-side application of a central identity map (ELMS Sync v2 step 4 preflight).
 *
 * Back-stamps the local cm / grade-item idnumbers of an already-distributed
 * course to central's UUIDs by STRICT STRUCTURAL MATCH — legitimate because the
 * school copy was restored from central's own .mbz, so each map entry pairs to
 * the local module in the same section, of the same module type, carrying the
 * same activity NAME.
 *
 * The pairing keys on the activity name, NOT the ordinal (position in
 * course_sections.sequence): the ordinal is only the .mbz restore order, so a
 * school that reorders two same-type activities in a section — or deletes one
 * and adds another of the same type — keeps the bucket count equal yet permutes
 * an ordinal pairing, which would silently back-stamp a UUID onto the wrong
 * activity (every later fact then keys on the swapped UUID). The activity name
 * travels with the module through both the reorder and the restore, so pairing
 * on it is stable; when a name is not 1:1 unique in a (section, module-type)
 * bucket the pairing is unprovable and the whole course is flagged.
 *
 * Two safety rails make this safe to run unattended:
 *  - DRY-RUN by default: apply() only writes when $execute is true; the pull
 *    dispatch stores the received map for operator review (apply_identity_map.php)
 *    and stamps nothing unless local_syncqueue/identity_map_autostamp is set.
 *  - ZERO-GUESS: any ambiguity for a course (module-count mismatch, a name that
 *    no longer matches, a duplicate name in a bucket, a local item already
 *    carrying a different idnumber, a missing candidate) flags the WHOLE course
 *    for a stamped-version republish and stamps NOTHING for it. A local idnumber
 *    is only ever written when it is empty; an idnumber already equal to the
 *    target is an idempotent no-op.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class identity_map_applier {

    /** @var string Durable store of received maps (guarded by table_ready). */
    const TABLE = 'local_syncqueue_identity_map';

    /**
     * Whether the received-map store table exists (dual-stack guard).
     *
     * @return bool
     */
    public static function table_ready(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(self::TABLE);
    }

    /**
     * Resolve a central course id to its local course through applied-state.
     *
     * @param int $centralcourseid Central course id.
     * @return int|null Local course id, or null when the course is not applied here.
     */
    public static function resolve_local_course(int $centralcourseid): ?int {
        global $DB;

        $state = applied_state::get('course', 'course:' . $centralcourseid);
        if ($state && $state->localid && $DB->record_exists('course', ['id' => $state->localid])) {
            return (int) $state->localid;
        }
        return null;
    }

    /**
     * Apply (or dry-run) an identity map against the local course.
     *
     * @param array $map Decoded map: centralcourseid, modules[].
     * @param bool $execute Write idnumbers on an unambiguous match (true) or
     *        only report the plan (false).
     * @param int|null $localcourseid Local course id; resolved from applied-state
     *        when null.
     * @return stdClass Report: ->status ('applied'|'pending'|'flagged'|'nocourse'),
     *         ->localcourseid, ->centralcourseid, ->stampedcms, ->stampedgis,
     *         ->wouldcms, ->wouldgis, ->ambiguities[].
     */
    public static function apply(array $map, bool $execute, ?int $localcourseid = null): stdClass {
        global $DB;

        $report = new stdClass();
        $report->centralcourseid = (int) ($map['centralcourseid'] ?? 0);
        $report->execute = $execute;
        $report->stampedcms = 0;
        $report->stampedgis = 0;
        $report->wouldcms = 0;
        $report->wouldgis = 0;
        $report->ambiguities = [];

        if ($localcourseid === null) {
            $localcourseid = self::resolve_local_course($report->centralcourseid);
        }
        if (!$localcourseid) {
            $report->status = 'nocourse';
            $report->localcourseid = null;
            return $report;
        }
        $report->localcourseid = (int) $localcourseid;

        $match = self::match_course($map, (int) $localcourseid);
        $report->ambiguities = $match->ambiguities;
        foreach ($match->plan as $planitem) {
            if ($planitem->type === 'cm') {
                $report->wouldcms++;
            } else {
                $report->wouldgis++;
            }
        }

        if (!$match->ok) {
            // Zero-guess: the course is flagged for a stamped-version republish
            // and nothing is written for it.
            $report->status = 'flagged';
            return $report;
        }
        if (empty($match->plan)) {
            // Every mapped item is already stamped (or the map is empty): no-op.
            $report->status = 'applied';
            return $report;
        }
        if (!$execute) {
            $report->status = 'pending';
            return $report;
        }

        $touchedcache = false;
        foreach ($match->plan as $planitem) {
            if ($planitem->type === 'cm') {
                $DB->set_field('course_modules', 'idnumber', $planitem->target, ['id' => $planitem->localid]);
                $report->stampedcms++;
                $touchedcache = true;
            } else {
                $DB->set_field('grade_items', 'idnumber', $planitem->target, ['id' => $planitem->localid]);
                $report->stampedgis++;
            }
        }
        if ($touchedcache) {
            rebuild_course_cache((int) $localcourseid, true);
        }
        $report->status = 'applied';
        return $report;
    }

    /**
     * Pure structural match of a map against a local course.
     *
     * Pairs each central map entry to a local module within the same
     * (section, module-type) bucket by the ACTIVITY NAME discriminator, never by
     * ordinal (the .mbz restore order): a post-restore reorder or same-type
     * substitution leaves the bucket count equal but permutes an ordinal pairing,
     * silently stamping the wrong UUID. A count divergence, a central name with no
     * matching local module (rename / substitution), or a name shared by two
     * modules in a bucket (unprovable pairing) flags the whole course.
     *
     * @param array $map Decoded map.
     * @param int $localcourseid Local course id.
     * @return stdClass ->ok (bool), ->ambiguities[], ->plan[] (each {type:'cm'|'gi',
     *         localid, target, current}). On any ambiguity ->ok is false and
     *         ->plan is emptied (zero-guess: stamp nothing for the course).
     */
    public static function match_course(array $map, int $localcourseid): stdClass {
        $out = new stdClass();
        $out->ok = true;
        $out->ambiguities = [];
        $out->plan = [];

        $entries = $map['modules'] ?? [];

        // Group local modules and central map entries into (section, modname)
        // buckets; the pairing happens within a bucket, keyed on the activity name.
        $localbuckets = [];
        foreach (item_identity::ordered_modules($localcourseid) as $localmod) {
            $localbuckets[$localmod->section . '|' . $localmod->modname][] = $localmod;
        }
        $mapbuckets = [];
        foreach ($entries as $entry) {
            $mapbuckets[((int) $entry['section']) . '|' . $entry['modname']][] = $entry;
        }

        foreach (array_unique(array_merge(array_keys($localbuckets), array_keys($mapbuckets))) as $bucket) {
            $locals = $localbuckets[$bucket] ?? [];
            $maps = $mapbuckets[$bucket] ?? [];

            // A module-count divergence means the local course was restructured
            // relative to central: flag, never guess a pairing.
            if (count($locals) !== count($maps)) {
                $out->ok = false;
                $out->ambiguities[] = "module count mismatch for section|modname '{$bucket}': central "
                    . count($maps) . ' vs local ' . count($locals);
                continue;
            }
            if (!$maps) {
                continue;
            }

            // Index the bucket's local modules by activity name; a name shared by
            // two modules is unprovable after a reorder, as is a central map that
            // itself carries a duplicate name — either flags the bucket.
            $localbyname = self::index_modules_by_name($locals);
            $mapnames = array_map(static function ($entry) {
                return (string) ($entry['name'] ?? '');
            }, $maps);
            if ($localbyname === null || count($mapnames) !== count(array_unique($mapnames))) {
                $out->ok = false;
                $out->ambiguities[] = "ambiguous pairing for section|modname '{$bucket}': two or more "
                    . 'modules share an activity name (needs a stamped-version republish)';
                continue;
            }

            foreach ($maps as $entry) {
                $name = (string) ($entry['name'] ?? '');
                if (!isset($localbyname[$name])) {
                    $out->ok = false;
                    $out->ambiguities[] = "no local {$entry['modname']} named '{$name}' in section "
                        . ((int) $entry['section']) . ' (local edit / substitution — needs a stamped-version republish)';
                    continue;
                }
                self::plan_module($entry, $localbyname[$name], $localcourseid, $out);
            }
        }

        if (!$out->ok) {
            $out->plan = [];
        }
        return $out;
    }

    /**
     * Index a bucket's local modules by activity name, or null when a name is
     * shared (the in-bucket pairing would be unprovable, so the caller flags).
     *
     * @param stdClass[] $modules item_identity::ordered_modules() rows for one
     *        (section, modname) bucket.
     * @return array<string,stdClass>|null name => module, or null on a duplicate.
     */
    protected static function index_modules_by_name(array $modules): ?array {
        $byname = [];
        foreach ($modules as $module) {
            $name = (string) ($module->name ?? '');
            if (isset($byname[$name])) {
                return null;
            }
            $byname[$name] = $module;
        }
        return $byname;
    }

    /**
     * Plan (or flag) the cm and its grade items for one name-matched map entry.
     *
     * The cm is already paired by name; grade items are then resolved
     * deterministically by (module, instance, itemnumber). A local idnumber is
     * planned only when empty; one that differs from the target — or a grade
     * itemname that diverges when both sides are set — flags the course.
     *
     * @param array $entry Map entry: cm_uuid, items[], section, modname, name.
     * @param stdClass $localmod The structurally matched local module.
     * @param int $localcourseid Local course id.
     * @param stdClass $out Match accumulator, mutated (->ok, ->ambiguities, ->plan).
     */
    protected static function plan_module(array $entry, stdClass $localmod, int $localcourseid, stdClass $out): void {
        global $DB;

        $cmtarget = (string) $entry['cm_uuid'];
        $label = $localmod->section . '|' . $localmod->modname . " '" . $localmod->name . "'";

        if ($localmod->idnumber !== '' && $localmod->idnumber !== $cmtarget) {
            $out->ok = false;
            $out->ambiguities[] = "local cm {$localmod->cmid} ({$label}) idnumber '{$localmod->idnumber}' "
                . "!= central '{$cmtarget}'";
        } else if ($localmod->idnumber === '') {
            $out->plan[] = self::planitem('cm', $localmod->cmid, $cmtarget, $localmod->idnumber);
        }

        foreach (($entry['items'] ?? []) as $item) {
            $itemnumber = (int) $item['itemnumber'];
            $gitarget = (string) $item['gi_uuid'];
            $gi = $DB->get_record('grade_items', [
                'courseid' => $localcourseid,
                'itemtype' => 'mod',
                'itemmodule' => $localmod->modname,
                'iteminstance' => $localmod->instance,
                'itemnumber' => $itemnumber,
            ], 'id, idnumber, itemname');
            if (!$gi) {
                $out->ok = false;
                $out->ambiguities[] = "no local grade item for {$label} itemnumber {$itemnumber}";
                continue;
            }
            // Secondary discriminator: a diverged itemname (both sides non-empty)
            // flags rather than stamps. 'mod' itemname is often empty (the name is
            // taken from the module), so the check is skipped unless both are set.
            $mapitemname = (string) ($item['itemname'] ?? '');
            $localitemname = (string) $gi->itemname;
            if ($mapitemname !== '' && $localitemname !== '' && $mapitemname !== $localitemname) {
                $out->ok = false;
                $out->ambiguities[] = "local grade item {$gi->id} ({$label}#{$itemnumber}) itemname "
                    . "'{$localitemname}' != central '{$mapitemname}'";
                continue;
            }
            $current = (string) $gi->idnumber;
            if ($current !== '' && $current !== $gitarget) {
                $out->ok = false;
                $out->ambiguities[] = "local grade item {$gi->id} ({$label}#{$itemnumber}) idnumber "
                    . "'{$current}' != central '{$gitarget}'";
            } else if ($current === '') {
                $out->plan[] = self::planitem('gi', (int) $gi->id, $gitarget, $current);
            }
        }
    }

    /**
     * Persist an apply report + the received map for operator review.
     *
     * Called by the pull dispatch on receipt (with the outbox entityversion /
     * payloadhash) and by apply_pending after a CLI run (leaving those null to
     * preserve the stored values). No-op when the store table is absent.
     *
     * @param array $map Decoded map.
     * @param stdClass $report An apply() report for this map.
     * @param int|null $entityversion Outbox entityversion, or null to keep.
     * @param string|null $payloadhash Outbox payloadhash, or null to keep.
     */
    public static function persist(array $map, stdClass $report, ?int $entityversion, ?string $payloadhash): void {
        global $DB;

        if (!self::table_ready()) {
            return;
        }

        $centralcourseid = (int) ($map['centralcourseid'] ?? 0);
        $existing = $DB->get_record(self::TABLE, ['centralcourseid' => $centralcourseid]);

        $record = $existing ?: new stdClass();
        $record->centralcourseid = $centralcourseid;
        $record->localcourseid = $report->localcourseid;
        if ($entityversion !== null) {
            $record->entityversion = $entityversion;
        }
        if ($payloadhash !== null) {
            $record->payloadhash = $payloadhash;
        }
        $record->map = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record->status = $report->status;
        $record->report = json_encode([
            'ambiguities' => $report->ambiguities,
            'stampedcms' => $report->stampedcms,
            'stampedgis' => $report->stampedgis,
            'wouldcms' => $report->wouldcms,
            'wouldgis' => $report->wouldgis,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record->timemodified = time();

        if ($existing) {
            $DB->update_record(self::TABLE, $record);
            return;
        }
        $record->timecreated = time();
        $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Stored received maps (optionally one course), newest structural status first.
     *
     * @param int|null $centralcourseid Filter to one central course, or null for all.
     * @return stdClass[] Store rows.
     */
    public static function stored(?int $centralcourseid = null): array {
        global $DB;

        if (!self::table_ready()) {
            return [];
        }
        $conditions = [];
        if ($centralcourseid !== null) {
            $conditions['centralcourseid'] = $centralcourseid;
        }
        return $DB->get_records(self::TABLE, $conditions, 'centralcourseid ASC');
    }

    /**
     * Apply (or dry-run) every stored received map, updating each stored status.
     *
     * The CLI entry point: dry-run reports each course's match; --execute stamps
     * the unambiguous ones. Idempotent.
     *
     * @param bool $execute Stamp unambiguous matches (true) or report only (false).
     * @param int|null $centralcourseid Filter to one central course, or null for all.
     * @return stdClass[] One apply() report per stored map.
     */
    public static function apply_stored(bool $execute, ?int $centralcourseid = null): array {
        $reports = [];
        foreach (self::stored($centralcourseid) as $row) {
            $map = json_decode($row->map, true);
            if (!is_array($map)) {
                continue;
            }
            $localcourseid = $row->localcourseid ? (int) $row->localcourseid : null;
            $report = self::apply($map, $execute, $localcourseid);
            self::persist($map, $report, null, null);
            $reports[] = $report;
        }
        return $reports;
    }

    /**
     * A stamping plan item.
     *
     * @param string $type 'cm' or 'gi'.
     * @param int $localid Local course_modules / grade_items id.
     * @param string $target Target UUID idnumber.
     * @param string $current Current (empty) idnumber.
     * @return stdClass
     */
    protected static function planitem(string $type, int $localid, string $target, string $current): stdClass {
        $planitem = new stdClass();
        $planitem->type = $type;
        $planitem->localid = $localid;
        $planitem->target = $target;
        $planitem->current = $current;
        return $planitem;
    }
}
