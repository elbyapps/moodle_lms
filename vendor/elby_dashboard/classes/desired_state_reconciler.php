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

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Desired-state enrolment reconciler (ELMS Sync v2 §6).
 *
 * A pure function of the persisted roster + links + course metadata
 * (elby_roster + elby_sdms_users + elby_course_meta) that converges cohorts,
 * cohort membership and cohort-sync enrol instances to the state those tables
 * imply. Runs hourly and offline, and is also invoked (scoped) from event paths.
 *
 * Because it recomputes desired state from scratch every run, ordering stops
 * mattering: a course pulled before its students link, or after, both converge
 * at the next run — the order-independent, self-healing enrolment the original
 * plan asked for. Diff-down (disable, never delete) makes it a genuine pure
 * function rather than monotone accretion: when central re-tags a course
 * (L3->L4) the reconciler disables the instances it owns for the old tagging
 * (suspending members, preserving grades) and creates the right ones.
 *
 * Scope guards keep it from ever touching anything a human set up: it only
 * reads/writes system cohorts whose idnumber is tdmp:{trade}:{level}:{year} and
 * enrol_cohort instances it created (name = INSTANCE_NAME). Manual enrolments
 * and admin cohorts are invisible to it.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class desired_state_reconciler {

    /** @var string Name stamped on every enrol_cohort instance this owns (matches cohort_course_linker). */
    public const INSTANCE_NAME = 'TDMP auto cohort sync';

    /**
     * Mass-suspend guard: if a run would suspend (remove from their tdmp cohort)
     * more than this fraction of currently-placed students, it skips the removals
     * and reports a warning. A truncated/broken roster must not mass-suspend a
     * school; an admin can force it once via the reconcile_force_suspend config.
     */
    protected const SUSPEND_GUARD_FRACTION = 0.20;

    /** @var int Below this many placed students the guard does not apply (bootstrap). */
    protected const SUSPEND_GUARD_FLOOR = 25;

    /**
     * Reconcile the whole instance to the desired state.
     *
     * @param array $opts Optional: 'trace' => callable(string) for progress.
     * @return \stdClass Report with per-phase counts.
     */
    public static function reconcile(array $opts = []): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $trace = $opts['trace'] ?? function (string $m): void {
        };
        $report = (object) [
            'cohorts_created' => 0,
            'members_added' => 0,
            'members_suspended' => 0,
            'suspend_skipped' => 0,
            'instances_created' => 0,
            'instances_reenabled' => 0,
            'instances_disabled' => 0,
            'warnings' => [],
        ];

        if (!cohort_course_linker::is_enabled()) {
            $report->warnings[] = 'auto cohort enrolment disabled; reconciler is a no-op';
            return $report;
        }

        self::reconcile_membership($report, $trace);
        self::reconcile_instances($report, $trace);

        return $report;
    }

    /**
     * Scoped reconcile for a single user (event-path invocation): place them in
     * exactly their roster cohort and make sure their courses are wired. Cheap
     * enough to call from link_user / a cohort adhoc without an hourly wait.
     *
     * @param int $userid Moodle user id.
     */
    public static function reconcile_user(int $userid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        if (!cohort_course_linker::is_enabled() || $userid <= 1) {
            return;
        }

        $roster = self::roster_for_user($userid);
        if ($roster === null) {
            // No cached roster row yet — unknown, not departed. Leave the user's
            // placement alone (assign_student_cohort handles link-time placement
            // from live data; the reconciler takes over once the roster caches them),
            // so a just-linked student is never suspended before the roster catches up.
            return;
        }
        $desiredcohortid = null;
        // A withdrawn roster status means no desired cohort, so the user is suspended
        // out of their tdmp cohort — same rule as the fleet reconcile.
        if (!self::is_withdrawn((string) ($roster->status ?? ''))) {
            $key = self::desired_cohort_key($roster);
            if ($key !== null) {
                $desiredcohortid = self::ensure_cohort($key);
                // Wire any already-present courses for this cohort so the user's
                // enrolment appears without waiting for the hourly run.
                cohort_course_linker::link_cohort($desiredcohortid);
            }
        }
        self::place_user_in_cohort($userid, $desiredcohortid, new \stdClass());
    }

    /**
     * Ensure every linked student sits in exactly the cohort their current roster
     * row implies; students with no valid roster row are suspended from all tdmp
     * cohorts, guarded against a mass-suspend from a broken roster.
     *
     * @param \stdClass $report Mutated with counts.
     * @param callable $trace Progress callback.
     */
    protected static function reconcile_membership(\stdClass $report, callable $trace): void {
        global $DB;

        $linked = $DB->get_records('elby_sdms_users', ['user_type' => 'student'],
            '', 'id, userid, sdms_id');
        if (!$linked) {
            $trace('no linked students; skipping membership');
            return;
        }

        // Build the plan first (so the guard can measure before anything changes).
        // A linked student with NO cached roster row is UNKNOWN, not departed: we
        // leave their placement entirely alone (never add, never remove), so a
        // just-linked student is not suspended before the daily roster sync catches
        // up, and a roster-less instance (e.g. central) is a whole no-op. Only a
        // roster row with a withdrawn status is a true departure that suspends.
        $plan = [];          // userid => [desired => ?cohortid, current => int[]].
        $placed = 0;
        $departures = 0;     // placed students who are truly leaving (desired = none).
        foreach ($linked as $link) {
            $userid = (int) $link->userid;
            if ($userid <= 1) {
                continue;
            }
            $roster = $DB->get_record('elby_roster', ['sdms_id' => $link->sdms_id, 'user_type' => 'student'],
                'id, sdms_id, program, program_code, class_grade, study_level, academic_year, status');
            if (!$roster) {
                // No roster row is the designed absence signal (the roster prune
                // deletes feed-absent rows): unknown, leave alone. Note get_record()
                // returns false — never null — on a miss, so a `=== null` test here
                // would fall through and crash desired_cohort_key(false).
                continue;
            }
            $cohortid = null;
            if (!self::is_withdrawn((string) ($roster->status ?? ''))) {
                $key = self::desired_cohort_key($roster);
                if ($key !== null) {
                    $cohortid = self::ensure_cohort($key, $report);
                }
            }
            $current = self::current_tdmp_cohorts($userid);
            if ($current) {
                $placed++;
                // A true departure removes them from every cohort; a MOVE (desired
                // is a different cohort) re-adds them elsewhere and must never count
                // toward the suspend guard, or the annual year-rollover — which
                // moves everyone at once — would always trip it.
                if ($cohortid === null) {
                    $departures++;
                }
            }
            $plan[$userid] = ['desired' => $cohortid, 'current' => $current];
        }

        // Mass-suspend guard: only true departures count. A truncated/broken roster
        // that makes many students look departed at once is held for admin review.
        $threshold = (int) ceil($placed * self::SUSPEND_GUARD_FRACTION);
        $forced = (bool) get_config('local_elby_dashboard', 'reconcile_force_suspend');
        $wouldtrip = ($placed >= self::SUSPEND_GUARD_FLOOR && $departures > $threshold);
        $guardtriggered = $wouldtrip && !$forced;
        if ($guardtriggered) {
            $report->warnings[] = "mass-suspend guard: run would suspend {$departures} of {$placed} "
                . 'placed students; holding removals (set reconcile_force_suspend=1 to override once)';
            $trace($report->warnings[count($report->warnings) - 1]);
        }

        foreach ($plan as $userid => $p) {
            self::place_user_in_cohort($userid, $p['desired'], $report, $guardtriggered, $p['current']);
        }

        // Consume the one-shot override only when it actually overrode a trip this
        // run, so an override armed early is not silently spent on a quiet run.
        if ($forced && $wouldtrip) {
            unset_config('reconcile_force_suspend', 'local_elby_dashboard');
        }
    }

    /**
     * Whether a roster status string signals the student has left (case-insensitive).
     * Unknown/blank statuses are treated as active — departure needs a positive
     * signal, never mere absence, so a student is never wrongly suspended.
     *
     * @param string $status Raw elby_roster.status (TDMP registrationStatus).
     * @return bool
     */
    protected static function is_withdrawn(string $status): bool {
        static $withdrawn = ['REMOVED', 'INACTIVE', 'DROPPED', 'WITHDRAWN', 'DELETED', 'SUSPENDED', 'TRANSFERRED'];
        return in_array(strtoupper(trim($status)), $withdrawn, true);
    }

    /**
     * Place one user in exactly $desiredcohortid and suspend them out of every
     * other tdmp cohort (unless the mass-suspend guard is holding removals back).
     *
     * @param int $userid Moodle user id.
     * @param int|null $desiredcohortid Cohort they should be in, or null for none.
     * @param \stdClass $report Mutated with counts.
     * @param bool $holdremovals When true, add-to-desired still runs but removals are skipped.
     */
    protected static function place_user_in_cohort(int $userid, ?int $desiredcohortid,
            \stdClass $report, bool $holdremovals = false, ?array $current = null): void {
        $current = $current ?? self::current_tdmp_cohorts($userid);

        if ($desiredcohortid !== null && !in_array($desiredcohortid, $current, true)) {
            cohort_add_member($desiredcohortid, $userid);
            if (isset($report->members_added)) {
                $report->members_added++;
            }
        }

        foreach ($current as $cohortid) {
            if ($cohortid === $desiredcohortid) {
                continue;
            }
            if ($holdremovals) {
                if (isset($report->suspend_skipped)) {
                    $report->suspend_skipped++;
                }
                continue;
            }
            // enrol_cohort/unenrolaction is pinned to SUSPENDNOROLES, so this
            // suspends the enrolment and removes the role but preserves grades.
            cohort_remove_member($cohortid, $userid);
            if (isset($report->members_suspended)) {
                $report->members_suspended++;
            }
        }
    }

    /**
     * Reconcile cohort-sync enrol instances: the desired set is every enriched
     * course crossed with the tdmp cohorts matching its trade/level. Ensure each
     * desired pair has an enabled owned instance; disable every owned instance no
     * longer desired (never delete — delete triggers grade_user_unenrol).
     *
     * @param \stdClass $report Mutated with counts.
     * @param callable $trace Progress callback.
     */
    protected static function reconcile_instances(\stdClass $report, callable $trace): void {
        global $DB;

        // Per-course: ensure/enable desired instances and disable owned instances
        // on that course no longer desired (handles a re-tag L3->L4).
        $enriched = [];
        $metas = $DB->get_records_select('elby_course_meta',
            "trade_code IS NOT NULL AND trade_code <> '' AND level IS NOT NULL AND level <> ''",
            null, '', 'id, courseid');
        foreach ($metas as $meta) {
            $enriched[(int) $meta->courseid] = true;
            self::reconcile_course_instances((int) $meta->courseid, $report, $trace);
        }

        // Fleet-wide diff-down: disable owned instances whose course has no enriched
        // meta at all any more (de-enriched or archived), which the per-course pass
        // above never visits.
        $owned = $DB->get_records('enrol', ['enrol' => 'cohort', 'name' => self::INSTANCE_NAME]);
        $plugin = enrol_get_plugin('cohort');
        foreach ($owned as $instance) {
            if (isset($enriched[(int) $instance->courseid])) {
                continue;
            }
            if ($plugin && (int) $instance->status === ENROL_INSTANCE_ENABLED) {
                $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
                $report->instances_disabled++;
                $trace("disabled instance on de-enriched course {$instance->courseid}");
            }
        }
    }

    /**
     * Reconcile the cohort-sync enrol instances for ONE course to its current
     * trade/level tagging: ensure an enabled owned instance for every matching
     * cohort, and disable owned instances on this course whose cohort no longer
     * matches (never delete). Scoped so event paths (course_updated) and tests can
     * converge a single course without a fleet sweep.
     *
     * @param int $courseid Course id.
     * @param \stdClass $report Mutated with counts.
     * @param callable|null $trace Optional progress callback.
     */
    public static function reconcile_course_instances(int $courseid, ?\stdClass $report = null, ?callable $trace = null): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/enrol/cohort/locallib.php');

        $report = $report ?? (object) [
            'instances_created' => 0, 'instances_reenabled' => 0, 'instances_disabled' => 0, 'warnings' => [],
        ];
        $trace = $trace ?? function (string $m): void {
        };
        $plugin = enrol_get_plugin('cohort');
        if (!$plugin) {
            $report->warnings[] = 'enrol_cohort plugin unavailable; skipped instance reconcile';
            return;
        }

        // Desired cohort ids for this course (empty when de-enriched or archived).
        $desired = [];
        if (!self::course_is_archived($courseid)) {
            $meta = $DB->get_record('elby_course_meta', ['courseid' => $courseid], 'trade_code, level');
            if ($meta && (string) ($meta->trade_code ?? '') !== '' && (string) ($meta->level ?? '') !== '') {
                foreach (self::matching_cohort_ids((string) $meta->trade_code, (string) $meta->level) as $cohortid) {
                    $desired[$cohortid] = true;
                }
            }
        }

        // Ensure desired instances exist and are enabled.
        foreach (array_keys($desired) as $cohortid) {
            $instance = self::owned_instance($courseid, (int) $cohortid);
            if (!$instance) {
                cohort_course_linker::link_course($courseid);
                $report->instances_created++;
            } else if ((int) $instance->status !== ENROL_INSTANCE_ENABLED) {
                $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
                $report->instances_reenabled++;
            }
        }

        // Diff-down: disable owned instances on this course no longer desired.
        $owned = $DB->get_records('enrol',
            ['enrol' => 'cohort', 'name' => self::INSTANCE_NAME, 'courseid' => $courseid]);
        foreach ($owned as $instance) {
            if (isset($desired[(int) $instance->customint1])) {
                continue;
            }
            if ((int) $instance->status === ENROL_INSTANCE_ENABLED) {
                $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
                $report->instances_disabled++;
                $trace("disabled stale instance: course {$courseid} cohort {$instance->customint1}");
            }
        }
    }

    /**
     * The desired cohort key for a roster row, or null when it lacks the data.
     *
     * @param \stdClass $roster elby_roster row.
     * @return array|null [trade, level, yearslug, idnumber, name] or null.
     */
    protected static function desired_cohort_key(\stdClass $roster): ?array {
        $trade = trim((string) ($roster->program_code ?? ''));
        if ($trade === '') {
            return null;
        }
        // Level number from class_grade (e.g. "Level 5" -> 5); study_level is a fallback.
        $gradesource = (string) ($roster->class_grade ?? '');
        if (!preg_match('/(\d+)/', $gradesource, $m)) {
            $gradesource = (string) ($roster->study_level ?? '');
            if (!preg_match('/(\d+)/', $gradesource, $m)) {
                return null;
            }
        }
        $level = $m[1];
        $year = trim((string) ($roster->academic_year ?? ''));
        if ($year === '') {
            return null;
        }
        $yearslug = str_replace('/', '-', $year);
        $tradename = trim((string) ($roster->program ?? '')) ?: $trade;

        return [
            'trade' => $trade,
            'level' => $level,
            'yearslug' => $yearslug,
            'idnumber' => "tdmp:{$trade}:{$level}:{$yearslug}",
            'name' => "{$tradename} · Level {$level} · {$year}",
        ];
    }

    /**
     * Get or create the system cohort for a desired key.
     *
     * @param array $key From desired_cohort_key().
     * @param \stdClass|null $report Optional report to bump cohorts_created.
     * @return int Cohort id.
     */
    protected static function ensure_cohort(array $key, ?\stdClass $report = null): int {
        $cohort = new \stdClass();
        $cohort->name = $key['name'];
        $cohort->description = "Auto-created by the TDMP reconciler (trade {$key['trade']}, "
            . "level {$key['level']}, {$key['yearslug']}).";
        $cohort->descriptionformat = FORMAT_PLAIN;
        $cohort->visible = 1;

        [$id, $created] = self::get_or_create_system_cohort($key['idnumber'], $cohort);
        if ($created && $report && isset($report->cohorts_created)) {
            $report->cohorts_created++;
        }
        return $id;
    }

    /**
     * Get or create a system-context cohort by idnumber, serialized by a named lock.
     *
     * cohort.idnumber has NO DB unique index, so the hourly reconcile and concurrent
     * interactive signups (sync_service::assign_student_cohort) could otherwise create
     * duplicate system cohorts for the same brand-new (trade, level, year). Both paths
     * route through here on the same lock resource ('cohort:{idnumber}' in the
     * 'local_elby_dashboard_cohort' factory) so exactly one wins the create. When the
     * lock can't be acquired the cohort is NOT created blindly: we re-read (the holder
     * may have just created it) and otherwise bail so the caller retries — creating
     * without the lock is the duplicate bug this guards against.
     *
     * @param string $idnumber Cohort idnumber (e.g. tdmp:{trade}:{level}:{year}).
     * @param \stdClass $tocreate Cohort record used only when creation is needed; the
     *        caller sets name/description/visible, this sets contextid + idnumber.
     * @return array{0:int,1:bool} [cohort id, whether THIS call created it]
     */
    public static function get_or_create_system_cohort(string $idnumber, \stdClass $tocreate): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $ctx = \context_system::instance();
        $existing = $DB->get_record('cohort', ['contextid' => $ctx->id, 'idnumber' => $idnumber], 'id');
        if ($existing) {
            return [(int) $existing->id, false];
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_elby_dashboard_cohort');
        $lock = $lockfactory->get_lock('cohort:' . $idnumber, 10);
        if (!$lock) {
            // Couldn't serialize: re-read in case the lock holder just created it,
            // otherwise bail rather than create the cohort un-serialized.
            $existing = $DB->get_record('cohort', ['contextid' => $ctx->id, 'idnumber' => $idnumber], 'id');
            if ($existing) {
                return [(int) $existing->id, false];
            }
            throw new \moodle_exception('error_cohortlock', 'local_elby_dashboard', '', $idnumber);
        }
        try {
            // Re-check under the lock (someone may have created it while we waited).
            $existing = $DB->get_record('cohort', ['contextid' => $ctx->id, 'idnumber' => $idnumber], 'id');
            if ($existing) {
                return [(int) $existing->id, false];
            }
            $tocreate->contextid = $ctx->id;
            $tocreate->idnumber = $idnumber;
            return [(int) cohort_add_cohort($tocreate), true];
        } finally {
            $lock->release();
        }
    }

    /**
     * System-context tdmp:{trade}:{level}:* cohort ids (any year).
     *
     * @param string $trade Trade code.
     * @param string $level Level.
     * @return int[]
     */
    protected static function matching_cohort_ids(string $trade, string $level): array {
        global $DB;

        $ctx = \context_system::instance();
        $like = $DB->sql_like('idnumber', ':pat');
        $pat = $DB->sql_like_escape("tdmp:{$trade}:{$level}:") . '%';
        return array_map('intval', $DB->get_fieldset_sql(
            "SELECT id FROM {cohort} WHERE contextid = :ctx AND {$like}",
            ['ctx' => $ctx->id, 'pat' => $pat]));
    }

    /**
     * The tdmp cohort ids a user is currently a member of.
     *
     * @param int $userid Moodle user id.
     * @return int[]
     */
    protected static function current_tdmp_cohorts(int $userid): array {
        global $DB;

        $like = $DB->sql_like('c.idnumber', ':pat');
        $rows = $DB->get_fieldset_sql(
            "SELECT c.id
               FROM {cohort} c
               JOIN {cohort_members} cm ON cm.cohortid = c.id
              WHERE cm.userid = :userid AND {$like}",
            ['userid' => $userid, 'pat' => $DB->sql_like_escape('tdmp:') . '%']);
        return array_map('intval', $rows);
    }

    /**
     * The owned (name = INSTANCE_NAME) student-role cohort-sync instance for a
     * (course, cohort) pair, or null.
     *
     * @param int $courseid Course id.
     * @param int $cohortid Cohort id.
     * @return \stdClass|null enrol row.
     */
    protected static function owned_instance(int $courseid, int $cohortid): ?\stdClass {
        global $DB;
        $row = $DB->get_record('enrol', [
            'enrol' => 'cohort', 'courseid' => $courseid,
            'customint1' => $cohortid, 'name' => self::INSTANCE_NAME,
        ]);
        return $row ?: null;
    }

    /**
     * The roster row backing a linked user, or null.
     *
     * @param int $userid Moodle user id.
     * @return \stdClass|null
     */
    protected static function roster_for_user(int $userid): ?\stdClass {
        global $DB;

        $sdms = $DB->get_field('elby_sdms_users', 'sdms_id', ['userid' => $userid, 'user_type' => 'student']);
        if (!$sdms) {
            return null;
        }
        $row = $DB->get_record('elby_roster', ['sdms_id' => $sdms, 'user_type' => 'student'],
            'id, sdms_id, program, program_code, class_grade, study_level, academic_year, status');
        return $row ?: null;
    }

    /**
     * Whether a course has been archived by versioned publication (§7); archived
     * courses carry an idnumber suffixed #archived-v{n} and the reconciler ignores them.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    protected static function course_is_archived(int $courseid): bool {
        global $DB;
        $idnumber = (string) $DB->get_field('course', 'idnumber', ['id' => $courseid]);
        return strpos($idnumber, '#archived-v') !== false;
    }
}
