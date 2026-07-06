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
 * Install script for local_elby_dashboard.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Fresh-install setup for local_elby_dashboard.
 *
 * Mirrors upgrade step 2026070200: db/upgrade.php never runs on a fresh
 * install, and newly provisioned school instances must not keep the core
 * default (ENROL_EXT_REMOVED_UNENROL, in force while the setting is unset),
 * which fully unenrols and destroys grades when a student is moved out of
 * a cohort (e.g. year rollover or trade change).
 */
function xmldb_local_elby_dashboard_install() {
    global $CFG;

    require_once($CFG->libdir . '/enrollib.php');
    set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES, 'enrol_cohort');

    return true;
}
