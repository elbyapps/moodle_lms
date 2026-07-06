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

use local_syncqueue\outbox\publisher;
use stdClass;

/**
 * Central-side cm / grade-item UUID identity stamping and the per-course
 * identity map (ELMS Sync v2 step 4 preflight, doc 5/7/13).
 *
 * Learner-fact natural keys key on stable cm and grade-item UUID idnumbers so
 * item resolution survives .mbz restores and content version bumps without
 * name matching. Central stamps every course_module and leaf grade item that
 * lacks an idnumber with a deterministic (or, on the rare collision, random)
 * UUID, then publishes a per-course identity map on the downstream channel so
 * already-distributed school copies can back-stamp the same UUIDs by strict
 * structural match (identity_map_applier).
 *
 * Category and course TOTAL grade items are NEVER stamped or synced: local
 * aggregation recomputes them, so only leaf items ('mod' + 'manual') carry a
 * synced identity. Stamping never overwrites a non-empty idnumber, and a
 * grade-item idnumber is kept unique within its course (spike (d)).
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_identity {

    /** @var string[] Grade item types that are leaf (synced) and thus stamped. */
    const LEAF_ITEMTYPES = ['mod', 'manual'];

    /**
     * Whether a string is a canonical RFC-4122 UUID (any version).
     *
     * The natural-key upgrade treats only a UUID idnumber as a stamped, globally
     * unique identity; any other idnumber is "unstamped" for keying purposes.
     *
     * @param string $value
     * @return bool
     */
    public static function is_uuid(string $value): bool {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * Stamp every unstamped cm and leaf grade item in a course with a UUID idnumber.
     *
     * Idempotent and re-runnable: a non-empty idnumber is never overwritten, so a
     * second run is a no-op on already-stamped items. Deterministic UUIDs keep the
     * stamp stable across re-runs; a computed UUID that would collide within the
     * course falls back to a random one (still unique, still never overwritten).
     *
     * @param int $courseid Central course id.
     * @param bool $execute Write idnumbers (true) or only report the plan (false).
     * @return stdClass Report: ->courseid, ->stampedcms, ->stampedgis, ->cms[],
     *         ->items[] (each {id, action:'stamp'|'keep', idnumber}).
     */
    public static function stamp_course(int $courseid, bool $execute = true): stdClass {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], 'id, idnumber', MUST_EXIST);
        $courseident = self::course_ident($course);

        $report = new stdClass();
        $report->courseid = $courseid;
        $report->stampedcms = 0;
        $report->stampedgis = 0;
        $report->cms = [];
        $report->items = [];

        // Idnumbers already in use within this course, for collision checks.
        $usedcm = self::used_idnumbers('course_modules', ['course' => $courseid]);
        $usedgi = self::used_idnumbers('grade_items', ['courseid' => $courseid]);

        $touchedcache = false;

        foreach (self::ordered_modules($courseid) as $m) {
            if ($m->idnumber !== '') {
                // Never overwrite: keep whatever identity the cm already carries.
                $cmuuid = $m->idnumber;
                $report->cms[] = self::note($m->cmid, 'keep', $cmuuid);
            } else {
                $cand = self::ns_uuid('cm:' . $courseident . ':' . $m->section . ':'
                    . $m->modname . ':' . $m->ordinal);
                if (isset($usedcm[$cand])) {
                    $cand = \core\uuid::generate();
                }
                if ($execute) {
                    $DB->set_field('course_modules', 'idnumber', $cand, ['id' => $m->cmid]);
                    $touchedcache = true;
                }
                $usedcm[$cand] = true;
                $cmuuid = $cand;
                $report->stampedcms++;
                $report->cms[] = self::note($m->cmid, 'stamp', $cand);
            }

            // Leaf 'mod' grade items for this cm. The itemnumber-0 (main) item's
            // idnumber is kept synced to the cm idnumber by core -- grade_update
            // re-syncs it on every module save -- so its stable identity IS the
            // cm UUID; a separately-derived UUID would be clobbered on the next
            // edit. Secondary items (itemnumber > 0) are independent and take a
            // derived UUID keyed by the cm UUID + itemnumber.
            foreach (self::mod_grade_items($courseid, $m->modname, $m->instance) as $gi) {
                if ((string) $gi->idnumber !== '') {
                    $report->items[] = self::note((int) $gi->id, 'keep', (string) $gi->idnumber);
                    continue;
                }
                if ((int) $gi->itemnumber === 0) {
                    $gcand = $cmuuid;
                } else {
                    $gcand = self::ns_uuid('gi:' . $cmuuid . ':' . (int) $gi->itemnumber);
                    if (isset($usedgi[$gcand])) {
                        $gcand = \core\uuid::generate();
                    }
                }
                if ($execute) {
                    $DB->set_field('grade_items', 'idnumber', $gcand, ['id' => $gi->id]);
                }
                $usedgi[$gcand] = true;
                $report->stampedgis++;
                $report->items[] = self::note((int) $gi->id, 'stamp', $gcand);
            }
        }

        // Course-level 'manual' grade items: stamped for identity (central's own
        // captured manual grades), keyed by course + creation-order ordinal.
        $manuals = $DB->get_records('grade_items',
            ['courseid' => $courseid, 'itemtype' => 'manual'], 'id ASC', 'id, idnumber');
        $ordinal = 0;
        foreach ($manuals as $gi) {
            if ((string) $gi->idnumber !== '') {
                $report->items[] = self::note((int) $gi->id, 'keep', (string) $gi->idnumber);
                $ordinal++;
                continue;
            }
            $gcand = self::ns_uuid('gi:manual:' . $courseident . ':' . $ordinal);
            if (isset($usedgi[$gcand])) {
                $gcand = \core\uuid::generate();
            }
            if ($execute) {
                $DB->set_field('grade_items', 'idnumber', $gcand, ['id' => $gi->id]);
            }
            $usedgi[$gcand] = true;
            $report->stampedgis++;
            $report->items[] = self::note((int) $gi->id, 'stamp', $gcand);
            $ordinal++;
        }

        if ($execute && $touchedcache) {
            rebuild_course_cache($courseid, true);
        }
        return $report;
    }

    /**
     * Build the per-course identity map published downstream to schools.
     *
     * Only stamped cms and their stamped 'mod' grade items are included; the
     * strict structural match on the school keys on (section number, module
     * type) plus the activity NAME as the in-bucket discriminator. Each module
     * carries its activity name and each grade item its itemname so the school
     * can pair by a stable identity instead of the fragile .mbz restore order
     * (ordinal), and flag rather than guess when the name is not 1:1 unique.
     * Manual grade items are intentionally not in the strict-match map (they
     * have no cm anchor); a course that needs their UUIDs back-stamped is
     * handled by a stamped-version republish.
     *
     * @param int $courseid Central course id.
     * @return array Map: centralcourseid, courseidnumber, modules[]:
     *         {section, modname, ordinal, name, cm_uuid,
     *          items[]:{itemnumber, gi_uuid, itemname}}.
     */
    public static function build_map(int $courseid): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], 'id, idnumber', MUST_EXIST);

        $modules = [];
        foreach (self::ordered_modules($courseid) as $m) {
            if ($m->idnumber === '') {
                // Unstamped cm: nothing stable to publish (should not occur after
                // stamp_course); skip rather than emit a keyless entry.
                continue;
            }
            $items = [];
            foreach (self::mod_grade_items($courseid, $m->modname, $m->instance) as $gi) {
                if ((string) $gi->idnumber === '') {
                    continue;
                }
                $items[] = [
                    'itemnumber' => (int) $gi->itemnumber,
                    'gi_uuid' => (string) $gi->idnumber,
                    // Discriminator: lets the school reject a diverged grade item
                    // rather than stamp it (checked only when both sides are set).
                    'itemname' => (string) $gi->itemname,
                ];
            }
            $modules[] = [
                'section' => $m->section,
                'modname' => $m->modname,
                // 'ordinal' is the .mbz restore order — kept for operator-facing
                // diagnostics only. The school pairs on 'name' (a stable
                // discriminator), never on ordinal, so a post-restore reorder or
                // same-type substitution cannot silently swap the stamping.
                'ordinal' => $m->ordinal,
                'name' => $m->name,
                'cm_uuid' => $m->idnumber,
                'items' => $items,
            ];
        }

        return [
            'centralcourseid' => $courseid,
            'courseidnumber' => (string) $course->idnumber,
            'modules' => $modules,
        ];
    }

    /**
     * Publish a course's identity map on the downstream outbox.
     *
     * Uses the course's own content partition and a stable 'identitymap:<id>'
     * entitykey, so it rides the same subscription and ordering as the course's
     * content and only reaches schools that hold the course.
     *
     * @param int $courseid Central course id.
     * @return int Outbox row id.
     */
    public static function publish_map(int $courseid): int {
        $map = self::build_map($courseid);
        return publisher::publish('identity_map', 'identitymap:' . $courseid, 'upsert', $map,
            'content:course:course:' . $courseid);
    }

    /**
     * A course's modules with a deterministic (section number, module name,
     * per-(section, modname) ordinal) coordinate, in display order.
     *
     * Shared by central map-build and the school structural match so both tiers
     * compute the identical coordinate for the same physical module (the school
     * copy restored from central's .mbz, which preserves section/module order).
     *
     * @param int $courseid Course id.
     * @return stdClass[] Each: ->cmid, ->instance, ->modname, ->section, ->ordinal,
     *         ->idnumber, ->name (the activity's display name, the structural
     *         match discriminator).
     */
    public static function ordered_modules(int $courseid): array {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid],
            'section ASC', 'id, section, sequence');
        $modnames = $DB->get_records_menu('modules', null, '', 'id, name');

        $result = [];
        $counters = [];
        foreach ($sections as $section) {
            $sectionnum = (int) $section->section;
            $sequence = array_filter(array_map('trim', explode(',', (string) $section->sequence)),
                static function ($v) {
                    return $v !== '';
                });
            foreach ($sequence as $cmid) {
                $cm = $DB->get_record('course_modules', ['id' => (int) $cmid, 'course' => $courseid],
                    'id, module, instance, idnumber');
                if (!$cm) {
                    continue;
                }
                $modname = $modnames[$cm->module] ?? null;
                if ($modname === null) {
                    continue;
                }
                $bucket = $sectionnum . '|' . $modname;
                $ordinal = $counters[$bucket] ?? 0;
                $counters[$bucket] = $ordinal + 1;

                $entry = new stdClass();
                $entry->cmid = (int) $cm->id;
                $entry->instance = (int) $cm->instance;
                $entry->modname = $modname;
                $entry->section = $sectionnum;
                $entry->ordinal = $ordinal;
                $entry->idnumber = (string) $cm->idnumber;
                // Every activity module's main table carries a 'name' column
                // (Moodle mod convention); it survives .mbz restore verbatim and
                // is the discriminator that makes the school-side pairing stable.
                $entry->name = (string) ($DB->get_field($modname, 'name', ['id' => (int) $cm->instance]) ?: '');
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * Leaf 'mod' grade items for one course module instance, ordered by itemnumber.
     *
     * @param int $courseid Course id.
     * @param string $modname Module name, e.g. 'assign'.
     * @param int $instance Module instance id.
     * @return stdClass[] Each: ->id, ->itemnumber, ->idnumber, ->itemname.
     */
    public static function mod_grade_items(int $courseid, string $modname, int $instance): array {
        global $DB;

        return $DB->get_records('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $instance,
        ], 'itemnumber ASC', 'id, itemnumber, idnumber, itemname');
    }

    /**
     * A course's stable identity string for UUID derivation: idnumber, or a
     * cid: fallback keyed on central's own stable course id.
     *
     * @param stdClass $course Course record with ->id and ->idnumber.
     * @return string
     */
    protected static function course_ident(stdClass $course): string {
        return ((string) $course->idnumber !== '') ? (string) $course->idnumber : 'cid:' . (int) $course->id;
    }

    /**
     * A deterministic sync-namespace UUID for a derivation name.
     *
     * @param string $name
     * @return string
     */
    protected static function ns_uuid(string $name): string {
        return fact_identity::uuid_v5(fact_identity::SYNC_NAMESPACE, $name);
    }

    /**
     * The set of non-empty idnumbers already used in a table for the given
     * conditions, as a lookup map (idnumber => true).
     *
     * @param string $table Table name.
     * @param array $conditions Field conditions.
     * @return array<string,bool>
     */
    protected static function used_idnumbers(string $table, array $conditions): array {
        global $DB;

        $used = [];
        foreach ($DB->get_records($table, $conditions, '', 'id, idnumber') as $row) {
            if ((string) $row->idnumber !== '') {
                $used[(string) $row->idnumber] = true;
            }
        }
        return $used;
    }

    /**
     * A per-row stamping report note.
     *
     * @param int $id Row id.
     * @param string $action 'stamp' or 'keep'.
     * @param string $idnumber The resulting idnumber.
     * @return stdClass
     */
    protected static function note(int $id, string $action, string $idnumber): stdClass {
        $note = new stdClass();
        $note->id = $id;
        $note->action = $action;
        $note->idnumber = $idnumber;
        return $note;
    }
}
