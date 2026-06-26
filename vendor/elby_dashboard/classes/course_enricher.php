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
 * Course enrichment service for local_elby_dashboard.
 *
 * Maintains elby_course_meta: the trade / level / owning-school metadata for a
 * course. Trade + level come from (in priority order) an enforced teacher form
 * selection, a manual override, the course category idnumber, or the creating
 * teacher's single speciality. School is the creating teacher's school.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Course enrichment service.
 */
class course_enricher {

    /** @var array<string,string> Per-request cache of trade code => trade name. */
    private static array $tradenamecache = [];

    /**
     * Whether course enrichment is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_elby_dashboard', 'auto_course_enrich_enabled');
    }

    /**
     * Whether the creating teacher's school may be stamped onto courses.
     *
     * @return bool
     */
    private static function set_school_enabled(): bool {
        return (bool) get_config('local_elby_dashboard', 'course_enrich_set_school');
    }

    /**
     * Canonical trade name map [tradeCode => tradeName] from the gateway /trades list.
     *
     * Cached (reference data, 1-day TTL); empty if the gateway is unavailable.
     *
     * @return array<string,string>
     */
    public static function get_trade_name_map(): array {
        $cache = \cache::make('local_elby_dashboard', 'trades');
        $map = $cache->get('namemap');
        if (is_array($map)) {
            return $map;
        }
        $map = [];
        try {
            $client = new tdmp_client();
            foreach ($client->get_trades() as $trade) {
                $code = (string) ($trade->tradeCode ?? '');
                if ($code === '') {
                    continue;
                }
                $map[$code] = (string) ($trade->tradeName ?? $code);
            }
        } catch (\Exception $e) {
            debugging('Trade list fetch failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        if (!empty($map)) {
            $cache->set('namemap', $map);
        }
        return $map;
    }

    /**
     * Canonical [tradeCode => combinationId] map from the gateway /trades list.
     *
     * The modules report filters by numeric combination id (= /trades[].id), not by
     * trade code, so the module search resolves the selected trade code through this.
     * Cached alongside the name map (1-day TTL); empty if the gateway is unavailable.
     *
     * @return array<string,int>
     */
    public static function get_trade_id_map(): array {
        $cache = \cache::make('local_elby_dashboard', 'trades');
        $map = $cache->get('idmap');
        if (is_array($map)) {
            return $map;
        }
        $map = [];
        try {
            $client = new tdmp_client();
            foreach ($client->get_trades() as $trade) {
                $code = (string) ($trade->tradeCode ?? '');
                $id = (int) ($trade->id ?? 0);
                if ($code === '' || $id === 0) {
                    continue;
                }
                $map[$code] = $id;
            }
        } catch (\Exception $e) {
            debugging('Trade id-map fetch failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        if (!empty($map)) {
            $cache->set('idmap', $map);
        }
        return $map;
    }

    /**
     * Full canonical trade options as [tradeCode => "Name (code)"], sorted by label.
     *
     * @return array<string,string>
     */
    public static function get_trade_options(): array {
        $opts = [];
        foreach (self::get_trade_name_map() as $code => $name) {
            $opts[$code] = $name . ' (' . $code . ')';
        }
        asort($opts);
        return $opts;
    }

    /**
     * Upsert enrichment metadata for one course.
     *
     * @param int $courseid Moodle course ID.
     * @param array $override Optional keys: creator_userid, trade_code, level, source.
     */
    public static function enrich_course(int $courseid, array $override = []): void {
        global $DB;

        if (!self::is_enabled() || $courseid <= 1) {
            return;
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }

        $existing = $DB->get_record('elby_course_meta', ['courseid' => $courseid]);

        // Resolve the creator.
        $creator = $override['creator_userid'] ?? ($existing->creator_userid ?? null);
        if (empty($creator)) {
            $creator = self::resolve_creator($courseid);
        }
        $creator = $creator ? (int) $creator : null;

        // Resolve trade / level / source.
        $tradecode = null;
        $level = null;
        $source = 'none';

        if (!empty($override['trade_code'])) {
            // Enforced form selection (or explicit override) wins.
            $tradecode = (string) $override['trade_code'];
            $level = isset($override['level']) && $override['level'] !== '' ? (string) $override['level'] : null;
            $source = $override['source'] ?? 'form';
        } else if ($existing && in_array($existing->source, ['form', 'manual'], true) && !empty($existing->trade_code)) {
            // Preserve an authoritative value set earlier.
            $tradecode = $existing->trade_code;
            $level = $existing->level;
            $source = $existing->source;
        } else if ($cat = self::trade_level_from_category($courseid)) {
            [$tradecode, $level] = $cat;
            $source = 'category';
        } else if ($creator && $single = self::single_speciality($creator)) {
            [$tradecode, $level] = $single;
            $source = 'teacher_speciality';
        }

        // Resolve module (subject) from an enforced form selection, else preserve existing.
        $moduleid = $existing->module_id ?? null;
        $modulename = $existing->module_name ?? null;
        if (array_key_exists('module_id', $override)) {
            $moduleid = !empty($override['module_id']) ? (string) $override['module_id'] : null;
            $modulename = ($moduleid !== null && isset($override['module_name']) && $override['module_name'] !== '')
                ? (string) $override['module_name'] : null;
        }

        // Resolve trade name (reuse stored value to avoid needless gateway calls).
        $tradename = null;
        if ($tradecode) {
            if ($existing && $existing->trade_code === $tradecode && !empty($existing->trade_name)) {
                $tradename = $existing->trade_name;
            } else {
                $tradename = self::resolve_trade_name($tradecode);
            }
        }

        // Resolve the owning school from the creator (linked teacher only).
        $schoolcode = $existing->school_code ?? null;
        $schoolname = $existing->school_name ?? null;
        if (self::set_school_enabled() && $creator && $school = self::creator_school($creator)) {
            [$schoolcode, $schoolname] = $school;
        }

        $now = time();
        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->creator_userid = $creator;
        $record->trade_code = $tradecode;
        $record->trade_name = $tradename;
        $record->level = $level;
        $record->module_id = $moduleid;
        $record->module_name = $modulename;
        $record->curriculum_course_id = $existing->curriculum_course_id ?? null; // No source in modules report yet.
        $record->school_code = $schoolcode;
        $record->school_name = $schoolname;
        $record->source = $source;
        $record->needs_review = empty($tradecode) ? 1 : 0;
        $record->last_enriched = $now;
        $record->timemodified = $now;

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('elby_course_meta', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('elby_course_meta', $record);
        }

        // Phase 1: now the course's trade/level is settled, attach matching cohorts.
        if ($tradecode && $level) {
            try {
                cohort_course_linker::link_course($courseid);
            } catch (\Throwable $e) {
                debugging('cohort link_course failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Re-enrich every course created by a user (called on teacher link / refresh).
     *
     * @param int $userid Moodle user ID.
     */
    public static function enrich_for_teacher(int $userid): void {
        if (!self::is_enabled()) {
            return;
        }
        foreach (self::courses_created_by($userid) as $courseid) {
            self::enrich_course($courseid, ['creator_userid' => $userid]);
        }
    }

    /**
     * Resolve a course creator: course_created log entry, else earliest editing teacher.
     *
     * @param int $courseid Moodle course ID.
     * @return int|null Moodle user ID, or null if unresolved.
     */
    public static function resolve_creator(int $courseid): ?int {
        global $DB;

        if ($DB->get_manager()->table_exists('logstore_standard_log')) {
            $userid = $DB->get_field_sql(
                "SELECT userid
                   FROM {logstore_standard_log}
                  WHERE eventname = :evt AND courseid = :cid AND userid > 0
               ORDER BY timecreated ASC",
                ['evt' => '\\core\\event\\course_created', 'cid' => $courseid],
                IGNORE_MULTIPLE
            );
            if ($userid) {
                return (int) $userid;
            }
        }

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        if ($roleid) {
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if ($context) {
                $userid = $DB->get_field_sql(
                    "SELECT userid
                       FROM {role_assignments}
                      WHERE roleid = :rid AND contextid = :ctx
                   ORDER BY timemodified ASC",
                    ['rid' => $roleid, 'ctx' => $context->id],
                    IGNORE_MULTIPLE
                );
                if ($userid) {
                    return (int) $userid;
                }
            }
        }

        return null;
    }

    /**
     * Course IDs created by a user (log + editing-teacher fallback + existing rows).
     *
     * @param int $userid Moodle user ID.
     * @return int[]
     */
    private static function courses_created_by(int $userid): array {
        global $DB;
        $ids = [];

        if ($DB->get_manager()->table_exists('logstore_standard_log')) {
            $rows = $DB->get_fieldset_sql(
                "SELECT DISTINCT courseid
                   FROM {logstore_standard_log}
                  WHERE eventname = :evt AND userid = :uid AND courseid > 1",
                ['evt' => '\\core\\event\\course_created', 'uid' => $userid]
            );
            foreach ($rows as $cid) {
                $ids[(int) $cid] = (int) $cid;
            }
        }

        $rows = $DB->get_fieldset_select('elby_course_meta', 'courseid', 'creator_userid = ?', [$userid]);
        foreach ($rows as $cid) {
            $ids[(int) $cid] = (int) $cid;
        }

        return array_values($ids);
    }

    /**
     * Parse trade + level from the course category idnumber chain (e.g. "527:3").
     *
     * @param int $courseid Moodle course ID.
     * @return array{0:string,1:string}|null [tradecode, level]
     */
    private static function trade_level_from_category(int $courseid): ?array {
        global $DB;

        $catid = (int) $DB->get_field('course', 'category', ['id' => $courseid]);
        if (!$catid) {
            return null;
        }
        $category = \core_course_category::get($catid, IGNORE_MISSING);
        if (!$category) {
            return null;
        }

        $catids = array_merge([$catid], $category->get_parents());
        foreach ($catids as $cid) {
            $idnumber = (string) $DB->get_field('course_categories', 'idnumber', ['id' => $cid]);
            if ($idnumber !== '' && preg_match('/^([A-Za-z0-9]+):(\d+)$/', $idnumber, $m)) {
                return [$m[1], $m[2]];
            }
        }
        return null;
    }

    /**
     * Resolve the full trade name via the gateway (cached, falls back to the code).
     *
     * @param string $tradecode Trade / combination code.
     * @return string
     */
    private static function resolve_trade_name(string $tradecode): string {
        if (isset(self::$tradenamecache[$tradecode])) {
            return self::$tradenamecache[$tradecode];
        }
        $name = $tradecode;
        try {
            $client = new tdmp_client();
            $trade = $client->get_trade($tradecode);
            if ($trade && !empty($trade->tradeName)) {
                $name = (string) $trade->tradeName;
            }
        } catch (\Exception $e) {
            debugging('Trade lookup failed for ' . $tradecode . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        self::$tradenamecache[$tradecode] = $name;
        return $name;
    }

    /**
     * The creator's school, if they are a linked teacher.
     *
     * @param int $userid Moodle user ID.
     * @return array{0:string,1:string}|null [school_code, school_name]
     */
    private static function creator_school(int $userid): ?array {
        global $DB;
        $rec = $DB->get_record_sql(
            "SELECT s.school_code, s.school_name
               FROM {elby_sdms_users} su
               JOIN {elby_schools} s ON s.id = su.schoolid
              WHERE su.userid = :uid AND su.user_type = :tt",
            ['uid' => $userid, 'tt' => 'teacher']
        );
        if (!$rec || empty($rec->school_code)) {
            return null;
        }
        return [$rec->school_code, $rec->school_name];
    }

    /**
     * The teacher's distinct (trade, level) specialities.
     *
     * Reads the stored specialities JSON; if absent, fetches live from the gateway
     * and caches it on the teacher record.
     *
     * @param int $userid Moodle user ID.
     * @return array<int,array{trade_code:string,trade_name:string,level:string,level_name:string}>
     */
    public static function get_teacher_specialities(int $userid): array {
        global $DB;

        $rec = $DB->get_record_sql(
            "SELECT t.id, t.specialities, su.sdms_id
               FROM {elby_sdms_users} su
               JOIN {elby_teachers} t ON t.sdms_userid = su.id
              WHERE su.userid = :uid AND su.user_type = :tt",
            ['uid' => $userid, 'tt' => 'teacher']
        );
        if (!$rec) {
            return [];
        }

        $raw = !empty($rec->specialities) ? json_decode($rec->specialities) : null;

        if (empty($raw) && !empty($rec->sdms_id)) {
            try {
                $client = new tdmp_client();
                $teacher = $client->get_teacher($rec->sdms_id);
                if ($teacher && !empty($teacher->specialities)) {
                    $raw = $teacher->specialities;
                    $DB->set_field('elby_teachers', 'specialities', json_encode($raw), ['id' => $rec->id]);
                }
            } catch (\Exception $e) {
                debugging('Speciality fetch failed for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if (empty($raw) || !is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $spec) {
            $code = (string) ($spec->combinationCode ?? '');
            if ($code === '') {
                continue;
            }
            $levelname = (string) ($spec->levelName ?? $spec->gradeName ?? '');
            $level = '';
            if (preg_match('/(\d+)/', $levelname, $m)) {
                $level = $m[1];
            }
            $key = $code . ':' . $level;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'trade_code' => $code,
                'trade_name' => (string) ($spec->combinationName ?? $code),
                'level' => $level,
                'level_name' => $levelname !== '' ? $levelname : ('Level ' . $level),
            ];
        }
        return $out;
    }

    /**
     * If the teacher has exactly one distinct (trade, level), return it.
     *
     * @param int $userid Moodle user ID.
     * @return array{0:string,1:string}|null [tradecode, level]
     */
    private static function single_speciality(int $userid): ?array {
        $specs = self::get_teacher_specialities($userid);
        if (count($specs) === 1) {
            return [$specs[0]['trade_code'], $specs[0]['level']];
        }
        return null;
    }
}
