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
 * Queue action-needed notifications for reviews decided BEFORE the
 * notification feature existed.
 *
 * Reviews saved as action_requested/rejected before this feature shipped
 * carry a reviewer comment the learner never received (there was no SMS,
 * bell or correction link back then), and the nightly task deliberately
 * refuses to mass-notify them as a deployment side effect. This script is
 * the explicit trigger: it queues one Moodle ad-hoc task per backlog review,
 * which cron then works through (each send goes through the normal deduped,
 * token-aware notify_learner() path).
 *
 * Dry-run by default — nothing is queued without --execute.
 *
 * Usage:
 *   php queue_backlog_notifications.php                    # preview
 *   php queue_backlog_notifications.php --execute          # queue the backlog
 *   php queue_backlog_notifications.php --campaign=X --limit=100 --execute
 *
 * @package    local_elby_dashboard
 * @copyright  2026 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'execute' => false,
    'campaign' => '',
    'limit' => 0,
    'help' => false,
], [
    'e' => 'execute',
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Queue action-needed SMS/bell notifications for RISE reviews decided before
the notification feature existed (action_requested/rejected with no
notification ever delivered).

Options:
  --execute, -e        Actually queue the ad-hoc tasks (default: dry-run preview).
  --campaign=ID        Only process reviews of this RISE campaign.
  --limit=N            Queue at most N reviews (0 = no cap).
  --help, -h           This help.
");
    exit(0);
}

// Strict validation: a typo like --limit=1oo must never silently mean
// "no cap" and queue the whole backlog.
if (!preg_match('/^\d+$/', (string) $options['limit'])) {
    cli_error('--limit must be a non-negative integer (0 = no cap).');
}
$limit = (int) $options['limit'];

// The backlog selection lives in the service, shared with the admin-panel trigger.
$service = new \local_elby_dashboard\rise_user_service();
$reviews = $service->backlog_reviews((string) $options['campaign'], $limit);

if (!$reviews) {
    cli_writeln('Backlog is empty — no reviews need queueing.');
    exit(0);
}

cli_writeln(count($reviews) . ' review(s) in the notification backlog'
    . ($options['campaign'] !== '' ? " for campaign {$options['campaign']}" : '')
    . ($limit ? " (capped at {$limit})" : '') . ':');
cli_writeln('');
cli_writeln(sprintf('  %-8s %-26s %-26s %-16s %s', 'REVIEW', 'CAMPAIGN', 'APPLICANT', 'DECISION', 'DECIDED'));
foreach ($reviews as $review) {
    cli_writeln(sprintf('  %-8d %-26s %-26s %-16s %s',
        $review->id, $review->campaignid, $review->applicantid, $review->nesastatus,
        userdate($review->timemodified, '%Y-%m-%d %H:%M')));
}
cli_writeln('');

if (!$options['execute']) {
    cli_writeln('Dry run — nothing queued. Re-run with --execute to queue these notifications.');
    cli_writeln('Each learner will receive ONE SMS (+ bell if they have an account) with the');
    cli_writeln('reviewer comment and a fresh correction link, on the next cron run.');
    exit(0);
}

$result = $service->queue_backlog($reviews);
$queued = $result['queued'];
$duplicates = $result['duplicates'];

cli_writeln("Queued {$queued} notification task(s)"
    . ($duplicates ? " ({$duplicates} already queued, skipped)" : '') . '.');
cli_writeln('Cron will deliver them (each send is deduped and token-aware); any transient');
cli_writeln('failures are retried by the nightly ensure_rise_users task.');
exit(0);
