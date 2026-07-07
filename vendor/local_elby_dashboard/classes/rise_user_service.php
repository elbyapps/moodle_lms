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
 * RISE learner -> Moodle user provisioning service.
 *
 * Owns all identity -> account logic so the approval hook, the manual
 * "create account" endpoint and the backfill task share one code path:
 *
 *  - link-first matching on user.idnumber = nid (production reality: many
 *    learners already have accounts that just aren't linked to a review row);
 *  - creation via user_create_user() with the sequential {type}{yy}{seq}
 *    username scheme, minted collision-free through the Lock API;
 *  - server-authoritative identity: provisioning always re-fetches the
 *    applicant from the RISE API and fails closed — the browser-supplied
 *    review snapshot never feeds user.idnumber, names, phone or the RISE
 *    linkedUserId back-write;
 *  - learner notifications (SMS + bell) with payload-hash dedupe and
 *    token-aware resend.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Provisioning service for RISE learners (see class file docblock).
 */
class rise_user_service {

    /** @var string Identity is complete and NIDA-consistent. */
    public const ACTION_OK = 'ok';

    /** @var string No NID on the applicant record. */
    public const ACTION_NID_MISSING = 'nid_missing';

    /** @var string NID present but not a 16-digit number. */
    public const ACTION_NID_INVALID = 'nid_invalid';

    /** @var string NIDA comparison was a mismatch. */
    public const ACTION_DETAILS_MISMATCH = 'details_mismatch';

    /** @var string Another user already owns this idnumber — blocked, reviewer resolves. */
    public const ACTION_DUPLICATE_NID = 'duplicate_nid';

    /** @var string[] Action codes the learner can fix themselves (drive SMS + badge). */
    public const FIXABLE_ACTIONS = [
        self::ACTION_NID_MISSING,
        self::ACTION_NID_INVALID,
        self::ACTION_DETAILS_MISMATCH,
    ];

    /** @var rise_client|null Lazily-created RISE API client (injectable for tests). */
    private ?rise_client $riseclient;

    /** @var sms_client|null Lazily-created SMS client (injectable for tests). */
    private ?sms_client $smsclient;

    /** @var tmis_client|null Lazily-created TMIS/NIDA client (injectable for tests). */
    private ?tmis_client $tmisclient;

    /**
     * Constructor.
     *
     * @param rise_client|null $riseclient RISE API client override (tests).
     * @param sms_client|null $smsclient SMS client override (tests).
     * @param tmis_client|null $tmisclient TMIS client override (tests).
     */
    public function __construct(?rise_client $riseclient = null, ?sms_client $smsclient = null,
            ?tmis_client $tmisclient = null) {
        $this->riseclient = $riseclient;
        $this->smsclient = $smsclient;
        $this->tmisclient = $tmisclient;
    }

    /**
     * The RISE API client.
     */
    private function rise(): rise_client {
        return $this->riseclient ?? ($this->riseclient = new rise_client());
    }

    /**
     * The SMS client.
     */
    private function sms(): sms_client {
        return $this->smsclient ?? ($this->smsclient = new sms_client());
    }

    // =========================================================================
    // Name / identity helpers (shared with external\rise and external\signup).
    // =========================================================================

    /**
     * Split a full name into firstname/lastname by the Rwandan convention:
     * first word = lastname (family name), rest = firstname.
     *
     * Example: "NIYONZIMA BRUNO AMAN" -> lastname "Niyonzima", firstname "Bruno Aman".
     *
     * @param string $fullname Full name string.
     * @return array{firstname: string, lastname: string}
     */
    public static function split_name(string $fullname): array {
        $fullname = trim($fullname);
        if ($fullname === '') {
            return ['firstname' => '', 'lastname' => ''];
        }

        $parts = preg_split('/\s+/', $fullname);

        $lastname = ucfirst(strtolower(array_shift($parts)));
        $firstname = '';
        if (!empty($parts)) {
            $firstname = implode(' ', array_map(function ($part) {
                return ucfirst(strtolower($part));
            }, $parts));
        }

        return ['firstname' => $firstname, 'lastname' => $lastname];
    }

    /**
     * Whether a National ID has the valid 16-digit format.
     *
     * Format-only check; identity correctness is established by the NIDA
     * comparison, not a local checksum.
     *
     * @param string $nid National ID.
     * @return bool
     */
    public static function is_valid_nid(string $nid): bool {
        return (bool) preg_match('/^\d{16}$/', trim($nid));
    }

    /**
     * Compare two names tolerantly (case/order/diacritics-insensitive, subset match).
     *
     * @param string $a First name.
     * @param string $b Second name.
     * @return bool True if they plausibly refer to the same person.
     */
    public static function names_match(string $a, string $b): bool {
        $tokens = function (string $s): array {
            $s = strtoupper(trim($s));
            // Strip accents to ASCII where possible.
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
            $s = preg_replace('/[^A-Z ]+/', ' ', $s);
            $parts = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique($parts));
        };
        $ta = $tokens($a);
        $tb = $tokens($b);
        if (empty($ta) || empty($tb)) {
            return false;
        }
        // All tokens of the shorter set must appear in the longer set.
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        foreach ($short as $t) {
            if (!in_array($t, $long, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Pull a citizen's name out of a TMIS payload, trying common field shapes.
     *
     * @param array $citizen Decoded TMIS payload.
     * @return string Best-effort full name.
     */
    public static function extract_citizen_name(array $citizen): string {
        // Unwrap a single 'data'/'user'/'citizen' envelope if present.
        foreach (['data', 'user', 'citizen', 'result'] as $wrap) {
            if (isset($citizen[$wrap]) && is_array($citizen[$wrap])) {
                $citizen = $citizen[$wrap];
                break;
            }
        }

        $get = function (array $keys) use ($citizen): string {
            foreach ($keys as $k) {
                if (!empty($citizen[$k]) && is_string($citizen[$k])) {
                    return $citizen[$k];
                }
            }
            return '';
        };

        $full = $get(['fullName', 'fullNames', 'names', 'name']);
        if ($full !== '') {
            return $full;
        }
        $fore = $get(['foreName', 'firstName', 'givenName', 'firstNames']);
        $sur = $get(['surname', 'lastName', 'familyName']);
        return trim($fore . ' ' . $sur);
    }

    /**
     * Pull a citizen's date of birth out of a TMIS payload.
     *
     * @param array $citizen Decoded TMIS payload.
     * @return string Best-effort DOB string.
     */
    public static function extract_citizen_dob(array $citizen): string {
        foreach (['data', 'user', 'citizen', 'result'] as $wrap) {
            if (isset($citizen[$wrap]) && is_array($citizen[$wrap])) {
                $citizen = $citizen[$wrap];
                break;
            }
        }
        foreach (['dateOfBirth', 'dob', 'birthDate', 'dateNaissance'] as $k) {
            if (!empty($citizen[$k]) && is_string($citizen[$k])) {
                return $citizen[$k];
            }
        }
        return '';
    }

    /**
     * Normalize a date of birth to YYYY-MM-DD for comparison.
     *
     * @param string $value Raw date string.
     * @return string Normalized date, or the digits-only fallback.
     */
    public static function normalize_dob(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Drop any time component.
        $datepart = preg_split('/[T ]/', $value)[0];
        $ts = strtotime($datepart);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return preg_replace('/\D+/', '', $value);
    }

    // =========================================================================
    // Action-code evaluation.
    // =========================================================================

    /**
     * Evaluate the learner-facing action code for an applicant + review pair.
     *
     * Duplicate detection needs DB context and happens inside provision();
     * this covers the identity-quality codes.
     *
     * @param array $applicant Server-fetched RISE applicant.
     * @param \stdClass|null $review Review row (for the NIDA status), if any.
     * @return string One of the ACTION_* codes (never duplicate_nid).
     */
    public function evaluate_action(array $applicant, ?\stdClass $review): string {
        $nid = trim((string) ($applicant['nid'] ?? ''));
        if ($nid === '') {
            return self::ACTION_NID_MISSING;
        }
        if (!self::is_valid_nid($nid)) {
            return self::ACTION_NID_INVALID;
        }
        if ($review && ($review->nidstatus ?? '') === 'mismatch') {
            return self::ACTION_DETAILS_MISMATCH;
        }
        return self::ACTION_OK;
    }

    // =========================================================================
    // Username generation.
    // =========================================================================

    /**
     * Mint the next sequential {type}{yy}{seq} username (e.g. 12609278).
     *
     * Portable and concurrency-safe: a per-prefix row in elby_rise_username_seq
     * is advanced inside a Moodle Lock, and numbers already taken by the legacy
     * manual scheme are skipped.
     *
     * @param string $type 'learner' (prefix 1) or 'facilitator' (prefix 2).
     * @return string The minted username.
     * @throws \moodle_exception When the lock cannot be acquired.
     */
    public function next_username(string $type = 'learner'): string {
        global $DB;

        $prefix = ($type === 'facilitator' ? '2' : '1') . substr(date('Y'), 2, 2);
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_elby_dashboard_username');
        $lock = $lockfactory->get_lock($prefix, 10);
        if (!$lock) {
            throw new \moodle_exception('riseusernamelock', 'local_elby_dashboard');
        }
        try {
            $seq = $DB->get_field('elby_rise_username_seq', 'nextval', ['seqkey' => $prefix]);
            if ($seq === false) {
                $seq = 1;
                $DB->insert_record('elby_rise_username_seq', (object) ['seqkey' => $prefix, 'nextval' => $seq]);
            }
            $seq = (int) $seq;
            // Skip any number already taken (e.g. manually created before this feature).
            do {
                $username = $prefix . str_pad((string) $seq++, 5, '0', STR_PAD_LEFT);
            } while ($DB->record_exists('user', ['username' => $username]));
            $DB->set_field('elby_rise_username_seq', 'nextval', $seq, ['seqkey' => $prefix]);
        } finally {
            $lock->release();
        }

        return $username;
    }

    // =========================================================================
    // Provisioning.
    // =========================================================================

    /**
     * Provision (link or create) the Moodle account for a RISE learner.
     *
     * Identity comes from a server-side RISE fetch and the call fails closed on
     * fetch error or campaign mismatch. Idempotent: re-running no-ops when
     * already linked and only refreshes the action code / notifications.
     *
     * @param string $campaignid RISE campaign _id.
     * @param string $applicantid RISE applicant _id.
     * @return array{userid: int, created: bool, action: string, username: string,
     *               risesync: string, blocked: bool, blockedreason: string}
     *         blocked=true means NO account exists/was linked (blockedreason:
     *         'not_approved' or 'duplicate_nid'). An ALREADY-LINKED learner whose
     *         corrected NID now belongs to another user keeps blocked=false (the
     *         account exists and stays linked) with action='duplicate_nid' as the
     *         reviewer-facing conflict flag — callers must check action, not only
     *         blocked, to detect conflicts.
     * @throws \moodle_exception When the identity fetch fails (fail closed).
     */
    public function provision(string $campaignid, string $applicantid): array {
        // Server-authoritative identity — never the browser snapshot. Fetched
        // outside the lock: it's a network call and needs no serialization.
        $applicant = $this->fetch_applicant($campaignid, $applicantid);

        // Serialize the whole critical section per applicant (and per NID, so two
        // different applicants sharing one NID can't both create an account for
        // it): approval auto-provision, the manual create button, the backfill
        // task AND review decision saves all contend on the same per-applicant
        // lock, so the approval state re-read below cannot change mid-flight.
        // Lock order is fixed (applicant first, then NID) so the two locks
        // cannot deadlock.
        $nid = trim((string) ($applicant['nid'] ?? ''));
        return $this->with_applicant_lock($campaignid, $applicantid,
            function () use ($campaignid, $applicantid, $applicant, $nid) {
                $nidlock = null;
                if ($nid !== '') {
                    $lockfactory = \core\lock\lock_config::get_lock_factory('local_elby_dashboard_provision');
                    $nidlock = $lockfactory->get_lock(sha1('nid:' . $nid), 30);
                    if (!$nidlock) {
                        throw new \moodle_exception('riseprovisionlocktimeout', 'local_elby_dashboard');
                    }
                }
                try {
                    return $this->provision_locked($campaignid, $applicantid, $applicant);
                } finally {
                    if ($nidlock) {
                        $nidlock->release();
                    }
                }
            });
    }

    /**
     * Run a callback while holding the per-applicant provisioning lock.
     *
     * Every writer that can affect the provisioning decision must go through
     * this: provision() itself AND review decision saves (save_review), so a
     * decision flip can never interleave with a running provision. The lock is
     * NOT reentrant — never call with_applicant_lock() or provision() for the
     * same applicant from inside the callback.
     *
     * @param string $campaignid RISE campaign _id.
     * @param string $applicantid RISE applicant _id.
     * @param callable $callback Callback executed under the lock.
     * @return mixed The callback's return value.
     * @throws \moodle_exception When the lock cannot be acquired.
     */
    public function with_applicant_lock(string $campaignid, string $applicantid, callable $callback) {
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_elby_dashboard_provision');
        $lock = $lockfactory->get_lock(sha1($campaignid . ':' . $applicantid), 30);
        if (!$lock) {
            throw new \moodle_exception('riseprovisionlocktimeout', 'local_elby_dashboard');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * The provisioning critical section. Must only run under the per-applicant
     * (and per-NID) locks taken by provision(): all review/user state is read
     * and decided here, inside the lock.
     *
     * @param string $campaignid RISE campaign _id.
     * @param string $applicantid RISE applicant _id.
     * @param array $applicant Validated server-fetched applicant.
     * @return array See provision().
     */
    private function provision_locked(string $campaignid, string $applicantid, array $applicant): array {
        global $DB;

        // Re-read the review INSIDE the lock and re-check approval: the caller's
        // approval check happened before the lock, and a concurrent review save
        // may have flipped the decision since. Accounts are only ever linked or
        // created for a currently-approved review.
        $review = $DB->get_record('elby_rise_reviews', [
            'campaignid' => $campaignid,
            'applicantid' => $applicantid,
        ]);
        if (!$review || $review->nesastatus !== 'approved') {
            return [
                'userid' => 0, 'created' => false,
                'action' => (string) (($review->provisioningaction ?? '') !== '' ? $review->provisioningaction : 'none'),
                'username' => '', 'risesync' => (string) ($review->risesyncstatus ?? ''),
                'blocked' => true, 'blockedreason' => 'not_approved',
            ];
        }

        $nid = trim((string) ($applicant['nid'] ?? ''));

        // Server-side NIDA verification (best effort; browser nidverified is not trusted).
        $this->verify_nida($applicant, $review);

        // Already linked? Refresh and no-op.
        if (!empty($review->userid)) {
            $user = $DB->get_record('user', ['id' => $review->userid, 'deleted' => 0]);
            if ($user) {
                $this->refresh_linked_user($user, $applicant, $nid);
                $action = $this->evaluate_action($applicant, $review);
                // A corrected RISE NID that now belongs to a DIFFERENT active user
                // is a conflict the reviewer must resolve — refresh_linked_user()
                // deliberately refused to move the idnumber, and reporting 'ok'
                // here would hide that.
                if ($nid !== '' && $nid !== $user->idnumber
                        && $DB->record_exists_select('user',
                            'idnumber = :nid AND deleted = 0 AND id <> :id',
                            ['nid' => $nid, 'id' => $user->id])) {
                    $action = self::ACTION_DUPLICATE_NID;
                }
                $this->save_provision_state($review, (int) $user->id, $action);
                $risesync = $this->sync_rise_link($review, $applicant, (int) $user->id);
                $this->maybe_resend_welcome($review, $applicant, $user);
                $this->notify_learner($review, $applicant);
                return [
                    'userid' => (int) $user->id, 'created' => false, 'action' => $action,
                    'username' => $user->username, 'risesync' => $risesync, 'blocked' => false,
                    'blockedreason' => '',
                ];
            }
            // The linked user was deleted — clear the stale link and re-resolve, so the
            // row is also visible again to the backfill sweep.
            $DB->set_field('elby_rise_reviews', 'userid', null, ['id' => $review->id]);
            $review->userid = null;
        }

        $action = $this->evaluate_action($applicant, $review);
        $user = null;
        $created = false;

        if ($nid !== '') {
            // Match by NID (primary path in production). Includes suspended users.
            $matches = $DB->get_records('user', ['idnumber' => $nid, 'deleted' => 0]);

            if (count($matches) > 1) {
                // Multiple active users share the NID — never guess.
                $this->save_provision_state($review, null, self::ACTION_DUPLICATE_NID);
                return [
                    'userid' => 0, 'created' => false, 'action' => self::ACTION_DUPLICATE_NID,
                    'username' => '', 'risesync' => (string) ($review->risesyncstatus ?? 'ok'), 'blocked' => true,
                    'blockedreason' => 'duplicate_nid',
                ];
            }

            if (count($matches) === 1) {
                $candidate = reset($matches);
                // A real person maps to one applicant: block if already linked elsewhere.
                // Also block when the account holding the NID isn't a RISE-shaped learner
                // account (e.g. a staff/SDMS/admin account whose idnumber is their NID) —
                // linking would let provisioning overwrite that account's identity.
                $linkedelsewhere = $DB->record_exists_select('elby_rise_reviews',
                    'userid = :userid AND id <> :reviewid',
                    ['userid' => $candidate->id, 'reviewid' => $review->id]);
                if ($linkedelsewhere || !self::is_linkable($candidate)) {
                    $this->save_provision_state($review, null, self::ACTION_DUPLICATE_NID);
                    return [
                        'userid' => 0, 'created' => false, 'action' => self::ACTION_DUPLICATE_NID,
                        'username' => '', 'risesync' => (string) ($review->risesyncstatus ?? 'ok'), 'blocked' => true,
                        'blockedreason' => 'duplicate_nid',
                    ];
                }
                // Link. Suspended users are linked but never silently re-activated;
                // status_for() surfaces the suspension to the reviewer.
                $user = $candidate;
                if ($action === self::ACTION_OK
                        && !self::names_match((string) ($applicant['fullName'] ?? ''), fullname($candidate))) {
                    $action = self::ACTION_DETAILS_MISMATCH;
                }
            }
        }

        if (!$user) {
            $user = $this->create_user($applicant, $nid);
            $created = true;
        }

        $this->save_provision_state($review, (int) $user->id, $action);
        $risesync = $this->sync_rise_link($review, $applicant, (int) $user->id);

        if ($created) {
            $this->send_welcome($review, $applicant, $user);
        }
        $this->notify_learner($review, $applicant);

        return [
            'userid' => (int) $user->id, 'created' => $created, 'action' => $action,
            'username' => $user->username, 'risesync' => $risesync, 'blocked' => false,
            'blockedreason' => '',
        ];
    }

    /**
     * Fetch an applicant from RISE and fail closed unless the response
     * positively matches the requested applicant AND campaign.
     *
     * Single validation point for every RISE identity consumer (provisioning,
     * sync retries, notifications, NIDA validation) — a response without a
     * matching campaign id is an identity failure, never a fallback.
     *
     * @param string $campaignid RISE campaign _id the caller expects.
     * @param string $applicantid RISE applicant _id.
     * @return array Validated applicant payload.
     * @throws \moodle_exception On fetch error or id/campaign mismatch.
     */
    public function fetch_applicant(string $campaignid, string $applicantid): array {
        try {
            $applicant = $this->rise()->get_applicant($applicantid);
        } catch (\moodle_exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \moodle_exception('riseprovisionfetchfailed', 'local_elby_dashboard', '', $e->getMessage());
        }
        if (empty($applicant['_id']) || (string) $applicant['_id'] !== $applicantid) {
            throw new \moodle_exception('riseprovisionfetchfailed', 'local_elby_dashboard', '',
                'Applicant id mismatch in RISE response.');
        }
        $remotecampaign = (string) ($applicant['campaignId'] ?? ($applicant['campaign']['_id'] ?? ''));
        if ($remotecampaign !== $campaignid) {
            throw new \moodle_exception('riseprovisionfetchfailed', 'local_elby_dashboard', '',
                $remotecampaign === '' ? 'RISE response carries no campaign id.'
                    : 'Applicant belongs to a different campaign.');
        }
        return $applicant;
    }

    /**
     * Re-run the TMIS/NIDA comparison server-side and persist the outcome.
     *
     * Best effort: an unreachable TMIS or inconclusive payload leaves the
     * stored nidstatus untouched (it never blocks provisioning).
     *
     * @param array $applicant Server-fetched RISE applicant.
     * @param \stdClass $review Review row; nidstatus/nidverified refreshed in place.
     */
    private function verify_nida(array $applicant, \stdClass $review): void {
        global $DB;

        $nid = trim((string) ($applicant['nid'] ?? ''));
        if (!self::is_valid_nid($nid)) {
            return;
        }
        try {
            $client = $this->tmisclient ?? new tmis_client();
            $citizen = $client->get_citizen($nid);
        } catch (\Throwable $e) {
            debugging('RISE server-side NIDA check failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        $citizenname = self::extract_citizen_name($citizen);
        if ($citizenname === '') {
            return;
        }
        $namematch = self::names_match((string) ($applicant['fullName'] ?? ''), $citizenname);
        $citizendob = self::extract_citizen_dob($citizen);
        $appdob = (string) ($applicant['dateOfBirth'] ?? '');
        $dobmatch = ($appdob === '' || $citizendob === '') ? null
            : (self::normalize_dob($appdob) === self::normalize_dob($citizendob));
        $match = $namematch && $dobmatch !== false;

        $review->nidstatus = $match ? 'verified' : 'mismatch';
        $review->nidverified = $match ? 1 : 0;
        $DB->update_record('elby_rise_reviews', (object) [
            'id' => $review->id,
            'nidstatus' => $review->nidstatus,
            'nidverified' => $review->nidverified,
            'timemodified' => time(),
        ]);
    }

    /**
     * Create the Moodle account for an applicant.
     *
     * @param array $applicant Server-fetched RISE applicant.
     * @param string $nid Trimmed NID (stored best-effort in idnumber, may be '').
     * @return \stdClass The created user record.
     */
    private function create_user(array $applicant, string $nid): \stdClass {
        global $CFG, $DB;

        $names = self::split_name((string) ($applicant['fullName'] ?? ''));
        $username = $this->next_username('learner');
        $domain = get_config('local_elby_dashboard', 'rise_signup_email_domain') ?: 'learner.rise.reb.rw';

        $user = new \stdClass();
        $user->username = $username;
        $user->password = generate_password(12);
        $user->firstname = $names['firstname'] !== '' ? $names['firstname'] : $names['lastname'];
        $user->lastname = $names['lastname'] !== '' ? $names['lastname'] : $username;
        // Deterministic synthetic email — unique by construction since the username is
        // unique. The applicant-supplied email is never used as the login email.
        $user->email = $username . '@' . $domain;
        $user->idnumber = $nid;
        $user->phone1 = (string) ($applicant['phone'] ?? '');
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;

        $userid = user_create_user($user, true, true);
        set_user_preference('auth_forcepasswordchange', 1, $userid);

        return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    }

    /**
     * Whether an existing account may be linked (and later refreshed) as a RISE learner.
     *
     * Only RISE-shaped learner accounts qualify: manual auth with the 8-digit
     * {type}{yy}{seq} username scheme, and never a site admin. Anything else
     * holding the NID (staff, SDMS, admin accounts) is blocked as a conflict
     * for the reviewer to resolve — provisioning must not mutate or notify an
     * account this feature doesn't own.
     *
     * @param \stdClass $user Candidate user record.
     * @return bool
     */
    public static function is_linkable(\stdClass $user): bool {
        if (is_siteadmin($user->id)) {
            return false;
        }
        return $user->auth === 'manual' && preg_match('/^[12]\d{7}$/', $user->username);
    }

    /**
     * After a correction + re-approval, update the linked user's names / idnumber /
     * phone when the (server-fetched) applicant data changed.
     *
     * @param \stdClass $user Linked user (updated in place).
     * @param array $applicant Server-fetched RISE applicant.
     * @param string $nid Trimmed NID.
     */
    private function refresh_linked_user(\stdClass $user, array $applicant, string $nid): void {
        global $DB;

        // Safety net: never rewrite an account this feature doesn't own.
        if (!self::is_linkable($user)) {
            return;
        }

        $changes = [];
        $names = self::split_name((string) ($applicant['fullName'] ?? ''));
        if ($names['lastname'] !== '' && ($names['firstname'] !== $user->firstname || $names['lastname'] !== $user->lastname)) {
            $changes['firstname'] = $names['firstname'] !== '' ? $names['firstname'] : $names['lastname'];
            $changes['lastname'] = $names['lastname'];
        }
        $phone = (string) ($applicant['phone'] ?? '');
        if ($phone !== '' && $phone !== $user->phone1) {
            $changes['phone1'] = $phone;
        }
        if ($nid !== '' && $nid !== $user->idnumber) {
            // Never move an idnumber onto this user while another active user holds it.
            $taken = $DB->record_exists_select('user',
                'idnumber = :nid AND deleted = 0 AND id <> :id', ['nid' => $nid, 'id' => $user->id]);
            if (!$taken) {
                $changes['idnumber'] = $nid;
            }
        }
        if (!$changes) {
            return;
        }
        $update = (object) (['id' => $user->id] + $changes);
        user_update_user($update, false, true);
        foreach ($changes as $field => $value) {
            $user->$field = $value;
        }
    }

    /**
     * Persist the provisioning outcome on the review row.
     *
     * @param \stdClass $review Review row (updated in place).
     * @param int|null $userid Linked user id, or null when blocked.
     * @param string $action Action code.
     */
    private function save_provision_state(\stdClass $review, ?int $userid, string $action): void {
        global $DB;

        $update = (object) [
            'id' => $review->id,
            'provisioningaction' => $action,
            'timemodified' => time(),
        ];
        if ($userid !== null && (int) ($review->userid ?? 0) !== $userid) {
            $update->userid = $userid;
            $update->userprovisionedat = time();
            $review->userid = $userid;
            $review->userprovisionedat = $update->userprovisionedat;
        }
        $DB->update_record('elby_rise_reviews', $update);
        $review->provisioningaction = $action;

        // Nothing outstanding any more: revoke any live correction link so a
        // stale token can't submit (or fetch evidence) after resolution.
        $this->revoke_correction_tokens_if_resolved($review);
    }

    /**
     * Whether a review still requires learner action: a fixable provisioning
     * code, an action_requested/rejected decision, or a resubmission awaiting
     * re-review. Single source of truth for the correction form, the
     * correction-evidence pluginfile access and token revocation.
     *
     * @param \stdClass $review Review row.
     * @return bool
     */
    public static function action_outstanding(\stdClass $review): bool {
        return in_array((string) ($review->provisioningaction ?? ''), self::FIXABLE_ACTIONS, true)
            || in_array($review->nesastatus, ['action_requested', 'rejected'], true)
            || ($review->correctionstatus ?? '') === 'resubmitted';
    }

    /**
     * Revoke unused correction tokens once the review no longer needs learner
     * action — called from every resolution path (provisioning state changes
     * and review saves) so no live link outlives its purpose.
     *
     * @param \stdClass $review Review row (current state).
     */
    public function revoke_correction_tokens_if_resolved(\stdClass $review): void {
        global $DB;
        if (self::action_outstanding($review)) {
            return;
        }
        $DB->delete_records_select('elby_rise_tokens',
            "purpose = :purpose AND campaignid = :campaignid AND applicantid = :applicantid AND usedat = 0",
            ['purpose' => rise_token::PURPOSE_CORRECTION,
             'campaignid' => $review->campaignid, 'applicantid' => $review->applicantid]);
    }

    /**
     * Write the Moodle userid back to RISE (linkedUserId) with conflict detection.
     *
     * Moodle is the source of truth: a failed PATCH never rolls provisioning
     * back — the failure is recorded and retried by the backfill task. An
     * existing different linkedUserId is a conflict, never overwritten.
     *
     * @param \stdClass $review Review row (sync state updated in place).
     * @param array $applicant Server-fetched RISE applicant.
     * @param int $userid Linked Moodle user id.
     * @return string Resulting sync status: ok, pending, error or conflict.
     */
    private function sync_rise_link(\stdClass $review, array $applicant, int $userid): string {
        global $DB;

        $remote = trim((string) ($applicant['linkedUserId'] ?? ''));
        $update = (object) ['id' => $review->id, 'timemodified' => time()];

        if ($remote !== '' && $remote !== (string) $userid) {
            $update->risesyncstatus = 'conflict';
            $update->risesyncerror = 'RISE already reports linkedUserId=' . $remote;
            $update->riselinkeduserid = $remote;
        } else if ($remote === (string) $userid) {
            $update->risesyncstatus = 'ok';
            $update->risesyncerror = null;
            $update->riselinkeduserid = $remote;
            if (empty($review->risesyncedat)) {
                $update->risesyncedat = time();
            }
        } else {
            // Not linked on the RISE side yet — idempotent PATCH.
            try {
                $response = $this->rise()->patch_applicant($review->applicantid, ['linkedUserId' => (string) $userid]);
                if (!empty($response['success'])) {
                    $update->risesyncstatus = 'ok';
                    $update->risesyncerror = null;
                    $update->risesyncedat = time();
                    $update->riselinkeduserid = (string) $userid;
                } else {
                    $update->risesyncstatus = 'error';
                    $update->risesyncerror = 'RISE rejected the update: ' . json_encode($response);
                }
            } catch (\Throwable $e) {
                $update->risesyncstatus = 'pending';
                $update->risesyncerror = $e->getMessage();
            }
        }

        $DB->update_record('elby_rise_reviews', $update);
        foreach ((array) $update as $field => $value) {
            if ($field !== 'id') {
                $review->$field = $value;
            }
        }
        return $review->risesyncstatus;
    }

    // =========================================================================
    // Notifications.
    // =========================================================================

    /**
     * Public base URL for learner-facing links in SMS messages.
     *
     * @return string Base URL without a trailing slash.
     */
    public static function link_base(): string {
        global $CFG;
        $base = trim((string) (get_config('local_elby_dashboard', 'rise_action_link_base') ?: ''));
        return rtrim($base !== '' ? $base : $CFG->wwwroot, '/');
    }

    /**
     * Welcome SMS on account creation: username + single-use set-password link.
     *
     * @param \stdClass $review Review row.
     * @param array $applicant Server-fetched RISE applicant (phone source).
     * @param \stdClass $user The newly created user.
     */
    private function send_welcome(\stdClass $review, array $applicant, \stdClass $user): void {
        $raw = rise_token::mint(rise_token::PURPOSE_SETPASSWORD, $review->campaignid, $review->applicantid,
            (int) $user->id);
        $url = self::link_base() . '/local/elby_dashboard/rise_setpassword.php?t=' . $raw;
        $message = get_string('rise_sms_welcome', 'local_elby_dashboard', (object) [
            'username' => $user->username,
            'url' => $url,
        ]);
        $this->log_and_send_sms((string) ($applicant['phone'] ?? ''), $message, 'welcome', $review);
    }

    /**
     * Re-send the welcome/set-password SMS when the original never went out and
     * the learner has never logged in — otherwise a transient gateway failure
     * would strand a freshly created account with no way to get credentials.
     *
     * Called from the already-linked provision branch (manual button, re-approval,
     * backfill), and only when the gateway is currently configured so a disabled
     * dev/staging gateway doesn't log skipped attempts forever.
     *
     * @param \stdClass $review Review row.
     * @param array $applicant Server-fetched RISE applicant (phone source).
     * @param \stdClass $user The linked user.
     */
    private function maybe_resend_welcome(\stdClass $review, array $applicant, \stdClass $user): void {
        global $DB;

        if ((int) $user->firstaccess > 0 || !$this->sms()->is_configured()) {
            return;
        }
        $params = ['campaignid' => $review->campaignid, 'applicantid' => $review->applicantid, 'purpose' => 'welcome'];
        // Only accounts this feature created get a welcome (an attempt exists),
        // and only while none has ever been delivered.
        if (!$DB->record_exists('elby_rise_sms_log', $params)
                || $DB->record_exists('elby_rise_sms_log', $params + ['status' => 'sent'])) {
            return;
        }
        $this->send_welcome($review, $applicant, $user);
    }

    /**
     * Retry only the RISE linkedUserId back-write for an already-linked review
     * (used by the backfill task so sync retries never re-run the notification
     * pipeline).
     *
     * @param string $campaignid RISE campaign _id.
     * @param string $applicantid RISE applicant _id.
     * @return string Resulting sync status, or 'skipped' when not applicable.
     */
    public function retry_rise_sync(string $campaignid, string $applicantid): string {
        global $DB;

        $review = $DB->get_record('elby_rise_reviews', [
            'campaignid' => $campaignid,
            'applicantid' => $applicantid,
        ]);
        if (!$review || empty($review->userid)) {
            return 'skipped';
        }
        $applicant = $this->fetch_applicant($campaignid, $applicantid);
        return $this->sync_rise_link($review, $applicant, (int) $review->userid);
    }

    /**
     * Strip raw deep-link tokens from a message before persisting it anywhere.
     *
     * Tokens are stored only as hashes in elby_rise_tokens; a cleartext copy in
     * the SMS log or bell payload would defeat that design.
     *
     * @param string $text Message text possibly containing ?t={token} links.
     * @return string Redacted text.
     */
    public static function redact_tokens(string $text): string {
        return preg_replace('/([?&]t=)[a-f0-9]{16,}/i', '$1[link]', $text);
    }

    /**
     * Action-needed notification (SMS + bell), deduped by payload hash with
     * token-aware resend.
     *
     * Sends when the review decision requires learner action (action_requested,
     * rejected) or the provisioning action is fixable. Skips when the payload
     * hash is unchanged AND an active correction token still exists.
     *
     * Concurrency contract: the dedupe is read-then-act, so callers MUST hold
     * the per-applicant lock (with_applicant_lock) and pass a review row read
     * under it — provision_locked() already does; save_review, the nightly
     * retry pass and the backlog ad-hoc task wrap their calls. Never acquire
     * the lock in here (it is not reentrant for the provisioning path).
     *
     * @param \stdClass $review Review row (notification state updated in place),
     *                          read under the applicant lock.
     * @param array|null $applicant MUST be a fetch_applicant() result for this
     *                              review (id + campaign already validated), or
     *                              null to fetch here. Never pass the browser
     *                              snapshot or any unvalidated payload — the
     *                              phone/dedupe key are derived from it.
     */
    public function notify_learner(\stdClass $review, ?array $applicant = null): void {
        global $DB;

        $action = (string) ($review->provisioningaction ?? '');
        $needsfix = in_array($action, self::FIXABLE_ACTIONS, true);
        $decisionneedsaction = in_array($review->nesastatus, ['action_requested', 'rejected'], true);
        if (!$needsfix && !$decisionneedsaction) {
            return;
        }

        $comment = trim((string) ($review->comment ?? ''));
        $hasactivetoken = rise_token::has_active(rise_token::PURPOSE_CORRECTION,
            $review->campaignid, $review->applicantid);

        // Identity/contact must be server-authoritative — fetched (and validated
        // against the review's applicant + campaign) before the dedupe decision,
        // because the phone is part of the dedupe key.
        $fetchfailed = false;
        if ($applicant === null) {
            try {
                $applicant = $this->fetch_applicant($review->campaignid, $review->applicantid);
            } catch (\Throwable $e) {
                $applicant = [];
                $fetchfailed = true;
                $this->log_sms('', '', 'action', $review, 'skipped',
                    'Could not fetch applicant from RISE for SMS: ' . $e->getMessage());
            }
        }

        // The normalised phone is folded into the payload hash so a learner whose
        // number was invalid (SMS skipped) is re-notified once RISE carries a
        // usable number, even when the decision/comment/action are unchanged.
        $phonekey = $fetchfailed ? ''
            : (string) (sms_client::normalise_rw((string) ($applicant['phone'] ?? '')) ?? '');
        $hash = sha1($review->nesastatus . '|' . $comment . '|' . $action . '|' . $phonekey);
        if (!$fetchfailed && $hash === ($review->lastnotifiedhash ?? null) && $hasactivetoken) {
            return;
        }
        // With RISE unreachable the hash can't be trusted; if the learner already
        // holds a live link from an earlier delivered notification, don't re-bell.
        if ($fetchfailed && !empty($review->lastnotifiedat) && $hasactivetoken) {
            return;
        }

        $raw = rise_token::mint(rise_token::PURPOSE_CORRECTION, $review->campaignid, $review->applicantid,
            !empty($review->userid) ? (int) $review->userid : null);
        $url = self::link_base() . '/local/elby_dashboard/rise_action.php?t=' . $raw;

        if ($needsfix) {
            $reason = get_string('rise_action_' . $action, 'local_elby_dashboard');
        } else {
            $reason = get_string('rise_action_review', 'local_elby_dashboard');
        }
        $prefix = $reason
            . ($comment !== '' ? ' ' . get_string('rise_sms_reviewercomment', 'local_elby_dashboard', $comment) : '');
        $smsbody = $prefix . ' ' . get_string('rise_sms_fixlink', 'local_elby_dashboard', $url);

        $smsstatus = 'skipped';
        if (!$fetchfailed) {
            $smsstatus = $this->log_and_send_sms((string) ($applicant['phone'] ?? ''), $smsbody, 'action', $review);
        }

        // Second channel: in-Moodle bell for learners with an account. The bell
        // never carries the raw token — logged-in learners use the session path.
        $bellsent = false;
        if (!empty($review->userid)) {
            $sessionurl = self::link_base() . '/local/elby_dashboard/rise_action.php';
            $bellsent = $this->send_bell((int) $review->userid, $prefix, $sessionurl);
        }

        // Only record the payload as notified when a channel delivered it, or the
        // skip is permanent (bad/missing phone, gateway deliberately disabled).
        // Transient failures (RISE fetch down, gateway send failure) leave the hash
        // untouched so the next save/provision/backfill pass retries the send.
        $delivered = $smsstatus === 'sent' || $bellsent;
        $permanentskip = !$fetchfailed && $smsstatus === 'skipped';
        if (!$delivered && !$permanentskip) {
            return;
        }

        $DB->update_record('elby_rise_reviews', (object) [
            'id' => $review->id,
            'lastnotifiedhash' => $hash,
            'lastnotifiedat' => time(),
            'timemodified' => time(),
        ]);
        $review->lastnotifiedhash = $hash;
        $review->lastnotifiedat = time();
    }

    /**
     * Send the in-Moodle bell notification.
     *
     * @param int $userid Learner user id.
     * @param string $body Message text (must not contain raw tokens).
     * @param string $url Correction-form URL (session-authenticated, token-less).
     * @return bool Whether the message was handed to the message system.
     */
    private function send_bell(int $userid, string $body, string $url): bool {
        try {
            $message = new \core\message\message();
            $message->courseid = SITEID;
            $message->component = 'local_elby_dashboard';
            $message->name = 'riseaction';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $userid;
            $message->subject = get_string('rise_action_subject', 'local_elby_dashboard');
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '<p>' . s($body) . '</p>';
            $message->smallmessage = $body;
            $message->notification = 1;
            $message->contexturl = $url;
            $message->contexturlname = get_string('rise_action_fixdetails', 'local_elby_dashboard');
            return (bool) message_send($message);
        } catch (\Throwable $e) {
            debugging('RISE bell notification failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Send an SMS and record the attempt in elby_rise_sms_log.
     *
     * @param string $phone Raw phone value.
     * @param string $message Message body.
     * @param string $purpose 'welcome', 'action' or 'correction'.
     * @param \stdClass|null $review Review row for campaign/applicant/user context.
     * @return bool True when the gateway accepted the message.
     */
    public function send_sms(string $phone, string $message, string $purpose = '', ?\stdClass $review = null): bool {
        return $this->log_and_send_sms($phone, $message, $purpose, $review) === 'sent';
    }

    /**
     * Internal: normalise, send and log an SMS.
     *
     * @param string $phone Raw phone value.
     * @param string $message Message body.
     * @param string $purpose Log purpose.
     * @param \stdClass|null $review Review row context.
     * @return string 'sent', 'failed' (transient — worth retrying) or 'skipped' (permanent).
     */
    private function log_and_send_sms(string $phone, string $message, string $purpose, ?\stdClass $review): string {
        $normalised = sms_client::normalise_rw($phone);
        if ($normalised === null) {
            // Invalid/missing phone: skip and flag — never silently dropped.
            $this->log_sms($phone, $message, $purpose, $review, 'skipped', 'Invalid Rwandan mobile number: ' . $phone);
            return 'skipped';
        }
        if (!$this->sms()->is_configured()) {
            $this->log_sms($normalised, $message, $purpose, $review, 'skipped', 'SMS gateway disabled or not configured');
            return 'skipped';
        }
        try {
            $ok = $this->sms()->send($normalised, $message);
            $this->log_sms($normalised, $message, $purpose, $review, $ok ? 'sent' : 'failed',
                $ok ? null : 'Gateway did not accept the message');
            return $ok ? 'sent' : 'failed';
        } catch (\Throwable $e) {
            $this->log_sms($normalised, $message, $purpose, $review, 'failed', $e->getMessage());
            return 'failed';
        }
    }

    /**
     * Append a row to the SMS audit log. Raw deep-link tokens are redacted so a
     * usable set-password/correction link never persists at rest.
     *
     * @param string $phone Phone as sent (or raw when unparseable).
     * @param string $message Message body.
     * @param string $purpose Log purpose.
     * @param \stdClass|null $review Review row context.
     * @param string $status sent, failed or skipped.
     * @param string|null $error Failure detail.
     */
    private function log_sms(string $phone, string $message, string $purpose, ?\stdClass $review,
            string $status, ?string $error): void {
        global $DB;
        $DB->insert_record('elby_rise_sms_log', (object) [
            'campaignid' => $review->campaignid ?? null,
            'applicantid' => $review->applicantid ?? null,
            'userid' => !empty($review->userid) ? (int) $review->userid : null,
            'phone' => \core_text::substr($phone, 0, 30),
            'purpose' => $purpose,
            'message' => self::redact_tokens($message),
            'status' => $status,
            'error' => $error,
            'timecreated' => time(),
        ]);
    }

    // =========================================================================
    // Account status for the applicants table.
    // =========================================================================

    /**
     * Resolve account status for a batch of {applicantid, nid} pairs.
     *
     * Because user.idnumber = nid is the join key on both sides, existing
     * accounts resolve even for learners who have never been reviewed.
     *
     * @param array $pairs Array of ['applicantid' => string, 'nid' => string].
     * @param string $campaignid RISE campaign _id.
     * @return array Keyed by applicantid: hasaccount, userid, username, linked,
     *               suspended, provisioningaction, correctionstatus, profileurl.
     */
    public function status_for(array $pairs, string $campaignid): array {
        global $DB;

        $out = [];
        if (empty($pairs)) {
            return $out;
        }

        $applicantids = array_values(array_unique(array_map(function ($p) {
            return (string) $p['applicantid'];
        }, $pairs)));
        [$insql, $inparams] = $DB->get_in_or_equal($applicantids, SQL_PARAMS_NAMED, 'aid');
        $reviews = [];
        foreach ($DB->get_records_select('elby_rise_reviews',
                "campaignid = :campaignid AND applicantid $insql",
                ['campaignid' => $campaignid] + $inparams) as $r) {
            $reviews[$r->applicantid] = $r;
        }

        // Users already linked on review rows.
        $userids = [];
        foreach ($reviews as $r) {
            if (!empty($r->userid)) {
                $userids[] = (int) $r->userid;
            }
        }
        $usersbyid = [];
        if ($userids) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $usersbyid = $DB->get_records_select('user', "id $insql AND deleted = 0", $inparams,
                '', 'id, username, suspended');
        }

        // Users matched by NID for pairs without a link.
        $nids = [];
        foreach ($pairs as $p) {
            $nid = trim((string) ($p['nid'] ?? ''));
            if ($nid !== '') {
                $nids[] = $nid;
            }
        }
        $usersbynid = [];
        if ($nids) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_values(array_unique($nids)), SQL_PARAMS_NAMED, 'nid');
            foreach ($DB->get_records_select('user', "idnumber $insql AND deleted = 0", $inparams,
                    '', 'id, username, auth, suspended, idnumber') as $u) {
                $usersbynid[$u->idnumber][] = $u;
            }
        }

        // Review links held by NID-matched candidates, to detect a candidate
        // already belonging to a different applicant (same rule as provision()).
        $candidateids = [];
        foreach ($usersbynid as $list) {
            if (count($list) === 1) {
                $candidateids[] = (int) $list[0]->id;
            }
        }
        $linksbyuser = [];
        if ($candidateids) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_values(array_unique($candidateids)), SQL_PARAMS_NAMED, 'cand');
            foreach ($DB->get_records_select('elby_rise_reviews', "userid $insql", $inparams,
                    '', 'id, userid, campaignid, applicantid') as $link) {
                $linksbyuser[$link->userid][] = $link->campaignid . '|' . $link->applicantid;
            }
        }

        foreach ($pairs as $p) {
            $applicantid = (string) $p['applicantid'];
            $nid = trim((string) ($p['nid'] ?? ''));
            $review = $reviews[$applicantid] ?? null;

            $user = null;
            $linked = false;
            $action = (string) ($review->provisioningaction ?? '');
            if ($review && !empty($review->userid) && isset($usersbyid[$review->userid])) {
                $user = $usersbyid[$review->userid];
                $linked = true;
            } else if ($nid !== '' && count($usersbynid[$nid] ?? []) === 1) {
                // Mirror provision()'s single-match rules: a candidate already
                // linked to a different applicant, or a non-RISE-shaped account
                // holding the NID, is a conflict — not "this learner's account".
                $candidate = $usersbynid[$nid][0];
                $links = $linksbyuser[$candidate->id] ?? [];
                $linkedelsewhere = !empty(array_diff($links, [$campaignid . '|' . $applicantid]));
                if ($linkedelsewhere || !self::is_linkable($candidate)) {
                    $action = self::ACTION_DUPLICATE_NID;
                } else {
                    $user = $candidate;
                }
            } else if ($nid !== '' && count($usersbynid[$nid] ?? []) > 1) {
                // Multiple accounts share this NID: the reviewer must see the
                // blocked state up front, not a create button that will fail.
                $action = self::ACTION_DUPLICATE_NID;
            }

            $out[$applicantid] = [
                'hasaccount' => $user !== null,
                'userid' => $user !== null ? (int) $user->id : 0,
                'username' => $user !== null ? $user->username : '',
                'linked' => $linked,
                'suspended' => $user !== null && !empty($user->suspended),
                'provisioningaction' => $action !== '' ? $action : 'none',
                'correctionstatus' => (string) (($review->correctionstatus ?? '') !== ''
                    ? $review->correctionstatus : 'none'),
                'risesync' => (string) ($review->risesyncstatus ?? ''),
                'profileurl' => $user !== null
                    ? (new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false)
                    : '',
                'correction' => ($review && ($review->correctionstatus ?? '') === 'resubmitted')
                    ? $this->latest_correction($review) : null,
            ];
        }

        return $out;
    }

    /**
     * The latest correction submitted for a review, with pluginfile URLs for the
     * uploaded evidence (viewable by reviewers via the pluginfile access rules).
     *
     * @param \stdClass $review Review row.
     * @return array|null Correction payload for the frontend.
     */
    private function latest_correction(\stdClass $review): ?array {
        global $DB;

        $corrections = $DB->get_records('elby_rise_corrections', [
            'campaignid' => $review->campaignid,
            'applicantid' => $review->applicantid,
        ], 'id DESC', '*', 0, 1);
        $correction = reset($corrections);
        if (!$correction) {
            return null;
        }

        $fs = get_file_storage();
        $context = \context_system::instance();
        $files = [];
        foreach (['rise_idcard', 'rise_nesaresult'] as $filearea) {
            $areafiles = $fs->get_area_files($context->id, 'local_elby_dashboard', $filearea,
                $correction->id, 'itemid', false);
            foreach ($areafiles as $file) {
                $files[$filearea] = \moodle_url::make_pluginfile_url($context->id, 'local_elby_dashboard',
                    $filearea, $correction->id, '/', $file->get_filename())->out(false);
                break;
            }
        }

        return [
            'id' => (int) $correction->id,
            'firstname' => $correction->firstname,
            'lastname' => $correction->lastname,
            'nid' => (string) ($correction->nid ?? ''),
            'note' => (string) ($correction->note ?? ''),
            'risesynced' => (int) ($correction->risesynced ?? 0),
            'timecreated' => (int) $correction->timecreated,
            'idcardurl' => $files['rise_idcard'] ?? '',
            'nesaresulturl' => $files['rise_nesaresult'] ?? '',
        ];
    }
}
