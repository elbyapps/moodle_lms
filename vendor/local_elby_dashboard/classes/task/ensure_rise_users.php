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
 * Backfill / link sweep for RISE learner accounts.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

use local_elby_dashboard\rise_user_service;

/**
 * Provision approved RISE learners that have no linked Moodle account yet
 * (mostly a linking sweep by user.idnumber = nid), including decisions saved
 * by reviewers without the manage capability, and retry pending/failed RISE
 * linkedUserId back-writes. Safely re-runnable: provision() is idempotent.
 */
class ensure_rise_users extends \core\task\scheduled_task {

    /** @var int Cap per category per run so a huge backlog can't blow the cron slot. */
    private const BATCH_LIMIT = 500;

    /**
     * Task name.
     */
    public function get_name(): string {
        return get_string('task_ensure_rise_users', 'local_elby_dashboard');
    }

    /**
     * Run the sweep.
     */
    public function execute(): void {
        global $DB;

        $service = new rise_user_service();
        $autoprovision = get_config('local_elby_dashboard', 'rise_autoprovision');
        $autoprovision = $autoprovision === false ? true : (bool) $autoprovision;

        // 1. Approved reviews without a linked user: link by NID, create when no match.
        //    duplicate_nid-blocked rows are retried too — the conflict may since be resolved.
        //    Honours the rise_autoprovision toggle: turning it off stops ALL automatic
        //    account creation, not just the approval hook.
        if ($autoprovision) {
            // Privacy-erased rows carry an opaque 'anon...' applicantid and must
            // never be provisioned (the RISE record link was deliberately broken).
            $pending = $DB->get_records_select('elby_rise_reviews',
                "nesastatus = 'approved' AND (userid IS NULL OR userid = 0) AND "
                    . $DB->sql_like('applicantid', ':anonpat', true, true, true),
                ['anonpat' => 'anon%'], 'id ASC', 'id, campaignid, applicantid', 0, self::BATCH_LIMIT);
            mtrace('RISE backfill: ' . count($pending) . ' approved review(s) without an account.');
            foreach ($pending as $review) {
                try {
                    $result = $service->provision($review->campaignid, $review->applicantid);
                    mtrace("  {$review->applicantid}: " . ($result['blocked'] ? 'blocked (' . $result['action'] . ')'
                        : ($result['created'] ? 'created ' : 'linked ') . $result['username']));
                } catch (\Throwable $e) {
                    mtrace("  {$review->applicantid}: failed — " . $e->getMessage());
                }
            }
        } else {
            mtrace('RISE backfill: auto-provisioning disabled — skipping the provisioning sweep.');
        }

        // 2. Retry RISE linkedUserId back-writes that are pending or errored. Sync-only:
        //    never re-runs the notification pipeline, so a stuck sync can't SMS anyone.
        $retries = $DB->get_records_select('elby_rise_reviews',
            "userid IS NOT NULL AND userid > 0 AND risesyncstatus IN ('pending', 'error')",
            [], 'id ASC', 'id, campaignid, applicantid', 0, self::BATCH_LIMIT);
        mtrace('RISE backfill: ' . count($retries) . ' pending/failed RISE sync(s) to retry.');
        foreach ($retries as $review) {
            try {
                $status = $service->retry_rise_sync($review->campaignid, $review->applicantid);
                mtrace("  {$review->applicantid}: risesync={$status}");
            } catch (\Throwable $e) {
                mtrace("  {$review->applicantid}: failed — " . $e->getMessage());
            }
        }

        // 3. Retry action-needed notifications that never went out (transient RISE or
        //    gateway failure left lastnotifiedhash empty). Guarded by an existing SMS-log
        //    attempt so historical pre-feature reviews are never mass-notified, and
        //    excluding privacy-erased (anonymized) rows, which must never be contacted.
        $unnotified = $DB->get_records_sql(
            "SELECT r.id, r.campaignid, r.applicantid
               FROM {elby_rise_reviews} r
              WHERE r.nesastatus IN ('action_requested', 'rejected')
                AND r.lastnotifiedhash IS NULL
                AND " . $DB->sql_like('r.applicantid', ':anonpat', true, true, true) . "
                AND EXISTS (SELECT 1
                              FROM {elby_rise_sms_log} l
                             WHERE l.campaignid = r.campaignid AND l.applicantid = r.applicantid)
           ORDER BY r.id ASC", ['anonpat' => 'anon%'], 0, self::BATCH_LIMIT);
        mtrace('RISE backfill: ' . count($unnotified) . ' undelivered learner notification(s) to retry.');
        foreach ($unnotified as $row) {
            try {
                // Fresh read + notify under the applicant lock, so the dedupe decision
                // can't race a concurrent reviewer save or backlog ad-hoc task.
                $service->with_applicant_lock($row->campaignid, $row->applicantid,
                    function () use ($DB, $row, $service) {
                        $review = $DB->get_record('elby_rise_reviews', ['id' => $row->id]);
                        if ($review && empty($review->lastnotifiedhash)
                                && in_array($review->nesastatus, ['action_requested', 'rejected'], true)) {
                            $service->notify_learner($review);
                        }
                    });
            } catch (\Throwable $e) {
                mtrace("  {$row->applicantid}: notify failed — " . $e->getMessage());
            }
        }

        // 4. Re-send welcome/set-password SMS for accounts we created whose welcome never
        //    went out and that have never been logged into. provision() is idempotent and
        //    performs the resend in its already-linked branch (gateway-configured only).
        if ($autoprovision) {
            $stranded = $DB->get_records_sql(
                "SELECT r.id, r.campaignid, r.applicantid
                   FROM {elby_rise_reviews} r
                   JOIN {user} u ON u.id = r.userid AND u.deleted = 0 AND u.firstaccess = 0
                  WHERE EXISTS (SELECT 1 FROM {elby_rise_sms_log} l
                                 WHERE l.campaignid = r.campaignid AND l.applicantid = r.applicantid
                                   AND l.purpose = 'welcome')
                    AND NOT EXISTS (SELECT 1 FROM {elby_rise_sms_log} l2
                                     WHERE l2.campaignid = r.campaignid AND l2.applicantid = r.applicantid
                                       AND l2.purpose = 'welcome' AND l2.status = 'sent')
               ORDER BY r.id ASC", [], 0, self::BATCH_LIMIT);
            mtrace('RISE backfill: ' . count($stranded) . ' account(s) with an undelivered welcome SMS.');
            foreach ($stranded as $review) {
                try {
                    $service->provision($review->campaignid, $review->applicantid);
                } catch (\Throwable $e) {
                    mtrace("  {$review->applicantid}: welcome retry failed — " . $e->getMessage());
                }
            }
        }

        // 5. Retention: purge long-expired tokens and old SMS log rows.
        $DB->delete_records_select('elby_rise_tokens', 'expires < :cutoff', ['cutoff' => time() - 30 * DAYSECS]);
        $DB->delete_records_select('elby_rise_sms_log', 'timecreated < :cutoff', ['cutoff' => time() - 365 * DAYSECS]);
    }
}
