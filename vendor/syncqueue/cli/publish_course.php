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
 * Publish a fresh content version of a course (ELMS Sync v2 step 7, §7, central).
 *
 * The explicit human publish act the change-scan flags courses for: builds a
 * fresh .mbz of the course's current content, stamps cm/grade-item UUIDs, bumps
 * the content version and appends the outbox rows. Schools re-restore alongside
 * and migrate grades/completion by cm-UUID.
 *
 * Usage:
 *   php publish_course.php --courseid=123 [--force]
 *   php publish_course.php --all-stale            (publish every drifted course)
 *   php publish_course.php --list                 (list drifted courses, no change)
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\content_publisher;

list($options) = cli_get_params(
    ['help' => false, 'courseid' => 0, 'force' => false, 'all-stale' => false, 'list' => false],
    ['h' => 'help']);

if (!empty($options['help'])
        || (empty($options['courseid']) && empty($options['all-stale']) && empty($options['list']))) {
    cli_writeln("Publish a fresh content version of a course (central).\n\n"
        . "  --courseid=N   publish course N (no-op if content unchanged unless --force)\n"
        . "  --force        publish even when content has not drifted\n"
        . "  --all-stale    publish every course flagged as drifted by the change-scan\n"
        . "  --list         list drifted courses without publishing\n");
    exit(0);
}

if (get_config('local_syncqueue', 'mode') !== 'central') {
    cli_error('publish_course: this is not a central instance.');
}

if (!empty($options['list'])) {
    $stale = content_publisher::stale_courses();
    if (!$stale) {
        cli_writeln('No drifted courses — all published courses are current.');
        exit(0);
    }
    cli_writeln(count($stale) . ' drifted course(s):');
    foreach ($stale as $s) {
        $drift = $s['driftseconds'] < 0 ? 'never versioned' : ($s['driftseconds'] . 's behind');
        cli_writeln("  course {$s['courseid']} (content v{$s['contentversion']}, {$drift})");
    }
    exit(0);
}

$targets = [];
if (!empty($options['all-stale'])) {
    foreach (content_publisher::stale_courses() as $s) {
        $targets[] = (int) $s['courseid'];
    }
    if (!$targets) {
        cli_writeln('No drifted courses to publish.');
        exit(0);
    }
} else {
    $targets[] = (int) $options['courseid'];
}

$force = !empty($options['force']);
$exit = 0;
foreach ($targets as $courseid) {
    $r = content_publisher::publish_course_version($courseid, $force);
    switch ($r['status']) {
        case 'published':
            cli_writeln("course {$courseid}: published content v{$r['contentversion']} "
                . "({$r['filename']}; stamped {$r['stampedcms']} cms / {$r['stampedgis']} grade items; "
                . "{$r['sequenced']} rows sequenced)");
            break;
        case 'unchanged':
            cli_writeln("course {$courseid}: unchanged (content v{$r['contentversion']}); use --force to republish");
            break;
        default:
            cli_writeln("course {$courseid}: {$r['status']} — " . ($r['reason'] ?? ''));
            $exit = 1;
    }
}
exit($exit);
