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
 * Custom signup page layout - Split design (same as login).
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Build body attributes.
$bodyattributes = $OUTPUT->body_attributes(['login-page']);

// Get logo URL.
$logourlobj = $OUTPUT->get_logo_url();
$logourl = $logourlobj ? $logourlobj->out(false) : '';

// Get login background image from theme settings.
$loginimage = $PAGE->theme->setting_file_url('loginbackgroundimage', 'loginbackgroundimage');
if (empty($loginimage)) {
    $loginimage = '';
}

// Get gradient colors from theme settings.
$gradient1 = get_config('theme_elby', 'logingradient1') ?: '#8b5cf6';
$gradient2 = get_config('theme_elby', 'logingradient2') ?: '#6366f1';
$gradient3 = get_config('theme_elby', 'logingradient3') ?: '#3b82f6';

// Build template context.
$templatecontext = [
    'sitename' => format_string($SITE->shortname, true,
        ['context' => context_course::instance(SITEID), 'escape' => false]),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'logourl' => $logourl,
    'haslogo' => !empty($logourl),
    'loginimage' => $loginimage,
    'hasloginimage' => !empty($loginimage),
    'gradient1' => $gradient1,
    'gradient2' => $gradient2,
    'gradient3' => $gradient3,
    'loginurl' => (new moodle_url('/login/index.php'))->out(false),
];

echo $OUTPUT->render_from_template('theme_elby/signup', $templatecontext);
