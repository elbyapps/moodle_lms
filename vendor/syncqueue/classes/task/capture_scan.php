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
use local_syncqueue\fact_ledger;

/**
 * School-side capture-scan (ELMS Sync v2 step 6, doc §9 / §9.1).
 *
 * The one blind spot a digest cannot see is a fact that was never CAPTURED — an event
 * that never fired (a crash mid-transaction, push_v2 toggled off when a grade was set,
 * an observer gap). Weekly, this scans the school's source tables for learner facts with
 * no fact-ledger row and regenerates them: it reconstructs the EXACT Moodle event from
 * the source row via core's own factory and feeds it through the normal {@see capture}
 * path, so the regenerated fact is byte-identical to an event-captured one. Even where a
 * payload could differ, the deterministic factuuid = UUIDv5(lineage ∥ factversion) is a
 * pure function of the source-derived natural key + version, so a regenerated fact
 * dedups exactly against whatever central still holds — it can never fork.
 *
 * It also surfaces the §9.1 local-loss case: a ledger fact that was never exported whose
 * source row is now gone is a genuine local loss (its terminal state can no longer be
 * regenerated), reported explicitly rather than silently dropped.
 *
 * v1 covers GRADES (the primary learner fact) for learners STILL on this school's
 * roster: capture stamps the CURRENT roster generation, which is valid only while the
 * learner is home here, so a departed learner's uncaptured facts are DEFERRED pending
 * §9.1 authoring-time bracketing (a follow-up) rather than mis-stamped and rejected.
 * Completions / submissions / quiz attempts are also follow-ups. School mode + push_v2.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capture_scan extends scheduled_task {

    /** @var int Source rows examined per run. */
    const BATCH_LIMIT = 500;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_capturescan', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (get_config('local_syncqueue', 'mode') !== 'school'
                || !get_config('local_syncqueue', 'enabled')
                || !get_config('local_syncqueue', 'push_v2')) {
            return;
        }

        $captured = $this->scan_uncaptured_grades();
        $losses = $this->scan_local_losses();

        mtrace('capture_scan: regenerated ' . $captured . ' never-captured grade fact(s); '
            . $losses . ' unexported-and-source-gone local-loss finding(s)');
    }

    /**
     * Regenerate never-captured grade facts by reconstructing their user_graded event.
     *
     * A single LEFT JOIN finds SDMS-linked learners' leaf grades (mod/manual, non-null
     * finalgrade) with NO fact-ledger row — i.e. never captured. For each, core's
     * user_graded::create_from_grade rebuilds the exact event and the normal capture path
     * records the ledger + appends the outbox row (idempotent: a second run finds the
     * ledger row and skips it, so nothing double-captures).
     *
     * @return int Facts newly captured.
     */
    protected function scan_uncaptured_grades(): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        if (!$DB->get_manager()->table_exists('elby_sdms_users')
                || !$DB->get_manager()->table_exists('local_syncqueue_ledger')) {
            return 0;
        }

        // Only regenerate for learners STILL on this school's roster. capture stamps the
        // CURRENT roster generation, which is correct while the learner is home here (the
        // school's open tenure interval covers it) but WRONG for a departed learner — the
        // stamp would land past central's closed interval and be tenure-rejected under
        // enforcement. Departed-learner facts need §9.1 authoring-time bracketing (a
        // follow-up); until then they are deferred, never mis-stamped. Also skip an
        // empty SDMS (capture would reject it) so such rows can't starve the batch.
        $rosterjoin = $DB->get_manager()->table_exists('elby_roster')
            ? 'AND EXISTS (SELECT 1 FROM {elby_roster} r WHERE r.sdms_id = esu.sdms_id)' : '';
        $sql = "SELECT gg.id
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  JOIN {elby_sdms_users} esu ON esu.userid = gg.userid
             LEFT JOIN {local_syncqueue_ledger} l
                    ON l.sourcetable = :st AND l.sourceid = gg.id
                 WHERE gg.finalgrade IS NOT NULL
                   AND gi.itemtype IN ('mod', 'manual')
                   AND esu.sdms_id <> :empty
                   AND l.id IS NULL
                   $rosterjoin
              ORDER BY gg.id ASC";
        $ids = $DB->get_fieldset_sql($sql, ['st' => 'grade_grades', 'empty' => ''], 0, self::BATCH_LIMIT);
        if (empty($ids)) {
            return 0;
        }

        $captured = 0;
        foreach ($ids as $ggid) {
            try {
                // Reconstruct the exact event core would have fired and capture it through
                // the unchanged event path (identical identity). Non-null = captured to v2
                // (a fresh outbox row id, or 0 for an idempotent no-op); null = not
                // v2-eligible (unlinked / source gone).
                if (capture::regenerate_grade((int) $ggid) !== null) {
                    $captured++;
                }
            } catch (\Throwable $e) {
                debugging('capture_scan: grade ' . $ggid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $captured;
    }

    /**
     * Count (and log) unexported facts whose source row is gone — genuine local losses.
     *
     * A ledger fact still CAPTURED (never exported, so never pushed) whose grade_grades
     * source row no longer exists cannot have its terminal state regenerated OR pushed:
     * §9.1 requires this be surfaced, never silently dropped. Only status = CAPTURED
     * qualifies — an EXPORTED/ACKED fact WAS pushed (central holds it), so a later source
     * deletion is not a loss, and matching those would swamp the signal with false alarms
     * (ledger rows persist after ack).
     *
     * @return int Local-loss findings.
     */
    protected function scan_local_losses(): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_syncqueue_ledger')) {
            return 0;
        }
        $rows = $DB->get_records_select('local_syncqueue_ledger',
            'sourcetable = :st AND status = :captured',
            ['st' => 'grade_grades', 'captured' => fact_ledger::STATUS_CAPTURED],
            'id ASC', 'id, sourceid, lineageuuid, status', 0, self::BATCH_LIMIT);

        $losses = 0;
        foreach ($rows as $r) {
            if ($r->sourceid === null) {
                continue;
            }
            if (!$DB->record_exists('grade_grades', ['id' => (int) $r->sourceid])) {
                debugging('capture_scan: LOCAL LOSS — unexported fact for deleted grade_grades '
                    . $r->sourceid . ' (lineage ' . $r->lineageuuid . ', status ' . $r->status . ')',
                    DEBUG_DEVELOPER);
                $losses++;
            }
        }
        return $losses;
    }
}
