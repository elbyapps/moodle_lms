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
 * Rotate this school's API key with central (ELMS Sync v2 step 7, §4.6).
 *
 * Safe to run while central is briefly unreachable and safe to re-run: the old key
 * stays valid through central's grace window, and a retried rotation re-confirms the
 * same pending key idempotently.
 *
 * Usage:  php rotate_key.php
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
    cli_writeln("Rotate this school's API key with central (dual-validity; the old key\n"
        . "stays valid through central's grace window). Idempotent / retry-safe.\n");
    exit(0);
}

$result = \local_syncqueue\key_rotation::rotate_local();

switch ($result['status']) {
    case 'rotated':
        $when = !empty($result['prev_expires']) ? userdate($result['prev_expires']) : 'the grace window';
        cli_writeln("rotate_key: rotated; the previous key remains valid until {$when}.");
        exit(0);
    case 'skipped':
        cli_writeln('rotate_key: skipped — ' . ($result['reason'] ?? ''));
        exit(0);
    case 'blocked':
        cli_error('rotate_key: blocked — ' . ($result['reason'] ?? '') . ' (the current key is unchanged).');
    default:
        cli_error('rotate_key: failed — ' . ($result['reason'] ?? 'unknown') . ' (the current key is unchanged).');
}
