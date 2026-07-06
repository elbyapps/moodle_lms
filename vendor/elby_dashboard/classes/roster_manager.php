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
 * Offline roster cache manager for local_elby_dashboard.
 *
 * On a school server, holds the full list of the school's own students and
 * teachers (pulled from TDMP via the central syncqueue proxy) so that signup
 * and account linking work offline.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Roster cache manager.
 */
class roster_manager {

    /** @var int Caches at or below this total row count are pruned without the shrink guard. */
    private const PRUNE_GUARD_MIN_ROWS = 50;

    /** @var float Largest fraction of a user_type's cached rows a single refresh may remove. */
    private const PRUNE_GUARD_MAX_FRACTION = 0.2;

    /**
     * Refresh the local roster from the central proxy (school mode only).
     *
     * @return array{students:int,teachers:int,total:int,removed:int,warning:string,rostergen:?int}
     *         Counts upserted/pruned plus the adopted central roster generation.
     * @throws \moodle_exception If syncqueue is unavailable.
     */
    public function sync_roster(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/elby_dashboard/lib.php');

        $owncode = local_elby_dashboard_own_school_code();
        if ($owncode === null) {
            // Not a school instance (proxy mode off) — nothing to pull.
            return ['students' => 0, 'teachers' => 0, 'total' => 0, 'removed' => 0, 'warning' => ''];
        }

        if (!class_exists('\local_syncqueue\sync_client')) {
            throw new \moodle_exception('tdmsapierror', 'local_elby_dashboard', '',
                'local_syncqueue is required to pull the roster.');
        }

        $client = new \local_syncqueue\sync_client();
        $roster = $client->tdmp_roster();

        // Snapshot pre-refresh cache sizes per type: the prune guard must judge the
        // shrink against what was cached before this refresh started upserting.
        $beforecounts = $DB->get_records_sql_menu(
            'SELECT user_type, COUNT(*) AS cnt FROM {elby_roster} GROUP BY user_type');

        $seen = [];
        $students = 0;
        foreach ($roster['students'] as $rec) {
            if (($sdmsid = $this->upsert($rec, 'student', $owncode)) !== null) {
                $students++;
                $seen[$sdmsid] = true;
            }
        }
        $teachers = 0;
        foreach ($roster['teachers'] as $rec) {
            if (($sdmsid = $this->upsert($rec, 'teacher', $owncode)) !== null) {
                $teachers++;
                $seen[$sdmsid] = true;
            }
        }

        [$removed, $warning] = $this->prune_stale($seen, $beforecounts);

        // A clean full refresh adopts central's roster generation (ELMS Sync v2
        // §5/§8.1, Option B): central owns the fleet-wide clock and stamps it on the
        // roster it serves, and every learner fact captured afterwards carries this
        // generation so central judges home-tenure in the SAME numbering space it
        // records intervals in. A prune refusal ($warning) means the response was
        // likely truncated — not a full refresh — so the generation is NOT adopted.
        // Guarded so elby_dashboard stays usable if syncqueue is absent.
        if ($warning === '' && class_exists('\local_syncqueue\rostergen')) {
            $gen = $roster['rostergen'] ?? null;
            if ($gen !== null) {
                \local_syncqueue\rostergen::adopt((int) $gen);
            }
        }

        $generation = class_exists('\local_syncqueue\rostergen') ? \local_syncqueue\rostergen::current() : null;
        return ['students' => $students, 'teachers' => $teachers, 'total' => $students + $teachers,
            'removed' => $removed, 'warning' => $warning, 'rostergen' => $generation];
    }

    /**
     * Delete cached rows absent from the fresh roster, refusing mass deletes.
     *
     * A truncated/erroneous TDMP response must not wipe the offline roster: when a
     * refresh would remove more than 20% of a non-trivial cache the prune is skipped
     * (the old cache is kept) unless local_elby_dashboard/force_roster_refresh is
     * set, which permits the shrink once and is then cleared. The 20% test runs per
     * user_type against the pre-refresh counts: teachers are far fewer than students,
     * so a silently-empty teachers list (the proxy degrades a missing list to [])
     * must not slip under a whole-cache percentage diluted by student rows.
     *
     * @param array $seen sdms_ids present in the fresh roster (as keys).
     * @param array $beforecounts Pre-refresh cached row counts keyed by user_type.
     * @return array{0:int,1:string} Rows removed and warning message ('' if none).
     */
    private function prune_stale(array $seen, array $beforecounts): array {
        global $DB;

        $existing = $DB->get_records('elby_roster', null, '', 'id, sdms_id, user_type');
        $stale = [];
        $stalebytype = [];
        foreach ($existing as $row) {
            if (!isset($seen[$row->sdms_id])) {
                $stale[] = $row->id;
                $stalebytype[$row->user_type] = ($stalebytype[$row->user_type] ?? 0) + 1;
            }
        }
        if (empty($stale)) {
            return [0, ''];
        }

        if (array_sum($beforecounts) > self::PRUNE_GUARD_MIN_ROWS) {
            foreach ($stalebytype as $type => $stalecount) {
                // Stale rows predate the refresh (upserts never touch them), so
                // they are all counted in the pre-refresh snapshot.
                $before = (int) ($beforecounts[$type] ?? $stalecount);
                // max(1, ...): never refuse over a single departed row of a tiny type.
                if ($stalecount <= max(1, $before * self::PRUNE_GUARD_MAX_FRACTION)) {
                    continue;
                }
                if (!get_config('local_elby_dashboard', 'force_roster_refresh')) {
                    $warning = "Roster prune refused: refresh would remove {$stalecount} of {$before} cached"
                        . " {$type} entries (>20%) — likely a truncated TDMP response. Old cache kept. Set"
                        . ' local_elby_dashboard/force_roster_refresh to accept the shrink once.';
                    $this->log_prune_refused($warning, count($stale), (int) array_sum($beforecounts));
                    return [0, $warning];
                }
                // One-shot override: consume it so the next erroneous shrink is caught again.
                unset_config('force_roster_refresh', 'local_elby_dashboard');
                break;
            }
        }

        $DB->delete_records_list('elby_roster', 'id', $stale);
        return [count($stale), ''];
    }

    /**
     * Record a refused mass prune in the sync log.
     *
     * @param string $message Human-readable warning.
     * @param int $stale Rows the refresh wanted to remove.
     * @param int $total Rows currently cached.
     */
    private function log_prune_refused(string $message, int $stale, int $total): void {
        global $DB, $USER;

        $record = new \stdClass();
        $record->sync_type = 'roster';
        $record->userid = $USER->id ?? 0;
        $record->operation = 'error';
        $record->error_message = $message;
        $record->details = json_encode(['stale' => $stale, 'total' => $total]);
        $record->triggered_by = 'task';
        $record->timecreated = time();

        try {
            $DB->insert_record('elby_sync_log', $record);
        } catch (\Exception $e) {
            debugging('Failed to log roster prune refusal: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Get a cached raw TDMP record by SDMS id, or null if not cached.
     *
     * @param string $sdmsid SDMS identifier.
     * @param string|null $usertype Optional type filter ("student", "staff"/"teacher").
     * @return object|null Raw TDMP record (as returned by the gateway), or null.
     */
    public function get_record(string $sdmsid, ?string $usertype = null): ?object {
        global $DB;
        $params = ['sdms_id' => $sdmsid];
        if ($usertype !== null) {
            $params['user_type'] = ($usertype === 'staff') ? 'teacher' : $usertype;
        }
        $row = $DB->get_record('elby_roster', $params, 'payload');
        if (!$row || empty($row->payload)) {
            return null;
        }
        $obj = json_decode($row->payload);
        return is_object($obj) ? $obj : null;
    }

    /**
     * Upsert one raw TDMP record into the roster cache.
     *
     * @param object $data Raw TDMP record.
     * @param string $usertype "student" or "teacher".
     * @param string $owncode This school's code (fallback school_code).
     * @return string|null The stored sdms_id, or null if the record had no usable id.
     */
    private function upsert(object $data, string $usertype, string $owncode): ?string {
        global $DB;

        $sdmsid = ($usertype === 'student')
            ? ($data->studentNumber ?? null)
            : ($data->sdmsStaffNumber ?? $data->staffNumber ?? $data->nidNumber ?? null);
        if (empty($sdmsid)) {
            return null;
        }

        $names = $data->names ?? trim(($data->lastName ?? '') . ' ' . ($data->firstName ?? ''));

        $record = new \stdClass();
        $record->sdms_id = (string) $sdmsid;
        $record->user_type = $usertype;
        $record->school_code = (string) ($data->schoolCode ?? $data->schooCode ?? $owncode);
        $record->names = $names !== '' ? $names : null;
        $record->gender = $data->gender ?? null;
        $record->program = ($usertype === 'student') ? ($data->combinationName ?? null) : null;
        $record->program_code = ($usertype === 'student') ? ($data->combinationCode ?? null) : null;
        $record->study_level = ($usertype === 'student') ? ($data->levelName ?? null) : null;
        $record->class_grade = ($usertype === 'student') ? ($data->gradeName ?? null) : null;
        $record->academic_year = $data->currentAcademicYear ?? $data->academicYear ?? null;
        $record->status = $data->registrationStatus ?? $data->employmentStatus ?? null;
        $record->position = ($usertype === 'teacher') ? ($data->positionName ?? null) : null;
        $record->payload = json_encode($data);
        $record->timemodified = time();

        $existing = $DB->get_record('elby_roster', ['sdms_id' => $record->sdms_id], 'id');
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('elby_roster', $record);
        } else {
            $record->timecached = time();
            $DB->insert_record('elby_roster', $record);
        }
        return $record->sdms_id;
    }
}
