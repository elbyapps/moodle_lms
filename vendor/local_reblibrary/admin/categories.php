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
 * Categories management page.
 *
 * This page is accessible only to users with system-level admin capabilities.
 * It provides a Preact-based interface for managing hierarchical resource categories.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');

// Require login and admin capability.
require_login();

// Set up the page context.
$context = context_system::instance();
$PAGE->set_context($context);

// Categories and labels are managed together; require the categories
// capability to access this page.
require_capability('local/reblibrary:managecategories', $context);

$PAGE->set_url(new moodle_url('/local/reblibrary/admin/categories.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('categories_page_title', 'local_reblibrary'));
$PAGE->set_heading(get_string('categories_page_heading', 'local_reblibrary'));

// Add body classes for plugin-specific page styling.
$PAGE->add_body_class('local-reblibrary-plugin');
$PAGE->add_body_class('local-reblibrary-admin-page');
$PAGE->add_body_class('local-reblibrary-categories');

// Load custom CSS.
$PAGE->requires->css('/local/reblibrary/styles.css');

// Load custom JavaScript module.
$PAGE->requires->js_call_amd('local_reblibrary/categories', 'init');

// Add breadcrumb navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_reblibrary'));
$PAGE->navbar->add(get_string('categories_page_title', 'local_reblibrary'));

// Fetch categories and labels data from database.
global $DB;

// Get all categories with their parent information.
$categories = $DB->get_records('local_reblibrary_categories', null, 'category_name ASC');
$categoriesdata = [];
foreach ($categories as $category) {
    $categoriesdata[] = [
        'id' => $category->id,
        'category_name' => $category->category_name,
        'parent_category_id' => $category->parent_category_id,
        'description' => $category->description ?? '',
    ];
}

// Get all labels.
$labels = $DB->get_records('local_reblibrary_labels', null, 'label_name ASC');
$labelsdata = [];
foreach ($labels as $label) {
    $labelsdata[] = [
        'id' => $label->id,
        'label_name' => $label->label_name,
        'description' => $label->description ?? '',
    ];
}

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
    'categories_json' => json_encode($categoriesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'labels_json' => json_encode($labelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'levels_json' => json_encode($levelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'sublevels_json' => json_encode($sublevelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'classes_json' => json_encode($classesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_reblibrary/admin_categories', $templatecontext);
echo $OUTPUT->footer();
