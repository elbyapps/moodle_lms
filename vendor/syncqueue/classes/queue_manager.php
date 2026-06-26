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
use stdClass;

/**
 * Queue manager for handling sync queue operations.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_manager {

    /** @var string Queue status constants */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFLICT = 'conflict';

    /** @var string School ID for this instance */
    protected string $schoolid;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->schoolid = get_config('local_syncqueue', 'schoolid') ?: 'unknown';
    }

    /**
     * Add an event to the sync queue.
     *
     * @param base $event The Moodle event.
     * @param string $eventtype Event category.
     * @param int $priority Priority 1-10.
     * @return int|null The queue item ID, or null if skipped.
     */
    public function add_event(base $event, string $eventtype, int $priority = 5): ?int {
        global $DB;

        // Build payload from event data.
        $payload = $this->build_payload($event);
        $payloadjson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $payloadhash = hash('sha256', $payloadjson);

        // Check for duplicate (same payload hash within last hour).
        if ($this->is_duplicate($payloadhash)) {
            return null;
        }

        $now = time();
        $record = new stdClass();
        $record->schoolid = $this->schoolid;
        $record->eventtype = $eventtype;
        $record->eventname = $event->eventname;
        $record->objecttable = $event->objecttable ?? null;
        $record->objectid = $event->objectid ?? null;
        $record->relateduserid = $event->relateduserid ?? null;
        $record->courseid = $event->courseid ?? null;
        $record->payload = $payloadjson;
        $record->payloadhash = $payloadhash;
        $record->priority = $priority;
        $record->status = self::STATUS_PENDING;
        $record->attempts = 0;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record('local_syncqueue_items', $record);
    }

    /**
     * Build payload from event for synchronization.
     *
     * @param base $event The event.
     * @return array Payload data.
     */
    protected function build_payload(base $event): array {
        $data = $event->get_data();

        // Get additional context data based on event type.
        $contextdata = $this->get_context_data($event);

        return [
            'event' => [
                'eventname' => $data['eventname'],
                'component' => $data['component'],
                'action' => $data['action'],
                'target' => $data['target'],
                'objecttable' => $data['objecttable'] ?? null,
                'objectid' => $data['objectid'] ?? null,
                'relateduserid' => $data['relateduserid'] ?? null,
                'userid' => $data['userid'],
                'courseid' => $data['courseid'] ?? null,
                'timecreated' => $data['timecreated'],
                'other' => $data['other'] ?? null,
            ],
            'context' => $contextdata,
            'school' => [
                'id' => $this->schoolid,
                'timestamp' => time(),
            ],
        ];
    }

    /**
     * Get additional context data for the event.
     *
     * @param base $event The event.
     * @return array Context data.
     */
    protected function get_context_data(base $event): array {
        global $DB;

        $data = $event->get_data();
        $context = [];

        // Include user identifier for mapping.
        if (!empty($data['relateduserid'])) {
            $user = $DB->get_record('user', ['id' => $data['relateduserid']], 'id, username, email, idnumber');
            if ($user) {
                $context['user'] = [
                    'localid' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'idnumber' => $user->idnumber,
                ];
            }
        }

        // Include course identifier for mapping.
        if (!empty($data['courseid'])) {
            $course = $DB->get_record('course', ['id' => $data['courseid']], 'id, shortname, idnumber');
            if ($course) {
                $context['course'] = [
                    'localid' => $course->id,
                    'shortname' => $course->shortname,
                    'idnumber' => $course->idnumber,
                ];
            }
        }

        // Get object-specific data based on table.
        if (!empty($data['objecttable']) && !empty($data['objectid'])) {
            $objectdata = $this->get_object_data($data['objecttable'], $data['objectid']);
            if ($objectdata) {
                $context['object'] = $objectdata;
            }
        }

        return $context;
    }

    /**
     * Get data for the affected object.
     *
     * @param string $table Table name.
     * @param int $id Object ID.
     * @return array|null Object data.
     */
    protected function get_object_data(string $table, int $id): ?array {
        global $DB;

        switch ($table) {
            case 'grade_grades':
                $grade = $DB->get_record('grade_grades', ['id' => $id]);
                if ($grade) {
                    $item = $DB->get_record('grade_items', ['id' => $grade->itemid]);
                    return [
                        'table' => $table,
                        'localid' => $id,
                        'userid' => $grade->userid,
                        'rawgrade' => $grade->rawgrade,
                        'finalgrade' => $grade->finalgrade,
                        'feedback' => $grade->feedback,
                        'item' => $item ? [
                            'itemtype' => $item->itemtype,
                            'itemmodule' => $item->itemmodule,
                            'iteminstance' => $item->iteminstance,
                            'itemname' => $item->itemname,
                            'idnumber' => $item->idnumber,
                        ] : null,
                    ];
                }
                break;

            case 'assign_submission':
                $submission = $DB->get_record('assign_submission', ['id' => $id]);
                if ($submission) {
                    $assign = $DB->get_record('assign', ['id' => $submission->assignment]);
                    $cmidnumber = null;
                    if ($assign) {
                        $moduleid = $DB->get_field('modules', 'id', ['name' => 'assign']);
                        $cm = $DB->get_record('course_modules', [
                            'module' => $moduleid,
                            'instance' => $assign->id,
                        ], 'idnumber');
                        $cmidnumber = $cm->idnumber ?? null;
                    }
                    return [
                        'table' => $table,
                        'localid' => $id,
                        'assignment' => $submission->assignment,
                        'userid' => $submission->userid,
                        'status' => $submission->status,
                        'timemodified' => $submission->timemodified,
                        'assignname' => $assign->name ?? null,
                        'assignidnumber' => $cmidnumber,
                    ];
                }
                break;

            case 'quiz_attempts':
                $attempt = $DB->get_record('quiz_attempts', ['id' => $id]);
                if ($attempt) {
                    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
                    $cmidnumber = null;
                    if ($quiz) {
                        $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
                        $cm = $DB->get_record('course_modules', [
                            'module' => $moduleid,
                            'instance' => $quiz->id,
                        ], 'idnumber');
                        $cmidnumber = $cm->idnumber ?? null;
                    }
                    return [
                        'table' => $table,
                        'localid' => $id,
                        'quiz' => $attempt->quiz,
                        'userid' => $attempt->userid,
                        'attempt' => $attempt->attempt,
                        'state' => $attempt->state,
                        'sumgrades' => $attempt->sumgrades,
                        'timefinish' => $attempt->timefinish,
                        'quizname' => $quiz->name ?? null,
                        'quizidnumber' => $cmidnumber,
                    ];
                }
                break;

            case 'forum_posts':
                $post = $DB->get_record('forum_posts', ['id' => $id]);
                if ($post) {
                    return [
                        'table' => $table,
                        'localid' => $id,
                        'discussion' => $post->discussion,
                        'userid' => $post->userid,
                        'subject' => $post->subject,
                        'message' => $post->message,
                        'created' => $post->created,
                    ];
                }
                break;

            case 'user_enrolments':
                $enrol = $DB->get_record('user_enrolments', ['id' => $id]);
                if ($enrol) {
                    $enrolinstance = $DB->get_record('enrol', ['id' => $enrol->enrolid]);
                    return [
                        'table' => $table,
                        'localid' => $id,
                        'userid' => $enrol->userid,
                        'status' => $enrol->status,
                        'timestart' => $enrol->timestart,
                        'timeend' => $enrol->timeend,
                        'enrolmethod' => $enrolinstance ? $enrolinstance->enrol : null,
                    ];
                }
                break;

            case 'course_modules_completion':
                $completion = $DB->get_record('course_modules_completion', ['id' => $id]);
                if ($completion) {
                    $cm = $DB->get_record('course_modules', ['id' => $completion->coursemoduleid]);
                    $modulename = null;
                    if ($cm) {
                        $modulename = $DB->get_field('modules', 'name', ['id' => $cm->module]);
                    }
                    return [
                        'table' => $table,
                        'localid' => $id,
                        'userid' => $completion->userid,
                        'completionstate' => $completion->completionstate,
                        'timemodified' => $completion->timemodified,
                        'coursemodule' => $cm ? [
                            'module' => $cm->module,
                            'instance' => $cm->instance,
                            'modulename' => $modulename,
                            'cmidnumber' => $cm->idnumber ?? null,
                        ] : null,
                    ];
                }
                break;
        }

        return null;
    }

    /**
     * Check if this payload is a duplicate of a recent queue item.
     *
     * @param string $payloadhash Hash of the payload.
     * @return bool True if duplicate.
     */
    protected function is_duplicate(string $payloadhash): bool {
        global $DB;

        $onehourago = time() - 3600;
        return $DB->record_exists_select(
            'local_syncqueue_items',
            'payloadhash = :hash AND timecreated > :time AND status != :failed',
            [
                'hash' => $payloadhash,
                'time' => $onehourago,
                'failed' => self::STATUS_FAILED,
            ]
        );
    }

    /**
     * Queue files associated with an event.
     *
     * @param int $queueitemid The queue item ID.
     * @param base $event The event.
     */
    public function queue_event_files(int $queueitemid, base $event): void {
        global $DB;

        $data = $event->get_data();
        $contextid = $data['contextid'] ?? null;

        if (!$contextid) {
            return;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'assignsubmission_file', 'submission_files', false, 'id', false);

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            $record = new stdClass();
            $record->queueitemid = $queueitemid;
            $record->schoolid = $this->schoolid;
            $record->contenthash = $file->get_contenthash();
            $record->filename = $file->get_filename();
            $record->filesize = $file->get_filesize();
            $record->mimetype = $file->get_mimetype();
            $record->status = self::STATUS_PENDING;
            $record->timecreated = time();

            // Avoid duplicate file entries.
            $exists = $DB->record_exists('local_syncqueue_files', [
                'schoolid' => $this->schoolid,
                'contenthash' => $record->contenthash,
                'status' => self::STATUS_PENDING,
            ]);

            if (!$exists) {
                $DB->insert_record('local_syncqueue_files', $record);
            }
        }
    }

    /**
     * Get pending queue items for processing.
     *
     * @param int $limit Maximum items to retrieve.
     * @return array Array of queue items.
     */
    public function get_pending_items(int $limit = 100): array {
        global $DB;

        $maxretries = get_config('local_syncqueue', 'maxretries') ?: 5;

        return $DB->get_records_select(
            'local_syncqueue_items',
            'status = :pending AND attempts < :maxretries',
            ['pending' => self::STATUS_PENDING, 'maxretries' => $maxretries],
            'priority ASC, timecreated ASC',
            '*',
            0,
            $limit
        );
    }

    /**
     * Mark items as processing.
     *
     * @param array $ids Array of item IDs.
     */
    public function mark_processing(array $ids): void {
        global $DB;

        if (empty($ids)) {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal($ids);
        $params[] = self::STATUS_PROCESSING;
        $params[] = time();

        $DB->execute(
            "UPDATE {local_syncqueue_items}
             SET status = ?, timemodified = ?, attempts = attempts + 1
             WHERE id $insql",
            array_merge([self::STATUS_PROCESSING, time()], $ids)
        );
    }

    /**
     * Mark item as synced.
     *
     * @param int $id Item ID.
     */
    public function mark_synced(int $id): void {
        global $DB;

        $now = time();
        $DB->update_record('local_syncqueue_items', (object) [
            'id' => $id,
            'status' => self::STATUS_SYNCED,
            'timemodified' => $now,
            'timesynced' => $now,
        ]);
    }

    /**
     * Mark item as failed.
     *
     * @param int $id Item ID.
     * @param string $error Error message.
     */
    public function mark_failed(int $id, string $error): void {
        global $DB;

        $item = $DB->get_record('local_syncqueue_items', ['id' => $id]);
        $maxretries = get_config('local_syncqueue', 'maxretries') ?: 5;

        $status = ($item && $item->attempts >= $maxretries) ? self::STATUS_FAILED : self::STATUS_PENDING;

        $DB->update_record('local_syncqueue_items', (object) [
            'id' => $id,
            'status' => $status,
            'lasterror' => $error,
            'timemodified' => time(),
        ]);
    }

    /**
     * Mark item as having a conflict.
     *
     * @param int $id Item ID.
     * @param string $details Conflict details.
     */
    public function mark_conflict(int $id, string $details): void {
        global $DB;

        $DB->update_record('local_syncqueue_items', (object) [
            'id' => $id,
            'status' => self::STATUS_CONFLICT,
            'lasterror' => $details,
            'timemodified' => time(),
        ]);
    }

    /**
     * Get queue statistics.
     *
     * @return stdClass Statistics object.
     */
    public function get_stats(): stdClass {
        global $DB;

        $stats = new stdClass();
        $stats->pending = $DB->count_records('local_syncqueue_items', ['status' => self::STATUS_PENDING]);
        $stats->processing = $DB->count_records('local_syncqueue_items', ['status' => self::STATUS_PROCESSING]);
        $stats->synced = $DB->count_records('local_syncqueue_items', ['status' => self::STATUS_SYNCED]);
        $stats->failed = $DB->count_records('local_syncqueue_items', ['status' => self::STATUS_FAILED]);
        $stats->conflict = $DB->count_records('local_syncqueue_items', ['status' => self::STATUS_CONFLICT]);

        $lastsync = $DB->get_field_sql(
            'SELECT MAX(timesynced) FROM {local_syncqueue_items} WHERE status = ?',
            [self::STATUS_SYNCED]
        );
        $stats->lastsync = $lastsync ?: null;

        return $stats;
    }

    /**
     * Clean up old synced records.
     *
     * @param int $keepdays Number of days to keep synced records.
     * @return int Number of deleted records.
     */
    public function cleanup(int $keepdays = 30): int {
        global $DB;

        $cutoff = time() - ($keepdays * DAYSECS);

        return $DB->delete_records_select(
            'local_syncqueue_items',
            'status = :synced AND timesynced < :cutoff',
            ['synced' => self::STATUS_SYNCED, 'cutoff' => $cutoff]
        );
    }
}
