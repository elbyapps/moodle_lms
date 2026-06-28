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
 * Version information for local_reblibrary.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_reblibrary';
$plugin->version = 2026061500;           // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires = 2025041400;          // Requires Moodle 5.0 (Build: 20250414).
$plugin->maturity = MATURITY_STABLE;     // Code maturity level.
$plugin->release = '1.7.1';              // Raise PDF upload cap to 200MB (code-only; no DB migration).
