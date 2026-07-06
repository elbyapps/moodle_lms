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
 * CLI wrapper for the legacy-to-v2 adoption pass (school side).
 *
 * Runs before a school's v2 cutover: seeds local_syncqueue_applied from the
 * legacy idmap and fallback 'central_<id>' idnumbers so the first v2 pull
 * adopts legacy courses/categories instead of duplicating them.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'execute' => false,
], [
    'h' => 'help',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOF
Legacy adoption pass for ELMS Sync v2 cutover (school instances only).

Matches legacy idmap rows and fallback 'central_<id>' course idnumbers to
local records and seeds the v2 applied-state resolution map so the first v2
pull refreshes courses in place instead of duplicating them. Ambiguous or
corpse mappings are quarantined and reported, never guessed; a corpse claim
beaten by a single surviving claim is dropped and printed for audit.

By default this is a DRY RUN: it only prints the match report.

Options:
  -h, --help      Show this help message
      --execute   Write applied-state rows (default: dry run)

Exit code is 1 when any entity was quarantined, 0 otherwise.

Examples:
  php adopt_legacy.php
  php adopt_legacy.php --execute

EOF;
    echo $help;
    exit(0);
}

if (get_config('local_syncqueue', 'mode') !== 'school') {
    cli_error("adopt_legacy.php runs on school instances only (local_syncqueue/mode is not 'school').");
}

$execute = (bool) $options['execute'];
$report = (new \local_syncqueue\adoption())->adopt($execute);

cli_heading($execute ? 'Adoption pass — EXECUTE' : 'Adoption pass — DRY RUN (no writes)');

if ($report->adopted) {
    cli_writeln('');
    cli_writeln($execute ? 'Adopted:' : 'Would adopt:');
    foreach ($report->adopted as $entry) {
        cli_writeln("  {$entry->entitykey} -> local {$entry->entitytype} {$entry->localid} [{$entry->sources}]");
        foreach ($entry->droppedclaims as $dropped) {
            cli_writeln("    dropped corpse claim: {$dropped}");
        }
    }
}

if ($report->alreadyadopted) {
    cli_writeln('');
    cli_writeln('Already adopted:');
    foreach ($report->alreadyadopted as $entry) {
        cli_writeln("  {$entry->entitykey} -> local {$entry->entitytype} {$entry->localid}");
        foreach ($entry->droppedclaims as $dropped) {
            cli_writeln("    dropped corpse claim: {$dropped}");
        }
    }
}

if ($report->quarantined) {
    cli_writeln('');
    cli_writeln('QUARANTINED (not adopted — resolve manually):');
    foreach ($report->quarantined as $entry) {
        cli_writeln("  {$entry->entitykey}: {$entry->reason}");
    }
}

cli_writeln('');
cli_writeln('Summary: ' . ($execute ? 'adopted ' : 'would adopt ') . $report->counts->adopted .
    ', already adopted ' . $report->counts->alreadyadopted .
    ', quarantined ' . $report->counts->quarantined . '.');

if ($report->counts->quarantined > 0) {
    cli_writeln('Quarantined entities need manual resolution before cutover; exiting 1.');
    exit(1);
}
exit(0);
