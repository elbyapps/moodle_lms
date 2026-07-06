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
 * Central-side cm / grade-item UUID stamping + identity-map publication
 * (ELMS Sync v2 step 4 preflight; central instances only).
 *
 * Stamps every unstamped course_module and leaf grade item of the national
 * courses with a stable UUID idnumber (idempotent, never overwriting a
 * non-empty idnumber) and, on --execute, publishes each course's identity map
 * on the downstream channel so already-distributed schools can back-stamp the
 * same UUIDs by structural match.
 *
 * Scope — "national courses" is every non-site course on central. Central is
 * the national LMS and holds the national course set by architecture, and this
 * is the whole population that needs stamping: the primary target is courses
 * distributed BEFORE stamping existed, which carry no v2 publication signal to
 * filter on, so a narrower predicate would miss exactly the courses the back-
 * stamp is for. Over-inclusion is inert: each identity map is published on its
 * own course content partition, which only schools that hold that course
 * subscribe to, so a map for a local/administrative/test course central never
 * distributed is never pulled by any school. Use --courseid to stamp and
 * publish a single course when a full pass is not wanted.
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
Stamp cm / grade-item UUID idnumbers and publish identity maps (central only).

For each national course (every non-site course, or a single --courseid), stamps
every unstamped course module and leaf grade item ('mod' + 'manual') with a
stable UUID idnumber. Idempotent: a non-empty idnumber is never overwritten, so
re-runs are no-ops on already-stamped items. Category and course TOTAL grade
items are never stamped (totals are never synced).

"National" is defined as all non-site courses: central holds the national set by
architecture, and courses distributed before stamping existed carry no publish
signal to filter on. A published map rides its course's content partition, so a
map for a course central never distributed is never pulled by any school.

By default this is a DRY RUN: it prints the stamping plan and stamps nothing.
With --execute it stamps and publishes each course's identity map downstream.

Options:
  -h, --help        Show this help
      --execute     Write idnumbers and publish identity maps (default: dry run)
      --courseid=N  Only this course (default: every non-site course)

Examples:
  php stamp_items.php
  php stamp_items.php --execute
  php stamp_items.php --courseid=42 --execute

EOF;
    exit(0);
}

if (get_config('local_syncqueue', 'mode') !== 'central') {
    cli_error("stamp_items.php runs on central instances only (local_syncqueue/mode is not 'central').");
}

$execute = (bool) $options['execute'];
$courseidfilter = ($options['courseid'] !== '') ? (int) $options['courseid'] : null;

if ($courseidfilter !== null) {
    if (!$DB->record_exists('course', ['id' => $courseidfilter]) || $courseidfilter == SITEID) {
        cli_error("No such national course: {$courseidfilter}");
    }
    $courseids = [$courseidfilter];
} else {
    $courseids = array_keys($DB->get_records_select('course', 'id <> :siteid',
        ['siteid' => SITEID], 'id ASC', 'id'));
}

cli_heading($execute ? 'Item stamping — EXECUTE' : 'Item stamping — DRY RUN (no writes)');

$totalcms = 0;
$totalgis = 0;
$published = 0;
foreach ($courseids as $courseid) {
    $report = \local_syncqueue\item_identity::stamp_course($courseid, $execute);
    $totalcms += $report->stampedcms;
    $totalgis += $report->stampedgis;

    $verb = $execute ? 'stamped' : 'would stamp';
    cli_writeln("course {$courseid}: {$verb} {$report->stampedcms} cm(s), {$report->stampedgis} grade item(s)"
        . ' (' . count($report->cms) . ' cms, ' . count($report->items) . ' items total)');

    if ($execute) {
        $outboxid = \local_syncqueue\item_identity::publish_map($courseid);
        $published++;
        cli_writeln("  published identity_map (outbox row {$outboxid})");
    }
}

cli_writeln('');
cli_writeln('CHECK stamp_summary: OK ' . ($execute ? 'stamped ' : 'would stamp ')
    . "{$totalcms} cm(s) and {$totalgis} grade item(s) across " . count($courseids) . ' course(s)'
    . ($execute ? ", published {$published} identity map(s)." : '.'));

if (!$execute) {
    cli_writeln('Dry run only — re-run with --execute to stamp and publish.');
}
exit(0);
