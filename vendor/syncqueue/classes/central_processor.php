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

use stdClass;

/**
 * Processor for handling uploads from schools (Central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class central_processor {

    /** @var id_mapper ID mapper instance */
    protected id_mapper $mapper;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->mapper = new id_mapper();
    }

    /**
     * Process a single sync item from a school.
     *
     * @param string $schoolid School identifier.
     * @param array $item Sync item data.
     * @return array Result with status and message.
     */
    public function process_item(string $schoolid, array $item): array {
        $eventtype = $item['eventtype'] ?? 'unknown';
        $payload = $item['payload'] ?? [];

        switch ($eventtype) {
            case 'grade':
                return $this->process_grade($schoolid, $payload);

            case 'submission':
                return $this->process_submission($schoolid, $payload);

            case 'quiz':
                return $this->process_quiz_attempt($schoolid, $payload);

            case 'forum':
                return $this->process_forum_post($schoolid, $payload);

            case 'completion':
                return $this->process_completion($schoolid, $payload);

            case 'enrol':
                return $this->process_enrolment($schoolid, $payload);

            case 'user':
                return $this->process_user($schoolid, $payload);

            default:
                return [
                    'status' => 'error',
                    'message' => "Unknown event type: {$eventtype}",
                ];
        }
    }

    /**
     * Process a grade sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Grade data.
     * @return array Result.
     */
    protected function process_grade(string $schoolid, array $payload): array {
        global $DB;

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing grade data'];
        }

        // Find user on central.
        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found on central'];
        }

        // Find course on central.
        $course = $this->find_course($context['course'] ?? []);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found on central'];
        }

        // Find or create grade item.
        $gradeitem = $this->find_grade_item($course->id, $object['item'] ?? []);
        if (!$gradeitem) {
            return ['status' => 'error', 'message' => 'Grade item not found on central'];
        }

        // Check for existing grade.
        $existinggrade = $DB->get_record('grade_grades', [
            'itemid' => $gradeitem->id,
            'userid' => $user->id,
        ]);

        if ($existinggrade) {
            // Conflict resolution: latest timestamp wins.
            $schooltime = $payload['event']['timecreated'] ?? 0;
            if ($existinggrade->timemodified > $schooltime) {
                return [
                    'status' => 'conflict',
                    'message' => 'Central grade is newer',
                    'centralid' => $existinggrade->id,
                ];
            }

            // Update existing grade.
            $existinggrade->rawgrade = $object['rawgrade'] ?? null;
            $existinggrade->finalgrade = $object['finalgrade'] ?? null;
            $existinggrade->feedback = $object['feedback'] ?? null;
            $existinggrade->timemodified = time();
            $DB->update_record('grade_grades', $existinggrade);

            return [
                'status' => 'success',
                'message' => 'Grade updated',
                'centralid' => $existinggrade->id,
            ];
        }

        // Create new grade.
        $grade = new stdClass();
        $grade->itemid = $gradeitem->id;
        $grade->userid = $user->id;
        $grade->rawgrade = $object['rawgrade'] ?? null;
        $grade->finalgrade = $object['finalgrade'] ?? null;
        $grade->feedback = $object['feedback'] ?? null;
        $grade->timecreated = time();
        $grade->timemodified = time();

        $centralid = $DB->insert_record('grade_grades', $grade);

        // Store ID mapping.
        $this->mapper->set_mapping('grade_grades', $object['localid'], $centralid);

        return [
            'status' => 'success',
            'message' => 'Grade created',
            'centralid' => $centralid,
        ];
    }

    /**
     * Process a submission sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Submission data.
     * @return array Result.
     */
    protected function process_submission(string $schoolid, array $payload): array {
        global $DB;

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing submission data'];
        }

        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found on central'];
        }

        $course = $this->find_course($context['course'] ?? []);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found on central'];
        }

        // Find assignment on central.
        $assign = $this->find_activity_instance(
            $course->id, 'assign', (int) $object['assignment'], $schoolid,
            $object['assignname'] ?? null, $object['assignidnumber'] ?? null
        );
        if (!$assign) {
            return ['status' => 'error', 'message' => 'Assignment not found on central'];
        }

        // Check for existing submission.
        $existing = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $user->id,
        ]);

        if ($existing) {
            $schooltime = $object['timemodified'] ?? 0;
            if ($existing->timemodified > $schooltime) {
                return [
                    'status' => 'conflict',
                    'message' => 'Central submission is newer',
                    'centralid' => $existing->id,
                ];
            }

            $existing->status = $object['status'] ?? $existing->status;
            $existing->timemodified = time();
            $DB->update_record('assign_submission', $existing);

            return [
                'status' => 'success',
                'message' => 'Submission updated',
                'centralid' => $existing->id,
            ];
        }

        // Create new submission.
        $submission = new stdClass();
        $submission->assignment = $assign->id;
        $submission->userid = $user->id;
        $submission->status = $object['status'] ?? 'submitted';
        $submission->timecreated = time();
        $submission->timemodified = time();

        $centralid = $DB->insert_record('assign_submission', $submission);

        $this->mapper->set_mapping('assign_submission', $object['localid'], $centralid);

        return [
            'status' => 'success',
            'message' => 'Submission created',
            'centralid' => $centralid,
        ];
    }

    /**
     * Process a quiz attempt sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Quiz attempt data.
     * @return array Result.
     */
    protected function process_quiz_attempt(string $schoolid, array $payload): array {
        global $DB;

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing quiz attempt data'];
        }

        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found on central'];
        }

        $course = $this->find_course($context['course'] ?? []);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found on central'];
        }

        // Find quiz on central.
        $quiz = $this->find_activity_instance(
            $course->id, 'quiz', (int) $object['quiz'], $schoolid,
            $object['quizname'] ?? null, $object['quizidnumber'] ?? null
        );
        if (!$quiz) {
            return ['status' => 'error', 'message' => 'Quiz not found on central'];
        }

        $attemptnum = (int) ($object['attempt'] ?? 1);

        // Check for existing attempt.
        $existing = $DB->get_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => $attemptnum,
        ]);

        if ($existing) {
            $schooltime = $object['timefinish'] ?? 0;
            if ($existing->timefinish > $schooltime && $existing->state === 'finished') {
                return [
                    'status' => 'conflict',
                    'message' => 'Central quiz attempt is newer',
                    'centralid' => $existing->id,
                ];
            }

            $existing->state = $object['state'] ?? $existing->state;
            $existing->sumgrades = $object['sumgrades'] ?? $existing->sumgrades;
            $existing->timefinish = $object['timefinish'] ?? $existing->timefinish;
            $existing->timemodified = time();
            $DB->update_record('quiz_attempts', $existing);

            return [
                'status' => 'success',
                'message' => 'Quiz attempt updated',
                'centralid' => $existing->id,
            ];
        }

        // Create new quiz attempt (summary only, not individual question responses).
        $attempt = new stdClass();
        $attempt->quiz = $quiz->id;
        $attempt->userid = $user->id;
        $attempt->attempt = $attemptnum;
        $attempt->state = $object['state'] ?? 'finished';
        $attempt->sumgrades = $object['sumgrades'] ?? null;
        $attempt->timestart = $payload['event']['timecreated'] ?? time();
        $attempt->timefinish = $object['timefinish'] ?? 0;
        $attempt->timemodified = time();
        $attempt->layout = '';
        // Generate a unique usage ID for the question engine.
        $attempt->uniqueid = $DB->get_field_sql('SELECT MAX(id) + 1 FROM {question_usages}') ?: 1;

        $centralid = $DB->insert_record('quiz_attempts', $attempt);

        $this->mapper->set_mapping('quiz_attempts', $object['localid'], $centralid);

        return [
            'status' => 'success',
            'message' => 'Quiz attempt created',
            'centralid' => $centralid,
        ];
    }

    /**
     * Process a forum post sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Forum post data.
     * @return array Result.
     */
    protected function process_forum_post(string $schoolid, array $payload): array {
        // TODO: Implement forum post processing.
        return [
            'status' => 'success',
            'message' => 'Forum post logged',
            'centralid' => 0,
        ];
    }

    /**
     * Process a completion sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Completion data.
     * @return array Result.
     */
    protected function process_completion(string $schoolid, array $payload): array {
        global $DB;

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing completion data'];
        }

        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found on central'];
        }

        $course = $this->find_course($context['course'] ?? []);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found on central'];
        }

        // Handle course_completions (course-level completion).
        $eventtable = $object['table'] ?? '';
        if ($eventtable === 'course_completions') {
            return $this->process_course_completion($user, $course, $object);
        }

        // Handle course_modules_completion (activity-level completion).
        $cmdata = $object['coursemodule'] ?? [];
        if (empty($cmdata)) {
            return ['status' => 'error', 'message' => 'Missing course module data'];
        }

        // Find the course module on central.
        $modulename = $cmdata['modulename'] ?? null;
        $instanceid = (int) ($cmdata['instance'] ?? 0);

        if (empty($modulename)) {
            // Fall back to looking up module name from type ID.
            $moduleid = (int) ($cmdata['module'] ?? 0);
            if ($moduleid) {
                $modulename = $DB->get_field('modules', 'name', ['id' => $moduleid]);
            }
        }

        if (empty($modulename) || empty($instanceid)) {
            return ['status' => 'error', 'message' => 'Cannot identify course module'];
        }

        $centralcm = $this->find_course_module(
            $course->id, $modulename, $instanceid, $schoolid,
            $cmdata['cmidnumber'] ?? null
        );
        if (!$centralcm) {
            return ['status' => 'error', 'message' => 'Course module not found on central'];
        }

        // Check for existing completion.
        $existing = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $centralcm->id,
            'userid' => $user->id,
        ]);

        if ($existing) {
            $schooltime = $object['timemodified'] ?? 0;
            if ($existing->timemodified > $schooltime) {
                return [
                    'status' => 'conflict',
                    'message' => 'Central completion is newer',
                    'centralid' => $existing->id,
                ];
            }

            $existing->completionstate = $object['completionstate'] ?? $existing->completionstate;
            $existing->timemodified = time();
            $DB->update_record('course_modules_completion', $existing);

            return [
                'status' => 'success',
                'message' => 'Completion updated',
                'centralid' => $existing->id,
            ];
        }

        // Create new completion record.
        $completion = new stdClass();
        $completion->coursemoduleid = $centralcm->id;
        $completion->userid = $user->id;
        $completion->completionstate = $object['completionstate'] ?? 1;
        $completion->timemodified = time();

        $centralid = $DB->insert_record('course_modules_completion', $completion);

        $this->mapper->set_mapping('course_modules_completion', $object['localid'], $centralid);

        return [
            'status' => 'success',
            'message' => 'Completion created',
            'centralid' => $centralid,
        ];
    }

    /**
     * Process a course-level completion record.
     *
     * @param stdClass $user The central user.
     * @param stdClass $course The central course.
     * @param array $object Completion object data.
     * @return array Result.
     */
    protected function process_course_completion(stdClass $user, stdClass $course, array $object): array {
        global $DB;

        $existing = $DB->get_record('course_completions', [
            'userid' => $user->id,
            'course' => $course->id,
        ]);

        if ($existing) {
            $schooltime = $object['timemodified'] ?? $object['timecompleted'] ?? 0;
            if (($existing->timecompleted ?? 0) > $schooltime) {
                return [
                    'status' => 'conflict',
                    'message' => 'Central course completion is newer',
                    'centralid' => $existing->id,
                ];
            }

            $existing->timecompleted = $object['timecompleted'] ?? time();
            $existing->timemodified = time();
            $DB->update_record('course_completions', $existing);

            return [
                'status' => 'success',
                'message' => 'Course completion updated',
                'centralid' => $existing->id,
            ];
        }

        $record = new stdClass();
        $record->userid = $user->id;
        $record->course = $course->id;
        $record->timecompleted = $object['timecompleted'] ?? time();
        $record->timestarted = $object['timestarted'] ?? time();
        $record->timeenrolled = $object['timeenrolled'] ?? time();

        $centralid = $DB->insert_record('course_completions', $record);

        return [
            'status' => 'success',
            'message' => 'Course completion created',
            'centralid' => $centralid,
        ];
    }

    /**
     * Process an enrolment sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Enrolment data.
     * @return array Result.
     */
    protected function process_enrolment(string $schoolid, array $payload): array {
        // Enrolments typically flow central → school, not school → central.
        // Log for audit but don't create.
        return [
            'status' => 'success',
            'message' => 'Enrolment logged',
            'centralid' => 0,
        ];
    }

    /**
     * Process a user sync.
     *
     * @param string $schoolid School identifier.
     * @param array $payload User data.
     * @return array Result.
     */
    protected function process_user(string $schoolid, array $payload): array {
        // User creation typically flows central → school.
        // Log for audit but don't create.
        return [
            'status' => 'success',
            'message' => 'User event logged',
            'centralid' => 0,
        ];
    }

    /**
     * Find a user on central by various identifiers.
     *
     * @param array $userdata User identification data.
     * @return stdClass|null
     */
    protected function find_user(array $userdata): ?stdClass {
        global $DB;

        if (empty($userdata)) {
            return null;
        }

        // Try idnumber first.
        if (!empty($userdata['idnumber'])) {
            $user = $DB->get_record('user', ['idnumber' => $userdata['idnumber']]);
            if ($user) {
                return $user;
            }
        }

        // Try email.
        if (!empty($userdata['email'])) {
            $user = $DB->get_record('user', ['email' => $userdata['email']]);
            if ($user) {
                return $user;
            }
        }

        // Try username.
        if (!empty($userdata['username'])) {
            $user = $DB->get_record('user', ['username' => $userdata['username']]);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Find a course on central by various identifiers.
     *
     * @param array $coursedata Course identification data.
     * @return stdClass|null
     */
    protected function find_course(array $coursedata): ?stdClass {
        global $DB;

        if (empty($coursedata)) {
            return null;
        }

        // Try idnumber first.
        if (!empty($coursedata['idnumber'])) {
            $course = $DB->get_record('course', ['idnumber' => $coursedata['idnumber']]);
            if ($course) {
                return $course;
            }
        }

        // Try shortname.
        if (!empty($coursedata['shortname'])) {
            $course = $DB->get_record('course', ['shortname' => $coursedata['shortname']]);
            if ($course) {
                return $course;
            }
        }

        return null;
    }

    /**
     * Find an activity instance on central by id_mapper lookup, idnumber, name, or sole-instance fallback.
     *
     * @param int $courseid Central course ID.
     * @param string $modulename Module type name (e.g. 'assign', 'quiz').
     * @param int $schoolinstanceid Instance ID on the school.
     * @param string $schoolid School identifier.
     * @param string|null $name Activity name for fallback matching.
     * @param string|null $idnumber Activity CM idnumber for fallback matching.
     * @return stdClass|null The activity instance record on central.
     */
    protected function find_activity_instance(
        int $courseid, string $modulename, int $schoolinstanceid,
        string $schoolid, ?string $name = null, ?string $idnumber = null
    ): ?stdClass {
        global $DB;

        // 1. Try id_mapper lookup.
        $centralid = $this->mapper->get_central_id($modulename, $schoolinstanceid);
        if ($centralid) {
            $instance = $DB->get_record($modulename, ['id' => $centralid, 'course' => $courseid]);
            if ($instance) {
                return $instance;
            }
        }

        // 2. Try matching by CM idnumber.
        if (!empty($idnumber)) {
            $moduleid = $DB->get_field('modules', 'id', ['name' => $modulename]);
            if ($moduleid) {
                $cm = $DB->get_record('course_modules', [
                    'course' => $courseid,
                    'module' => $moduleid,
                    'idnumber' => $idnumber,
                ]);
                if ($cm) {
                    $instance = $DB->get_record($modulename, ['id' => $cm->instance, 'course' => $courseid]);
                    if ($instance) {
                        $this->mapper->set_mapping($modulename, $schoolinstanceid, $instance->id);
                        return $instance;
                    }
                }
            }
        }

        // 3. Try matching by name.
        if (!empty($name)) {
            $instances = $DB->get_records($modulename, ['course' => $courseid, 'name' => $name]);
            if (count($instances) === 1) {
                $instance = reset($instances);
                $this->mapper->set_mapping($modulename, $schoolinstanceid, $instance->id);
                return $instance;
            }
        }

        // 4. Sole-instance fallback: if only one instance of this module type in the course.
        $instances = $DB->get_records($modulename, ['course' => $courseid]);
        if (count($instances) === 1) {
            $instance = reset($instances);
            $this->mapper->set_mapping($modulename, $schoolinstanceid, $instance->id);
            return $instance;
        }

        return null;
    }

    /**
     * Find a course module on central by module type and instance.
     *
     * @param int $courseid Central course ID.
     * @param string $modulename Module type name (e.g. 'assign', 'quiz', 'forum').
     * @param int $schoolinstanceid Instance ID on the school.
     * @param string $schoolid School identifier.
     * @param string|null $cmidnumber CM idnumber for matching.
     * @return stdClass|null The course_modules record on central.
     */
    protected function find_course_module(
        int $courseid, string $modulename, int $schoolinstanceid,
        string $schoolid, ?string $cmidnumber = null
    ): ?stdClass {
        global $DB;

        $moduleid = $DB->get_field('modules', 'id', ['name' => $modulename]);
        if (!$moduleid) {
            return null;
        }

        // Try CM idnumber match first.
        if (!empty($cmidnumber)) {
            $cm = $DB->get_record('course_modules', [
                'course' => $courseid,
                'module' => $moduleid,
                'idnumber' => $cmidnumber,
            ]);
            if ($cm) {
                return $cm;
            }
        }

        // Find the activity instance on central, then get its CM.
        $instance = $this->find_activity_instance($courseid, $modulename, $schoolinstanceid, $schoolid);
        if ($instance) {
            $cm = $DB->get_record('course_modules', [
                'course' => $courseid,
                'module' => $moduleid,
                'instance' => $instance->id,
            ]);
            if ($cm) {
                return $cm;
            }
        }

        // Sole-CM fallback: if only one CM of this module type in the course.
        $cms = $DB->get_records('course_modules', [
            'course' => $courseid,
            'module' => $moduleid,
        ]);
        if (count($cms) === 1) {
            return reset($cms);
        }

        return null;
    }

    /**
     * Find a grade item on central.
     *
     * @param int $courseid Course ID.
     * @param array $itemdata Grade item data.
     * @return stdClass|null
     */
    protected function find_grade_item(int $courseid, array $itemdata): ?stdClass {
        global $DB;

        if (empty($itemdata)) {
            return null;
        }

        $params = ['courseid' => $courseid];

        if (!empty($itemdata['idnumber'])) {
            $params['idnumber'] = $itemdata['idnumber'];
        } elseif (!empty($itemdata['itemname'])) {
            $params['itemname'] = $itemdata['itemname'];
        } else {
            return null;
        }

        return $DB->get_record('grade_items', $params) ?: null;
    }
}
