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
use local_syncqueue\outbox\sequencer;
use stdClass;

/**
 * Central-side history down-sync producer (ELMS Sync v2 step 5, doc §8.3).
 *
 * When a learner's home changes to a school, central seeds the learner's TERMINAL
 * grades and completions down to that school so it never shows a clean slate
 * (§8.3 "the clean-slate fix", G2). The move is detected by the tenure producer
 * ({@see tenure::record_home}); this class turns it into a coalesced reseed job and,
 * at execution, regenerates the learner's current state from central's own
 * gradebook (§9.1 "regeneration yields current state, not history") and publishes
 * it as seed rows on a per-destination downstream partition.
 *
 * Two responsibilities:
 *  - enqueue(): coalesce to one pending job per (learner, school), flap-guarded so a
 *    learner bouncing between schools cannot storm the fleet with reseeds.
 *  - republish(): enumerate the learner's overridden leaf grades + completion latches
 *    and publish seed_grade / seed_completion rows targeted at the school. The school
 *    applies them as overridden (regrade-proof) grades under the Highest/max policy
 *    and hands over to local evidence ({@see \local_syncqueue\seed_applier},
 *    {@see \local_syncqueue\task\seed_handover}).
 *
 * Every method is table_exists-guarded so a partially-migrated or non-central site is
 * unaffected. Seeds are ordinary downstream publications (no fact identity): they are
 * central's report of terminal state, not upstream learner facts.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seed_publisher {

    /** @var string Coalescing reseed-job queue table. */
    const JOB_TABLE = 'local_syncqueue_seedjob';

    /** @var int Home changes per learner in a rolling 30 days beyond which reseed is quarantined (flap guard). */
    const MAX_MONTHLY_MOVES = 6;

    /** @var int The per-destination downstream seed partition prefix. */
    const SEED_PARTITION_PREFIX = 'seed:school:';

    /**
     * Coalesce a history-reseed job for a learner whose home just became $schoolid.
     *
     * One row per (sdms, schoolid): a repeat move just resets the existing row to
     * pending (attempts cleared) rather than piling up jobs, and the task regenerates
     * fresh state at execution — so a job that waited never seeds stale data. A learner
     * with more than MAX_MONTHLY_MOVES recorded home changes in the last 30 days is
     * QUARANTINED instead (mirrors the roster mass-delete guard): a flapping roster must
     * not storm the fleet with reseeds; an admin resolves quarantined jobs.
     *
     * Best-effort and never throws — it runs inside the tenure producer's lock during a
     * roster serve, and a reseed hiccup must never break roster delivery.
     *
     * @param string $sdms Learner SDMS code.
     * @param string $schoolid Destination school (the learner's new home).
     * @param int|null $rostergen Roster generation the home change was recorded at.
     */
    public static function enqueue(string $sdms, string $schoolid, ?int $rostergen): void {
        global $DB;

        try {
            $sdms = trim($sdms);
            if ($sdms === '' || $schoolid === '' || !self::table(self::JOB_TABLE)) {
                return;
            }

            $status = self::is_flapping($sdms) ? 'quarantined' : 'pending';
            if ($status === 'quarantined') {
                debugging("seed_publisher: quarantining reseed for flapping learner {$sdms} -> {$schoolid}",
                    DEBUG_DEVELOPER);
            }

            $now = time();
            $existing = $DB->get_record(self::JOB_TABLE, ['sdms' => $sdms, 'schoolid' => $schoolid]);
            if ($existing) {
                // Already quarantined stays quarantined until an admin clears it; do not
                // let a fresh flap silently re-open it as pending.
                if ((string) $existing->status === 'quarantined' && $status !== 'quarantined') {
                    return;
                }
                $existing->status = $status;
                $existing->rostergen = $rostergen;
                $existing->attempts = 0;
                $existing->lasterror = null;
                $existing->timemodified = $now;
                $DB->update_record(self::JOB_TABLE, $existing);
                return;
            }

            $row = new stdClass();
            $row->sdms = $sdms;
            $row->schoolid = $schoolid;
            $row->rostergen = $rostergen;
            $row->status = $status;
            $row->attempts = 0;
            $row->lasterror = null;
            $row->timecreated = $now;
            $row->timemodified = $now;
            $DB->insert_record(self::JOB_TABLE, $row);
        } catch (\Throwable $e) {
            debugging('seed_publisher::enqueue failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Whether a learner has changed home more than MAX_MONTHLY_MOVES times in 30 days.
     *
     * Counts tenure intervals opened for the learner in the window (each home change
     * opens exactly one), by the row's local capture clock — a single-machine
     * comparison (principle 2), not a cross-machine rostergen comparison.
     *
     * @param string $sdms Learner SDMS code.
     * @return bool
     */
    protected static function is_flapping(string $sdms): bool {
        global $DB;

        if (!self::table(tenure::TENURE_TABLE)) {
            return false;
        }
        $since = time() - (30 * DAYSECS);
        $moves = $DB->count_records_select(tenure::TENURE_TABLE,
            'sdms = :sdms AND timecreated > :since', ['sdms' => $sdms, 'since' => $since]);
        return $moves > self::MAX_MONTHLY_MOVES;
    }

    /**
     * Regenerate a learner's terminal grades + completions and publish them as seed
     * rows targeted at $schoolid. Returns the number of seed rows published.
     *
     * Reads central's OWN gradebook — overridden leaf grades (regrade-proof, the shape
     * central applies inbound facts as) and completion latches — so the seed is current
     * terminal state, independent of ingest payload retention (§9.1). Only items with a
     * stamped UUID idnumber are seeded: the identity must resolve structurally on the
     * destination (step-4 preflight stamps national courses). Category/course TOTALS are
     * never seeded (§8.2). Idempotent: re-running republishes the same entitykeys, and
     * unchanged rows are a no-op on the school under the entityversion/hash guard.
     *
     * @param string $sdms Learner SDMS code.
     * @param string $schoolid Destination school id.
     * @return int Seed rows published.
     */
    public static function republish(string $sdms, string $schoolid): int {
        global $DB;

        $sdms = trim($sdms);
        if ($sdms === '' || $schoolid === '') {
            return 0;
        }
        $userid = self::userid_for($sdms);
        if ($userid === null) {
            // Learner not linked on central: nothing to seed yet. Not an error — the
            // task settles the job done (a later move re-enqueues when links exist).
            return 0;
        }

        $partition = self::SEED_PARTITION_PREFIX . $schoolid;
        $count = 0;
        $count += self::publish_grades($userid, $sdms, $partition);
        $count += self::publish_activity_completions($userid, $sdms, $partition);
        $count += self::publish_course_completions($userid, $sdms, $partition);

        // Sequence the freshly-appended rows now so they are pullable immediately
        // (mirrors publish_school_state); the minute sequencer would also catch them.
        if ($count > 0) {
            sequencer::assign();
        }
        return $count;
    }

    /**
     * Publish the learner's overridden leaf-item grades as seed_grade rows.
     *
     * @param int $userid Central user id.
     * @param string $sdms Learner SDMS code.
     * @param string $partition Destination seed partition.
     * @return int Rows published.
     */
    protected static function publish_grades(int $userid, string $sdms, string $partition): int {
        global $DB;

        $sql = "SELECT gg.id, gg.finalgrade, gi.idnumber AS giidnumber, gi.itemtype, gi.itemname,
                       c.idnumber AS courseidnumber
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  JOIN {course} c ON c.id = gi.courseid
                 WHERE gg.userid = :userid
                   AND gg.overridden > 0
                   AND gg.finalgrade IS NOT NULL
                   AND gi.itemtype IN ('mod', 'manual')";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);

        $count = 0;
        foreach ($rows as $g) {
            $itemuuid = (string) $g->giidnumber;
            if (!item_identity::is_uuid($itemuuid)) {
                // No stamped identity: the destination can't resolve it structurally.
                continue;
            }
            $payload = [
                'sdms' => $sdms,
                'item_uuid' => $itemuuid,
                'course_idnumber' => (string) $g->courseidnumber,
                'finalgrade' => (float) $g->finalgrade,
                'itemtype' => (string) $g->itemtype,
                'itemname' => (string) $g->itemname,
            ];
            publisher::publish('seed_grade', 'seedgrade:' . $itemuuid . ':' . $sdms,
                'upsert', $payload, $partition);
            $count++;
        }
        return $count;
    }

    /**
     * Publish the learner's COMPLETE activity-completion latches as seed_completion rows.
     *
     * @param int $userid Central user id.
     * @param string $sdms Learner SDMS code.
     * @param string $partition Destination seed partition.
     * @return int Rows published.
     */
    protected static function publish_activity_completions(int $userid, string $sdms, string $partition): int {
        global $DB;

        // Only COMPLETE / COMPLETE_PASS latch (§8.2); INCOMPLETE/FAIL are not seeded.
        $sql = "SELECT cmc.id, cm.idnumber AS cmidnumber, c.idnumber AS courseidnumber
                  FROM {course_modules_completion} cmc
                  JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                  JOIN {course} c ON c.id = cm.course
                 WHERE cmc.userid = :userid
                   AND cmc.completionstate IN (1, 2)";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);

        $count = 0;
        foreach ($rows as $cmc) {
            $itemuuid = (string) $cmc->cmidnumber;
            if (!item_identity::is_uuid($itemuuid)) {
                continue;
            }
            $payload = [
                'sdms' => $sdms,
                'kind' => 'activity',
                'item_uuid' => $itemuuid,
                'course_idnumber' => (string) $cmc->courseidnumber,
            ];
            publisher::publish('seed_completion', 'seedcmp:' . $itemuuid . ':' . $sdms,
                'upsert', $payload, $partition);
            $count++;
        }
        return $count;
    }

    /**
     * Publish the learner's course completions as seed_completion (course) rows.
     *
     * @param int $userid Central user id.
     * @param string $sdms Learner SDMS code.
     * @param string $partition Destination seed partition.
     * @return int Rows published.
     */
    protected static function publish_course_completions(int $userid, string $sdms, string $partition): int {
        global $DB;

        $sql = "SELECT cc.id, c.idnumber AS courseidnumber
                  FROM {course_completions} cc
                  JOIN {course} c ON c.id = cc.course
                 WHERE cc.userid = :userid
                   AND cc.timecompleted IS NOT NULL";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);

        $count = 0;
        foreach ($rows as $cc) {
            $courseidn = (string) $cc->courseidnumber;
            if ($courseidn === '') {
                // No stable course identity to seed against.
                continue;
            }
            $payload = [
                'sdms' => $sdms,
                'kind' => 'course',
                'course_idnumber' => $courseidn,
            ];
            publisher::publish('seed_completion', 'seedcmp:course:' . $courseidn . ':' . $sdms,
                'upsert', $payload, $partition);
            $count++;
        }
        return $count;
    }

    /**
     * The central user id for an SDMS code, or null when unlinked/deleted.
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
