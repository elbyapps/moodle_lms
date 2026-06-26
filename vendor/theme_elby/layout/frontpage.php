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
 * Frontpage layout with custom sections.
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Get theme settings helper.
$themesettings = new \theme_elby\output\themesettings();

// Standard body attributes.
$bodyattributes = $OUTPUT->body_attributes(['frontpage-layout']);

// Get block regions.
$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = strpos($blockshtml, 'data-block=') !== false;

// Build template context.
$templatecontext = [
    'sitename' => format_string($SITE->shortname, true,
        ['context' => context_course::instance(SITEID)]),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'sidepreblocks' => $blockshtml,
    'hasblocks' => $hasblocks,
    'isloggedin' => isloggedin(),
    'isguest' => isguestuser(),
];

// Merge frontpage section data from settings.
$templatecontext = array_merge($templatecontext, $themesettings->get_frontpage_data());

// Add navigation and user menu data.
$primarymenu = new \core\navigation\output\primary($PAGE);
$renderer = $PAGE->get_renderer('core');
$templatecontext['primarymoremenu'] = $primarymenu->export_for_template($renderer);
$templatecontext['mobileprimarynav'] = $primarymenu->export_for_template($renderer)['mobileprimarynav'];
$templatecontext['usermenu'] = $OUTPUT->user_menu($USER);
$templatecontext['loginurl'] = get_login_url();

// Get logo.
$templatecontext['logourl'] = $OUTPUT->get_logo_url();
$templatecontext['haslogo'] = !empty($templatecontext['logourl']);
$templatecontext['compactlogourl'] = $OUTPUT->get_compact_logo_url();

// Show site name setting.
$templatecontext['showsitename'] = get_config('theme_elby', 'showsitename') ?? true;

echo $OUTPUT->render_from_template('theme_elby/frontpage', $templatecontext);
