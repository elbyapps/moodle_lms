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
 * Library functions for local_reblibrary.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Capabilities that grant access to the REB Library administration area.
 *
 * Possessing any one of these is enough to see the admin entry-points and to
 * land on the admin dashboard. Each individual admin sub-page / web-service
 * still enforces its own, more specific capability.
 *
 * @return string[]
 */
function local_reblibrary_get_manage_capabilities(): array {
    return [
        'local/reblibrary:manageedulevels',
        'local/reblibrary:manageedusublevels',
        'local/reblibrary:manageclasses',
        'local/reblibrary:managesections',
        'local/reblibrary:manageauthors',
        'local/reblibrary:manageresources',
        'local/reblibrary:managecategories',
        'local/reblibrary:manageassignments',
    ];
}

/**
 * Whether the current user can access the REB Library administration area.
 *
 * Returns true when the user has any of the local/reblibrary:manage*
 * capabilities. Site administrators always pass via Moodle's capability
 * resolver.
 *
 * @param context|null $context Defaults to the system context.
 * @return bool
 */
function local_reblibrary_user_can_admin(?context $context = null): bool {
    $context = $context ?? context_system::instance();
    return has_any_capability(local_reblibrary_get_manage_capabilities(), $context);
}

/**
 * Require that the current user can access the REB Library administration
 * area. Throws if they cannot.
 *
 * @param context|null $context Defaults to the system context.
 * @throws required_capability_exception
 */
function local_reblibrary_require_admin(?context $context = null): void {
    $context = $context ?? context_system::instance();
    if (!local_reblibrary_user_can_admin($context)) {
        // Use manageresources as the representative capability for the standard
        // "missing capability" exception message.
        throw new required_capability_exception(
            $context,
            'local/reblibrary:manageresources',
            'nopermissions',
            ''
        );
    }
}

/**
 * Add nodes to the global navigation.
 *
 * @param global_navigation $navigation
 */
function local_reblibrary_extend_navigation(global_navigation $navigation) {
    global $CFG;

    // Add REB Library to the custom menu (appears in "More" menu in Moodle 5.1).
    $libraryurl = new moodle_url('/local/reblibrary/index.php');
    $menuitem = get_string('pluginname', 'local_reblibrary') . '|' . $libraryurl->out();

    if (empty($CFG->custommenuitems)) {
        $CFG->custommenuitems = '';
    }

    // Only add if not already present.
    if (strpos($CFG->custommenuitems, '/local/reblibrary/index.php') === false) {
        $CFG->custommenuitems = trim($CFG->custommenuitems) . "\n" . $menuitem;
    }

    // Also add to secondary navigation (navigation drawer).
    $node = $navigation->add(
        get_string('pluginname', 'local_reblibrary'),
        $libraryurl,
        navigation_node::TYPE_CUSTOM,
        null,
        'reblibrary',
        new pix_icon('i/book', get_string('pluginname', 'local_reblibrary'))
    );

    // Make it visible in the navigation drawer.
    $node->showinflatnavigation = true;
}

/**
 * Add nodes to the settings navigation.
 *
 * @param settings_navigation $navigation
 * @param context $context
 */
function local_reblibrary_extend_settings_navigation(settings_navigation $navigation, context $context) {
    global $PAGE;

    // Only add admin link if user can administer the library.
    $systemcontext = context_system::instance();
    if (local_reblibrary_user_can_admin($systemcontext)) {
        // Try to add to site administration if available.
        if ($settingnode = $navigation->find('siteadministration', navigation_node::TYPE_SITE_ADMIN)) {
            $adminurl = new moodle_url('/local/reblibrary/admin/index.php');
            $adminnode = navigation_node::create(
                get_string('nav_admin', 'local_reblibrary'),
                $adminurl,
                navigation_node::TYPE_SETTING,
                null,
                'reblibrary_admin',
                new pix_icon('i/settings', get_string('nav_admin', 'local_reblibrary'))
            );
            $settingnode->add_node($adminnode);
        }
    }
}

/**
 * Generate navigation menu data for library pages.
 *
 * @param string $activepage The current active page (index, browse, search, collection)
 * @return array Navigation items for the template
 */
function local_reblibrary_get_navigation($activepage = 'index') {
    $navitems = [
        [
            'name' => get_string('nav_home', 'local_reblibrary'),
            'url' => new moodle_url('/local/reblibrary/index.php'),
            'active' => ($activepage === 'index'),
        ],
        [
            'name' => get_string('nav_browse', 'local_reblibrary'),
            'url' => new moodle_url('/local/reblibrary/browse.php'),
            'active' => ($activepage === 'browse'),
        ],
        [
            'name' => get_string('nav_search', 'local_reblibrary'),
            'url' => new moodle_url('/local/reblibrary/search.php'),
            'active' => ($activepage === 'search'),
        ],
        [
            'name' => get_string('nav_collection', 'local_reblibrary'),
            'url' => new moodle_url('/local/reblibrary/collection.php'),
            'active' => ($activepage === 'collection'),
        ],
    ];

    return ['nav_items' => $navitems];
}
