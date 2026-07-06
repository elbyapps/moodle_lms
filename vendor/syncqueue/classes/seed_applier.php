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
 * School-side seed applier (ELMS Sync v2 step 5, doc §8.3).
 *
 * Applies the history central seeds DOWN when a learner arrives at a school. This is
 * the FIRST code on the downstream path to write grades/completions — the update
 * processor delegates its new seed_grade / seed_completion cases here.
 *
 * Grades land as OVERRIDDEN leaf-item finalgrades (regrade-proof, exactly central's
 * shape) at the Highest/max of seeded-vs-existing (G3 move-back), so an arriving
 * learner immediately shows their prior record and a course they already finished
 * reads complete (G2). The write is echo-suppressed so the grade/completion events it
 * fires are not re-captured as fresh home-origin facts (§8.2), and its provenance is
 * recorded in local_syncqueue_seed so the handover releaser
 * ({@see \local_syncqueue\task\seed_handover}) can later release the override to local
 * evidence (G4).
 *
 * Handover-aware on entry: if the learner already holds NATIVE local evidence for an
 * item (a real rawgrade), local authority owns it and no seed override is written —
 * provenance is recorded 'released'. A seed for a course the school does not have is a
 * benign no-op (it stays central-side for reporting, §8.3); a seed whose learner/item
 * is merely not resolved YET raises dependency_missing so the pull loop retries without
 * burning its budget.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seed_applier {

    /**
     * @var string Source label passed to the grade write for event/audit provenance.
     * NOTE: grade_grades has no persisted source column — this only tags the
     * grade_updated event. The discriminator "is this override a seed?" is the
     * local_syncqueue_seed provenance row + its recorded value, not this string.
     */
    const SEED_SOURCE = 'local_syncqueue_seed';

    /** @var string Seed provenance table. */
    const SEED_TABLE = 'local_syncqueue_seed';

    /**
     * Apply a seed_grade row: an overridden leaf finalgrade = max(seeded, existing).
     *
     * @param array $payload {sdms, item_uuid, course_idnumber, finalgrade, itemtype, itemname}
     * @return int|null Local grade_item id, or null when this is not the school's item (benign no-op).
     * @throws dependency_missing_exception When the learner/item is not resolvable YET (retry).
     */
    public static function apply_grade(array $payload): ?int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');

        $sdms = trim((string) ($payload['sdms'] ?? ''));
        $itemuuid = trim((string) ($payload['item_uuid'] ?? ''));
        $courseidn = trim((string) ($payload['course_idnumber'] ?? ''));
        $seeded = isset($payload['finalgrade']) ? (float) $payload['finalgrade'] : null;
        if ($sdms === '' || $itemuuid === '' || $seeded === null) {
            return null;
        }

        $userid = self::userid_for($sdms);
        if ($userid === null) {
            throw new dependency_missing_exception("seed_grade: learner {$sdms} is not linked locally yet");
        }

        $gi = $DB->get_record('grade_items', ['idnumber' => $itemuuid]);
        if (!$gi) {
            // The learner is linked but this item is absent locally: the school's copy
            // of the course predates it (in-place content refresh is step 7), and it may
            // never appear. Benign no-op — the grade stays central-side for reporting
            // (§8.3), NOT a dependency_missing that would retry every run forever.
            debugging("seed_grade: item {$itemuuid} absent locally; left central-side", DEBUG_DEVELOPER);
            return null;
        }

        $existing = $DB->get_record('grade_grades', ['itemid' => $gi->id, 'userid' => $userid]);

        // Handover-aware: native local evidence (a real rawgrade) means local authority
        // already owns the item — never seed an override over it. Record provenance as
        // released so the handover releaser leaves it alone.
        if ($existing && $existing->rawgrade !== null) {
            self::record_provenance($sdms, $itemuuid, 'grade', $seeded, (int) $gi->id, 'released');
            return (int) $gi->id;
        }

        $existingfinal = ($existing && $existing->finalgrade !== null) ? (float) $existing->finalgrade : null;

        // A human/foreign override wins (§8.3, G5). grade_grades has no source column, so
        // our own seed provenance is the discriminator: an override is OURS only if a
        // seeded provenance row holds this exact value. Any other existing override — a
        // teacher's participation mark (rawgrade null), or a human edit that drifted our
        // seed — must be respected, never raised or lowered under max-policy, and never
        // mis-tagged 'seeded' (which the handover could later release).
        $prov = $DB->get_record(self::SEED_TABLE,
            ['schoolid' => self::schoolid(), 'sdms' => $sdms, 'itemuuid' => $itemuuid]);
        $ourseed = $prov && (string) $prov->status === 'seeded' && $prov->seededvalue !== null
            && $existingfinal !== null && !grade_floats_different($existingfinal, (float) $prov->seededvalue);
        if ($existing && (int) $existing->overridden > 0 && !$ourseed) {
            self::record_provenance($sdms, $itemuuid, 'grade', $seeded, (int) $gi->id, 'released');
            return (int) $gi->id;
        }

        $newvalue = ($existingfinal !== null) ? max($existingfinal, $seeded) : $seeded;

        // Idempotent: already overridden at (at least) the target value — no write.
        if ($existing && (int) $existing->overridden > 0 && $existingfinal !== null
                && !grade_floats_different($existingfinal, $newvalue)) {
            self::record_provenance($sdms, $itemuuid, 'grade', $newvalue, (int) $gi->id, 'seeded');
            return (int) $gi->id;
        }

        $item = \grade_item::fetch(['id' => $gi->id]);
        capture::suppress(true);
        try {
            $item->update_final_grade($userid, $newvalue, self::SEED_SOURCE);
        } finally {
            capture::suppress(false);
        }
        self::record_provenance($sdms, $itemuuid, 'grade', $newvalue, (int) $gi->id, 'seeded');
        return (int) $gi->id;
    }

    /**
     * Apply a seed_completion row as a completion latch (activity or course).
     *
     * @param array $payload {sdms, kind: activity|course, item_uuid?, course_idnumber}
     * @return int|null Local completion row id, or null for a benign no-op.
     * @throws dependency_missing_exception When the learner/module is not resolvable YET.
     */
    public static function apply_completion(array $payload): ?int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $sdms = trim((string) ($payload['sdms'] ?? ''));
        $kind = (string) ($payload['kind'] ?? '');
        if ($sdms === '') {
            return null;
        }
        $userid = self::userid_for($sdms);
        if ($userid === null) {
            throw new dependency_missing_exception("seed_completion: learner {$sdms} is not linked locally yet");
        }

        if ($kind === 'activity') {
            $itemuuid = trim((string) ($payload['item_uuid'] ?? ''));
            $courseidn = trim((string) ($payload['course_idnumber'] ?? ''));
            if ($itemuuid === '') {
                return null;
            }
            $cm = $DB->get_record('course_modules', ['idnumber' => $itemuuid]);
            if (!$cm) {
                // Module absent locally (course version skew, refresh is step 7): benign
                // no-op, not a forever-retry — the completion stays central-side.
                debugging("seed_completion: module {$itemuuid} absent locally; left central-side", DEBUG_DEVELOPER);
                return null;
            }
            return self::latch_activity($cm, $userid, $sdms, $itemuuid);
        }

        if ($kind === 'course') {
            $courseidn = trim((string) ($payload['course_idnumber'] ?? ''));
            if ($courseidn === '') {
                return null;
            }
            $course = $DB->get_record('course', ['idnumber' => $courseidn]);
            if (!$course) {
                return null;
            }
            return self::latch_course($course, $userid, $sdms, 'course:' . $courseidn);
        }
        return null;
    }

    /**
     * Latch an activity completion to COMPLETE (mirrors central's latch, echo-suppressed).
     *
     * @param stdClass $cmrow course_modules row.
     * @param int $userid Local user id.
     * @param string $sdms Learner SDMS code.
     * @param string $itemuuid cm UUID (provenance key).
     * @return int completion row id.
     */
    protected static function latch_activity(stdClass $cmrow, int $userid, string $sdms, string $itemuuid): int {
        global $DB;

        $course = $DB->get_record('course', ['id' => $cmrow->course], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm((int) $cmrow->id);
        $completion = new \completion_info($course);

        capture::suppress(true);
        try {
            $completion->update_state($cm, COMPLETION_COMPLETE, $userid, true);
            // update_state early-returns when already at the target state, leaving
            // overrideby unset — stamp it directly so the latch survives a later reset.
            $row = $DB->get_record('course_modules_completion',
                ['coursemoduleid' => $cm->id, 'userid' => $userid]);
            if ($row && $row->overrideby === null && (int) $row->completionstate !== COMPLETION_INCOMPLETE) {
                $row->overrideby = self::actor();
                $row->timemodified = time();
                $DB->update_record('course_modules_completion', $row);
                \cache::make('core', 'completion')->delete($userid . '_' . $course->id);
            }
        } finally {
            capture::suppress(false);
        }

        self::record_provenance($sdms, $itemuuid, 'activity', null, (int) $cm->id, 'seeded');
        $final = $DB->get_record('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $userid]);
        return $final ? (int) $final->id : 0;
    }

    /**
     * Latch a course completion (mark_complete + criteria; mirrors central, echo-suppressed).
     *
     * @param stdClass $course course row.
     * @param int $userid Local user id.
     * @param string $sdms Learner SDMS code.
     * @param string $itemuuid Provenance key ('course:<idnumber>').
     * @return int course_completions row id.
     */
    protected static function latch_course(stdClass $course, int $userid, string $sdms, string $itemuuid): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->dirroot . '/completion/completion_criteria_completion.php');

        $time = time();
        capture::suppress(true);
        try {
            $completion = new \completion_info($course);
            foreach ($completion->get_criteria() as $criterion) {
                $cc = new \completion_criteria_completion([
                    'course' => (int) $course->id,
                    'userid' => $userid,
                    'criteriaid' => (int) $criterion->id,
                ]);
                if (!$cc->is_complete()) {
                    $cc->mark_complete($time);
                }
            }
            $ccompletion = new \completion_completion(['course' => (int) $course->id, 'userid' => $userid]);
            $ccompletion->mark_complete($time);
        } finally {
            capture::suppress(false);
        }

        self::record_provenance($sdms, $itemuuid, 'course', null, (int) $course->id, 'seeded');
        $final = $DB->get_record('course_completions', ['course' => $course->id, 'userid' => $userid]);
        return $final ? (int) $final->id : 0;
    }

    /**
     * Upsert a seed provenance row (one per school+learner+item).
     *
     * @param string $sdms Learner SDMS code.
     * @param string $itemuuid Item UUID / course key.
     * @param string $itemtype grade|activity|course.
     * @param float|null $seededvalue Seeded finalgrade (null for completions).
     * @param int|null $localitemid Resolved local grade_item / cm / course id.
     * @param string $status seeded|released.
     */
    protected static function record_provenance(string $sdms, string $itemuuid, string $itemtype,
            ?float $seededvalue, ?int $localitemid, string $status): void {
        global $DB;

        if (!self::table(self::SEED_TABLE)) {
            return;
        }
        $schoolid = self::schoolid();
        $now = time();
        $existing = $DB->get_record(self::SEED_TABLE,
            ['schoolid' => $schoolid, 'sdms' => $sdms, 'itemuuid' => $itemuuid]);
        if ($existing) {
            $existing->itemtype = $itemtype;
            $existing->seededvalue = $seededvalue;
            $existing->localitemid = $localitemid;
            $existing->status = $status;
            $existing->timemodified = $now;
            $DB->update_record(self::SEED_TABLE, $existing);
            return;
        }
        $row = new stdClass();
        $row->schoolid = $schoolid;
        $row->sdms = $sdms;
        $row->itemuuid = $itemuuid;
        $row->itemtype = $itemtype;
        $row->seededvalue = $seededvalue;
        $row->localitemid = $localitemid;
        $row->status = $status;
        $row->timecreated = $now;
        $row->timemodified = $now;
        $DB->insert_record(self::SEED_TABLE, $row);
    }

    /**
     * The local user id for an SDMS code, or null when unlinked/deleted.
     *
     * @param string $sdms Learner SDMS code.
     * @return int|null
     */
    protected static function userid_for(string $sdms): ?int {
        global $DB;

        if (!self::table('elby_sdms_users')) {
            return null;
        }
        $userid = $DB->get_field('elby_sdms_users', 'userid', ['sdms_id' => $sdms]);
        if (!$userid) {
            return null;
        }
        return $DB->record_exists('user', ['id' => $userid, 'deleted' => 0]) ? (int) $userid : null;
    }

    /**
     * This school's id (self).
     *
     * @return string
     */
    protected static function schoolid(): string {
        return get_config('local_syncqueue', 'schoolid') ?: 'unknown';
    }

    /**
     * A valid actor user id for the completion override marker.
     *
     * @return int
     */
    protected static function actor(): int {
        global $USER;
        return !empty($USER->id) ? (int) $USER->id : (int) get_admin()->id;
    }

    /**
     * Whether a table is installed (dual-stack guard).
     *
     * @param string $table Table name.
     * @return bool
     */
    protected static function table(string $table): bool {
        global $DB;
        return $DB->get_manager()->table_exists($table);
    }
}
