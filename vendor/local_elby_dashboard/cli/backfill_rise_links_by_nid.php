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
 * Backfill RISE review -> Moodle user links for accounts that already match by NID.
 *
 * This is intentionally narrower than \local_elby_dashboard\task\ensure_rise_users:
 * it never calls the RISE API, never creates Moodle accounts, and never sends SMS.
 * It only writes elby_rise_reviews.userid when an approved, unlinked review has a
 * valid NID and exactly one safe, RISE-shaped Moodle account already has that NID.
 *
 * Dry-run by default — nothing is changed without --execute.
 *
 * Usage:
 *   php backfill_rise_links_by_nid.php
 *   php backfill_rise_links_by_nid.php --execute
 *   php backfill_rise_links_by_nid.php --campaign=X --limit=100 --execute
 *   php backfill_rise_links_by_nid.php --verbose
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
    'verbose' => false,
    'help' => false,
], [
    'e' => 'execute',
    'v' => 'verbose',
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Backfill approved RISE review links from existing Moodle accounts matched by National ID.

This script is link-only: no account creation, no SMS, no RISE API calls.
It updates elby_rise_reviews.userid only when there is exactly one safe
RISE-shaped Moodle account with user.idnumber = review.nid.

Options:
  --execute, -e        Actually write links (default: dry-run preview).
  --campaign=ID        Only process reviews of this RISE campaign.
  --limit=N            Link at most N reviews (0 = no cap).
  --verbose, -v        Also print skipped rows and reasons.
  --help, -h           This help.
");
    exit(0);
}

if (!preg_match('/^\d+$/', (string) $options['limit'])) {
    cli_error('--limit must be a non-negative integer (0 = no cap).');
}
$limit = (int) $options['limit'];
$campaign = trim((string) $options['campaign']);
$execute = (bool) $options['execute'];
$verbose = (bool) $options['verbose'];

$where = [
    "nesastatus = 'approved'",
    '(userid IS NULL OR userid = 0)',
    $DB->sql_like('applicantid', ':anonpat', true, true, true),
];
$params = ['anonpat' => 'anon%'];
if ($campaign !== '') {
    $where[] = 'campaignid = :campaignid';
    $params['campaignid'] = $campaign;
}

$fields = 'id, campaignid, applicantid, fullname, nid, userid, nidstatus, provisioningaction, '
    . 'userprovisionedat, risesyncstatus, riselinkeduserid, timemodified';
$reviews = $DB->get_records_select('elby_rise_reviews', implode(' AND ', $where), $params,
    'id ASC', $fields);

$stats = [
    'scanned' => 0,
    'candidates' => 0,
    'linked' => 0,
    'skipped_invalid_nid' => 0,
    'skipped_no_user' => 0,
    'skipped_multiple_users' => 0,
    'skipped_not_linkable' => 0,
    'skipped_linked_elsewhere' => 0,
    'skipped_limit' => 0,
];

$claimedusers = [];
$rows = [];
$now = time();

$printheader = function (): void {
    cli_writeln(sprintf('  %-8s %-26s %-26s %-16s %-10s %-10s %s',
        'REVIEW', 'CAMPAIGN', 'APPLICANT', 'NID', 'USERID', 'USERNAME', 'RESULT'));
};

$printrow = function (array $row): void {
    cli_writeln(sprintf('  %-8s %-26s %-26s %-16s %-10s %-10s %s',
        $row['reviewid'], $row['campaignid'], $row['applicantid'], $row['nid'],
        $row['userid'], $row['username'], $row['result']));
};

foreach ($reviews as $review) {
    $stats['scanned']++;
    $nid = trim((string) ($review->nid ?? ''));

    $skip = function (string $key, string $reason) use (&$stats, &$rows, $review, $nid, $verbose): void {
        $stats[$key]++;
        if ($verbose) {
            $rows[] = [
                'reviewid' => (string) $review->id,
                'campaignid' => (string) $review->campaignid,
                'applicantid' => (string) $review->applicantid,
                'nid' => $nid !== '' ? $nid : '-',
                'userid' => '-',
                'username' => '-',
                'result' => 'skip: ' . $reason,
            ];
        }
    };

    if (!\local_elby_dashboard\rise_user_service::is_valid_nid($nid)) {
        $skip('skipped_invalid_nid', 'invalid NID');
        continue;
    }

    $users = $DB->get_records('user', ['idnumber' => $nid, 'deleted' => 0], '',
        'id, username, auth, suspended, idnumber');
    if (count($users) === 0) {
        $skip('skipped_no_user', 'no Moodle user with this NID');
        continue;
    }
    if (count($users) > 1) {
        $skip('skipped_multiple_users', 'multiple Moodle users with this NID');
        continue;
    }

    /** @var stdClass $user */
    $user = reset($users);
    if (!\local_elby_dashboard\rise_user_service::is_linkable($user)) {
        $skip('skipped_not_linkable', 'matching user is not a RISE learner account');
        continue;
    }

    $linkkey = (string) $review->campaignid . '|' . (string) $review->applicantid;
    $existinglinks = $DB->get_records('elby_rise_reviews', ['userid' => $user->id], '',
        'id, campaignid, applicantid');
    $linkedelsewhere = false;
    foreach ($existinglinks as $link) {
        if ((string) $link->campaignid . '|' . (string) $link->applicantid !== $linkkey) {
            $linkedelsewhere = true;
            break;
        }
    }
    if (isset($claimedusers[(int) $user->id]) && $claimedusers[(int) $user->id] !== $linkkey) {
        $linkedelsewhere = true;
    }
    if ($linkedelsewhere) {
        $skip('skipped_linked_elsewhere', 'matching user is linked to another RISE review');
        continue;
    }

    if ($limit > 0 && $stats['candidates'] >= $limit) {
        $stats['skipped_limit']++;
        continue;
    }

    $stats['candidates']++;
    $claimedusers[(int) $user->id] = $linkkey;
    $result = $execute ? 'linked' : 'would link';

    if ($execute) {
        $update = (object) [
            'id' => $review->id,
            'userid' => (int) $user->id,
            'timemodified' => $now,
        ];
        if (empty($review->userprovisionedat)) {
            $update->userprovisionedat = $now;
        }
        // If the only prior blocker was an unresolved/missing provisioning state,
        // mark a verified approved NID match as OK so profile badges can display
        // the verified state. Preserve learner-action states such as details_mismatch.
        if (($review->nidstatus ?? '') === 'verified'
                && (empty($review->provisioningaction)
                    || $review->provisioningaction === \local_elby_dashboard\rise_user_service::ACTION_DUPLICATE_NID)) {
            $update->provisioningaction = \local_elby_dashboard\rise_user_service::ACTION_OK;
        }
        $DB->update_record('elby_rise_reviews', $update);
        $stats['linked']++;
    }

    $rows[] = [
        'reviewid' => (string) $review->id,
        'campaignid' => (string) $review->campaignid,
        'applicantid' => (string) $review->applicantid,
        'nid' => $nid,
        'userid' => (string) $user->id,
        'username' => (string) $user->username,
        'result' => $result,
    ];
}

cli_writeln(($execute ? 'Executing' : 'Dry run for') . ' RISE NID link backfill'
    . ($campaign !== '' ? " for campaign {$campaign}" : '')
    . ($limit ? " (limit {$limit})" : '') . '.');
cli_writeln('');

if ($rows) {
    $printheader();
    foreach ($rows as $row) {
        $printrow($row);
    }
    cli_writeln('');
}

cli_writeln('Summary:');
foreach ($stats as $key => $value) {
    cli_writeln(sprintf('  %-28s %d', $key . ':', $value));
}

if (!$execute) {
    cli_writeln('');
    cli_writeln('Dry run — nothing changed. Re-run with --execute to write the links.');
    cli_writeln('This link-only script does not create accounts, send SMS, or call the RISE API.');
}

exit(0);
