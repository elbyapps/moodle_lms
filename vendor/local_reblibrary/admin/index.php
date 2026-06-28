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
 * REB Library admin dashboard.
 *
 * This page displays the admin dashboard accessible only to users
 * with system-level admin capabilities.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/local/reblibrary/lib.php');

// Require login and admin capability.
require_login();

// Set up the page context.
$context = context_system::instance();
$PAGE->set_context($context);

// Require any of the local/reblibrary:manage* capabilities to land on the
// admin dashboard. Individual sub-pages enforce a more specific capability.
local_reblibrary_require_admin($context);

$PAGE->set_url(new moodle_url('/local/reblibrary/admin/index.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('adminpage_title', 'local_reblibrary'));
$PAGE->set_heading(get_string('adminpage_heading', 'local_reblibrary'));

// Add body classes for plugin-specific page styling.
$PAGE->add_body_class('local-reblibrary-plugin');
$PAGE->add_body_class('local-reblibrary-admin-page');

// Load custom CSS.
$PAGE->requires->css('/local/reblibrary/styles.css');

// Load custom JavaScript module.
$PAGE->requires->js_call_amd('local_reblibrary/dashboard', 'init');

// Add breadcrumb navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_reblibrary'));
$PAGE->navbar->add(get_string('admin_nav_dashboard', 'local_reblibrary'));

// Prepare user data for Preact (via data attributes).
$userroles = [];
foreach (get_user_roles($context, $USER->id) as $role) {
    $userroles[] = $role->shortname;
}

$userdata = [
    'id' => $USER->id,
    'fullname' => fullname($USER),
    'firstname' => $USER->firstname,
    'lastname' => $USER->lastname,
    'email' => $USER->email,
    'avatar' => $OUTPUT->get_generated_image_for_id($USER->id),
    'roles' => $userroles,
];

// Prepare stats data for Preact (via data attributes).
// TODO: Replace with real database queries.
$statsdata = [
    'totalResources' => $DB->count_records('local_reblibrary_resources'),
    'totalAuthors' => $DB->count_records('local_reblibrary_authors'),
    'totalCategories' => $DB->count_records('local_reblibrary_categories'),
    'totalClasses' => $DB->count_records('local_reblibrary_classes'),
];

// Get education structure for sidebar navigation.
$levels = $DB->get_records('local_reblibrary_edu_levels', null, 'level_name ASC');
$levelsdata = [];
foreach ($levels as $level) {
    $levelsdata[] = [
        'id' => $level->id,
        'level_name' => $level->level_name,
    ];
}

$sublevels = $DB->get_records('local_reblibrary_edu_sublevels', null, 'sublevel_name ASC');
$sublevelsdata = [];
foreach ($sublevels as $sublevel) {
    $sublevelsdata[] = [
        'id' => $sublevel->id,
        'sublevel_name' => $sublevel->sublevel_name,
        'level_id' => $sublevel->level_id,
    ];
}

$sql = "SELECT c.id, c.class_name, c.class_code, c.sublevel_id
        FROM {local_reblibrary_classes} c
        ORDER BY c.class_name";
$classes = $DB->get_records_sql($sql);
$classesdata = [];
foreach ($classes as $class) {
    $classesdata[] = [
        'id' => $class->id,
        'class_name' => $class->class_name,
        'class_code' => $class->class_code,
        'sublevel_id' => $class->sublevel_id,
    ];
}

// Prepare data for template (only JSON-encoded data for Preact).
$templatecontext = [
    'user_data_json' => json_encode($userdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'stats_data_json' => json_encode($statsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'levels_json' => json_encode($levelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'sublevels_json' => json_encode($sublevelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'classes_json' => json_encode($classesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_reblibrary/root', $templatecontext);
echo $OUTPUT->footer();
