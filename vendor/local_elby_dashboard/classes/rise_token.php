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
 * Single-use, time-limited tokens for RISE learner deep links.
 *
 * Backs the SMS set-password and correction-form links. Only the SHA-256 hash
 * of a token is stored; the raw value travels in the SMS URL and is looked up
 * by hash, so tokens are secure-random, single-use, time-limited and revocable
 * (delete the row).
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Mint, validate and consume RISE deep-link tokens (table elby_rise_tokens).
 */
class rise_token {

    /** @var string Token purpose: set the learner's first password. */
    public const PURPOSE_SETPASSWORD = 'setpassword';

    /** @var string Token purpose: open the identity-correction form. */
    public const PURPOSE_CORRECTION = 'correction';

    /** @var int Set-password token lifetime (24h). */
    public const TTL_SETPASSWORD = DAYSECS;

    /** @var int Correction token lifetime (72h). */
    public const TTL_CORRECTION = 3 * DAYSECS;

    /**
     * Mint a new token, revoking any previous unused tokens for the same
     * purpose + applicant so at most one link is live at a time.
     *
     * @param string $purpose One of the PURPOSE_* constants.
     * @param string $campaignid RISE campaign _id.
     * @param string $applicantid RISE applicant _id.
     * @param int|null $userid Moodle user id (required for setpassword tokens).
     * @param int|null $ttl Lifetime in seconds; defaults per purpose.
     * @return string The raw token (64 hex chars) — the only time it exists in clear.
     */
    public static function mint(string $purpose, string $campaignid, string $applicantid,
            ?int $userid = null, ?int $ttl = null): string {
        global $DB;

        if ($ttl === null) {
            $ttl = $purpose === self::PURPOSE_SETPASSWORD ? self::TTL_SETPASSWORD : self::TTL_CORRECTION;
        }

        // Revoke prior unused tokens for this purpose + applicant.
        $DB->delete_records_select('elby_rise_tokens',
            'purpose = :purpose AND campaignid = :campaignid AND applicantid = :applicantid AND usedat = 0',
            ['purpose' => $purpose, 'campaignid' => $campaignid, 'applicantid' => $applicantid]);

        $raw = bin2hex(random_bytes(32));
        $DB->insert_record('elby_rise_tokens', (object) [
            'purpose' => $purpose,
            'tokenhash' => hash('sha256', $raw),
            'campaignid' => $campaignid,
            'applicantid' => $applicantid,
            'userid' => $userid,
            'expires' => time() + $ttl,
            'usedat' => 0,
            'timecreated' => time(),
        ]);

        return $raw;
    }

    /**
     * Check a raw token, distinguishing why it isn't usable (for clear UX).
     *
     * @param string $raw Raw token from the URL.
     * @param string $purpose Expected purpose.
     * @return array{0: string, 1: \stdClass|null} ['ok'|'invalid'|'expired'|'used', record when ok].
     */
    public static function check(string $raw, string $purpose): array {
        global $DB;

        $raw = trim($raw);
        if (!preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            return ['invalid', null];
        }
        $record = $DB->get_record('elby_rise_tokens', [
            'tokenhash' => hash('sha256', strtolower($raw)),
            'purpose' => $purpose,
        ]);
        if (!$record) {
            return ['invalid', null];
        }
        if ((int) $record->usedat !== 0) {
            return ['used', null];
        }
        if ((int) $record->expires < time()) {
            return ['expired', null];
        }
        return ['ok', $record];
    }

    /**
     * Resolve a raw token to its record when it is valid (exists, right purpose,
     * not expired, not used).
     *
     * @param string $raw Raw token from the URL.
     * @param string $purpose Expected purpose.
     * @return \stdClass|null Token record, or null when invalid.
     */
    public static function validate(string $raw, string $purpose): ?\stdClass {
        [$status, $record] = self::check($raw, $purpose);
        return $status === 'ok' ? $record : null;
    }

    /**
     * Atomically consume a token (single-use): succeeds for exactly one caller.
     *
     * Serialised through the Lock API so concurrent POSTs carrying the same
     * token cannot both pass the used/expired check — call this BEFORE any
     * irreversible side effect (password change, correction insert, uploads).
     *
     * @param int $id Token record id.
     * @return bool True when this call consumed the token; false when it was
     *              already used, expired, missing, or the lock timed out.
     */
    public static function try_consume(int $id): bool {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_elby_dashboard_token');
        $lock = $lockfactory->get_lock('token' . $id, 5);
        if (!$lock) {
            return false;
        }
        try {
            $record = $DB->get_record('elby_rise_tokens', ['id' => $id]);
            if (!$record || (int) $record->usedat !== 0 || (int) $record->expires < time()) {
                return false;
            }
            $DB->set_field('elby_rise_tokens', 'usedat', time(), ['id' => $id]);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Mark a token as consumed (single-use). Prefer try_consume() and check the
     * result when the caller must know whether it won the consumption race.
     *
     * @param int $id Token record id.
     */
    public static function consume(int $id): void {
        self::try_consume($id);
    }

    /**
     * Whether an active (unexpired, unused) token exists for this purpose + applicant.
     *
     * Used by the notification dedupe: when no live correction link exists the
     * SMS must be re-sent with a fresh token even if the payload is unchanged.
     *
     * @param string $purpose One of the PURPOSE_* constants.
     * @param string $campaignid RISE campaign _id.
     * @param string $applicantid RISE applicant _id.
     * @return bool
     */
    public static function has_active(string $purpose, string $campaignid, string $applicantid): bool {
        global $DB;
        return $DB->record_exists_select('elby_rise_tokens',
            'purpose = :purpose AND campaignid = :campaignid AND applicantid = :applicantid
             AND usedat = 0 AND expires > :now',
            ['purpose' => $purpose, 'campaignid' => $campaignid, 'applicantid' => $applicantid, 'now' => time()]);
    }
}
