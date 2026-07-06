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
 * Learner-outcome migration across a course content version bump (step 7, §7).
 *
 * A content bump restores the new .mbz ALONGSIDE the existing copy (never in
 * place — Moodle has no safe in-place content merge). The old copy keeps ALL its
 * data; this class bridges the learner OUTCOMES that must appear in the new,
 * active copy — achieved gradebook grades and completion latches — matching
 * activities and grade items by the stable cm / grade-item UUID idnumber that
 * backup/restore preserves (§5 identity). Submissions and attempts stay in the
 * archived old copy for reference.
 *
 * Because the published .mbz carries no user data (backups exclude users), the
 * new copy has nothing to recompute a grade from, so every achieved finalgrade
 * is written as an OVERRIDDEN leaf grade on the new copy — terminal-state
 * preservation that "must never resurrect the clean-slate problem". A later
 * local re-grade (a teacher, or the seed handover once real module evidence
 * appears) can still override it; category/course totals recompute locally.
 *
 * All writes are echo-suppressed so migrating outcomes does not emit fresh
 * home-origin facts.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_migrator {

    /** @var string Import source recorded on migrated overridden grades. */
    const SOURCE = 'local_syncqueue';

    /**
     * Migrate achieved grades + completion latches from the old copy to the new.
     *
     * @param int $oldcourseid The superseded copy (data source).
     * @param int $newcourseid The freshly restored copy (data target).
     * @return array{grades:int, activitylatches:int, courselatches:int, unmatcheditems:int, unmatchedcms:int}
     */
    public static function migrate_by_uuid(int $oldcourseid, int $newcourseid): array {
        $grades = self::migrate_grades($oldcourseid, $newcourseid);
        $cms = self::migrate_activity_completion($oldcourseid, $newcourseid);
        $courselatches = self::migrate_course_completion($oldcourseid, $newcourseid);

        return [
            'grades' => $grades['migrated'],
            'unmatcheditems' => $grades['unmatched'],
            'activitylatches' => $cms['migrated'],
            'unmatchedcms' => $cms['unmatched'],
            'courselatches' => $courselatches,
        ];
    }

    /**
     * Migrate every achieved leaf-grade finalgrade old->new as an overridden grade,
     * matching grade items by their UUID idnumber.
     *
     * @param int $oldcourseid
     * @param int $newcourseid
     * @return array{migrated:int, unmatched:int}
     */
    protected static function migrate_grades(int $oldcourseid, int $newcourseid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        // New copy grade items keyed by UUID idnumber (mod leaf items only).
        $newbyuuid = self::uuid_grade_items($newcourseid);

        $migrated = 0;
        $unmatched = 0;
        capture::suppress(true);
        try {
            foreach (self::uuid_grade_items($oldcourseid) as $uuid => $olditem) {
                if (!isset($newbyuuid[$uuid])) {
                    $unmatched++;
                    continue;
                }
                $newitem = \grade_item::fetch(['id' => $newbyuuid[$uuid]->id]);
                if (!$newitem) {
                    $unmatched++;
                    continue;
                }
                // Every user with a real achieved finalgrade in the old copy.
                $grades = $DB->get_records_select('grade_grades',
                    'itemid = :itemid AND finalgrade IS NOT NULL',
                    ['itemid' => (int) $olditem->id], '', 'id, userid, finalgrade');
                foreach ($grades as $g) {
                    // Clamp to the NEW item's range: a bumped content version may have
                    // changed the item's grademax, so an unclamped old value could write
                    // an out-of-range override.
                    $value = (float) $g->finalgrade;
                    $value = max((float) $newitem->grademin, min((float) $newitem->grademax, $value));
                    $newitem->update_final_grade((int) $g->userid, $value, self::SOURCE);
                    $migrated++;
                }
            }
        } finally {
            capture::suppress(false);
        }
        return ['migrated' => $migrated, 'unmatched' => $unmatched];
    }

    /**
     * Migrate activity completion latches old->new, matching modules by cm UUID.
     *
     * @param int $oldcourseid
     * @param int $newcourseid
     * @return array{migrated:int, unmatched:int}
     */
    protected static function migrate_activity_completion(int $oldcourseid, int $newcourseid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $newcourse = $DB->get_record('course', ['id' => $newcourseid], '*', MUST_EXIST);
        if (!(int) $newcourse->enablecompletion) {
            return ['migrated' => 0, 'unmatched' => 0];
        }
        $newmodinfo = get_fast_modinfo($newcourse);
        $newcompletion = new \completion_info($newcourse);
        $newbyuuid = self::uuid_course_modules($newcourseid);

        $migrated = 0;
        $unmatched = 0;
        capture::suppress(true);
        try {
            foreach (self::uuid_course_modules($oldcourseid) as $uuid => $oldcm) {
                if (!isset($newbyuuid[$uuid])) {
                    $unmatched++;
                    continue;
                }
                $newcmid = (int) $newbyuuid[$uuid]->id;
                try {
                    $cm = $newmodinfo->get_cm($newcmid);
                } catch (\Throwable $e) {
                    $unmatched++;
                    continue;
                }
                // Users completed (any non-incomplete state) on the old module.
                $rows = $DB->get_records_select('course_modules_completion',
                    'coursemoduleid = :cmid AND completionstate <> :incomplete',
                    ['cmid' => (int) $oldcm->id, 'incomplete' => COMPLETION_INCOMPLETE], '', 'id, userid');
                foreach ($rows as $r) {
                    $newcompletion->update_state($cm, COMPLETION_COMPLETE, (int) $r->userid, true);
                    $done = $DB->get_record('course_modules_completion',
                        ['coursemoduleid' => $newcmid, 'userid' => (int) $r->userid]);
                    if ($done && $done->overrideby === null
                            && (int) $done->completionstate !== COMPLETION_INCOMPLETE) {
                        $done->overrideby = self::actor();
                        $done->timemodified = time();
                        $DB->update_record('course_modules_completion', $done);
                        \cache::make('core', 'completion')->delete((int) $r->userid . '_' . $newcourseid);
                    }
                    $migrated++;
                }
            }
        } finally {
            capture::suppress(false);
        }
        return ['migrated' => $migrated, 'unmatched' => $unmatched];
    }

    /**
     * Migrate course-completion latches old->new for every completed user.
     *
     * @param int $oldcourseid
     * @param int $newcourseid
     * @return int Latches applied.
     */
    protected static function migrate_course_completion(int $oldcourseid, int $newcourseid): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->dirroot . '/completion/completion_criteria_completion.php');

        $newcourse = $DB->get_record('course', ['id' => $newcourseid], '*', MUST_EXIST);
        if (!(int) $newcourse->enablecompletion) {
            return 0;
        }
        $completed = $DB->get_records_select('course_completions',
            'course = :course AND timecompleted IS NOT NULL',
            ['course' => $oldcourseid], '', 'id, userid, timecompleted');
        if (!$completed) {
            return 0;
        }

        $latched = 0;
        capture::suppress(true);
        try {
            $completion = new \completion_info($newcourse);
            $criteria = $completion->get_criteria();
            foreach ($completed as $c) {
                $time = (int) $c->timecompleted ?: time();
                foreach ($criteria as $criterion) {
                    $cc = new \completion_criteria_completion([
                        'course' => $newcourseid, 'userid' => (int) $c->userid, 'criteriaid' => (int) $criterion->id,
                    ]);
                    if (!$cc->is_complete()) {
                        $cc->mark_complete($time);
                    }
                }
                $ccompletion = new \completion_completion(['course' => $newcourseid, 'userid' => (int) $c->userid]);
                $ccompletion->mark_complete($time);
                $latched++;
            }
        } finally {
            capture::suppress(false);
        }
        return $latched;
    }

    /**
     * Mod leaf grade items of a course keyed by their UUID idnumber.
     *
     * @param int $courseid
     * @return array<string, \stdClass> uuid => grade_items row (id, idnumber)
     */
    protected static function uuid_grade_items(int $courseid): array {
        global $DB;
        $rows = $DB->get_records_select('grade_items',
            "courseid = :courseid AND itemtype = 'mod' AND idnumber IS NOT NULL AND idnumber <> ''",
            ['courseid' => $courseid], '', 'id, idnumber');
        $out = [];
        foreach ($rows as $r) {
            if (item_identity::is_uuid((string) $r->idnumber)) {
                $out[(string) $r->idnumber] = $r;
            }
        }
        return $out;
    }

    /**
     * Course modules of a course keyed by their UUID idnumber.
     *
     * @param int $courseid
     * @return array<string, \stdClass> uuid => course_modules row (id, idnumber)
     */
    protected static function uuid_course_modules(int $courseid): array {
        global $DB;
        $rows = $DB->get_records_select('course_modules',
            "course = :course AND idnumber IS NOT NULL AND idnumber <> ''",
            ['course' => $courseid], '', 'id, idnumber');
        $out = [];
        foreach ($rows as $r) {
            if (item_identity::is_uuid((string) $r->idnumber)) {
                $out[(string) $r->idnumber] = $r;
            }
        }
        return $out;
    }

    /**
     * The actor id stamped as completion overrideby (admin — a system migration).
     *
     * @return int
     */
    protected static function actor(): int {
        return (int) get_admin()->id;
    }
}
