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
 * Bootstrap this school from central's snapshot manifest (ELMS Sync v2 step 6, §4.4).
 *
 * Run once when onboarding a fresh school or after a re-incarnation: it loads the full
 * head content state and sets the pull cursor to the pinned head seq, so the ordinary
 * pull_stream then resumes incrementally. Idempotent — safe to re-run.
 *
 * Usage:  php bootstrap_snapshot.php
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options) = cli_get_params(['help' => false], []);
if (!empty($options['help'])) {
    cli_writeln("Load this school's full content state from central's snapshot manifest\n"
        . "and set the pull cursor to the pinned head seq.\n");
    exit(0);
}

$result = \local_syncqueue\snapshot_bootstrap::run();

if ($result['status'] === 'skipped') {
    cli_writeln('bootstrap_snapshot: skipped (not a pull_v2 school in school mode)');
    exit(0);
}
cli_writeln('bootstrap_snapshot: ' . $result['entries'] . ' manifest entries; '
    . $result['applied'] . ' applied, ' . $result['deferred'] . ' deferred; cursor set to head seq '
    . $result['headseq']);
exit(0);
