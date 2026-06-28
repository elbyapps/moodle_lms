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
 * Library functions for local_elby_dashboard.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add nodes to the global navigation.
 *
 * @param global_navigation $navigation
 */
function local_elby_dashboard_extend_navigation(global_navigation $navigation) {
    global $CFG, $DB, $USER, $SESSION;

    // Only show the menu to logged-in users with the view capability.
    if (!isloggedin() || isguestuser()) {
        return;
    }
    $systemcontext = context_system::instance();
    if (!has_capability('local/elby_dashboard:view', $systemcontext)) {
        return;
    }

    // Add Elby Dashboard to the custom menu (appears in "More" menu in Moodle 5.1).
    $dashboardurl = new moodle_url('/local/elby_dashboard/index.php');
    $menuitem = get_string('pluginname', 'local_elby_dashboard') . '|' . $dashboardurl->out();

    if (empty($CFG->custommenuitems)) {
        $CFG->custommenuitems = '';
    }

    // Only add if not already present.
    if (strpos($CFG->custommenuitems, '/local/elby_dashboard/index.php') === false) {
        $CFG->custommenuitems = trim($CFG->custommenuitems) . "\n" . $menuitem;
    }

    // Also add to secondary navigation (navigation drawer).
    $node = $navigation->add(
        get_string('pluginname', 'local_elby_dashboard'),
        $dashboardurl,
        navigation_node::TYPE_CUSTOM,
        null,
        'elby_dashboard',
        new pix_icon('i/dashboard', get_string('pluginname', 'local_elby_dashboard'))
    );

    // Make it visible in the navigation drawer.
    $node->showinflatnavigation = true;

    // Feature 2: Prompt unlinked users to self-link (once per session).
    if (!is_siteadmin()
            && empty($SESSION->elby_sdms_prompted)) {
        $islinked = $DB->record_exists('elby_sdms_users', ['userid' => $USER->id]);
        if (!$islinked) {
            $linkurl = (new moodle_url('/local/elby_dashboard/link_sdms.php'))->out();
            $message = get_string('self_link_prompt', 'local_elby_dashboard', $linkurl);
            \core\notification::info($message);
            $SESSION->elby_sdms_prompted = true;
        }
    }
}

/**
 * Add nodes to the settings navigation.
 *
 * @param settings_navigation $navigation
 * @param context $context
 */
function local_elby_dashboard_extend_settings_navigation(settings_navigation $navigation, context $context) {
    global $PAGE;

    // Only add admin link if user has site config capability.
    $systemcontext = context_system::instance();
    if (has_capability('moodle/site:config', $systemcontext)) {
        // Try to add to site administration if available.
        if ($settingnode = $navigation->find('siteadministration', navigation_node::TYPE_SITE_ADMIN)) {
            $adminurl = new moodle_url('/local/elby_dashboard/admin/index.php');
            $adminnode = navigation_node::create(
                get_string('nav_admin', 'local_elby_dashboard'),
                $adminurl,
                navigation_node::TYPE_SETTING,
                null,
                'elby_dashboard_admin',
                new pix_icon('i/settings', get_string('nav_admin', 'local_elby_dashboard'))
            );
            $settingnode->add_node($adminnode);

            // Bulk Link navigation item.
            $bulkurl = new moodle_url('/local/elby_dashboard/admin/bulk_link.php');
            $bulknode = navigation_node::create(
                get_string('bulk_link_title', 'local_elby_dashboard'),
                $bulkurl,
                navigation_node::TYPE_SETTING,
                null,
                'elby_dashboard_bulk_link',
                new pix_icon('i/upload', get_string('bulk_link_title', 'local_elby_dashboard'))
            );
            $settingnode->add_node($bulknode);
        }
    }
}

/**
 * Serve plugin files.
 *
 * @param stdClass $course Course object
 * @param stdClass $cm Course module object
 * @param context $context Context object
 * @param string $filearea File area
 * @param array $args Arguments
 * @param bool $forcedownload Force download
 * @param array $options Additional options
 * @return bool|void
 */
function local_elby_dashboard_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        send_file_not_found();
    }

    // Valid file areas for this plugin.
    $validareas = ['logo'];

    if (!in_array($filearea, $validareas)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $filename = array_pop($args);
    $itemid = 0;

    $file = $fs->get_file($context->id, 'local_elby_dashboard', $filearea, $itemid, '/', $filename);

    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 86400, 0, $forcedownload, $options);
}

/**
 * Add SDMS information to the user profile page.
 *
 * @param \core_user\output\myprofile\tree $tree Profile tree.
 * @param stdClass $user The user whose profile is being viewed.
 * @param bool $iscurrentuser Whether the profile belongs to the current user.
 * @param stdClass|null $course Course object (null for site profile).
 */
function local_elby_dashboard_myprofile_navigation(
    \core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
) {
    global $DB, $USER;

    // Get the SDMS link record for this user.
    $sdmsuser = $DB->get_record('elby_sdms_users', ['userid' => $user->id]);

    if ($sdmsuser) {
        // User is linked — show SDMS Information category.
        $category = new \core_user\output\myprofile\category(
            'sdms_information',
            get_string('profile_sdms_category', 'local_elby_dashboard'),
            'contact'
        );
        $tree->add_category($category);

        // SDMS ID.
        $tree->add_node(new \core_user\output\myprofile\node(
            'sdms_information',
            'sdms_id',
            get_string('profile_sdms_id', 'local_elby_dashboard'),
            null,
            null,
            $sdmsuser->sdms_id
        ));

        // User Type.
        $tree->add_node(new \core_user\output\myprofile\node(
            'sdms_information',
            'sdms_user_type',
            get_string('profile_user_type', 'local_elby_dashboard'),
            null,
            null,
            ucfirst($sdmsuser->user_type)
        ));

        // School.
        if ($sdmsuser->schoolid) {
            $school = $DB->get_record('elby_schools', ['id' => $sdmsuser->schoolid], 'school_code, school_name');
            if ($school) {
                $tree->add_node(new \core_user\output\myprofile\node(
                    'sdms_information',
                    'sdms_school',
                    get_string('profile_school', 'local_elby_dashboard'),
                    null,
                    null,
                    $school->school_name . ' (' . $school->school_code . ')'
                ));
            }
        }

        // Type-specific data.
        if ($sdmsuser->user_type === 'student') {
            $student = $DB->get_record('elby_students', ['sdms_userid' => $sdmsuser->id]);
            if ($student) {
                if (!empty($student->program)) {
                    $tree->add_node(new \core_user\output\myprofile\node(
                        'sdms_information',
                        'sdms_program',
                        get_string('profile_program', 'local_elby_dashboard'),
                        null,
                        null,
                        $student->program
                    ));
                }
                if (!empty($student->gender)) {
                    $tree->add_node(new \core_user\output\myprofile\node(
                        'sdms_information',
                        'sdms_gender',
                        get_string('profile_gender', 'local_elby_dashboard'),
                        null,
                        null,
                        ucfirst(strtolower($student->gender))
                    ));
                }
            }
        } else {
            $teacher = $DB->get_record('elby_teachers', ['sdms_userid' => $sdmsuser->id]);
            if ($teacher) {
                if (!empty($teacher->position)) {
                    $tree->add_node(new \core_user\output\myprofile\node(
                        'sdms_information',
                        'sdms_position',
                        get_string('profile_position', 'local_elby_dashboard'),
                        null,
                        null,
                        $teacher->position
                    ));
                }
                if (!empty($teacher->gender)) {
                    $tree->add_node(new \core_user\output\myprofile\node(
                        'sdms_information',
                        'sdms_gender',
                        get_string('profile_gender', 'local_elby_dashboard'),
                        null,
                        null,
                        ucfirst(strtolower($teacher->gender))
                    ));
                }
            }
        }

        // Status.
        if (!empty($sdmsuser->sdms_status)) {
            $tree->add_node(new \core_user\output\myprofile\node(
                'sdms_information',
                'sdms_status',
                get_string('profile_status', 'local_elby_dashboard'),
                null,
                null,
                ucfirst(strtolower($sdmsuser->sdms_status))
            ));
        }

        // Academic Year.
        if (!empty($sdmsuser->academic_year)) {
            $tree->add_node(new \core_user\output\myprofile\node(
                'sdms_information',
                'sdms_academic_year',
                get_string('profile_academic_year', 'local_elby_dashboard'),
                null,
                null,
                $sdmsuser->academic_year
            ));
        }
    } else {
        // User is NOT linked.
        if ($iscurrentuser) {
            // Viewing own profile — show link prompt.
            $category = new \core_user\output\myprofile\category(
                'sdms_information',
                get_string('profile_sdms_category', 'local_elby_dashboard'),
                'contact'
            );
            $tree->add_category($category);

            $linkurl = new moodle_url('/local/elby_dashboard/link_sdms.php');
            $tree->add_node(new \core_user\output\myprofile\node(
                'sdms_information',
                'sdms_link_prompt',
                get_string('profile_link_own', 'local_elby_dashboard'),
                null,
                $linkurl
            ));
        } else if (has_capability('local/elby_dashboard:manage', context_system::instance())) {
            // Admin viewing another user's profile — show admin link.
            $category = new \core_user\output\myprofile\category(
                'sdms_information',
                get_string('profile_sdms_category', 'local_elby_dashboard'),
                'contact'
            );
            $tree->add_category($category);

            $adminurl = new moodle_url('/local/elby_dashboard/admin/index.php', ['page' => 'admin']);
            $tree->add_node(new \core_user\output\myprofile\node(
                'sdms_information',
                'sdms_link_admin',
                get_string('profile_link_admin', 'local_elby_dashboard'),
                null,
                $adminurl
            ));
        }
    }
}

/**
 * Parse a region code into province and district components.
 *
 * Region codes follow the format PP DD SS (province, district, sector digits).
 *
 * @param string $regioncode The region code string.
 * @return array{province: string, district: string} Parsed components.
 */
function local_elby_dashboard_parse_region_code(string $regioncode): array {
    if (strlen($regioncode) < 2) {
        return ['province' => '', 'district' => ''];
    }
    return [
        'province' => substr($regioncode, 0, 2),
        'district' => strlen($regioncode) >= 4 ? substr($regioncode, 0, 4) : '',
    ];
}
