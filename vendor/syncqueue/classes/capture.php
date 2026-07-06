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
use local_syncqueue\outbox\publisher;
use stdClass;

/**
 * School-side v2 upstream fact capture (ELMS Sync v2 step 2, doc 4.3/8.1/9.1).
 *
 * When push_v2 is on and the affected learner is SDMS-linked, a syncable event
 * is captured as an upstream fact: its deterministic two-level identity is
 * recorded in the fact ledger and an upstream row is appended to the sequenced
 * outbox. Called from internal event observers, so both writes commit
 * atomically with the business write (a rolled-back grade never leaves a fact).
 *
 * A fact for an unlinked user (no SDMS) is NOT captured here — v2 identity needs
 * the SDMS code — and the observer keeps it on the legacy queue instead.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capture {

    /** @var bool Request-scoped echo-suppression flag (doc §8.2). */
    protected static $suppressed = false;

    /**
     * Suppress (true) or re-enable (false) upstream fact capture for the rest of
     * this request.
     *
     * A sync applier wraps its own grade / completion writes in suppress(true) so
     * the user_graded / completion events those writes fire are NOT re-captured
     * here as fresh home-origin facts: otherwise applying a peer's fact would mint
     * a spurious local fact and seeding 200 grades on the home side would emit 200
     * fake facts (doc §8.2). Request-scoped; always pair with a try/finally so a
     * throw still clears it.
     *
     * @param bool $on
     */
    public static function suppress(bool $on): void {
        self::$suppressed = $on;
    }

    /**
     * Whether an in-flight applier write is currently echo-suppressing capture.
     *
     * @return bool
     */
    public static function suppressed(): bool {
        return self::$suppressed;
    }

    /**
     * Capture a learner event as an upstream v2 fact.
     *
     * Safe to run inside the event's DB transaction (insert-only; opens no
     * nested transaction). The source table selects the identity shape; the
     * fact type stamps the outbox/ledger rows.
     *
     * @param base $event The triggering Moodle event.
     * @param string $facttype grade|submission|quiz_attempt|completion|enrolment.
     * @param string $sourcetable Source Moodle table driving natural-key resolution.
     * @return int|null Outbox row id for a fresh capture; 0 when the fact is an
     *         idempotent no-op already held by v2 (so the caller must NOT also
     *         legacy-queue it); null when not captured to v2 (push_v2 off,
     *         unlinked learner, or missing source data) so the caller should
     *         fall back to the legacy queue and never lose the fact.
     */
    public static function capture_event(base $event, string $facttype, string $sourcetable,
            bool $force = false): ?int {
        if (self::$suppressed) {
            // A sync applier is writing; the grade/completion event it fired must
            // not echo back as a fresh fact. Return 0 (not null): v2 "owns" it, so
            // the observer must not fall back to legacy-queueing it either.
            return 0;
        }
        if (!self::enabled()) {
            return null;
        }

        $spec = self::resolve($event, $sourcetable);
        if ($spec === null) {
            // Unlinked learner or unresolvable source row: legacy path owns it.
            return null;
        }

        $data = $event->get_data();
        $action = (($data['crud'] ?? '') === 'd') ? 'delete' : 'upsert';
        $payload = self::fact_payload($event);
        $payloadhash = publisher::hash_payload($payload);

        return self::store($facttype, $sourcetable, $spec, $action, $payload, $payloadhash, $force);
    }

    /**
     * Capture an account (identity + credentials) as an upstream v2 fact.
     *
     * Mirrors queue_manager::add_account_push but writes the ledger + outbox
     * pair instead of a legacy item. Only SDMS-linked accounts are captured
     * (both paths require it), so no legacy fallback is needed here.
     *
     * @param int $userid Local Moodle user id.
     * @return int|null Outbox row id, 0 on an idempotent no-op, or null when
     *         not captured (push_v2 off, deleted or unlinked account).
     */
    public static function capture_account(int $userid): ?int {
        global $DB;

        if (self::$suppressed) {
            // Echo suppression: an applier's own write must not re-capture (§8.2).
            return 0;
        }
        if (!self::enabled() || !$userid) {
            return null;
        }

        $user = $DB->get_record('user', ['id' => $userid],
            'id, email, firstname, lastname, idnumber, suspended, deleted');
        if (!$user || $user->deleted) {
            return null;
        }
        $sdms = self::sdms_for($userid);
        if ($sdms === null) {
            return null;
        }
        $usertype = null;
        if ($DB->get_manager()->table_exists('elby_sdms_users')) {
            $usertype = $DB->get_field('elby_sdms_users', 'user_type', ['userid' => $userid]) ?: null;
        }

        $origin = self::schoolid();
        $payload = [
            'account' => [
                'sdms_id' => $sdms,
                'user_type' => $usertype,
                'localid' => (int) $user->id,
                // Credentials (username/password hash/auth) are deliberately NOT
                // mirrored upstream: central resolves accounts by SDMS link and
                // applies nothing from these fields, so shipping them only exposes
                // password hashes in the outbox, on the wire, and at rest in the
                // central ingest buffer. Password propagation, if ever needed, must
                // ride a separately-authorized channel, not the account fact.
                'email' => $user->email,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'idnumber' => $user->idnumber,
                'suspended' => (int) $user->suspended,
            ],
            // No wall-clock timestamp: the fact hash must be a pure function of
            // account state so an unchanged account is an idempotent no-op.
            'school' => ['id' => $origin],
        ];
        $payloadhash = publisher::hash_payload($payload);

        $spec = new stdClass();
        $spec->sourceid = (int) $userid;
        $spec->keyparts = ['account', $sdms];
        $spec->rostergen = self::current_rostergen();

        return self::store('account', 'user', $spec, 'upsert', $payload, $payloadhash);
    }

    /**
     * Regenerate a grade fact from its source row (ELMS Sync v2 step 6, §9.1).
     *
     * Reconstructs the EXACT user_graded event core would have fired via its own factory
     * and runs it through the normal capture path, so the regenerated fact carries the
     * identical source-derived natural key (→ lineageuuid) as an event-captured one and
     * dedups by factuuid. Shared by the capture-scan (never-captured facts) and the
     * upstream anti-entropy repair (facts central lost). A source whose current state
     * differs from the ledger's last hash is captured as a NEW version (the payload
     * includes the acting user, so a re-capture is a genuine new version, never an
     * idempotent no-op) — which is exactly how central's lost state is recovered.
     *
     * @param int $ggid grade_grades row id.
     * @param bool $force Re-append the outbox row even if v2 already holds the fact (the
     *        upstream repair — central lost it and must be re-pushed).
     * @return int|null Outbox row id / 0 (idempotent no-op) / null (not v2-eligible or gone).
     */
    public static function regenerate_grade(int $ggid, bool $force = false): ?int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $grade = \grade_grade::fetch(['id' => $ggid]);
        if (!$grade) {
            return null;
        }
        $grade->load_grade_item();
        $event = \core\event\user_graded::create_from_grade($grade);
        return self::capture_event($event, 'grade', 'grade_grades', $force);
    }

    /**
     * Record the ledger row + append the upstream outbox row for a fact.
     *
     * @param string $facttype Fact type.
     * @param string $sourcetable Source table.
     * @param stdClass $spec ->sourceid (?int), ->keyparts (array), ->rostergen (?int).
     * @param string $action upsert|delete.
     * @param array $payload Canonical (deterministic) fact payload.
     * @param string $payloadhash publisher::hash_payload($payload).
     * @param bool $force Re-append the outbox row even when v2 already holds this exact
     *        fact (the upstream anti-entropy repair: central lost it, so it must be
     *        re-pushed regardless of local idempotency; the sequencer re-finalizes the
     *        existing ledger row to the same identity and central dedups by factuuid).
     * @return int Outbox row id, or 0 when unchanged (idempotent no-op).
     */
    protected static function store(string $facttype, string $sourcetable, stdClass $spec,
            string $action, array $payload, string $payloadhash, bool $force = false): int {
        global $DB;

        $origin = self::schoolid();
        $naturalkey = fact_identity::natural_key($spec->keyparts);
        $sourceid = $spec->sourceid;

        // Idempotency: if the newest ledger row for this source row already
        // carries this payloadhash, v2 already holds the fact (pending, exported
        // or acked) — do not append a duplicate outbox row. Mirrors
        // fact_ledger::record's own unchanged-hash rule, but lets us signal the
        // caller (0) that v2 owns the fact so it is not double-sent via legacy.
        // $force bypasses this for a deliberate re-push (central lost the fact).
        if (!$force && $sourceid !== null) {
            $existing = fact_ledger::get_by_source($sourcetable, $sourceid);
            $prior = $existing ? reset($existing) : null;
            if ($prior && $prior->payloadhash === $payloadhash) {
                return 0;
            }
        }

        $opts = ['homeschool' => $origin];
        if ($spec->rostergen !== null) {
            $opts['rostergen'] = $spec->rostergen;
        }
        $ledger = fact_ledger::record($origin, $facttype, $naturalkey, $sourcetable, $sourceid, $payloadhash, $opts);

        // Publisher-style insert of an upstream outbox row (seq/factversion/
        // factuuid NULL until the sequencer finalizes them). entityversion is a
        // placeholder 0 that the sequencer overwrites with the factversion.
        $record = new stdClass();
        $record->seq = null;
        $record->entitytype = $facttype;
        $record->entitykey = $ledger->lineageuuid;
        $record->entityversion = 0;
        $record->action = $action;
        $record->payload = publisher::canonical_json($payload);
        $record->payloadhash = $payloadhash;
        $record->contentversion = null;
        $record->partitionkey = 'learner:school:' . $origin;
        $record->lineageuuid = $ledger->lineageuuid;
        $record->factversion = null;
        $record->factuuid = null;
        $record->rostergen = $spec->rostergen;
        $record->ledgerid = $ledger->id;
        $record->timecreated = time();

        return (int) $DB->insert_record('local_syncqueue_outbox', $record);
    }

    /**
     * Resolve source id + natural-key components for a fact (SDMS-gated).
     *
     * @param base $event The event.
     * @param string $sourcetable Source table selecting the identity shape.
     * @return stdClass|null ->sourceid, ->keyparts, ->rostergen; null when the
     *         learner is unlinked or the source row cannot be resolved.
     */
    protected static function resolve(base $event, string $sourcetable): ?stdClass {
        global $DB;

        $data = $event->get_data();
        $objectid = isset($data['objectid']) ? (int) $data['objectid'] : 0;
        $relateduserid = isset($data['relateduserid']) ? (int) $data['relateduserid'] : 0;
        $courseid = isset($data['courseid']) ? (int) $data['courseid'] : 0;

        $spec = new stdClass();
        $spec->rostergen = self::current_rostergen();

        switch ($sourcetable) {
            case 'grade_grades':
                $grade = $DB->get_record('grade_grades', ['id' => $objectid], 'id, itemid, userid');
                if (!$grade) {
                    return null;
                }
                $sdms = self::sdms_for((int) $grade->userid);
                if ($sdms === null) {
                    return null;
                }
                $item = $DB->get_record('grade_items', ['id' => $grade->itemid], 'id, idnumber');
                $giidnumber = ($item && (string) $item->idnumber !== '') ? (string) $item->idnumber : '';
                $spec->sourceid = $objectid;
                if (item_identity::is_uuid($giidnumber)) {
                    // A step-4 stamped grade-item UUID is globally unique (doc 9.1):
                    // key on it alone, dropping the per-course scope the fallback
                    // needs. Identity stays stable for already-UUID items; the one-time
                    // unstamped->stamped move is a deliberate new lineage at cutover.
                    $spec->keyparts = [$giidnumber, $sdms];
                } else {
                    // Unstamped: keep the course-scoped scheme. grade_item idnumbers
                    // are only unique per course, so two courses reusing an idnumber
                    // for the same learner would otherwise collapse into one lineage
                    // and one grade would supersede the other.
                    $itemident = ($giidnumber !== '') ? $giidnumber : 'giid:' . $grade->itemid;
                    $spec->keyparts = ['course:' . self::course_identity($courseid), $itemident, $sdms];
                }
                return $spec;

            case 'quiz_attempts':
                $attempt = $DB->get_record('quiz_attempts', ['id' => $objectid], 'id, quiz, userid, attempt');
                if (!$attempt) {
                    return null;
                }
                $sdms = self::sdms_for((int) $attempt->userid);
                if ($sdms === null) {
                    return null;
                }
                $cmident = self::cm_identity('quiz', (int) $attempt->quiz);
                if ($cmident === null) {
                    return null;
                }
                $spec->sourceid = $objectid;
                $spec->keyparts = [$cmident, $sdms, (int) $attempt->attempt];
                return $spec;

            case 'assign_submission':
                $other = $data['other'] ?? [];
                $subid = isset($other['submissionid']) ? (int) $other['submissionid'] : 0;
                if (!$subid) {
                    return null;
                }
                $sub = $DB->get_record('assign_submission', ['id' => $subid],
                    'id, assignment, userid, groupid, attemptnumber');
                if (!$sub) {
                    return null;
                }
                $sdms = self::sdms_for((int) $sub->userid);
                if ($sdms === null) {
                    return null;
                }
                $cmident = self::cm_identity('assign', (int) $sub->assignment);
                if ($cmident === null) {
                    return null;
                }
                $attemptnumber = isset($other['submissionattempt'])
                    ? (int) $other['submissionattempt'] : (int) $sub->attemptnumber;
                // Moodle keys a submission by (assignment, userid, groupid,
                // attemptnumber); groupid MUST be part of the fact lineage (and the
                // central AGS token) so two group rows for the same learner/assignment/
                // attempt cannot share one lineage/high-water.
                $spec->sourceid = $subid;
                $spec->keyparts = [$cmident, $sdms, (int) $sub->groupid, $attemptnumber];
                return $spec;

            case 'course_modules_completion':
                $comp = $DB->get_record('course_modules_completion', ['id' => $objectid], 'id, coursemoduleid, userid');
                if (!$comp) {
                    return null;
                }
                $sdms = self::sdms_for((int) $comp->userid);
                if ($sdms === null) {
                    return null;
                }
                // Prefer a stamped cm-UUID idnumber; a human idnumber is not
                // globally unique so it falls back to the cmid: key (see
                // cm_identity()). Keeps the cm key scheme consistent across all
                // cm-anchored fact types.
                $cm = $DB->get_record('course_modules', ['id' => $comp->coursemoduleid], 'id, idnumber');
                $cmidnumber = $cm ? (string) $cm->idnumber : '';
                $cmident = item_identity::is_uuid($cmidnumber) ? $cmidnumber : 'cmid:' . $comp->coursemoduleid;
                $spec->sourceid = $objectid;
                $spec->keyparts = [$cmident, $sdms];
                return $spec;

            case 'course_completions':
                // Course-level completion: no cm, key on the course entitykey.
                // A 'course:' prefix keeps this lineage distinct from an
                // activity completion even if a course and cm idnumber coincide.
                if (!$relateduserid) {
                    return null;
                }
                $sdms = self::sdms_for($relateduserid);
                if ($sdms === null) {
                    return null;
                }
                $spec->sourceid = $objectid ?: null;
                $spec->keyparts = ['course:' . self::course_identity($courseid), $sdms];
                return $spec;

            case 'user_enrolments':
                if (!$relateduserid) {
                    return null;
                }
                $sdms = self::sdms_for($relateduserid);
                if ($sdms === null) {
                    return null;
                }
                $spec->sourceid = $objectid ?: null;
                $spec->keyparts = [self::course_identity($courseid), $sdms];
                return $spec;

            default:
                return null;
        }
    }

    /**
     * The deterministic fact payload for an event (event-shaped, no dispatch clock).
     *
     * Reuses queue_manager::build_payload so central's existing appliers consume
     * an identical shape, but strips the two dispatch-time fields — the school
     * capture timestamp and the event's own timecreated. The fact payloadhash
     * MUST be a pure function of source state: it is the version key (a changed
     * hash is a new factversion) and the §9.1 regeneration guarantee requires a
     * fact rebuilt from source tables to reproduce the identical hash. Leaving a
     * wall clock in would mint a spurious new version on every re-capture and
     * break regeneration/dedup. central_processor reads event.timecreated only
     * for the legacy wall-clock LWW (null-safe, and slated for deletion in
     * step 4); the schoolid reaches process_item as a separate argument.
     *
     * @param base $event The event.
     * @return array Deterministic payload.
     */
    protected static function fact_payload(base $event): array {
        $payload = (new queue_manager())->build_payload($event);
        unset($payload['school']['timestamp']);
        unset($payload['event']['timecreated']);
        // event.userid is the ACTOR (the grading teacher live, but cron/admin when a fact
        // is regenerated by the capture-scan or upstream repair), NOT source state. Leaving
        // it in would make regeneration mint a spurious new version with rewritten
        // provenance instead of reproducing the identical hash (§9.1). The appliers resolve
        // the learner from context.user, never event.userid, so dropping it is safe.
        unset($payload['event']['userid']);
        return $payload;
    }

    /**
     * Resolve a course module's stable identity: its stamped UUID idnumber, or a
     * cmid: fallback.
     *
     * A step-4 stamped UUID idnumber is a globally unique cross-instance identity
     * (doc 5/9.1) and is used as-is. Any other value — including a HUMAN idnumber
     * a course author set — is NOT globally unique (spike (d): cm idnumbers are
     * unique only per course, duplicates are allowed site-wide), so it falls back
     * to the instance-scoped cmid: key exactly like an empty idnumber, mirroring
     * the grade natural key. Stamping never overwrites a human idnumber, so this
     * is the only correct key for those cms.
     *
     * @param string $modname Module name, e.g. 'quiz' or 'assign'.
     * @param int $instanceid Module instance id.
     * @return string|null cm identity, or null when the cm cannot be resolved.
     */
    protected static function cm_identity(string $modname, int $instanceid): ?string {
        global $DB;

        $moduleid = $DB->get_field('modules', 'id', ['name' => $modname]);
        if (!$moduleid || !$instanceid) {
            return null;
        }
        $cm = $DB->get_record('course_modules', ['module' => $moduleid, 'instance' => $instanceid], 'id, idnumber');
        if (!$cm) {
            return null;
        }
        return item_identity::is_uuid((string) $cm->idnumber) ? (string) $cm->idnumber : 'cmid:' . $cm->id;
    }

    /**
     * Resolve a course's stable identity: its idnumber, or a cid: fallback.
     *
     * @param int $courseid Local course id.
     * @return string
     */
    protected static function course_identity(int $courseid): string {
        global $DB;

        if (!$courseid) {
            return 'cid:0';
        }
        $idnumber = (string) $DB->get_field('course', 'idnumber', ['id' => $courseid]);
        return ($idnumber !== '') ? $idnumber : 'cid:' . $courseid;
    }

    /**
     * The SDMS code for a local user from the school-local cache, or null.
     *
     * @param int $userid Local Moodle user id.
     * @return string|null SDMS code, or null when the cache is absent or the
     *         user is not linked.
     */
    protected static function sdms_for(int $userid): ?string {
        global $DB;

        if (!$userid || !$DB->get_manager()->table_exists('elby_sdms_users')) {
            return null;
        }
        $sdms = $DB->get_field('elby_sdms_users', 'sdms_id', ['userid' => $userid]);
        return !empty($sdms) ? (string) $sdms : null;
    }

    /**
     * This school's id (origin of every fact authored here).
     *
     * @return string
     */
    protected static function schoolid(): string {
        return get_config('local_syncqueue', 'schoolid') ?: 'unknown';
    }

    /**
     * The current roster generation stamp, or null before the first roster refresh.
     *
     * Delegates to the real per-instance counter (rostergen), which elby_dashboard's
     * roster_manager bumps on each successful full roster refresh. Central judges
     * home-tenure against the generation stamped here at capture (§5/§8.1), so it
     * must be the generation in force when the fact is authored. Null until the
     * first refresh, so a never-synced box stamps NULL exactly as before.
     *
     * @return int|null
     */
    protected static function current_rostergen(): ?int {
        return rostergen::current();
    }

    /**
     * Whether v2 upstream capture is active: enabled, school mode, push_v2 on.
     *
     * @return bool
     */
    protected static function enabled(): bool {
        return (bool) get_config('local_syncqueue', 'enabled')
            && get_config('local_syncqueue', 'mode') === 'school'
            && (bool) get_config('local_syncqueue', 'push_v2');
    }
}
