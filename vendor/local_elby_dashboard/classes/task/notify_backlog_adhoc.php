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
 * Ad-hoc task: send one queued backlog action-needed notification.
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

use local_elby_dashboard\rise_user_service;

/**
 * Sends the action-needed SMS/bell for a single review queued by
 * cli/queue_backlog_notifications.php (reviews decided before the
 * notification feature existed, so the learner was never told).
 *
 * Eligibility is re-checked at execution time UNDER the per-applicant lock —
 * the decision may have changed, a reviewer re-save may already have notified
 * the learner, or the learner may have been privacy-erased between queueing
 * and cron picking the task up. Failures are swallowed (mtrace only) so
 * core's ad-hoc retry never becomes a second retry owner: an undelivered
 * notification leaves lastnotifiedhash empty with the attempt logged, which
 * is exactly the state the nightly ensure_rise_users retry pass owns.
 */
class notify_backlog_adhoc extends \core\task\adhoc_task {

    /**
     * Send the notification for the queued review.
     */
    public function execute(): void {
        global $DB;

        $reviewid = (int) ($this->get_custom_data()->reviewid ?? 0);
        $ids = $DB->get_record('elby_rise_reviews', ['id' => $reviewid], 'id, campaignid, applicantid');
        if (!$ids) {
            mtrace("RISE backlog notify: review {$reviewid} no longer exists — skipping.");
            return;
        }

        $service = new rise_user_service();
        try {
            // Same lock as provisioning and review saves: the dedupe decision and
            // the send cannot interleave with a concurrent reviewer re-save.
            $service->with_applicant_lock($ids->campaignid, $ids->applicantid,
                function () use ($DB, $reviewid, $service) {
                    $review = $DB->get_record('elby_rise_reviews', ['id' => $reviewid]);
                    if (!$review
                            || !in_array($review->nesastatus, ['action_requested', 'rejected'], true)
                            || !empty($review->lastnotifiedhash)
                            // Privacy-erased learners must never be contacted or have
                            // log/token rows re-created (mirrors the CLI's queue filter).
                            || $review->applicantid === ''
                            || strpos($review->applicantid, 'anon') === 0) {
                        mtrace("RISE backlog notify: review {$reviewid} no longer eligible — skipping.");
                        return;
                    }
                    $service->notify_learner($review);
                    mtrace("RISE backlog notify: processed review {$reviewid} ({$review->applicantid}).");
                });
        } catch (\Throwable $e) {
            // Never rethrow: core would retry this task up to 12 times, which could
            // re-send an SMS whose bookkeeping write failed. The nightly retry pass
            // (or a CLI re-run) is the single retry owner.
            mtrace("RISE backlog notify: review {$reviewid} failed — " . $e->getMessage());
        }
    }
}
