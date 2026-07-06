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
 * School-side identity-map back-stamp (ELMS Sync v2 step 4 preflight; school only).
 *
 * Applies the cm / grade-item identity maps received from central to the local
 * course copies by strict structural match. DRY RUN by default: prints a
 * per-course match report and stamps nothing. With --execute it back-stamps the
 * local idnumbers of unambiguously matched, currently-empty items. ZERO-GUESS:
 * any ambiguous course is flagged for a stamped-version republish and stamped
 * nothing.
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
    'courseid' => '',
], [
    'h' => 'help',
]);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo <<<EOF
Back-stamp local cm / grade-item idnumbers from received identity maps (school only).

Matches each received central identity map to the local course copy by
(section ordinal, module type, ordinal within section) and stamps the local
idnumber only on an unambiguous 1:1 match where the local idnumber is empty
(or already equal). Idempotent.

By default this is a DRY RUN: it prints the per-course match report and stamps
nothing. With --execute it writes the idnumbers of matched, empty items.

Zero-guess: a course with any ambiguity (module-count mismatch, a local item
already carrying a different idnumber, a missing candidate) is FLAGGED for a
stamped-version republish and stamped nothing. Exit code is 1 when any course
was flagged, 0 otherwise.

Options:
  -h, --help        Show this help
      --execute     Write matched idnumbers (default: dry run)
      --courseid=N  Only the map for this central course (default: all)

Examples:
  php apply_identity_map.php
  php apply_identity_map.php --execute
  php apply_identity_map.php --courseid=42 --execute

EOF;
    exit(0);
}

if (get_config('local_syncqueue', 'mode') !== 'school') {
    cli_error("apply_identity_map.php runs on school instances only (local_syncqueue/mode is not 'school').");
}

if (!\local_syncqueue\identity_map_applier::table_ready()) {
    cli_error('The identity-map store is missing; run the plugin upgrade first.');
}

$execute = (bool) $options['execute'];
$courseidfilter = ($options['courseid'] !== '') ? (int) $options['courseid'] : null;

$reports = \local_syncqueue\identity_map_applier::apply_stored($execute, $courseidfilter);

cli_heading($execute ? 'Identity-map back-stamp — EXECUTE' : 'Identity-map back-stamp — DRY RUN (no writes)');

$flagged = 0;
$stampedcms = 0;
$stampedgis = 0;
foreach ($reports as $report) {
    $where = 'central course ' . $report->centralcourseid
        . ' -> local ' . ($report->localcourseid ?: 'none');
    if ($report->status === 'flagged') {
        $flagged++;
        cli_writeln("FLAGGED {$where}: needs a stamped-version republish");
        foreach ($report->ambiguities as $ambiguity) {
            cli_writeln("  - {$ambiguity}");
        }
        continue;
    }
    if ($report->status === 'nocourse') {
        cli_writeln("SKIP {$where}: course not present locally");
        continue;
    }
    if ($report->status === 'pending') {
        cli_writeln("PENDING {$where}: would stamp {$report->wouldcms} cm(s), {$report->wouldgis} grade item(s)");
        continue;
    }
    // applied.
    $stampedcms += $report->stampedcms;
    $stampedgis += $report->stampedgis;
    if ($report->stampedcms || $report->stampedgis) {
        cli_writeln("APPLIED {$where}: stamped {$report->stampedcms} cm(s), {$report->stampedgis} grade item(s)");
    } else {
        cli_writeln("APPLIED {$where}: already fully stamped (no-op)");
    }
}

cli_writeln('');
cli_writeln('CHECK apply_summary: OK ' . count($reports) . ' map(s), '
    . ($execute ? "stamped {$stampedcms} cm(s) and {$stampedgis} grade item(s), " : '')
    . "{$flagged} flagged.");

if (!$execute && $reports) {
    cli_writeln('Dry run only — re-run with --execute to back-stamp matched items.');
}
if ($flagged > 0) {
    cli_writeln('Flagged courses need a stamped-version republish before their facts can key on UUIDs; exiting 1.');
    exit(1);
}
exit(0);
