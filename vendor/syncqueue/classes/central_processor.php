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

    /** @var id_mapper|null ID mapper bound to the school currently being processed */
    protected ?id_mapper $mapper = null;

    /**
     * @var bool Whether the current apply is an authoritative v2 fact. When true,
     * the wall-clock last-write-wins gates are skipped: the v2 ingest applier has
     * already established ordering by monotonic factversion (doc §8.1 AGS), so a
     * stale-clock verdict would wrongly drop a legitimate update (e.g. a regrade
     * whose source-deterministic payload carries no dispatch timestamp). The
     * legacy synchronous upload path never sets this, so its behaviour is
     * unchanged. The full LWW removal + overridden-grade/latch appliers are step 4.
     */
    protected bool $authoritative = false;

    /**
     * Mark subsequent applies as authoritative v2 facts (ordering already decided
     * by factversion), so the wall-clock LWW gates are bypassed.
     *
     * @param bool $authoritative
     */
    public function set_authoritative(bool $authoritative): void {
        $this->authoritative = $authoritative;
    }

    /**
     * @var array|null Ordering + tenure context of the v2 fact currently being
     * applied (ELMS Sync v2 step 4). The async ingest applier sets this from the
     * ingest row before process_item so the overridden-grade / completion-latch
     * appliers can tenure-gate and AGS-order the write (tenure::in_force /
     * tenure::is_stale) without reaching back into the ingest table. This is an
     * inert seam: the legacy synchronous upload path never sets it, so
     * fact_context() returns null there and applier behaviour is unchanged.
     */
    protected ?array $factcontext = null;

    /**
     * Provide (or clear) the current fact's ordering + tenure context.
     *
     * @param array|null $ctx origin, epoch, schoolseq, rostergen, sdms, itemuuid,
     *        lineageuuid, factuuid, factversion; or null to clear.
     */
    public function set_fact_context(?array $ctx): void {
        $this->factcontext = $ctx;
    }

    /**
     * The current fact's ordering + tenure context, or null on the legacy path.
     *
     * @return array|null
     */
    public function fact_context(): ?array {
        return $this->factcontext;
    }

    /**
     * Process a single sync item from a school.
     *
     * @param string $schoolid School identifier.
     * @param array $item Sync item data.
     * @return array Result with status and message.
     */
    public function process_item(string $schoolid, array $item): array {
        // Bind id mappings to the authenticated school so local ids from
        // different schools can never collide in one shared namespace.
        if ($this->mapper === null || $this->mapper->get_schoolid() !== $schoolid) {
            $this->mapper = new id_mapper($schoolid);
        }

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

            case 'account':
                return $this->process_account($schoolid, $payload);

            default:
                return [
                    'status' => 'error',
                    'message' => "Unknown event type: {$eventtype}",
                ];
        }
    }

    /**
     * Map a v2 upstream fact type to the event category process_item dispatches on.
     *
     * The async ingest applier stores a fact under its v2 facttype
     * (local_syncqueue_ingest.facttype = local_syncqueue_outbox.entitytype), but
     * process_item switches on the legacy eventtype vocabulary. Two v2 fact types
     * are named differently from their applier category — quiz_attempt -> quiz and
     * enrolment -> enrol — the rest are identical. An unrecognised fact type is
     * returned unchanged so process_item's default branch reports it as unknown
     * (which the applier then retries rather than silently dropping).
     *
     * @param string $facttype v2 fact type.
     * @return string The eventtype process_item expects.
     */
    public static function eventtype_for_facttype(string $facttype): string {
        static $map = [
            'quiz_attempt' => 'quiz',
            'enrolment' => 'enrol',
        ];
        return $map[$facttype] ?? $facttype;
    }

    /**
     * Process a grade sync: v2-authoritative overridden-grade applier, or the
     * legacy synchronous raw-grade write.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Grade data.
     * @return array Result.
     */
    protected function process_grade(string $schoolid, array $payload): array {
        if ($this->authoritative) {
            return $this->apply_grade_fact($schoolid, $payload);
        }
        return $this->process_grade_legacy($schoolid, $payload);
    }

    /**
     * Apply a v2 grade fact as an OVERRIDDEN leaf-item finalgrade (doc §8.2, spike a).
     *
     * The leaf grade item is resolved by its stamped grade-item UUID idnumber and
     * written through the grade API as an overridden finalgrade, which a regrade
     * and a later module grade_update leave untouched. Category and course TOTAL
     * grades are NEVER written — local aggregation recomputes them. Across in-tenure
     * origins the pinned policy is Highest, so the overridden value is the max ever
     * asserted (a later lower re-take can never erase a higher record). Ordering is
     * by factversion (apply_ingest pre-checks supersession) + tenure + AGS, never a
     * wall clock; the write is echo-suppressed so its own user_graded event is not
     * re-captured as a fresh fact.
     *
     * @param string $schoolid Authoring school id (fact origin).
     * @param array $payload Grade fact payload.
     * @return array Result.
     */
    protected function apply_grade_fact(string $schoolid, array $payload): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];
        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing grade data'];
        }

        // Category/course TOTAL grades are never synced (doc §8.2). The upstream
        // capture only emits leaf grades, but refuse anything non-leaf defensively.
        $itemmeta = $object['item'] ?? [];
        $itemtype = (string) ($itemmeta['itemtype'] ?? '');
        if ($itemtype !== '' && !in_array($itemtype, ['mod', 'manual'], true)) {
            return ['status' => 'success', 'centralid' => 0,
                'message' => "ignored non-leaf grade item ({$itemtype}); totals are locally aggregated"];
        }

        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return $this->user_unresolved_error($context['user'] ?? []);
        }
        $course = $this->find_course($context['course'] ?? []);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found on central'];
        }
        $gradeitem = $this->find_grade_item($course->id, $itemmeta);
        if (!$gradeitem) {
            return ['status' => 'error', 'message' => 'Grade item not found on central'];
        }
        // Defence in depth: never author an aggregated (course/category) total row.
        if (in_array((string) $gradeitem->itemtype, ['course', 'category'], true)) {
            return ['status' => 'success', 'centralid' => 0,
                'message' => 'refused to write aggregated total grade'];
        }

        // v2 ordering + tenure gate (own-origin skip / tenure-fail / AGS stale).
        $sdms = $this->sdms_of($context);
        $itemtoken = $this->ags_token('gi', (string) $gradeitem->idnumber, (int) $gradeitem->id);
        $gate = $this->fact_gate($schoolid, $sdms, $itemtoken, (string) $gradeitem->idnumber);
        if ($gate !== null) {
            return $gate;
        }

        // A null-finalgrade fact carries no authoritative value to override.
        $incoming = $this->to_grade($object['finalgrade'] ?? null);
        if ($incoming === null) {
            $this->observe_fact_seq($schoolid, $sdms, $itemtoken);
            return ['status' => 'success', 'centralid' => 0,
                'message' => 'grade fact carries no finalgrade; nothing to override'];
        }

        $gi = \grade_item::fetch(['id' => $gradeitem->id]);
        if (!$gi) {
            return ['status' => 'error', 'message' => 'Grade item vanished on central'];
        }
        $existing = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);

        // Highest merge across in-tenure origins (pinned for national courses).
        $newvalue = $incoming;
        if ($existing && $existing->finalgrade !== null) {
            $newvalue = max((float) $existing->finalgrade, $incoming);
        }

        // Idempotent: an already-overridden identical value is left untouched (no
        // needless user_graded event, no timemodified churn).
        if ($existing && $existing->finalgrade !== null && (int) $existing->overridden > 0
                && !grade_floats_different((float) $existing->finalgrade, $newvalue)) {
            $this->observe_fact_seq($schoolid, $sdms, $itemtoken);
            return ['status' => 'success', 'centralid' => (int) $existing->id,
                'message' => 'grade already overridden at ' . $newvalue];
        }

        $feedback = (isset($object['feedback']) && $object['feedback'] !== null)
            ? (string) $object['feedback'] : false;

        // Write the overridden leaf finalgrade, echo-suppressed.
        capture::suppress(true);
        try {
            $ok = $gi->update_final_grade($user->id, $newvalue, 'local_syncqueue', $feedback);
        } finally {
            capture::suppress(false);
        }
        if ($ok === false) {
            return ['status' => 'error',
                'message' => 'grade_item::update_final_grade refused (locked or no gradetype)'];
        }

        $this->observe_fact_seq($schoolid, $sdms, $itemtoken);
        $written = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
        return ['status' => 'success', 'centralid' => $written ? (int) $written->id : 0,
            'message' => 'overridden leaf grade written (' . $newvalue . ')'];
    }

    /**
     * Legacy synchronous raw-grade write (pre-v2 upload path). Wall-clock LWW.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Grade data.
     * @return array Result.
     */
    protected function process_grade_legacy(string $schoolid, array $payload): array {
        global $DB;

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing grade data'];
        }

        // Find user on central.
        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return $this->user_unresolved_error($context['user'] ?? []);
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
            return $this->user_unresolved_error($context['user'] ?? []);
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

        // Moodle keys each submission attempt uniquely by (assignment, userid,
        // groupid, attemptnumber); capture carries groupid + attemptnumber so central
        // preserves distinct attempts instead of aliasing them onto one row.
        $attemptnumber = (int) ($object['attemptnumber'] ?? 0);
        $groupid = (int) ($object['groupid'] ?? 0);

        // v2 ordering + tenure gate (per-(group, attempt) token so distinct attempts /
        // groups keep independent AGS high-waters). Runs only for an authoritative v2
        // apply; the legacy path stays ungated.
        $sdms = '';
        $itemtoken = '';
        if ($this->authoritative) {
            $sdms = $this->sdms_of($context);
            $cmidnumber = (string) ($object['assignidnumber'] ?? '');
            $itemtoken = $this->ags_token('sub', $cmidnumber, (int) $assign->id)
                . ':g' . $groupid . ':a' . $attemptnumber;
            $gate = $this->fact_gate($schoolid, $sdms, $itemtoken, $cmidnumber);
            if ($gate !== null) {
                return $gate;
            }
        }

        // Find the EXACT attempt, not just any submission for the user/assignment.
        $existing = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $user->id,
            'groupid' => $groupid,
            'attemptnumber' => $attemptnumber,
        ]);

        if ($existing) {
            $schooltime = $object['timemodified'] ?? 0;
            if (!$this->authoritative && $existing->timemodified > $schooltime) {
                return [
                    'status' => 'conflict',
                    'message' => 'Central submission is newer',
                    'centralid' => $existing->id,
                ];
            }

            $existing->status = $object['status'] ?? $existing->status;
            $existing->timemodified = time();
            $DB->update_record('assign_submission', $existing);
            $centralid = (int) $existing->id;
        } else {
            $submission = new stdClass();
            $submission->assignment = $assign->id;
            $submission->userid = $user->id;
            $submission->groupid = $groupid;
            $submission->attemptnumber = $attemptnumber;
            $submission->latest = 0;
            $submission->status = $object['status'] ?? 'submitted';
            $submission->timecreated = time();
            $submission->timemodified = time();
            $centralid = (int) $DB->insert_record('assign_submission', $submission);
            $this->mapper->set_mapping('assign_submission', $object['localid'], $centralid);
        }

        // Recompute `latest` DETERMINISTICALLY from attemptnumber: the highest attempt
        // central holds for this (assignment, userid, groupid) is the current one.
        // Trusting the payload's historical `latest` would let an out-of-order retry of
        // an OLDER attempt clear the flag from a newer attempt already applied —
        // apply_ingest drains buffered/retry rows independently and can replay a failed
        // older fact after a newer one, and the per-attempt AGS token does not order
        // across attempts. Order-independent, so replays converge to the same result.
        $maxattempt = (int) $DB->get_field_sql(
            'SELECT MAX(attemptnumber) FROM {assign_submission} '
            . 'WHERE assignment = ? AND userid = ? AND groupid = ?',
            [$assign->id, $user->id, $groupid]);
        $DB->execute(
            'UPDATE {assign_submission} SET latest = CASE WHEN attemptnumber = ? THEN 1 ELSE 0 END '
            . 'WHERE assignment = ? AND userid = ? AND groupid = ?',
            [$maxattempt, $assign->id, $user->id, $groupid]);

        if ($this->authoritative) {
            $this->observe_fact_seq($schoolid, $sdms, $itemtoken);
        }
        return [
            'status' => 'success',
            'message' => $existing ? 'Submission updated' : 'Submission created',
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
            return $this->user_unresolved_error($context['user'] ?? []);
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

        // v2 ordering + tenure gate. Uses a per-ATTEMPT AGS token so distinct attempts
        // keep independent high-waters (a bare cm token would stale-drop a later
        // attempt that arrives at a lower seq than an earlier one's regrade). Runs
        // only for an authoritative v2 apply; the legacy path stays ungated.
        $sdms = '';
        $itemtoken = '';
        if ($this->authoritative) {
            $sdms = $this->sdms_of($context);
            $cmidnumber = (string) ($object['quizidnumber'] ?? '');
            $itemtoken = $this->ags_token('qz', $cmidnumber, (int) $quiz->id) . ':a' . $attemptnum;
            $gate = $this->fact_gate($schoolid, $sdms, $itemtoken, $cmidnumber);
            if ($gate !== null) {
                return $gate;
            }
        }

        // Check for existing attempt.
        $existing = $DB->get_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => $attemptnum,
        ]);

        if ($existing) {
            $schooltime = $object['timefinish'] ?? 0;
            if (!$this->authoritative && $existing->timefinish > $schooltime && $existing->state === 'finished') {
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

            if ($this->authoritative) {
                $this->observe_fact_seq($schoolid, $sdms, $itemtoken);
            }
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
        // A summary carries no per-question responses, but uniqueid is a FK to
        // question_usages: create a REAL (empty) usage row so the FK is valid. The old
        // MAX(id)+1 fabricated a dangling id and — because question_usages never grew —
        // recomputed the SAME id for the next attempt, colliding on quiz_attempts'
        // unique (quiz,userid,attempt)/uniqueid indexes.
        $attempt->uniqueid = $this->make_summary_question_usage((int) $course->id, (int) $quiz->id);

        $centralid = $DB->insert_record('quiz_attempts', $attempt);

        $this->mapper->set_mapping('quiz_attempts', $object['localid'], $centralid);

        if ($this->authoritative) {
            $this->observe_fact_seq($schoolid, $sdms, $itemtoken);
        }
        return [
            'status' => 'success',
            'message' => 'Quiz attempt created',
            'centralid' => $centralid,
        ];
    }

    /**
     * Create a real (empty) question usage for a summary quiz-attempt import so
     * quiz_attempts.uniqueid is a valid, unique FK. A summary has no per-question
     * responses, so the usage carries no slots; correctness only needs the id to be
     * real and monotonic (unlike the old MAX(question_usages.id)+1, which dangled and
     * then collided once question_usages stopped growing).
     *
     * @param int $courseid Central course id.
     * @param int $quizid Central quiz instance id.
     * @return int question_usages id.
     */
    protected function make_summary_question_usage(int $courseid, int $quizid): int {
        global $DB;

        $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid);
        $contextid = $cm
            ? \context_module::instance($cm->id)->id
            : \context_course::instance($courseid)->id;

        $usage = new stdClass();
        $usage->contextid = $contextid;
        $usage->component = 'mod_quiz';
        $usage->preferredbehaviour = 'deferredfeedback';
        return (int) $DB->insert_record('question_usages', $usage);
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
        if ($this->authoritative) {
            return $this->apply_completion_fact($schoolid, $payload);
        }
        return $this->process_completion_legacy($schoolid, $payload);
    }

    /**
     * Apply a v2 completion fact as a latch (doc §8.2): activity-level via an
     * override-to-COMPLETE, course-level via mark_complete + criteria rows.
     *
     * @param string $schoolid Authoring school id (fact origin).
     * @param array $payload Completion fact payload.
     * @return array Result.
     */
    protected function apply_completion_fact(string $schoolid, array $payload): array {
        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return $this->user_unresolved_error($context['user'] ?? []);
        }
        $course = $this->find_course($context['course'] ?? []);
        if (!$course) {
            return ['status' => 'error', 'message' => 'Course not found on central'];
        }

        // Course-level completion is captured from course_completed, whose payload
        // carries no object row (build_payload has no course_completions case), so
        // the event target is the reliable discriminator; object.table is a fallback.
        $eventtarget = (string) ($payload['event']['objecttable'] ?? '');
        $objecttable = (string) ($object['table'] ?? '');
        if ($eventtarget === 'course_completions' || $objecttable === 'course_completions') {
            return $this->apply_course_completion_fact($schoolid, $payload, $user, $course);
        }
        return $this->apply_activity_completion_fact($schoolid, $payload, $user, $course);
    }

    /**
     * Apply an activity-completion fact as an override-to-COMPLETE latch (spike b).
     *
     * The cm is resolved by its stamped cm-UUID idnumber. Only COMPLETE latches;
     * an override-to-INCOMPLETE stays recomputable per core. The write is echo-
     * suppressed. update_state early-returns when the row already holds the target
     * state, so when a COMPLETE latch was intended but the override marker is
     * absent, it is stamped directly.
     *
     * @param string $schoolid Fact origin.
     * @param array $payload Completion fact payload.
     * @param stdClass $user Central user.
     * @param stdClass $course Central course.
     * @return array Result.
     */
    protected function apply_activity_completion_fact(string $schoolid, array $payload,
            stdClass $user, stdClass $course): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];
        $cmdata = $object['coursemodule'] ?? [];
        if (empty($cmdata)) {
            return ['status' => 'error', 'message' => 'Missing course module data'];
        }

        $modulename = $cmdata['modulename'] ?? null;
        $instanceid = (int) ($cmdata['instance'] ?? 0);
        if (empty($modulename)) {
            $moduleid = (int) ($cmdata['module'] ?? 0);
            if ($moduleid) {
                $modulename = $DB->get_field('modules', 'name', ['id' => $moduleid]);
            }
        }
        if (empty($modulename) || empty($instanceid)) {
            return ['status' => 'error', 'message' => 'Cannot identify course module'];
        }

        $centralcm = $this->find_course_module(
            $course->id, $modulename, $instanceid, $schoolid, $cmdata['cmidnumber'] ?? null);
        if (!$centralcm) {
            return ['status' => 'error', 'message' => 'Course module not found on central'];
        }

        $sdms = $this->sdms_of($context);
        $itemtoken = $this->ags_token('cm', (string) $centralcm->idnumber, (int) $centralcm->id);
        $gate = $this->fact_gate($schoolid, $sdms, $itemtoken, (string) $centralcm->idnumber);
        if ($gate !== null) {
            return $gate;
        }

        $completionstate = (int) ($object['completionstate'] ?? COMPLETION_COMPLETE);
        try {
            $cm = get_fast_modinfo($course)->get_cm((int) $centralcm->id);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Course module not in modinfo: ' . $e->getMessage()];
        }

        $completion = new \completion_info($course);
        if (!$completion->is_enabled($cm)) {
            // Completion isn't tracked for this activity on central; nothing to latch.
            $this->observe_fact_seq($schoolid, $sdms, $itemtoken);
            return ['status' => 'success', 'centralid' => 0,
                'message' => 'activity completion not enabled on central; skipped'];
        }

        $centralid = $this->latch_activity_completion($completion, $course, $cm, (int) $user->id, $completionstate);
        $this->observe_fact_seq($schoolid, $sdms, $itemtoken);

        $latched = in_array($completionstate,
            [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL], true);
        return ['status' => 'success', 'centralid' => $centralid,
            'message' => $latched
                ? 'activity completion latched (override->COMPLETE)'
                : 'activity completion applied (state ' . $completionstate . ')'];
    }

    /**
     * Establish the activity-completion latch (echo-suppressed). Returns the
     * course_modules_completion row id.
     *
     * @param \completion_info $completion Course completion helper.
     * @param stdClass $course Central course.
     * @param \cm_info $cm Central course module.
     * @param int $userid Central user id.
     * @param int $completionstate Incoming completion state.
     * @return int
     */
    protected function latch_activity_completion(\completion_info $completion, stdClass $course,
            \cm_info $cm, int $userid, int $completionstate): int {
        global $DB;

        // Only COMPLETE latches (spike b): override-to-INCOMPLETE stays recomputable.
        $iscomplete = in_array($completionstate,
            [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL], true);
        $target = $iscomplete ? COMPLETION_COMPLETE : $completionstate;

        capture::suppress(true);
        try {
            $completion->update_state($cm, $target, $userid, true);

            // update_state early-returns when the row already holds the target
            // state, leaving overrideby unset (spike b) — a natural completion would
            // then not latch against a later reset. If a COMPLETE latch was intended
            // but the override marker is missing, stamp it directly (echo-free) and
            // drop the stale completion cache so the latch is read from the row.
            if ($target === COMPLETION_COMPLETE) {
                $row = $DB->get_record('course_modules_completion',
                    ['coursemoduleid' => $cm->id, 'userid' => $userid]);
                if ($row && $row->overrideby === null
                        && (int) $row->completionstate !== COMPLETION_INCOMPLETE) {
                    $row->overrideby = $this->override_actor();
                    $row->timemodified = time();
                    $DB->update_record('course_modules_completion', $row);
                    \cache::make('core', 'completion')->delete($userid . '_' . $course->id);
                }
            }
        } finally {
            capture::suppress(false);
        }

        $final = $DB->get_record('course_modules_completion',
            ['coursemoduleid' => $cm->id, 'userid' => $userid]);
        return $final ? (int) $final->id : 0;
    }

    /**
     * Apply a course-completion fact as a mark_complete + criteria latch (spike c).
     *
     * @param string $schoolid Fact origin.
     * @param array $payload Completion fact payload.
     * @param stdClass $user Central user.
     * @param stdClass $course Central course.
     * @return array Result.
     */
    protected function apply_course_completion_fact(string $schoolid, array $payload,
            stdClass $user, stdClass $course): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->dirroot . '/completion/completion_criteria_completion.php');

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        $sdms = $this->sdms_of($context);
        $coursetoken = $this->course_token($course);
        $gate = $this->fact_gate($schoolid, $sdms, $coursetoken, 'course:' . $coursetoken);
        if ($gate !== null) {
            return $gate;
        }

        // The captured course_completed payload carries no timecompleted, so absent
        // an explicit time we keep an existing latch (idempotent) or stamp now for a
        // fresh completion (mark_complete never moves an existing time — spike c).
        $desired = (isset($object['timecompleted']) && (int) $object['timecompleted'] > 0)
            ? (int) $object['timecompleted'] : null;
        $existing = $DB->get_record('course_completions', ['course' => $course->id, 'userid' => $user->id]);

        capture::suppress(true);
        try {
            if ($existing && $existing->timecompleted && $desired !== null
                    && (int) $existing->timecompleted !== $desired) {
                // Superseding DIFFERENT completion outcome: mark_complete refuses to
                // move an existing time, so clear the rows and purge the
                // coursecompletion MUC cache before re-asserting (spike c).
                $this->clear_course_completion((int) $course->id, (int) $user->id);
            }
            $this->latch_course_completion($course, (int) $user->id, $desired);
        } finally {
            capture::suppress(false);
        }

        $final = $DB->get_record('course_completions', ['course' => $course->id, 'userid' => $user->id]);
        $this->observe_fact_seq($schoolid, $sdms, $coursetoken);
        return ['status' => 'success', 'centralid' => $final ? (int) $final->id : 0,
            'message' => 'course completion latched (timecompleted ' . ($final ? $final->timecompleted : '?') . ')'];
    }

    /**
     * Latch a course completion: mark each course criterion complete then
     * completion_completion::mark_complete (spike c). Idempotent; echo-suppressed
     * by the caller.
     *
     * @param stdClass $course Central course.
     * @param int $userid Central user id.
     * @param int|null $timecompleted Explicit completion time, or null for now().
     */
    protected function latch_course_completion(stdClass $course, int $userid, ?int $timecompleted): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->dirroot . '/completion/completion_criteria_completion.php');

        $time = $timecompleted ?: time();

        // Latch the course's completion criteria so cron re-aggregation re-latches
        // from surviving criteria rows if timecompleted is ever cleared (spike c).
        $completion = new \completion_info($course);
        foreach ($completion->get_criteria() as $criterion) {
            $cc = new \completion_criteria_completion([
                'course' => (int) $course->id,
                'userid' => $userid,
                'criteriaid' => (int) $criterion->id,
            ]);
            if (!$cc->is_complete()) {
                $cc->mark_complete($time);
            }
        }

        // Latch the course_completions row. mark_complete never moves an existing time.
        $ccompletion = new \completion_completion(['course' => (int) $course->id, 'userid' => $userid]);
        $ccompletion->mark_complete($time);
    }

    /**
     * Clear a user's course-completion rows and purge the coursecompletion MUC
     * cache, so a superseding DIFFERENT completion time can be re-asserted (spike c).
     *
     * @param int $courseid Central course id.
     * @param int $userid Central user id.
     */
    protected function clear_course_completion(int $courseid, int $userid): void {
        global $DB;
        $DB->delete_records('course_completion_crit_compl', ['course' => $courseid, 'userid' => $userid]);
        $DB->delete_records('course_completions', ['course' => $courseid, 'userid' => $userid]);
        \cache::make('core', 'coursecompletion')->delete($userid . '_' . $courseid);
    }

    /**
     * Legacy synchronous completion write (pre-v2 upload path). Wall-clock LWW.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Completion data.
     * @return array Result.
     */
    protected function process_completion_legacy(string $schoolid, array $payload): array {
        global $DB;

        $context = $payload['context'] ?? [];
        $object = $context['object'] ?? [];

        if (empty($object)) {
            return ['status' => 'error', 'message' => 'Missing completion data'];
        }

        $user = $this->find_user($context['user'] ?? []);
        if (!$user) {
            return $this->user_unresolved_error($context['user'] ?? []);
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
     * Process a course-level completion record (legacy synchronous path).
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
        // Generic user events are audit-only. Real account creation/credential
        // sync flows through the dedicated 'account' event (process_account).
        return [
            'status' => 'success',
            'message' => 'User event logged',
            'centralid' => 0,
        ];
    }

    /**
     * Update a central account from a school account push.
     *
     * Resolution is strictly via the SDMS link (elby_sdms_users): a school push
     * can never create a central user or bind to one by username/email guessing.
     * Credential-bearing fields (password, auth, username) in the payload are
     * stripped so a school can never change central credentials. Unresolved
     * accounts return a retryable error so the school queue keeps retrying
     * until the identity is provisioned/linked on central.
     *
     * @param string $schoolid School identifier.
     * @param array $payload Account payload.
     * @return array Result with the central user ID.
     */
    protected function process_account(string $schoolid, array $payload): array {
        global $DB;

        $account = $payload['account'] ?? [];
        $sdmsid = trim((string) ($account['sdms_id'] ?? ''));
        $usertype = (string) ($account['user_type'] ?? '');
        if ($sdmsid === '' || $usertype === '') {
            return ['status' => 'error', 'message' => 'Missing SDMS identity in account payload'];
        }

        // Account-takeover guard: never accept credential fields from a school.
        $stripped = [];
        foreach (['password', 'auth', 'username'] as $field) {
            if (isset($account[$field]) && $account[$field] !== '') {
                $stripped[] = $field;
            }
            unset($account[$field]);
        }
        if ($stripped) {
            debugging('Syncqueue: stripped credential fields (' . implode(', ', $stripped) .
                ") from account push for SDMS id {$sdmsid} from school {$schoolid}", DEBUG_NORMAL);
        }

        if (!$DB->get_manager()->table_exists('elby_sdms_users')) {
            return [
                'status' => 'error',
                'message' => 'SDMS link table not installed on central; account deferred (item will be retried)',
            ];
        }

        // Locate the central user via the SDMS link only.
        $user = null;
        $link = $DB->get_record('elby_sdms_users', ['sdms_id' => $sdmsid], 'userid');
        if ($link) {
            $user = $DB->get_record('user', ['id' => $link->userid, 'deleted' => 0]) ?: null;
        }
        if (!$user) {
            return [
                'status' => 'error',
                'message' => "No central user linked to SDMS id {$sdmsid}; " .
                    'deferred until the identity is provisioned on central (item will be retried)',
            ];
        }

        // Record the school-local userid mapping (when the school sends it) so
        // later content events for this user resolve without any guessing.
        $localid = (int) ($account['localid'] ?? 0);
        if ($localid) {
            $this->mapper->set_mapping('user', $localid, (int) $user->id);
        }

        // Enrich + refresh via elby_dashboard: pulls the full TDMP profile, applies
        // national cohorts and auto-enrolments, and caches the identity.
        if (class_exists('\local_elby_dashboard\sync_service')) {
            // sync_service expects 'student' or 'staff'; school stores 'teacher'.
            $lookuptype = ($usertype === 'teacher') ? 'staff' : $usertype;
            try {
                (new \local_elby_dashboard\sync_service())->link_user((int) $user->id, $sdmsid, $lookuptype);
            } catch (\Exception $e) {
                debugging('Account enrich failed for ' . $sdmsid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return [
            'status' => 'success',
            'message' => 'Account synced' . ($stripped ? ' (credential fields ignored)' : ''),
            'centralid' => (int) $user->id,
        ];
    }

    /**
     * Resolve an incoming school user to a central user via exact SDMS identity only.
     *
     * A school payload may only bind to a central account through the SDMS link
     * table (elby_sdms_users), or through a per-school id mapping that was itself
     * recorded from an SDMS-linked account push. Heuristic idnumber/email/username
     * matching is deliberately not attempted: a wrong guess files another person's
     * records and is unrecoverable, while an unresolved user is simply retried.
     *
     * @param array $userdata User identification data from the payload.
     * @return stdClass|null The central user, or null when no exact link exists.
     */
    protected function find_user(array $userdata): ?stdClass {
        global $DB;

        if (empty($userdata)) {
            return null;
        }

        // Exact SDMS number, when the school includes it in the payload.
        $sdmsid = trim((string) ($userdata['sdms_id'] ?? ''));
        if ($sdmsid !== '' && $DB->get_manager()->table_exists('elby_sdms_users')) {
            $link = $DB->get_record('elby_sdms_users', ['sdms_id' => $sdmsid], 'userid');
            if ($link) {
                $user = $DB->get_record('user', ['id' => $link->userid, 'deleted' => 0]);
                if ($user) {
                    return $user;
                }
            }
        }

        // Per-school mapping recorded when this school's SDMS-linked account
        // push was accepted (see process_account).
        $localid = (int) ($userdata['localid'] ?? 0);
        if ($localid) {
            $centraluserid = $this->mapper->get_central_id('user', $localid);
            if ($centraluserid) {
                $user = $DB->get_record('user', ['id' => $centraluserid, 'deleted' => 0]);
                if ($user) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * Retryable error result for a user payload with no exact SDMS resolution.
     *
     * Returned as status 'error' so the school queue keeps retrying the item;
     * fuzzy matching or auto-creation must never substitute for a missing link.
     *
     * @param array $userdata User identification data from the payload.
     * @return array Error result.
     */
    protected function user_unresolved_error(array $userdata): array {
        $sdmsid = trim((string) ($userdata['sdms_id'] ?? ''));
        if ($sdmsid === '') {
            $message = 'User payload carries no SDMS identity and no per-school user mapping exists; ' .
                'deferred until the school account push links it (item will be retried)';
        } else {
            $message = "No central user linked to SDMS id {$sdmsid}; " .
                'deferred until the identity is linked on central (item will be retried)';
        }
        return ['status' => 'error', 'message' => $message];
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

    // --- v2 home-authorship gating (doc §5/§8.1) --------------------------------

    /**
     * Apply the v2 ordering + tenure gates for a fact about learner $sdms on the
     * item identified by $itemtoken (a stable per-central-item AGS token).
     *
     * Returns null when the fact is clear to apply, or a terminal result array the
     * applier returns verbatim and apply_ingest routes:
     *  - own-origin echo -> success no-op (this instance authored it);
     *  - 'tenurefail'    -> apply_ingest records a conflict and marks the row stale;
     *  - 'stale'         -> apply_ingest marks the row stale (AGS out-of-order).
     *
     * Gating only runs for a v2-authoritative apply (fact context set). Tenure is
     * only ENFORCED once tenure knowledge exists for the learner, so before the
     * roster/home signal is populated v2 apply is not globally bricked (dual-stack);
     * a null rostergen (pre-first roster refresh) is likewise uncheckable and not
     * gated. AGS is table_exists-guarded inside tenure::is_stale.
     *
     * @param string $origin Authoring school id (the pushing school = fact origin).
     * @param string $sdms Learner SDMS code resolved from the payload.
     * @param string $itemtoken Stable per-(central item) AGS token.
     * @param string $itemuuid Item UUID recorded on a tenure conflict (may equal the token).
     * @return array|null
     */
    protected function fact_gate(string $origin, string $sdms, string $itemtoken, string $itemuuid): ?array {
        global $DB;

        $ctx = $this->factcontext;
        if ($ctx === null) {
            // Legacy path: no v2 ordering context, so no gating.
            return null;
        }

        // NOTE: the doc §8.2 "skip a fact this instance itself authored" rule belongs
        // to the FUTURE school-side pull applier (step 5 seeding), where an instance
        // can legitimately receive back a fact it authored. On THIS central push-apply
        // path central is never a legitimate fact origin, so an origin==self test here
        // would only misfire on a misconfigured central schoolid and SILENTLY DROP a
        // real school's facts. Echo suppression (capture::suppress) already stops the
        // applier's own writes from being re-captured. So no own-origin skip here.

        $rostergen = $ctx['rostergen'] ?? null;
        $epoch = (string) ($ctx['epoch'] ?? '');
        $schoolseq = (int) ($ctx['schoolseq'] ?? 0);

        // Tenure: a fact applies only if its origin held home tenure for the learner
        // at the roster generation stamped on it (doc §5/§8.1) — judged against
        // tenure in force at G, never arrival time. Enforcement is a deliberate
        // operator switch (tenure_enforce): the Option B producer populates and
        // observes intervals as soon as central upgrades, but rejection stays off
        // until the fleet has exchanged at least one roster generation so a school
        // still stamping a pre-Option-B (or NULL) generation is never rejected in a
        // numbering space it has not yet adopted.
        if ($sdms !== '' && $rostergen !== null && $this->tenure_enforced() && $this->tenure_known($sdms)) {
            if (!tenure::in_force($sdms, $origin, (int) $rostergen)) {
                return [
                    'status' => 'tenurefail',
                    'sdms' => $sdms,
                    'entitykey' => $itemuuid,
                    'rostergen' => (int) $rostergen,
                    'message' => "origin {$origin} did not hold home tenure for {$sdms} at rostergen {$rostergen}",
                ];
            }
        }

        // AGS: within (origin, epoch, learner, item) the origin sequence must be
        // strictly increasing; a lower/equal arrival is explicitly stale (doc §8.1).
        if ($sdms !== '' && $epoch !== ''
                && tenure::is_stale($origin, $epoch, $sdms, $itemtoken, $schoolseq)) {
            return [
                'status' => 'stale',
                'message' => "AGS stale: schoolseq {$schoolseq} not newer than the high-water for {$sdms} on this item",
            ];
        }

        return null;
    }

    /**
     * Advance the AGS (origin, epoch, learner, item) origin-seq high-water after a
     * fact is durably applied. No-op on the legacy path or without a learner/epoch.
     *
     * @param string $origin Authoring school id.
     * @param string $sdms Learner SDMS code.
     * @param string $itemtoken Stable per-(central item) AGS token.
     */
    protected function observe_fact_seq(string $origin, string $sdms, string $itemtoken): void {
        $ctx = $this->factcontext;
        if ($ctx === null || $sdms === '') {
            return;
        }
        $epoch = (string) ($ctx['epoch'] ?? '');
        if ($epoch === '') {
            return;
        }
        tenure::observe_seq($origin, $epoch, $sdms, $itemtoken, (int) ($ctx['schoolseq'] ?? 0));
    }

    /**
     * Whether the operator has switched on home-tenure rejection (see fact_gate).
     *
     * Off by default so central's Option B producer can populate and observe tenure
     * intervals before they begin rejecting any fact — turned on only once the fleet
     * is stamping facts in central's roster-generation space.
     *
     * @return bool
     */
    protected function tenure_enforced(): bool {
        return (bool) get_config('local_syncqueue', 'tenure_enforce');
    }

    /**
     * Whether central holds any home-tenure knowledge for a learner (gates whether
     * the tenure check is enforced — see fact_gate).
     *
     * @param string $sdms Learner SDMS code.
     * @return bool
     */
    protected function tenure_known(string $sdms): bool {
        global $DB;
        if ($sdms === '' || !$DB->get_manager()->table_exists('local_syncqueue_tenure')) {
            return false;
        }
        return $DB->record_exists('local_syncqueue_tenure', ['sdms' => $sdms]);
    }

    /**
     * A stable per-(central item) AGS token: the stamped UUID idnumber when present,
     * else a local-id fallback so the token is still stable on central.
     *
     * @param string $prefix Fallback token prefix ('gi', 'cm', 'course').
     * @param string $idnumber The central item's idnumber.
     * @param int $localid The central item's local id.
     * @return string
     */
    protected function ags_token(string $prefix, string $idnumber, int $localid): string {
        // Always keep the kind prefix, even for a UUID idnumber: an itemnumber-0
        // module stamps its grade-item idnumber EQUAL to its cm idnumber (one UUID),
        // so without the 'gi:'/'cm:' discriminator a grade fact and that activity's
        // completion fact would share one AGS (origin, epoch, learner, item)
        // high-water and a lower-seq grade could be wrongly stale-dropped and lost.
        return $prefix . ':' . (item_identity::is_uuid($idnumber) ? $idnumber : (string) $localid);
    }

    /**
     * The learner SDMS code carried on a fact payload's user context.
     *
     * @param array $context Payload context.
     * @return string
     */
    protected function sdms_of(array $context): string {
        return trim((string) ($context['user']['sdms_id'] ?? ''));
    }

    /**
     * Coerce a payload grade value to a float, or null when absent.
     *
     * @param mixed $value
     * @return float|null
     */
    protected function to_grade($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    /**
     * A stable per-course AGS token: the central course idnumber when set, else a
     * local-id fallback.
     *
     * @param stdClass $course Central course.
     * @return string
     */
    protected function course_token(stdClass $course): string {
        $idnumber = (string) ($course->idnumber ?? '');
        return ($idnumber !== '') ? $idnumber : 'cid:' . (int) $course->id;
    }

    /**
     * The user id to record as a completion override author. The apply task runs as
     * the admin cron user (get_admin); fall back to get_admin() explicitly.
     *
     * @return int
     */
    protected function override_actor(): int {
        global $USER;
        if (!empty($USER->id)) {
            return (int) $USER->id;
        }
        return (int) get_admin()->id;
    }
}
