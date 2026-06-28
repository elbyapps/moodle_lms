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
 * REB Library browse page - Browse books by category.
 *
 * This page displays all available resources grouped by categories,
 * with uncategorized resources shown first.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

// Allow both authenticated users and guests to access this page.
require_login(null, true);

// Set up the page context.
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reblibrary/browse.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('browsepage_title', 'local_reblibrary'));
$PAGE->set_heading(get_string('browsepage_heading', 'local_reblibrary'));

// Add body classes for styling.
$PAGE->add_body_class('local-reblibrary-plugin');
$PAGE->add_body_class('local-reblibrary-browse');

// Load custom CSS.
$PAGE->requires->css('/local/reblibrary/styles.css');

// Load JavaScript module for library browse.
$PAGE->requires->js_call_amd('local_reblibrary/library-browse', 'init');

// Fetch all resources from database with author information, class assignments, and categories.
global $DB;

// Get resources with author info.
$sql = "SELECT r.id, r.title, r.isbn, r.description, r.file_url, r.cover_image_url,
               r.author_id, r.created_at,
               CONCAT(a.first_name, ' ', a.last_name) as author_name,
               a.first_name, a.last_name
        FROM {local_reblibrary_resources} r
        LEFT JOIN {local_reblibrary_authors} a ON r.author_id = a.id
        ORDER BY r.created_at DESC";

$resources = $DB->get_records_sql($sql);

// Get class assignments for each resource.
$classassignments = $DB->get_records('local_reblibrary_res_assigns', null, '', 'id, resource_id, class_id');
$resourceclasses = [];
foreach ($classassignments as $assignment) {
    if (!isset($resourceclasses[$assignment->resource_id])) {
        $resourceclasses[$assignment->resource_id] = [];
    }
    if ($assignment->class_id) {
        $resourceclasses[$assignment->resource_id][] = $assignment->class_id;
    }
}

// Get category assignments for each resource.
$categoryassignments = $DB->get_records('local_reblibrary_res_categories', null, '', 'id, resource_id, category_id');
$resourcecategories = [];
foreach ($categoryassignments as $assignment) {
    if (!isset($resourcecategories[$assignment->resource_id])) {
        $resourcecategories[$assignment->resource_id] = [];
    }
    $resourcecategories[$assignment->resource_id][] = $assignment->category_id;
}

// Build resources data with assignments.
$resourcesdata = [];
foreach ($resources as $resource) {
    $resourcesdata[] = [
        'id' => $resource->id,
        'title' => $resource->title,
        'isbn' => $resource->isbn ?? '',
        'description' => $resource->description ?? '',
        'file_url' => $resource->file_url ?? '',
        'cover_image_url' => $resource->cover_image_url ?? '',
        'author_id' => $resource->author_id,
        'author_name' => $resource->author_name ?? 'Unknown',
        'created_at' => $resource->created_at,
        'class_ids' => $resourceclasses[$resource->id] ?? [],
        'category_ids' => $resourcecategories[$resource->id] ?? [],
    ];
}

// Get education structure for filters.
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

// Get classes with hierarchy info.
$sql = "SELECT c.id, c.class_name, c.class_code, c.sublevel_id,
               s.sublevel_name, s.level_id, l.level_name
        FROM {local_reblibrary_classes} c
        JOIN {local_reblibrary_edu_sublevels} s ON c.sublevel_id = s.id
        JOIN {local_reblibrary_edu_levels} l ON s.level_id = l.id
        ORDER BY l.level_name, s.sublevel_name, c.class_name";

$classes = $DB->get_records_sql($sql);
$classesdata = [];
foreach ($classes as $class) {
    $classesdata[] = [
        'id' => $class->id,
        'class_name' => $class->class_name,
        'class_code' => $class->class_code,
        'sublevel_id' => $class->sublevel_id,
        'sublevel_name' => $class->sublevel_name,
        'level_id' => $class->level_id,
        'level_name' => $class->level_name,
    ];
}

// Get all categories for filtering.
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

// Prepare data for template.
$templatecontext = [
    'resources_json' => json_encode($resourcesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'levels_json' => json_encode($levelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'sublevels_json' => json_encode($sublevelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'classes_json' => json_encode($classesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'categories_json' => json_encode($categoriesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_reblibrary/library_browse', $templatecontext);
echo $OUTPUT->footer();
