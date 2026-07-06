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

use core\event\base;

/**
 * Event observer for capturing Moodle events and queuing them for sync.
 *
 * Learner-fact observers are registered internal => true (db/events.php) so that
 * when push_v2 is on, capture commits atomically with the business write. Each
 * such handler routes to \local_syncqueue\capture (v2) when push_v2 is on and
 * the learner is SDMS-linked, and otherwise falls back to the legacy queue — so
 * unmigrated schools (push_v2=0) and unlinked users behave exactly as before.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Handle user graded event.
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event): void {
        self::route($event, 'grade', 'grade_grades', 'grade', 1); // Highest priority.
    }

    /**
     * Handle assignment submission created.
     *
     * @param \mod_assign\event\submission_created $event
     */
    public static function submission_created(\mod_assign\event\submission_created $event): void {
        self::route($event, 'submission', 'assign_submission', 'submission', 2);
    }

    /**
     * Handle assignment submission updated.
     *
     * @param \mod_assign\event\submission_updated $event
     */
    public static function submission_updated(\mod_assign\event\submission_updated $event): void {
        self::route($event, 'submission', 'assign_submission', 'submission', 2);
    }

    /**
     * Handle file submission created.
     *
     * @param \assignsubmission_file\event\submission_created $event
     */
    public static function file_submission_created(\assignsubmission_file\event\submission_created $event): void {
        self::route_with_files($event, 'submission', 'assign_submission', 'submission', 2);
    }

    /**
     * Handle file submission updated.
     *
     * @param \assignsubmission_file\event\submission_updated $event
     */
    public static function file_submission_updated(\assignsubmission_file\event\submission_updated $event): void {
        self::route_with_files($event, 'submission', 'assign_submission', 'submission', 2);
    }

    /**
     * Handle quiz attempt submitted.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        self::route($event, 'quiz_attempt', 'quiz_attempts', 'quiz', 1); // High priority.
    }

    /**
     * Handle forum post created.
     *
     * @param \mod_forum\event\post_created $event
     */
    public static function forum_post_created(\mod_forum\event\post_created $event): void {
        // Forum posts are not v2 facts; they stay on the legacy queue.
        self::queue_event($event, 'forum', 5);
    }

    /**
     * Handle forum discussion created.
     *
     * @param \mod_forum\event\discussion_created $event
     */
    public static function forum_discussion_created(\mod_forum\event\discussion_created $event): void {
        self::queue_event($event, 'forum', 5);
    }

    /**
     * Handle user enrolment created.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event): void {
        self::route($event, 'enrolment', 'user_enrolments', 'enrol', 3);
        // Push the account to central when enrolling into a synced course.
        self::maybe_push_account((int) ($event->relateduserid ?? 0), (int) ($event->courseid ?? 0));
    }

    /**
     * Handle user enrolment deleted.
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        self::route($event, 'enrolment', 'user_enrolments', 'enrol', 3);
    }

    /**
     * Handle user enrolment updated.
     *
     * @param \core\event\user_enrolment_updated $event
     */
    public static function user_enrolment_updated(\core\event\user_enrolment_updated $event): void {
        self::route($event, 'enrolment', 'user_enrolments', 'enrol', 3);
    }

    /**
     * Handle activity completion updated.
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function completion_updated(\core\event\course_module_completion_updated $event): void {
        self::route($event, 'completion', 'course_modules_completion', 'completion', 4);
    }

    /**
     * Handle course completed.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        self::route($event, 'completion', 'course_completions', 'completion', 2);
    }

    /**
     * Handle user created.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event): void {
        // Not a v2 fact type; keep the legacy user-profile sync unchanged.
        self::queue_event($event, 'user', 3);
    }

    /**
     * Handle user updated.
     *
     * @param \core\event\user_updated $event
     */
    public static function user_updated(\core\event\user_updated $event): void {
        self::queue_event($event, 'user', 4);
        // Propagate profile/password changes to central, but only for accounts
        // already synced there (otherwise we'd push every local user).
        self::maybe_push_account_update((int) ($event->objectid ?? 0));
    }

    /**
     * Handle a password change. Moodle fires this (not user_updated) when a
     * password is set via update_internal_user_password(), so it's required for
     * school→central password propagation.
     *
     * @param \core\event\user_password_updated $event
     */
    public static function user_password_updated(\core\event\user_password_updated $event): void {
        self::maybe_push_account_update((int) ($event->relateduserid ?? 0));
    }

    /**
     * Route a learner event to v2 capture or the legacy queue.
     *
     * When push_v2 is on: capture returns a non-null id (fresh capture) or 0
     * (idempotent no-op already held by v2) — either way v2 owns the fact and it
     * is not legacy-queued. It returns null for an unlinked learner or
     * unresolvable source row, which falls back to the legacy queue so the fact
     * is never lost. A capture exception also falls back to legacy.
     *
     * @param base $event The event.
     * @param string $facttype v2 fact type.
     * @param string $sourcetable Source table driving identity resolution.
     * @param string $legacytype Legacy queue event category.
     * @param int $priority Legacy queue priority.
     */
    protected static function route(base $event, string $facttype, string $sourcetable,
            string $legacytype, int $priority): void {
        if (capture::suppressed()) {
            // A sync applier is writing; its own grade/completion event must not be
            // re-captured or legacy-queued as a fresh home-origin fact (doc §8.2).
            return;
        }
        if (self::push_v2()) {
            try {
                if (capture::capture_event($event, $facttype, $sourcetable) !== null) {
                    return;
                }
            } catch (\Throwable $e) {
                debugging('Syncqueue: v2 capture failed, using legacy: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        self::queue_event($event, $legacytype, $priority);
    }

    /**
     * Route a file-submission event: capture the fact (v2) but keep the file
     * bytes on the legacy file channel until the step-7 transfer lands.
     *
     * @param base $event The event.
     * @param string $facttype v2 fact type.
     * @param string $sourcetable Source table.
     * @param string $legacytype Legacy queue event category.
     * @param int $priority Legacy queue priority.
     */
    protected static function route_with_files(base $event, string $facttype, string $sourcetable,
            string $legacytype, int $priority): void {
        if (capture::suppressed()) {
            return; // Echo suppression (doc §8.2).
        }
        if (self::push_v2()) {
            try {
                if (capture::capture_event($event, $facttype, $sourcetable) !== null) {
                    // Fact captured to v2; still queue the file bytes for transfer
                    // with a null queueitemid (no legacy parent item).
                    self::queue_files_only($event);
                    return;
                }
            } catch (\Throwable $e) {
                debugging('Syncqueue: v2 file capture failed, using legacy: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        self::queue_event_with_files($event, $legacytype, $priority);
    }

    /**
     * Queue a fact's files on the legacy file channel without a legacy queue item.
     *
     * @param base $event The event.
     */
    protected static function queue_files_only(base $event): void {
        try {
            // null (not 0) = no legacy parent item: matches the nullable queueitemid
            // FK and the file-channel spike's no-parent convention.
            (new queue_manager())->queue_event_files(null, $event);
        } catch (\Exception $e) {
            debugging('Syncqueue: v2 file queueing failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Queue an account push if the user is enrolling into a synced course.
     *
     * @param int $userid Affected user ID.
     * @param int $courseid Course ID the user was enrolled into.
     */
    protected static function maybe_push_account(int $userid, int $courseid): void {
        if (capture::suppressed() || !self::is_enabled() || !$userid || !$courseid) {
            return;
        }
        try {
            if (!self::is_synced_course($courseid)) {
                return;
            }
            if (self::push_v2()) {
                capture::capture_account($userid);
            } else {
                (new queue_manager())->add_account_push($userid);
            }
        } catch (\Exception $e) {
            debugging('Syncqueue: account push failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Queue an account push for an already-synced user (e.g. password change).
     *
     * @param int $userid Affected user ID.
     */
    protected static function maybe_push_account_update(int $userid): void {
        if (capture::suppressed() || !self::is_enabled() || !$userid) {
            return;
        }
        try {
            // Only push updates for users already mapped to a central account.
            if (!(new id_mapper())->get_central_id('user', $userid)) {
                return;
            }
            if (self::push_v2()) {
                capture::capture_account($userid);
            } else {
                (new queue_manager())->add_account_push($userid);
            }
        } catch (\Exception $e) {
            debugging('Syncqueue: account update push failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Whether a course originated from central (and therefore syncs back).
     *
     * @param int $courseid Local course ID.
     * @return bool
     */
    protected static function is_synced_course(int $courseid): bool {
        global $DB;
        if ((new id_mapper())->get_central_id('course', $courseid)) {
            return true;
        }
        $idnumber = (string) $DB->get_field('course', 'idnumber', ['id' => $courseid]);
        return $idnumber !== '' && strpos($idnumber, 'central_') === 0;
    }

    /**
     * Queue an event for synchronization.
     *
     * @param base $event The event to queue.
     * @param string $eventtype Category of event (grade, submission, etc.).
     * @param int $priority Priority 1-10, 1 = highest.
     */
    protected static function queue_event(base $event, string $eventtype, int $priority = 5): void {
        if (capture::suppressed() || !self::is_enabled()) {
            return;
        }

        try {
            $queuemanager = new queue_manager();
            $queuemanager->add_event($event, $eventtype, $priority);
        } catch (\Exception $e) {
            // Log but don't interrupt the user's action.
            debugging('Syncqueue: Failed to queue event: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Queue an event that includes file attachments.
     *
     * @param base $event The event to queue.
     * @param string $eventtype Category of event.
     * @param int $priority Priority 1-10.
     */
    protected static function queue_event_with_files(base $event, string $eventtype, int $priority = 5): void {
        if (capture::suppressed() || !self::is_enabled()) {
            return;
        }

        try {
            $queuemanager = new queue_manager();
            $queueitemid = $queuemanager->add_event($event, $eventtype, $priority);

            // Queue associated files.
            if ($queueitemid) {
                $queuemanager->queue_event_files($queueitemid, $event);
            }
        } catch (\Exception $e) {
            debugging('Syncqueue: Failed to queue event with files: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Check if sync queue is enabled and in school mode.
     *
     * @return bool
     */
    protected static function is_enabled(): bool {
        $enabled = get_config('local_syncqueue', 'enabled');
        $mode = get_config('local_syncqueue', 'mode');

        // Only queue events when enabled AND in school mode.
        // Central mode receives events, doesn't queue them for upload.
        return $enabled && $mode === 'school';
    }

    /**
     * Whether v2 upstream capture is active (enabled, school mode, push_v2 on).
     *
     * @return bool
     */
    protected static function push_v2(): bool {
        return self::is_enabled() && (bool) get_config('local_syncqueue', 'push_v2');
    }
}
