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
 * CLI: backfill elby_course_meta (trade / level / school) for all courses.
 *
 * Usage: php local/elby_dashboard/cli/enrich_courses.php
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Locate config.php across both standard and public/ split layouts.
if (file_exists(__DIR__ . '/../../../config.php')) {
    require(__DIR__ . '/../../../config.php');
} else {
    require(__DIR__ . '/../../../../config.php');
}
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['help' => false], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    cli_writeln("Backfill course trade/level/school metadata into elby_course_meta.\n\nOptions:\n  -h, --help  Show this help.");
    exit(0);
}

$courseids = $DB->get_fieldset_select('course', 'id', 'id > 1', []);
$count = 0;
foreach ($courseids as $courseid) {
    try {
        \local_elby_dashboard\course_enricher::enrich_course((int) $courseid);
        $count++;
    } catch (\Throwable $e) {
        cli_problem('course ' . $courseid . ': ' . $e->getMessage());
    }
}
cli_writeln("Enriched {$count} course(s).");
