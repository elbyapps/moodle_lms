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

/**
 * Auto cohort-sync enrolment linker for local_elby_dashboard.
 *
 * Keeps course <-> cohort wiring correct in both directions: when a course's
 * trade/level becomes known it attaches every matching tdmp:{trade}:{level}:*
 * cohort, and when a new tdmp cohort is created it attaches that cohort to all
 * already-matching courses. Ongoing student membership then flows via the core
 * enrol_cohort sync.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Links courses and TDMP cohorts via cohort-sync enrolment instances.
 */
class cohort_course_linker {

    /**
     * Whether auto cohort-sync enrolment is enabled.
     *
     * Defaults on when the setting has never been saved.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $value = get_config('local_elby_dashboard', 'auto_cohort_enrol_enabled');
        return $value === false ? true : (bool) $value;
    }

    /**
     * Phase 1: a course's trade/level is known — attach all matching cohorts.
     *
     * @param int $courseid Moodle course ID.
     */
    public static function link_course(int $courseid): void {
        global $DB;

        if (!self::is_enabled() || $courseid <= 1) {
            return;
        }
        $meta = $DB->get_record('elby_course_meta', ['courseid' => $courseid], 'trade_code, level');
        if (!$meta || empty($meta->trade_code) || empty($meta->level)) {
            return;
        }

        foreach (self::matching_cohorts((string) $meta->trade_code, (string) $meta->level) as $cohortid) {
            self::ensure_instance($courseid, (int) $cohortid);
        }
    }

    /**
     * Phase 2: a new cohort exists — attach it to all already-matching courses.
     *
     * @param int $cohortid Cohort ID.
     */
    public static function link_cohort(int $cohortid): void {
        global $DB;

        if (!self::is_enabled()) {
            return;
        }
        $cohort = $DB->get_record('cohort', ['id' => $cohortid], 'id, idnumber');
        if (!$cohort) {
            return;
        }
        // Only our TDMP cohorts: tdmp:{trade}:{level}:{yearSlug}.
        if (!preg_match('/^tdmp:([^:]+):(\d+):/', (string) $cohort->idnumber, $m)) {
            return;
        }
        $trade = $m[1];
        $level = $m[2];

        $courseids = $DB->get_fieldset_select('elby_course_meta', 'courseid',
            'trade_code = ? AND level = ?', [$trade, $level]);
        foreach ($courseids as $courseid) {
            self::ensure_instance((int) $courseid, $cohortid);
        }
    }

    /**
     * System-context cohorts whose idnumber is tdmp:{trade}:{level}:* (any year).
     *
     * @param string $trade Trade / combination code.
     * @param string $level Level number.
     * @return int[] Cohort IDs.
     */
    private static function matching_cohorts(string $trade, string $level): array {
        global $DB;

        $ctx = \context_system::instance();
        $like = $DB->sql_like('idnumber', ':pat');
        $pat = $DB->sql_like_escape("tdmp:{$trade}:{$level}:") . '%';
        return $DB->get_fieldset_sql(
            "SELECT id FROM {cohort} WHERE contextid = :ctx AND {$like}",
            ['ctx' => $ctx->id, 'pat' => $pat]
        );
    }

    /**
     * Idempotently add a student-role cohort-sync enrol instance for (course, cohort).
     *
     * @param int $courseid Moodle course ID.
     * @param int $cohortid Cohort ID.
     */
    private static function ensure_instance(int $courseid, int $cohortid): void {
        global $DB, $CFG;

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        if (!$roleid) {
            return;
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_elby_dashboard_cohort_enrol');
        $lock = $lockfactory->get_lock("course:{$courseid}:cohort:{$cohortid}:role:{$roleid}", 10);
        if (!$lock) {
            // Avoid duplicate instances under concurrent requests; a later retry can add it.
            return;
        }

        try {
            $exists = $DB->record_exists('enrol', [
                'enrol' => 'cohort', 'courseid' => $courseid,
                'customint1' => $cohortid, 'roleid' => (int) $roleid,
            ]);
            if ($exists) {
                return;
            }
            if (!enrol_is_enabled('cohort')) {
                return; // Installed but disabled site-wide.
            }
            $plugin = enrol_get_plugin('cohort');
            if (!$plugin) {
                return; // Missing/broken plugin.
            }
            require_once($CFG->dirroot . '/enrol/cohort/locallib.php');
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $plugin->add_instance($course, [
                'name' => 'TDMP auto cohort sync',
                'customint1' => $cohortid,
                'customint2' => 0,
                'roleid' => (int) $roleid,
            ]); // add_instance() runs enrol_cohort_sync() itself.
        } catch (\Throwable $e) {
            debugging('TDMP cohort enrol add failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        } finally {
            $lock->release();
        }
    }
}
