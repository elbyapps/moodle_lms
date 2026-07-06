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

namespace local_syncqueue\task;

use core\task\scheduled_task;
use local_syncqueue\capture;
use local_syncqueue\seed_applier;
use stdClass;

/**
 * School-side deterministic handover of seeded grades (ELMS Sync v2 step 5, doc §8.3).
 *
 * A seeded grade is an override that protects the arriving learner's record. This task
 * releases it item-by-item to LOCAL authority when the release is safe — and only then:
 *
 *  - Human edit wins: the grade is now overridden by a non-seed source (a teacher edited
 *    it). The human value stands; provenance is marked released and never touched again.
 *  - Local module evidence that meets or beats the record: the learner has a real
 *    rawgrade whose final-scale value L >= the seeded value. Local has genuinely caught
 *    up, so the override is cleared and the item regraded from rawgrade (local owns it);
 *    provenance released. On release finalgrade = max(seeded, L) = L.
 *  - A casual re-take BELOW the record (L < seeded): nothing happens — the seeded
 *    override stands, so a 40% re-take can never erase an 85% record (§8.3). No write, no
 *    churn; a later higher attempt re-evaluates and may then release.
 *
 * Only GRADE seeds hand over — a completion latch is binary and a local completion of the
 * same activity is consistent with it, so it stays latched. School mode only; a no-op
 * when nothing was seeded. Writes are echo-suppressed so the regrade events they fire are
 * not re-captured as fresh facts.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seed_handover extends scheduled_task {

    /** @var int Seeded items examined per run. */
    const BATCH_LIMIT = 500;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_seedhandover', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB, $CFG;

        if (!get_config('local_syncqueue', 'enabled')
                || get_config('local_syncqueue', 'mode') !== 'school'
                || !$DB->get_manager()->table_exists(seed_applier::SEED_TABLE)) {
            return;
        }
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $rows = $DB->get_records('local_syncqueue_seed',
            ['itemtype' => 'grade', 'status' => 'seeded'], 'id ASC', '*', 0, self::BATCH_LIMIT);
        if (empty($rows)) {
            return;
        }

        $released = 0;
        foreach ($rows as $seed) {
            try {
                if ($this->maybe_release($seed)) {
                    $released++;
                }
            } catch (\Throwable $e) {
                debugging('seed_handover: ' . $seed->itemuuid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        if ($released > 0) {
            mtrace('seed_handover: released ' . $released . ' of ' . count($rows) . ' seeded grade(s)');
        }
    }

    /**
     * Evaluate one seeded grade and release it if safe. Returns whether it released.
     *
     * @param stdClass $seed Seed provenance row.
     * @return bool
     */
    protected function maybe_release(stdClass $seed): bool {
        global $DB;

        $itemid = (int) $seed->localitemid;
        if (!$itemid) {
            return false;
        }
        $userid = $DB->get_field('elby_sdms_users', 'userid', ['sdms_id' => $seed->sdms]);
        if (!$userid) {
            return false;
        }
        $item = \grade_item::fetch(['id' => $itemid]);
        if (!$item) {
            // The item is gone; the seed no longer applies.
            $this->mark_released($seed);
            return true;
        }
        $gg = $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $userid]);
        if (!$gg) {
            $this->mark_released($seed);
            return true;
        }

        $applied = ($seed->seededvalue !== null) ? (float) $seed->seededvalue : null;

        // Someone cleared the override -> local authority already owns the item -> release.
        if ((int) $gg->overridden === 0) {
            $this->mark_released($seed);
            return true;
        }

        // Human edit wins: the override value drifted from what we seeded (a teacher set a
        // different grade). grade_grades has no persisted source column, so the recorded
        // seed value — not a source string — is the discriminator between our override and
        // a human one.
        if ($applied !== null && grade_floats_different((float) $gg->finalgrade, $applied)) {
            $this->mark_released($seed);
            return true;
        }

        // No native local evidence yet -> keep protecting the record, no release.
        if ($gg->rawgrade === null) {
            return false;
        }

        // A degenerate raw range (min == max, or null bounds cast to 0) makes core's
        // standardise_score return grademax, which for a wide-range item reads as "local
        // beat the record" and would wrongly release + regrade the record away. We can't
        // compare on a degenerate scale, so keep protecting the record (favouring no data
        // loss); a human edit can still release it.
        if ((float) $gg->rawgrademax <= (float) $gg->rawgrademin) {
            return false;
        }

        // Local module evidence exists. Compute its final-scale value and release only
        // when it meets or beats the seeded record (else the record stands, no churn).
        $localfinal = \grade_grade::standardise_score((float) $gg->rawgrade,
            (float) $gg->rawgrademin, (float) $gg->rawgrademax,
            (float) $item->grademin, (float) $item->grademax);
        if ($applied !== null && grade_floats_different($localfinal, $applied) && $localfinal < $applied) {
            // Casual re-take below the record: leave the seeded override in place.
            return false;
        }

        // Release to local: clear the override and regrade from rawgrade.
        capture::suppress(true);
        try {
            $grade = new \grade_grade(['itemid' => $itemid, 'userid' => $userid], true);
            if ($grade && $grade->id) {
                $grade->set_overridden(false, true);
            }
            $item->force_regrading();
            grade_regrade_final_grades((int) $item->courseid, (int) $userid, $item);
        } finally {
            capture::suppress(false);
        }
        $this->mark_released($seed);
        return true;
    }

    /**
     * Mark a seed provenance row released.
     *
     * @param stdClass $seed Seed provenance row.
     */
    protected function mark_released(stdClass $seed): void {
        global $DB;

        $update = new stdClass();
        $update->id = (int) $seed->id;
        $update->status = 'released';
        $update->timemodified = time();
        $DB->update_record('local_syncqueue_seed', $update);
    }
}
