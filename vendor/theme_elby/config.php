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
 * Theme configuration.
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

$THEME->name = 'elby';

// Extend Boost theme (Bootstrap 5.3).
$THEME->parents = ['boost'];

// CSS sheets loaded after SCSS compilation.
$THEME->sheets = ['navigation'];
$THEME->editor_sheets = [];

// Dock is deprecated in Moodle 5.0.
$THEME->enable_dock = false;

// No legacy YUI CSS modules.
$THEME->yuicssmodules = [];

// Enable renderer factory for class-based overrides.
$THEME->rendererfactory = 'theme_overridden_renderer_factory';

// SCSS injection callbacks.
// Use parent theme's preset for proper Bootstrap compilation.
$THEME->prescsscallback = 'theme_elby_get_pre_scss';
$THEME->extrascsscallback = 'theme_elby_get_extra_scss';

// SCSS import paths - tells compiler where to find @imported files.
// Include parent boost theme's scss directory for Bootstrap imports.
global $CFG;
$THEME->scssimportpaths = [
    __DIR__ . '/scss',
    __DIR__ . '/scss/preset',
    $CFG->dirroot . '/theme/boost/scss',
    $CFG->dirroot . '/theme/boost/scss/bootstrap',
];

// Icon system: FontAwesome 6.
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;

// Feature flags.
$THEME->haseditswitch = true;
$THEME->usescourseindex = true;
$THEME->hidefromselector = false;

// Layout definitions.
$THEME->layouts = [
    'base' => [
        'file' => 'drawers.php',
        'regions' => [],
    ],
    'standard' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'course' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options' => ['langmenu' => true],
    ],
    'coursecategory' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'incourse' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'frontpage' => [
        'file' => 'frontpage.php',
        'regions' => [],
        'options' => ['nonavbar' => true, 'nofooter' => true, 'langmenu' => true],
    ],
    'mydashboard' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options' => ['nonavbar' => false, 'langmenu' => true],
    ],
    'mypublic' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'login' => [
        'file' => 'login.php',
        'regions' => [],
        'options' => ['langmenu' => true, 'nonavbar' => true, 'nofooter' => true],
    ],
    'signup' => [
        'file' => 'signup.php',
        'regions' => [],
        'options' => ['langmenu' => true, 'nonavbar' => true, 'nofooter' => true],
    ],
    'admin' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'popup' => [
        'file' => 'columns1.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => true],
    ],
    'maintenance' => [
        'file' => 'maintenance.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => true],
    ],
    'embedded' => [
        'file' => 'columns1.php',
        'regions' => [],
        'options' => ['nofooter' => true, 'nonavbar' => true],
    ],
    'report' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
    ],
    'secure' => [
        'file' => 'drawers.php',
        'regions' => ['side-pre'],
        'defaultregion' => 'side-pre',
        'options' => ['nofooter' => true],
    ],
];
