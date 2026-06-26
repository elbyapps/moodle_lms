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

    /**
     * Refresh the local roster from the central proxy (school mode only).
     *
     * @return array{students:int,teachers:int,total:int} Counts upserted.
     * @throws \moodle_exception If syncqueue is unavailable.
     */
    public function sync_roster(): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/elby_dashboard/lib.php');

        $owncode = local_elby_dashboard_own_school_code();
        if ($owncode === null) {
            // Not a school instance (proxy mode off) — nothing to pull.
            return ['students' => 0, 'teachers' => 0, 'total' => 0];
        }

        if (!class_exists('\local_syncqueue\sync_client')) {
            throw new \moodle_exception('tdmsapierror', 'local_elby_dashboard', '',
                'local_syncqueue is required to pull the roster.');
        }

        $client = new \local_syncqueue\sync_client();
        $roster = $client->tdmp_roster();

        $students = 0;
        foreach ($roster['students'] as $rec) {
            if ($this->upsert($rec, 'student', $owncode)) {
                $students++;
            }
        }
        $teachers = 0;
        foreach ($roster['teachers'] as $rec) {
            if ($this->upsert($rec, 'teacher', $owncode)) {
                $teachers++;
            }
        }

        return ['students' => $students, 'teachers' => $teachers, 'total' => $students + $teachers];
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
     * @return bool True if stored.
     */
    private function upsert(object $data, string $usertype, string $owncode): bool {
        global $DB;

        $sdmsid = ($usertype === 'student')
            ? ($data->studentNumber ?? null)
            : ($data->sdmsStaffNumber ?? $data->staffNumber ?? $data->nidNumber ?? null);
        if (empty($sdmsid)) {
            return false;
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
        return true;
    }
}
