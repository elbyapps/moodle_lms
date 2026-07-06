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

use local_syncqueue\outbox\applied_state;
use stdClass;

/**
 * Thrown by the v2 appliers when a row cannot apply because a prerequisite
 * entity (e.g. the course a content row belongs to) has not been applied yet.
 *
 * Deliberately defined in this file rather than its own autoloaded one:
 * step-1 file ownership is limited to the processor, and every consumer
 * (the pull_stream replay loop) constructs update_processor before anything
 * can throw, so the class is always loaded when instanceof-checked. The
 * replay loop treats it as retry-without-attempt-counting.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dependency_missing_exception extends \RuntimeException {
}

/**
 * Processor for updates downloaded from the central server.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_processor {

    /** @var id_mapper ID mapping helper */
    protected id_mapper $mapper;

    /** @var int|null Category id pre-resolved from a v2 categorykey reference;
     * when set it short-circuits the path-based category lookup. */
    protected ?int $v2categoryid = null;

    /** @var string|null Root cause captured when a legacy applier swallows an
     * exception and returns false; surfaced in v2 deadletter errors. */
    protected ?string $lastapplyerror = null;

    /** @var bool When true (set for the duration of a v2 course apply), course
     * adoption may NOT fall back to a fuzzy shortname match — only the scoped idmap,
     * central_{id}, or the payload idnumber may bind an existing local course. The
     * architecture removes fuzzy shortname matching for courses; on the authoritative
     * path it could otherwise hijack a coincidentally-named local teacher course and
     * skip the restore of the real central content. */
    protected bool $v2strictadoption = false;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->mapper = new id_mapper();
    }

    /**
     * Process a batch of updates from the central server.
     *
     * @param array $updates Array of update records.
     * @return array Results with success/failed/skipped counts.
     */
    public function process(array $updates): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($updates as $update) {
            try {
                $processed = $this->process_update($update);
                if ($processed) {
                    $results['success']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'update' => $update,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Apply a single update (public wrapper for async per-item processing).
     *
     * @param array $update Update data.
     * @return bool True if processed, false if skipped.
     * @throws \Exception On failure.
     */
    public function apply_update(array $update): bool {
        return $this->process_update($update);
    }

    /**
     * Apply one v2 outbox row (ELMS Sync v2 step 1, school side).
     *
     * Maps sequenced stream rows onto the existing legacy appliers with
     * entitykey-first resolution through applied-state (falling back to the
     * legacy idmap/idnumber lookups, so courses the legacy stack already
     * created get adopted instead of duplicated). Writes no legacy tracking
     * state; idempotence comes from entitykey+entityversion, recorded by the
     * caller in applied-state on success.
     *
     * @param stdClass $row Stream row: entitytype, entitykey, entityversion,
     *        action, payload (JSON string or null), payloadhash, contentversion.
     * @return int Local id of the created/updated/archived course or category;
     *        0 when there is nothing local to point at (e.g. deleting a course
     *        that never existed here).
     * @throws dependency_missing_exception When a prerequisite entity has not
     *        been applied yet (retried without counting towards dead).
     * @throws \Exception On any other apply failure.
     */
    public function apply_outbox_row(stdClass $row): int {
        $payload = null;
        if (isset($row->payload) && $row->payload !== '') {
            $payload = json_decode($row->payload, true);
            if (!is_array($payload)) {
                throw new \RuntimeException("v2 row {$row->entitytype} {$row->entitykey}: payload is not valid JSON");
            }
        }

        switch ($row->entitytype) {
            case 'category':
                return $this->apply_v2_category($row, $payload);

            case 'course':
                return $this->apply_v2_course($row, $payload);

            case 'course_content':
                return $this->apply_v2_course_content($row, $payload);

            case 'identity_map':
                return $this->apply_v2_identity_map($row, $payload);

            case 'seed_grade':
                // History down-sync (§8.3): seed an overridden leaf grade. A null return
                // (not this school's item) maps to 0 = applied no-op; dependency_missing
                // propagates so the pull loop retries without burning its budget.
                return (int) (seed_applier::apply_grade($payload ?? []) ?? 0);

            case 'seed_completion':
                return (int) (seed_applier::apply_completion($payload ?? []) ?? 0);

            default:
                throw new \RuntimeException("v2 row has unknown entitytype '{$row->entitytype}'");
        }
    }

    /**
     * Apply a v2 course row through the legacy course applier.
     *
     * @param stdClass $row Stream row.
     * @param array|null $payload Decoded payload.
     * @return int Local course id.
     */
    protected function apply_v2_course(stdClass $row, ?array $payload): int {
        global $DB;

        $centralid = $this->central_id_from_entitykey($row->entitykey, 'course');

        if ($row->action === 'delete') {
            return $this->archive_course($row->entitykey, $centralid);
        }
        // 'publish' is applied as a full-state upsert: bootstrap snapshot rows
        // were sequenced with it before the action contract was pinned, and the
        // outbox is append-only, so those rows must stay applicable forever.
        if ($row->action !== 'upsert' && $row->action !== 'publish') {
            throw new \RuntimeException("v2 course row {$row->entitykey}: unsupported action '{$row->action}'");
        }
        if ($payload === null || empty($payload['fullname']) || empty($payload['shortname'])) {
            throw new \RuntimeException("v2 course row {$row->entitykey}: payload missing fullname/shortname");
        }

        // Entity identity comes from the entitykey, never from the payload.
        $payload['id'] = $centralid;
        $this->seed_course_mapping_from_applied_state($row->entitykey, $centralid);

        $this->v2categoryid = $this->resolve_payload_categorykey($payload);
        $this->lastapplyerror = null;
        $this->v2strictadoption = true;
        try {
            $ok = $this->process_course_update([
                'type' => 'course',
                'action' => 'update',
                'data' => $payload,
            ]);
        } finally {
            $this->v2categoryid = null;
            $this->v2strictadoption = false;
        }
        if (!$ok) {
            throw new \RuntimeException("v2 course row {$row->entitykey}: course applier reported failure"
                . ($this->lastapplyerror !== null ? ' (' . $this->lastapplyerror . ')' : ''));
        }

        // A raw idmap lookup here can return a dangling row (a locally deleted
        // course this apply just re-created); resolve through the guarded path,
        // falling back to the payload idnumber for custom-idnumber courses.
        $localid = $this->resolve_local_course($row->entitykey, $centralid);
        if (!$localid && !empty($payload['idnumber'])) {
            $localid = (int) $DB->get_field('course', 'id', ['idnumber' => $payload['idnumber']]) ?: null;
        }
        if (!$localid) {
            throw new \RuntimeException("v2 course row {$row->entitykey}: applied but no local mapping recorded");
        }
        return $localid;
    }

    /**
     * Apply a v2 category row.
     *
     * Direct resolution goes through applied-state; adoption/create reuses the
     * legacy path-based lookup so pre-existing categories are matched by
     * idnumber or name+parent instead of duplicated.
     *
     * @param stdClass $row Stream row.
     * @param array|null $payload Decoded payload.
     * @return int Local category id.
     */
    protected function apply_v2_category(stdClass $row, ?array $payload): int {
        global $DB;

        $this->central_id_from_entitykey($row->entitykey, 'category');

        $localid = null;
        $state = applied_state::get('category', $row->entitykey);
        if ($state && $state->localid && $DB->record_exists('course_categories', ['id' => $state->localid])) {
            $localid = (int)$state->localid;
        }

        if ($row->action === 'delete') {
            // Archive, never destroy (doc section 4.4): hide the category.
            if ($localid) {
                $category = \core_course_category::get($localid, IGNORE_MISSING, true);
                if ($category && $category->visible) {
                    $category->hide();
                }
            }
            return $localid ?: 0;
        }
        // 'publish' is applied as a full-state upsert (see apply_v2_course).
        if ($row->action !== 'upsert' && $row->action !== 'publish') {
            throw new \RuntimeException("v2 category row {$row->entitykey}: unsupported action '{$row->action}'");
        }
        if ($payload === null) {
            throw new \RuntimeException("v2 category row {$row->entitykey}: upsert without payload");
        }

        if ($localid) {
            $category = \core_course_category::get($localid, MUST_EXIST, true);
            $name = $payload['name'] ?? null;
            $idnumber = $payload['idnumber'] ?? null;
            if ($name === null || $name === '') {
                // Path-shaped payloads carry a rename only in the leaf element.
                $path = $payload['path'] ?? $payload['category']['path'] ?? null;
                $leaf = is_array($path) ? end($path) : null;
                if (is_array($leaf)) {
                    $name = $leaf['name'] ?? null;
                    $idnumber = $idnumber ?? $leaf['idnumber'] ?? null;
                }
            }
            $changes = [];
            if (!empty($name) && $name !== $category->name) {
                $changes['name'] = $name;
            }
            if ($idnumber !== null && (string)$idnumber !== (string)$category->idnumber) {
                $changes['idnumber'] = $idnumber;
            }
            if ($changes) {
                $category->update($changes);
            }
            return $localid;
        }

        $path = null;
        if (!empty($payload['path']) && is_array($payload['path'])) {
            $path = $payload['path'];
        } else if (!empty($payload['category']['path']) && is_array($payload['category']['path'])) {
            $path = $payload['category']['path'];
        } else if (!empty($payload['name'])) {
            $path = [['name' => $payload['name'], 'idnumber' => $payload['idnumber'] ?? '']];
        }
        if (!$path) {
            throw new \RuntimeException("v2 category row {$row->entitykey}: payload has neither path nor name");
        }
        return $this->get_or_create_category_from_path(['path' => $path]);
    }

    /**
     * Apply a v2 course_content row through the existing download/restore path.
     *
     * Step 1 only restores content for courses absent locally; in-place content
     * refresh (apply-alongside + latch migration) is versioned publication work.
     *
     * @param stdClass $row Stream row.
     * @param array|null $payload Decoded payload.
     * @return int Local course id.
     */
    protected function apply_v2_course_content(stdClass $row, ?array $payload): int {
        global $DB;

        $centralcourseid = $this->central_id_from_entitykey($row->entitykey, 'coursecontent');
        $coursekey = 'course:' . $centralcourseid;
        $contentversion = isset($row->contentversion) ? (int)$row->contentversion : null;

        if (!in_array($row->action, ['publish', 'upsert'], true)) {
            // Content archival/removal only lands with versioned publication.
            mtrace("v2 course_content {$row->entitykey}: action '{$row->action}' is a no-op in step 1");
            return $this->resolve_local_course($coursekey, $centralcourseid) ?? 0;
        }

        $localid = $this->resolve_local_course($coursekey, $centralcourseid);
        $appliedcv = applied_state::get_contentversion('course_content', $row->entitykey);

        // Present course, already at this content version or newer: idempotent no-op.
        if ($localid && $contentversion !== null && $contentversion <= $appliedcv) {
            return $localid;
        }
        // Present course, no version signal (a legacy content row): nothing to compute.
        if ($localid && $contentversion === null) {
            return $localid;
        }
        // Present course receiving its FIRST content-version marker: the 'course' row
        // already bootstrapped this content (step-1 contract — the course row carries
        // the backup and restores when the course is absent), so just record the
        // version for future bump comparison. Do NOT re-restore. If the course has no
        // activities the 'course' row likely fell back to a metadata-only create (its
        // backup download failed): we still record the version (re-restoring an
        // unfetchable artifact would only wedge the stream), but surface it — content
        // then lands on the next version bump or the anti-entropy content pass.
        if ($localid && $appliedcv === 0) {
            if (!$DB->record_exists('course_modules', ['course' => $localid])) {
                debugging("course_content {$row->entitykey}: course {$localid} has no activities "
                    . "(the course row likely fell back to metadata-only); recording content v{$contentversion} "
                    . 'without restore — content lands on the next bump or anti-entropy pass', DEBUG_DEVELOPER);
            }
            applied_state::upsert('course_content', $row->entitykey, (int) $row->entityversion,
                (string) $row->payloadhash, $localid);
            applied_state::set_contentversion('course_content', $row->entitykey, (int) $contentversion);
            return $localid;
        }

        // A restore is required — an absent course (first restore) or a genuine content
        // bump ($contentversion > a recorded applied version). Both go through the
        // crash-safe restore-alongside path, which for a bump also migrates learner
        // outcomes and retires the old copy.
        if ($payload === null || empty($payload['backup']['filename'])) {
            throw new dependency_missing_exception("course_content {$row->entitykey}: course {$coursekey} "
                . 'not applied yet and the content row carries no restorable backup');
        }
        if (empty($payload['fullname']) || empty($payload['shortname'])) {
            throw new dependency_missing_exception("course_content {$row->entitykey}: course {$coursekey} "
                . 'not applied yet and the content row lacks course metadata');
        }

        $this->lastapplyerror = null;
        $newid = content_refresh::apply($this, $row, $payload, $localid, $centralcourseid, $contentversion ?? 1);

        // Record the applied content version: a re-delivery of the same version is now
        // a no-op, and the next bump is measured against it. (The course entity was
        // re-pointed to the new copy by content_refresh's promotion.)
        applied_state::upsert('course_content', $row->entitykey, (int) $row->entityversion,
            (string) $row->payloadhash, $newid);
        applied_state::set_contentversion('course_content', $row->entitykey, (int) ($contentversion ?? 1));

        return $newid;
    }

    /**
     * Apply a v2 identity_map row: the step-4 preflight cm/grade-item UUID
     * back-stamp for an already-distributed course.
     *
     * DRY-RUN by default: the received map is stored for operator review
     * (apply_identity_map.php) and nothing is stamped, unless
     * local_syncqueue/identity_map_autostamp is set. Either way the structural
     * match is zero-guess, so an ambiguous course is flagged and never guessed.
     *
     * @param stdClass $row Stream row.
     * @param array|null $payload Decoded map payload.
     * @return int Local course id the map targets.
     * @throws dependency_missing_exception When the course has not been applied here yet.
     */
    protected function apply_v2_identity_map(stdClass $row, ?array $payload): int {
        if ($row->action !== 'upsert' && $row->action !== 'publish') {
            throw new \RuntimeException("v2 identity_map row {$row->entitykey}: unsupported action '{$row->action}'");
        }
        if ($payload === null || empty($payload['centralcourseid'])) {
            throw new \RuntimeException("v2 identity_map row {$row->entitykey}: payload missing centralcourseid");
        }

        $centralcourseid = (int) $payload['centralcourseid'];
        $localcourseid = identity_map_applier::resolve_local_course($centralcourseid);
        if (!$localcourseid) {
            // The course must land before its identity map can be matched; it
            // rides the same content partition, so this resolves on retry.
            throw new dependency_missing_exception("identity_map {$row->entitykey}: course "
                . "{$centralcourseid} not applied locally yet");
        }

        $autostamp = get_config('local_syncqueue', 'identity_map_autostamp');
        $execute = ($autostamp !== false && $autostamp !== '' && (bool) $autostamp);

        $report = identity_map_applier::apply($payload, $execute, $localcourseid);
        identity_map_applier::persist($payload, $report, (int) $row->entityversion, (string) $row->payloadhash);

        if ($execute) {
            mtrace("  identity_map {$row->entitykey}: course {$localcourseid} autostamp {$report->status} "
                . "(cms {$report->stampedcms}, gis {$report->stampedgis})");
        } else {
            mtrace("  identity_map {$row->entitykey}: course {$localcourseid} stored {$report->status} "
                . "(would stamp cms {$report->wouldcms}, gis {$report->wouldgis}"
                . (count($report->ambiguities) ? ', ' . count($report->ambiguities) . ' ambiguities' : '')
                . '; review with apply_identity_map.php)');
        }
        return (int) $localcourseid;
    }

    /**
     * Archive (never destroy) a course on a v2 delete row: hide it and keep
     * all learner data. Physical deletion stays a manual local act after the
     * outbox drains (doc section 4.4); the legacy hard delete_course() path is
     * deliberately not used by the v2 stream.
     *
     * @param string $entitykey Course entitykey.
     * @param int $centralid Central course id.
     * @return int Local course id, or 0 when the course never existed here.
     */
    protected function archive_course(string $entitykey, int $centralid): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $localid = $this->resolve_local_course($entitykey, $centralid);
        if (!$localid) {
            return 0;
        }

        $course = $DB->get_record('course', ['id' => $localid]);
        if (!$course) {
            return 0;
        }

        // A deleted central course must stop being "desired" by the reconciler, or it
        // keeps auto-enrolling national-cohort members forever (hiding alone is not
        // enough — the reconciler ignores visibility and its only archived signal is
        // the #archived-v idnumber). Mirror content-refresh retirement: disable owned
        // cohort-sync instances, drop the enrichment meta, archive the idnumber, hide.
        // Idempotent: the full teardown runs only on the first archive.
        if (strpos((string) $course->idnumber, '#archived-v') === false) {
            // 1. Disable OWNED cohort-sync enrol instances (name = the reconciler's
            //    INSTANCE_NAME) — never delete (SUSPENDNOROLES preserves grades), and
            //    never touch an admin's own cohort enrolment on the course.
            $plugin = enrol_get_plugin('cohort');
            if ($plugin) {
                $owned = $DB->get_records('enrol',
                    ['courseid' => $localid, 'enrol' => 'cohort', 'name' => 'TDMP auto cohort sync']);
                foreach ($owned as $instance) {
                    if ((int) $instance->status === ENROL_INSTANCE_ENABLED) {
                        $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
                    }
                }
            }

            // 2. Archive the idnumber (the reconciler's only archived signal) and hide.
            $archived = ((string) $course->idnumber !== '' ? (string) $course->idnumber
                : 'central_' . (int) $course->id) . '#archived-v0';
            $update = new stdClass();
            $update->id = $course->id;
            $update->idnumber = $archived;
            $update->visible = 0;
            update_course($update);

            // 3. Remove the enrichment meta so the reconciler stops managing it —
            //    AFTER update_course(), because that fires course_updated, which the
            //    elby enricher observes and would otherwise re-insert the meta row it
            //    just found missing. (content_refresh::retire_old deletes meta in the
            //    same order for the same reason.)
            if ($DB->get_manager()->table_exists('elby_course_meta')) {
                $DB->delete_records('elby_course_meta', ['courseid' => $localid]);
            }
        } else if ((int) $course->visible !== 0) {
            $update = new stdClass();
            $update->id = $course->id;
            $update->visible = 0;
            update_course($update);
        }
        return $localid;
    }

    /**
     * Resolve a course entitykey to a local course id: applied-state first,
     * then the legacy idmap, then the legacy fallback idnumber.
     *
     * @param string $entitykey Course entitykey.
     * @param int $centralid Central course id.
     * @return int|null Local course id or null when unresolved.
     */
    protected function resolve_local_course(string $entitykey, int $centralid): ?int {
        global $DB;

        $state = applied_state::get('course', $entitykey);
        if ($state && $state->localid && $DB->record_exists('course', ['id' => $state->localid])) {
            return (int)$state->localid;
        }

        // The idmap can hold dangling rows (course deleted locally, then
        // possibly re-created, which adds a second row for the same central
        // id and makes the lookup ambiguous); drop dangling rows as they
        // surface so resolution converges on the live course.
        while (($localid = $this->mapper->get_local_id('course', $centralid)) !== null) {
            if ($DB->record_exists('course', ['id' => $localid])) {
                return $localid;
            }
            $this->mapper->delete_mapping('course', $localid);
        }

        $course = $DB->get_record('course', ['idnumber' => 'central_' . $centralid]);
        return $course ? (int)$course->id : null;
    }

    /**
     * Seed the legacy idmap from applied-state so the legacy applier's own
     * mapper lookup resolves the course directly. Applied-state is the
     * authoritative v2 resolution map; the idmap is what the legacy lookup
     * code actually reads, so keeping them aligned also protects the legacy
     * stack while both run.
     *
     * @param string $entitykey Course entitykey.
     * @param int $centralid Central course id.
     */
    protected function seed_course_mapping_from_applied_state(string $entitykey, int $centralid): void {
        global $DB;

        $state = applied_state::get('course', $entitykey);
        if ($state && $state->localid && $DB->record_exists('course', ['id' => $state->localid])
                && $this->mapper->get_local_id('course', $centralid) !== (int)$state->localid) {
            $this->mapper->set_mapping('course', (int)$state->localid, $centralid);
        }
    }

    /**
     * Resolve a v2 'categorykey' course-payload reference through applied-state.
     *
     * Payloads carrying an embedded category path (the legacy shape) resolve
     * through the path logic instead and return null here.
     *
     * @param array $payload Course payload.
     * @return int|null Local category id, or null when the payload has no
     *        categorykey (or also embeds a path, which wins).
     * @throws dependency_missing_exception When the referenced category has
     *        not been applied yet.
     */
    protected function resolve_payload_categorykey(array $payload): ?int {
        global $DB;

        if (empty($payload['categorykey']) || !empty($payload['category']['path'])) {
            return null;
        }

        $state = applied_state::get('category', $payload['categorykey']);
        if ($state && $state->localid && $DB->record_exists('course_categories', ['id' => $state->localid])) {
            return (int)$state->localid;
        }
        throw new dependency_missing_exception("category {$payload['categorykey']} not applied yet "
            . '(course row depends on it)');
    }

    /**
     * Extract the central id from an entitykey like 'course:123'.
     *
     * @param string $entitykey Entity key.
     * @param string $expectedtype Expected key prefix ('course', 'category', 'coursecontent').
     * @return int Central id.
     */
    protected function central_id_from_entitykey(string $entitykey, string $expectedtype): int {
        $parts = explode(':', $entitykey, 2);
        if (count($parts) !== 2 || $parts[0] !== $expectedtype
                || !ctype_digit($parts[1]) || (int)$parts[1] <= 0) {
            throw new \RuntimeException("Malformed entitykey '{$entitykey}' "
                . "(expected '{$expectedtype}:<central id>')");
        }
        return (int)$parts[1];
    }

    /**
     * Process a single update.
     *
     * @param array $update Update data.
     * @return bool True if processed, false if skipped.
     */
    protected function process_update(array $update): bool {
        $type = $update['type'] ?? null;

        // Dual-stack guard: once the v2 pull stream owns the downstream
        // course/content channel, the legacy download path must not apply the
        // same entity types a second time. User/enrolment updates stay on the
        // legacy path in step 1.
        if (in_array($type, ['course', 'course_content'], true)
                && get_config('local_syncqueue', 'pull_v2')) {
            mtrace("Legacy {$type} update skipped: pull_v2 stream owns this entity type");
            return false;
        }

        switch ($type) {
            case 'course':
                return $this->process_course_update($update);

            case 'user':
                return $this->process_user_update($update);

            case 'enrolment':
                return $this->process_enrolment_update($update);

            case 'course_content':
                return $this->process_content_update($update);

            default:
                debugging("Unknown update type: {$type}", DEBUG_DEVELOPER);
                return false;
        }
    }

    /**
     * Process a course update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_course_update(array $update): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];
        $action = $update['action'] ?? 'update';
        $centralid = $data['id'];

        // Check if we already have this course mapped.
        $localid = $this->mapper->get_local_id('course', $centralid);

        if ($action === 'delete') {
            if ($localid) {
                delete_course($localid, false);
                $this->mapper->delete_mapping('course', $localid);
            }
            return true;
        }

        if ($localid) {
            // Update existing course.
            $course = $DB->get_record('course', ['id' => $localid]);
            if ($course) {
                $course->fullname = $data['fullname'];
                $course->shortname = $this->ensure_unique_shortname($data['shortname'], $localid);
                $course->summary = $data['summary'] ?? '';
                $course->visible = $data['visible'] ?? 1;
                $course->startdate = $data['startdate'] ?? time();
                $course->enddate = $data['enddate'] ?? 0;
                $course->timemodified = time();
                update_course($course);
                return true;
            }
        }

        // Fallback: try to find course by idnumber (and, on the legacy path only, by
        // shortname) before creating a new one. On the v2 authoritative path, fuzzy
        // shortname adoption is forbidden (v2strictadoption): binding a coincidentally-
        // named local teacher course to the central id would hijack it and skip the
        // restore of the real central content — adoption stays idmap / central_{id} /
        // payload idnumber only.
        $existingcourse = $DB->get_record('course', ['idnumber' => 'central_' . $centralid]);
        if (!$existingcourse && !empty($data['idnumber'])) {
            $existingcourse = $DB->get_record('course', ['idnumber' => $data['idnumber']]);
        }
        if (!$existingcourse && !$this->v2strictadoption && !empty($data['shortname'])) {
            $existingcourse = $DB->get_record('course', ['shortname' => $data['shortname']]);
        }
        if ($existingcourse) {
            // Repair the mapping and update.
            $this->mapper->set_mapping('course', $existingcourse->id, $centralid);
            $existingcourse->fullname = $data['fullname'];
            $existingcourse->shortname = $this->ensure_unique_shortname($data['shortname'], $existingcourse->id);
            $existingcourse->summary = $data['summary'] ?? '';
            $existingcourse->visible = $data['visible'] ?? 1;
            $existingcourse->startdate = $data['startdate'] ?? time();
            $existingcourse->enddate = $data['enddate'] ?? 0;
            $existingcourse->timemodified = time();
            update_course($existingcourse);
            return true;
        }

        // Course doesn't exist locally - create it.
        // Check if we have a backup to restore.
        if (!empty($data['backup']) && !empty($data['backup']['has_backup'])) {
            return $this->restore_course_from_backup($data, $centralid);
        }

        return $this->create_course_from_central($data, $centralid);
    }

    /**
     * Restore a course from a backup file.
     *
     * @param array $data Course data with backup info.
     * @param int $centralid Central course ID.
     * @return bool Success.
     */
    protected function restore_course_from_backup(array $data, int $centralid): bool {
        global $CFG, $USER;

        $backupinfo = $data['backup'];
        $filename = $backupinfo['filename'];

        // Get or create the category.
        $categoryid = $this->get_or_create_category_from_path($data['category'] ?? null);

        // Download the backup file.
        $tempdir = make_temp_directory('syncqueue_restore');
        $backuppath = $tempdir . '/' . $filename;

        try {
            $client = new sync_client();
            $downloaded = $client->download_backup($filename, $backuppath);

            if (!$downloaded) {
                // Fall back to metadata-only creation.
                $this->note_apply_error("backup download failed: {$filename}");
                return $this->create_course_from_central($data, $centralid);
            }

            // Restore the course.
            $backupmanager = new backup_manager();
            $userid = $USER->id ?: get_admin()->id;

            $newcourseid = $backupmanager->restore_course($backuppath, $categoryid, $userid);

            // Clean up temp file.
            @unlink($backuppath);

            if (!$newcourseid) {
                // Fall back to metadata-only creation.
                $this->note_apply_error("restore produced no course from {$filename}");
                return $this->create_course_from_central($data, $centralid);
            }

            // Update course with correct metadata.
            global $DB;
            $course = $DB->get_record('course', ['id' => $newcourseid]);
            if ($course) {
                $course->shortname = $this->ensure_unique_shortname($data['shortname'], $newcourseid);
                $course->fullname = $data['fullname'];
                $course->idnumber = !empty($data['idnumber']) ? $data['idnumber'] : 'central_' . $centralid;
                $course->visible = $data['visible'] ?? 1;
                $DB->update_record('course', $course);
            }

            // Save mapping.
            $this->mapper->set_mapping('course', $newcourseid, $centralid);

            // Restore bypasses the core course_created/course_updated events (the
            // metadata fix above is a raw update_record), so the elby_dashboard
            // observer never enriches restored courses; call it directly now that
            // the category and idnumber are final.
            $this->enrich_applied_course($newcourseid);

            return true;

        } catch (\Exception $e) {
            debugging('Restore failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->note_apply_error('restore failed: ' . $e->getMessage());
            @unlink($backuppath);
            // Fall back to metadata-only creation.
            return $this->create_course_from_central($data, $centralid);
        }
    }

    /**
     * Restore a course content .mbz into a NEW, hidden course copy, stamping a
     * provisional marker idnumber IMMEDIATELY (crash-safety, step 7). Does NOT set
     * the canonical idnumber, the resolution mapping, or enrichment — those are the
     * promotion step, run only after the old copy is retired. Returns the new
     * course id, or null on any failure (the caller retries / dead-letters; unlike
     * the legacy path there is no metadata-only fallback for a content restore).
     *
     * @param array $payload course_content payload (carries backup + metadata).
     * @param int $centralid Central course id.
     * @param string $marker Provisional idnumber to stamp for crash identification.
     * @return int|null New local course id, or null on failure.
     */
    public function restore_content_copy(array $payload, int $centralid, string $marker): ?int {
        global $DB, $USER;

        if (empty($payload['backup']['filename'])) {
            $this->note_apply_error('content restore: payload carries no backup filename');
            return null;
        }
        $filename = (string) $payload['backup']['filename'];
        $categoryid = $this->get_or_create_category_from_path($payload['category'] ?? null);

        $tempdir = make_temp_directory('syncqueue_restore');
        $backuppath = $tempdir . '/' . $filename;
        try {
            $client = new sync_client();
            if (!$client->download_backup($filename, $backuppath)) {
                $this->note_apply_error("content restore: backup download failed: {$filename}");
                return null;
            }
            $userid = $USER->id ?: get_admin()->id;
            $newid = (new backup_manager())->restore_course($backuppath, $categoryid, $userid);
            @unlink($backuppath);
            if (!$newid) {
                $this->note_apply_error("content restore: restore produced no course from {$filename}");
                return null;
            }
            // Immediately give the copy an identifiable, non-canonical identity and
            // hide it: a crash before promotion then leaves a corpse the restorelog
            // recovery deletes by marker, never a duplicate that resolves the entitykey.
            $course = $DB->get_record('course', ['id' => $newid]);
            if ($course) {
                $course->shortname = $this->ensure_unique_shortname(
                    (string) ($payload['shortname'] ?? $course->shortname), (int) $newid);
                $course->idnumber = $marker;
                $course->visible = 0;
                $DB->update_record('course', $course);
            }
            return (int) $newid;
        } catch (\Throwable $e) {
            @unlink($backuppath);
            $this->note_apply_error('content restore failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Promote a freshly restored content copy to the canonical course identity:
     * set its real idnumber, make it visible, re-point entitykey resolution
     * (applied-state + legacy idmap) to it, and enrich it (meta + cohort wiring).
     * Run only after any superseded copy has been retired to an archived idnumber.
     *
     * @param int $newid New local course id.
     * @param array $payload course_content payload.
     * @param int $centralid Central course id.
     * @param string $coursekey Course entitykey ('course:<centralid>').
     */
    public function promote_content_copy(int $newid, array $payload, int $centralid, string $coursekey): void {
        global $DB;

        $course = $DB->get_record('course', ['id' => $newid], '*', MUST_EXIST);
        $course->idnumber = !empty($payload['idnumber']) ? $payload['idnumber'] : 'central_' . $centralid;
        if (!empty($payload['fullname'])) {
            $course->fullname = $payload['fullname'];
        }
        $course->visible = $payload['visible'] ?? 1;
        $DB->update_record('course', $course);

        // Resolution now points at the new copy (applied-state is authoritative).
        applied_state::set_localid('course', $coursekey, $newid);

        // Keep the legacy idmap aligned: drop stale rows for this central id, map new.
        while (($oldmap = $this->mapper->get_local_id('course', $centralid)) !== null && $oldmap !== $newid) {
            $this->mapper->delete_mapping('course', $oldmap);
        }
        $this->mapper->set_mapping('course', $newid, $centralid);

        // Restore bypasses course_created/updated, so enrich directly (meta + cohorts).
        $this->enrich_applied_course($newid);
    }

    /**
     * Create a new course from central server data.
     *
     * @param array $data Course data from central.
     * @param int $centralid Central course ID.
     * @return bool Success.
     */
    protected function create_course_from_central(array $data, int $centralid): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        // Get or create the category (matching central's structure).
        $categoryid = $this->get_or_create_category_from_path($data['category'] ?? null);

        // Prepare course data.
        $coursedata = new stdClass();
        $coursedata->fullname = $data['fullname'];
        $coursedata->shortname = $this->ensure_unique_shortname($data['shortname']);
        $coursedata->category = $categoryid;
        $coursedata->summary = $data['summary'] ?? '';
        $coursedata->summaryformat = $data['summaryformat'] ?? FORMAT_HTML;
        $coursedata->format = $data['format'] ?? 'topics';
        $coursedata->visible = $data['visible'] ?? 1;
        $coursedata->startdate = $data['startdate'] ?? time();
        $coursedata->enddate = $data['enddate'] ?? 0;
        $coursedata->idnumber = !empty($data['idnumber']) ? $data['idnumber'] : 'central_' . $centralid;
        $coursedata->numsections = $data['numsections'] ?? 10;

        try {
            $newcourse = create_course($coursedata);

            // Save mapping.
            $this->mapper->set_mapping('course', $newcourse->id, $centralid);

            return true;
        } catch (\Exception $e) {
            debugging('Failed to create course: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->note_apply_error('create_course failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Record why a legacy applier is about to report plain boolean failure.
     *
     * The legacy course helpers debug() and return false, which suits the
     * legacy queue but strips the root cause from v2 deadletter errors; the v2
     * entry points reset this before dispatch and append it to the exception
     * they throw when the applier reports failure.
     *
     * @param string $message Failure detail.
     */
    protected function note_apply_error(string $message): void {
        $this->lastapplyerror = $this->lastapplyerror === null
            ? $message
            : $this->lastapplyerror . '; ' . $message;
    }

    /**
     * Best-effort local_elby_dashboard enrichment for a course applied from central.
     *
     * Only needed on apply paths that bypass the core course events: the other
     * paths go through create_course()/update_course(), whose course_created /
     * course_updated events the elby_dashboard observer already enriches, so
     * calling this there would enrich twice. Guarded so syncqueue keeps working
     * where elby_dashboard is absent, and an enrichment failure never fails the
     * queue item apply.
     *
     * @param int $courseid Local course ID with final category and idnumber.
     */
    protected function enrich_applied_course(int $courseid): void {
        if (!class_exists(\local_elby_dashboard\course_enricher::class)) {
            return;
        }
        try {
            \local_elby_dashboard\course_enricher::enrich_course($courseid);
        } catch (\Throwable $e) {
            mtrace('syncqueue: elby_dashboard enrichment failed for course ' . $courseid . ': ' . $e->getMessage());
            debugging('elby_dashboard enrichment failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Get or create category from a category path.
     *
     * @param array|null $categorydata Category data with path.
     * @return int Category ID.
     */
    protected function get_or_create_category_from_path(?array $categorydata): int {
        global $DB;

        // A v2 course row that referenced its category by entitykey has already
        // resolved it through applied-state (such payloads carry no path).
        if ($this->v2categoryid !== null) {
            return $this->v2categoryid;
        }

        // If no category data, use default sync category.
        if (empty($categorydata) || empty($categorydata['path'])) {
            return $this->get_sync_category();
        }

        $path = $categorydata['path'];
        $parentid = 0;
        $lastcategoryid = 0;

        foreach ($path as $catinfo) {
            $name = $catinfo['name'];
            $idnumber = $catinfo['idnumber'] ?? '';

            // Try to find existing category by idnumber first, then by name+parent.
            $category = null;
            if (!empty($idnumber)) {
                $category = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
            }
            if (!$category) {
                $category = $DB->get_record('course_categories', [
                    'name' => $name,
                    'parent' => $parentid,
                ]);
            }

            if ($category) {
                $lastcategoryid = $category->id;
                $parentid = $category->id;
            } else {
                // Create the category.
                $newcatdata = new stdClass();
                $newcatdata->name = $name;
                $newcatdata->idnumber = $idnumber ?: null;
                $newcatdata->parent = $parentid;
                $newcatdata->description = '';

                $newcategory = \core_course_category::create($newcatdata);
                $lastcategoryid = $newcategory->id;
                $parentid = $newcategory->id;
            }
        }

        return $lastcategoryid ?: $this->get_sync_category();
    }

    /**
     * Get or create the category for synced courses.
     *
     * @return int Category ID.
     */
    protected function get_sync_category(): int {
        global $DB;

        // Check for existing sync category.
        $category = $DB->get_record('course_categories', ['idnumber' => 'syncqueue_courses']);

        if ($category) {
            return $category->id;
        }

        // Create the category.
        $categorydata = new stdClass();
        $categorydata->name = get_string('syncedcourses', 'local_syncqueue');
        $categorydata->idnumber = 'syncqueue_courses';
        $categorydata->description = get_string('syncedcoursesdesc', 'local_syncqueue');
        $categorydata->parent = 0;

        $newcategory = \core_course_category::create($categorydata);

        return $newcategory->id;
    }

    /**
     * Ensure course shortname is unique.
     *
     * @param string $shortname Desired shortname.
     * @param int|null $excludeid Exclude this course ID from check.
     * @return string Unique shortname.
     */
    protected function ensure_unique_shortname(string $shortname, ?int $excludeid = null): string {
        global $DB;

        $params = ['shortname' => $shortname];
        $where = 'shortname = :shortname';

        if ($excludeid) {
            $where .= ' AND id != :excludeid';
            $params['excludeid'] = $excludeid;
        }

        if (!$DB->record_exists_select('course', $where, $params)) {
            return $shortname;
        }

        // Add suffix to make unique.
        $counter = 1;
        do {
            $newshortname = $shortname . '_' . $counter;
            $params['shortname'] = $newshortname;
            $counter++;
        } while ($DB->record_exists_select('course', $where, $params));

        return $newshortname;
    }

    /**
     * Process a user update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_user_update(array $update): bool {
        global $DB;

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];
        $centralid = $data['id'];

        // Try to find user by email or username.
        $user = $DB->get_record('user', ['email' => $data['email']]);
        if (!$user) {
            $user = $DB->get_record('user', ['username' => $data['username']]);
        }

        if ($user) {
            // Update mapping.
            $this->mapper->set_mapping('user', $user->id, $centralid);

            // Update user fields. Central server overrides school credentials.
            $user->firstname = $data['firstname'];
            $user->lastname = $data['lastname'];
            $user->idnumber = $data['idnumber'] ?? '';
            if (!empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->timemodified = time();
            $DB->update_record('user', $user);
            return true;
        }

        // Create new user.
        $newuser = new stdClass();
        $newuser->username = $data['username'];
        $newuser->email = $data['email'];
        $newuser->firstname = $data['firstname'];
        $newuser->lastname = $data['lastname'];
        $newuser->idnumber = $data['idnumber'] ?? '';
        $newuser->auth = 'manual';
        $newuser->confirmed = 1;
        $newuser->mnethostid = $DB->get_field('mnet_host', 'id', ['wwwroot' => $GLOBALS['CFG']->wwwroot]);
        $newuser->password = !empty($data['password']) ? $data['password'] : hash_internal_user_password(random_string(20));
        $newuser->timecreated = time();
        $newuser->timemodified = time();

        $localid = $DB->insert_record('user', $newuser);

        // Save mapping.
        $this->mapper->set_mapping('user', $localid, $centralid);

        return true;
    }

    /**
     * Process an enrolment update.
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_enrolment_update(array $update): bool {
        global $DB;

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];

        // DEBUG: Log the incoming enrolment data.
        error_log('[SYNCQUEUE ENROL DEBUG] Raw data: ' . json_encode($data));
        error_log('[SYNCQUEUE ENROL DEBUG] userid=' . ($data['userid'] ?? 'NULL') . ' courseid=' . ($data['courseid'] ?? 'NULL'));
        error_log('[SYNCQUEUE ENROL DEBUG] user info: ' . json_encode($data['user'] ?? 'MISSING'));
        error_log('[SYNCQUEUE ENROL DEBUG] course info: ' . json_encode($data['course'] ?? 'MISSING'));

        // Get local IDs.
        $localuserid = $this->mapper->get_local_id('user', $data['userid']);
        $localcourseid = $this->mapper->get_local_id('course', $data['courseid']);

        error_log('[SYNCQUEUE ENROL DEBUG] Mapper results: localuserid=' . ($localuserid ?? 'NULL') . ' localcourseid=' . ($localcourseid ?? 'NULL'));

        // Fallback: look up user by email/username.
        if (!$localuserid && !empty($data['user'])) {
            $userinfo = $data['user'];
            $localuser = null;
            if (!empty($userinfo['email'])) {
                $localuser = $DB->get_record('user', ['email' => $userinfo['email']]);
                error_log('[SYNCQUEUE ENROL DEBUG] User fallback by email "' . $userinfo['email'] . '": ' . ($localuser ? 'found id=' . $localuser->id : 'NOT FOUND'));
            }
            if (!$localuser && !empty($userinfo['username'])) {
                $localuser = $DB->get_record('user', ['username' => $userinfo['username']]);
                error_log('[SYNCQUEUE ENROL DEBUG] User fallback by username "' . $userinfo['username'] . '": ' . ($localuser ? 'found id=' . $localuser->id : 'NOT FOUND'));
            }
            if ($localuser) {
                $localuserid = $localuser->id;
                $this->mapper->set_mapping('user', $localuser->id, $data['userid']);
            }
        }

        // Fallback: look up course by idnumber or shortname.
        if (!$localcourseid && !empty($data['course'])) {
            $courseinfo = $data['course'];
            $localcourse = null;
            if (!empty($courseinfo['idnumber'])) {
                $localcourse = $DB->get_record('course', ['idnumber' => $courseinfo['idnumber']]);
                error_log('[SYNCQUEUE ENROL DEBUG] Course fallback by idnumber "' . $courseinfo['idnumber'] . '": ' . ($localcourse ? 'found id=' . $localcourse->id : 'NOT FOUND'));
            }
            if (!$localcourse) {
                $centralidnumber = 'central_' . $data['courseid'];
                $localcourse = $DB->get_record('course', ['idnumber' => $centralidnumber]);
                error_log('[SYNCQUEUE ENROL DEBUG] Course fallback by central idnumber "' . $centralidnumber . '": ' . ($localcourse ? 'found id=' . $localcourse->id : 'NOT FOUND'));
            }
            if (!$localcourse && !empty($courseinfo['shortname'])) {
                $localcourse = $DB->get_record('course', ['shortname' => $courseinfo['shortname']]);
                error_log('[SYNCQUEUE ENROL DEBUG] Course fallback by shortname "' . $courseinfo['shortname'] . '": ' . ($localcourse ? 'found id=' . $localcourse->id : 'NOT FOUND'));
            }
            if ($localcourse) {
                $localcourseid = $localcourse->id;
                $this->mapper->set_mapping('course', $localcourse->id, $data['courseid']);
            }
        }

        error_log('[SYNCQUEUE ENROL DEBUG] Final: localuserid=' . ($localuserid ?? 'NULL') . ' localcourseid=' . ($localcourseid ?? 'NULL'));

        if (!$localuserid || !$localcourseid) {
            error_log('[SYNCQUEUE ENROL DEBUG] SKIPPING enrolment - missing ' . (!$localuserid ? 'user' : '') . (!$localcourseid ? ' course' : ''));
            return false; // Can't enrol without both.
        }

        // Get manual enrol instance.
        $enrol = $DB->get_record('enrol', [
            'courseid' => $localcourseid,
            'enrol' => 'manual',
        ]);

        if (!$enrol) {
            return false;
        }

        // Check if already enrolled.
        $existing = $DB->get_record('user_enrolments', [
            'enrolid' => $enrol->id,
            'userid' => $localuserid,
        ]);

        if ($existing) {
            // Update status if needed.
            if ($existing->status != $data['status']) {
                $existing->status = $data['status'];
                $existing->timemodified = time();
                $DB->update_record('user_enrolments', $existing);
            }
            return true;
        }

        // Create enrolment.
        $enrolment = new stdClass();
        $enrolment->enrolid = $enrol->id;
        $enrolment->userid = $localuserid;
        $enrolment->status = $data['status'] ?? 0;
        $enrolment->timestart = $data['timestart'] ?? 0;
        $enrolment->timeend = $data['timeend'] ?? 0;
        $enrolment->timecreated = time();
        $enrolment->timemodified = time();

        $DB->insert_record('user_enrolments', $enrolment);

        // Assign role.
        $context = \context_course::instance($localcourseid);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        if ($roleid) {
            role_assign($roleid, $localuserid, $context->id);
        }

        return true;
    }

    /**
     * Process a course content update (requires backup/restore).
     *
     * @param array $update Update data.
     * @return bool Success.
     */
    protected function process_content_update(array $update): bool {
        // Course content updates require backup/restore.
        // This is a placeholder for the more complex implementation.

        $data = is_string($update['data']) ? json_decode($update['data'], true) : $update['data'];
        $backupurl = $data['backup_url'] ?? null;

        if (!$backupurl) {
            return false;
        }

        // TODO: Download backup file and restore.
        // This requires significant implementation for:
        // 1. Download backup file from central server
        // 2. Extract and validate
        // 3. Run restore process
        // 4. Update ID mappings
        // 5. Call enrich_applied_course() once the course record is final
        //    (restore fires no core course events).

        return false;
    }
}
