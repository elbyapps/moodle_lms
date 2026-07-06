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
 * Crash-safe course content (re-)restore orchestration (step 7, doc §7, school side).
 *
 * A course_content row whose contentversion advances past what is applied means
 * central published new content. Moodle has no safe in-place content merge, so the
 * new .mbz is restored ALONGSIDE the existing copy and the copies are swapped:
 *
 *   1. restorelog row written BEFORE the restore (crash-safety): a mid-restore crash
 *      leaves an identifiable corpse (its marker idnumber / recorded newlocalid) that
 *      the next apply deletes and retries — never an unidentifiable course a later
 *      resolution binds wrongly, and never a silent duplicate.
 *   2. restore the new copy, marker idnumber stamped IMMEDIATELY, hidden.
 *   3. bridge learner outcomes old->new by cm-UUID ({@see content_migrator}).
 *   4. retire the old copy: archived idnumber, disabled enrol instances, hidden, meta
 *      removed — enrolments and grades stay intact, the reconciler ignores it.
 *   5. promote the new copy to the canonical identity + mapping + enrichment.
 *   6. mark the restorelog done.
 *
 * The same path serves a FIRST restore (no old copy) so its crash-safety and identity
 * handling are identical — closing the pre-existing "restore crash leaves an
 * unmapped corpse, retry duplicates the course" hole.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_refresh {

    /** @var int Restore attempts before the row is dead-lettered. */
    const MAX_ATTEMPTS = 3;

    /** @var string Provisional idnumber prefix stamped on a restoring copy. */
    const MARKER_PREFIX = 'syncrestore:';

    /**
     * (Re-)restore a course's content to $contentversion, crash-safe.
     *
     * @param update_processor $proc Owns the restore/promote primitives.
     * @param stdClass $row The course_content outbox row.
     * @param array $payload Decoded payload (carries the backup block + metadata).
     * @param int|null $oldlocalid The present copy to supersede, or null for a first restore.
     * @param int $centralid Central course id.
     * @param int $contentversion Version being restored.
     * @return int The local course id now serving this course.
     */
    public static function apply(update_processor $proc, stdClass $row, array $payload,
            ?int $oldlocalid, int $centralid, int $contentversion): int {
        global $DB;

        $entitykey = 'course:' . $centralid;
        $marker = self::MARKER_PREFIX . $centralid . ':v' . $contentversion;

        // Serialize per course: the restore/migrate/retire/promote swap is multi-step
        // and NOT transactional, so two concurrent drivers (cron + a manual "sync now")
        // must never interleave on the same course (double restore, or one deleting the
        // other's in-flight corpse). Single-cron-task is the normal case; this closes
        // the non-cron path the review flagged.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_syncqueue');
        $lock = $lockfactory->get_lock('contentrefresh:' . $entitykey, 30);
        if (!$lock) {
            throw new \RuntimeException("content_refresh {$entitykey}: could not acquire lock");
        }

        try {
            $log = $DB->get_record('local_syncqueue_restorelog',
                ['entitykey' => $entitykey, 'contentversion' => $contentversion]);

            // Already applied (idempotent re-delivery).
            if ($log && $log->status === 'done') {
                return (int) ($log->newlocalid ?: ($oldlocalid ?: 0));
            }

            // A prior attempt for this exact version did not complete ('restoring' after
            // a crash, or 'failed' after the ceiling): delete its corpse and either retry
            // or, past the ceiling, dead-letter. 'failed' is handled here too so it stays
            // terminal (throws without re-restoring) instead of falling through and
            // leaking a fresh corpse on every DLQ retry.
            if ($log && $log->status !== 'done') {
                self::cleanup_corpse($log);
                if ((int) $log->attempts + 1 > self::MAX_ATTEMPTS) {
                    self::mark($log, 'failed', 'exceeded ' . self::MAX_ATTEMPTS . ' restore attempts');
                    throw new \RuntimeException("content_refresh {$entitykey} v{$contentversion}: "
                        . 'restore failed after ' . self::MAX_ATTEMPTS . ' attempts');
                }
                $log->attempts = (int) $log->attempts + 1;
                $log->oldlocalid = $oldlocalid;
                $log->newlocalid = null;
                $log->status = 'restoring';
                $log->error = null;
                $log->timemodified = time();
                $DB->update_record('local_syncqueue_restorelog', $log);
            } else if (!$log) {
                $log = (object) [
                    'entitykey' => $entitykey, 'centralcourseid' => $centralid,
                    'contentversion' => $contentversion, 'oldlocalid' => $oldlocalid, 'newlocalid' => null,
                    'marker' => $marker, 'status' => 'restoring', 'attempts' => 1, 'error' => null,
                    'timecreated' => time(), 'timemodified' => time(),
                ];
                $log->id = $DB->insert_record('local_syncqueue_restorelog', $log);
            }

            // Restore the new copy alongside (marker idnumber stamped immediately, hidden).
            $newid = $proc->restore_content_copy($payload, $centralid, $marker);
            if (!$newid) {
                self::mark($log, 'restoring', 'restore produced no course');
                throw new \RuntimeException("content_refresh {$entitykey} v{$contentversion}: "
                    . 'restore produced no course');
            }
            $log->newlocalid = $newid;
            $log->timemodified = time();
            $DB->update_record('local_syncqueue_restorelog', $log);

            // Bridge learner outcomes from the old copy, then retire it.
            if ($oldlocalid && (int) $oldlocalid !== (int) $newid
                    && $DB->record_exists('course', ['id' => $oldlocalid])) {
                content_migrator::migrate_by_uuid((int) $oldlocalid, $newid);
                $oldcv = applied_state::get_contentversion('course_content', 'coursecontent:' . $centralid);
                self::retire_old((int) $oldlocalid, $oldcv);
            }

            // Promote the new copy to the canonical identity + mapping + enrichment.
            $proc->promote_content_copy($newid, $payload, $centralid, $entitykey);

            self::mark($log, 'done', null);

            // House-keep + heal supersession: an earlier version's un-promoted corpse
            // (a crash between restore and promote) is never re-driven once a higher
            // version applies (the DLQ marks it 'replayed'), so its cleanup would never
            // run — sweep those corpses here, and drop the now-historical rows so the
            // log stays ~one row per course.
            self::sweep_superseded($entitykey, $contentversion);

            return $newid;
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete un-promoted marker corpses left by lower-version attempts and drop the
     * now-historical restorelog rows for a course, once a newer version is 'done'.
     *
     * @param string $entitykey
     * @param int $donecontentversion The version that just completed.
     */
    protected static function sweep_superseded(string $entitykey, int $donecontentversion): void {
        global $DB;

        $stale = $DB->get_records_select('local_syncqueue_restorelog',
            'entitykey = :ek AND contentversion < :cv', ['ek' => $entitykey, 'cv' => $donecontentversion]);
        foreach ($stale as $s) {
            if ($s->status !== 'done') {
                self::cleanup_corpse($s);
            }
            $DB->delete_records('local_syncqueue_restorelog', ['id' => $s->id]);
        }
    }

    /**
     * Retire a superseded course copy: rewrite its idnumber to an archived token,
     * disable the enrol_cohort instances it holds (grades/enrolments preserved),
     * hide it, and drop its enrichment meta so the reconciler ignores it.
     *
     * @param int $oldlocalid
     * @param int $oldcontentversion The version the old copy was, for the token.
     */
    protected static function retire_old(int $oldlocalid, int $oldcontentversion): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $course = $DB->get_record('course', ['id' => $oldlocalid]);
        if (!$course) {
            return;
        }

        // Disable ONLY the cohort-sync enrol instances this integration owns (never
        // delete — SUSPENDNOROLES keeps the enrolments and grades; a disabled
        // instance just stops syncing). Match the reconciler's ownership marker
        // (name = 'TDMP auto cohort sync', i.e.
        // local_elby_dashboard\desired_state_reconciler::INSTANCE_NAME) so an admin's
        // own cohort enrolment on this archived copy is left untouched.
        $plugin = enrol_get_plugin('cohort');
        if ($plugin) {
            $owned = $DB->get_records('enrol',
                ['courseid' => $oldlocalid, 'enrol' => 'cohort', 'name' => 'TDMP auto cohort sync']);
            foreach ($owned as $instance) {
                if ((int) $instance->status === ENROL_INSTANCE_ENABLED) {
                    $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
                }
            }
        }

        // Archive the idnumber so it no longer resolves the entitykey, and the new
        // copy can take the canonical one. Idempotent (don't double-suffix).
        $archived = (string) $course->idnumber;
        if (strpos($archived, '#archived-v') === false) {
            $archived = ($archived !== '' ? $archived : 'central_' . (int) $course->id)
                . '#archived-v' . max(0, $oldcontentversion);
        }
        $update = new stdClass();
        $update->id = $oldlocalid;
        $update->idnumber = $archived;
        $update->visible = 0;
        update_course($update);

        // Drop the enrichment meta (no archived flag in the schema) so the
        // desired-state reconciler stops managing this hidden copy.
        if ($DB->get_manager()->table_exists('elby_course_meta')) {
            $DB->delete_records('elby_course_meta', ['courseid' => $oldlocalid]);
        }
    }

    /**
     * Delete the corpse a crashed prior attempt left, if it is still present and
     * still wears the restore marker (never delete a promoted/canonical course).
     *
     * @param stdClass $log restorelog row.
     */
    protected static function cleanup_corpse(stdClass $log): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $newid = (int) ($log->newlocalid ?? 0);
        if (!$newid) {
            // Restore never returned an id (crash before it was recorded); try the
            // marker idnumber, which is stamped immediately after the course appears.
            $corpse = $DB->get_record('course', ['idnumber' => (string) $log->marker]);
            $newid = $corpse ? (int) $corpse->id : 0;
        }
        if (!$newid) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $newid]);
        // Guard: only delete while it still wears our marker (an unpromoted corpse).
        if ($course && (string) $course->idnumber === (string) $log->marker) {
            delete_course($newid, false);
        }
    }

    /**
     * Update a restorelog row's status/error.
     *
     * @param stdClass $log
     * @param string $status
     * @param string|null $error
     */
    protected static function mark(stdClass $log, string $status, ?string $error): void {
        global $DB;
        $log->status = $status;
        $log->error = $error;
        $log->timemodified = time();
        $DB->update_record('local_syncqueue_restorelog', $log);
    }
}
