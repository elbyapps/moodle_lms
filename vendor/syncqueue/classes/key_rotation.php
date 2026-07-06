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

/**
 * School-side API key rotation driver (ELMS Sync v2 step 7, §4.6).
 *
 * Generates a fresh key, has central adopt it (the old key stays valid through central's
 * grace window), then adopts it locally. Ack-loss safe: the pending key is persisted
 * BEFORE the call and reused on retry, so a lost response never desyncs the school from
 * central — a retry with the still-valid old key re-confirms the SAME new key idempotently.
 * The local key is only swapped once central confirms it holds the new key as current.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_rotation {

    /** @var string Config holding the generated-but-not-yet-adopted key. */
    const PENDING = 'apikey_pending';

    /**
     * Rotate this school's API key with central and adopt it locally on confirmation.
     *
     * @param sync_client|null $client Injected client (for testing); a real one by default.
     * @return array{status:string, prev_expires?:int, reason?:string}
     */
    public static function rotate_local(?sync_client $client = null): array {
        if (get_config('local_syncqueue', 'mode') !== 'school') {
            return ['status' => 'skipped', 'reason' => 'not a school instance'];
        }

        // Rotating the syncqueue apikey does NOT touch the Moodle WS token. If this
        // school authenticates the WS transport with its apikey (no distinct wstoken
        // configured — sync_client falls back to apikey), rotating would change the
        // apikey while central's external_tokens row keeps the old value, breaking every
        // WS call BEFORE verify_apikey even runs. Refuse until a separate wstoken exists.
        $wstoken = get_config('local_syncqueue', 'wstoken');
        $currentkey = get_config('local_syncqueue', 'apikey');
        if ($wstoken === false || $wstoken === '' || $wstoken === $currentkey) {
            return ['status' => 'blocked',
                'reason' => 'configure a distinct Moodle wstoken before rotating the apikey '
                    . '(this deployment uses the apikey as the WS token, so rotation would break transport auth)'];
        }

        // Reuse a pending key across retries (ack-loss safe); generate on first attempt.
        $pending = get_config('local_syncqueue', self::PENDING);
        if ($pending === false || $pending === '') {
            $pending = school_manager::generate_key();
            set_config(self::PENDING, $pending, 'local_syncqueue');
        }

        try {
            $resp = ($client ?? new sync_client())->rotate_key($pending);
        } catch (\Throwable $e) {
            // Transport failure: keep the pending key for the next attempt (the old key
            // is still our live credential, so nothing is broken).
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }

        if (!empty($resp['current'])) {
            // Central holds our new key as the current key (freshly rotated or already):
            // adopt it locally and clear the pending marker.
            set_config('apikey', $pending, 'local_syncqueue');
            unset_config(self::PENDING, 'local_syncqueue');
            return ['status' => 'rotated', 'prev_expires' => (int) ($resp['prev_expires'] ?? 0)];
        }
        return ['status' => 'failed', 'reason' => 'central did not confirm the new key'];
    }
}
