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
 * REB Library Reading Materials page - Public library with reading materials.
 *
 * This page displays reading materials in a book grid format,
 * accessible to both authenticated users and guests.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/reblibrary/lib.php');

// Auto-login as guest if user is not logged in (for public library access).
if (!isloggedin()) {
    // Get guest user and complete login automatically.
    $guest = guest_user();
    complete_user_login($guest);
}

// Allow both authenticated users and guests to access this page.
require_login(null, true);

// Set up the page context.
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reblibrary/reading-materials.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('readingmaterialspage_title', 'local_reblibrary'));
$PAGE->set_heading(get_string('readingmaterialspage_heading', 'local_reblibrary'));

// Add body classes for styling.
$PAGE->add_body_class('local-reblibrary-plugin');
$PAGE->add_body_class('local-reblibrary-reading-materials');

// Load custom CSS.
$PAGE->requires->css('/local/reblibrary/styles.css');

// Load JavaScript module for reading materials.
$PAGE->requires->js_call_amd('local_reblibrary/reading-materials', 'init');

// Fetch all resources from database with author information, class assignments, and categories.
global $DB;

// Get URL filter parameters.
$levelid = optional_param('level_id', null, PARAM_INT);
$sublevelid = optional_param('sublevel_id', null, PARAM_INT);
$classid = optional_param('class_id', null, PARAM_INT);
$categoryid = optional_param('category_id', null, PARAM_INT);
$searchquery = optional_param('q', '', PARAM_TEXT);

// Build SQL query with filters.
$sql = "SELECT r.id, r.title, r.isbn, r.description, r.file_url, r.cover_image_url,
               r.author_id, r.created_at,
               CONCAT(a.first_name, ' ', a.last_name) as author_name,
               a.first_name, a.last_name
        FROM {local_reblibrary_resources} r
        LEFT JOIN {local_reblibrary_authors} a ON r.author_id = a.id";

$whereclauses = [];
$params = [];

// Apply class/sublevel/level filters via resource assignments.
if ($classid) {
    $sql .= " INNER JOIN {local_reblibrary_res_assigns} ra ON r.id = ra.resource_id";
    $whereclauses[] = "ra.class_id = :classid";
    $params['classid'] = $classid;
} else if ($sublevelid) {
    $sql .= " INNER JOIN {local_reblibrary_res_assigns} ra ON r.id = ra.resource_id
              INNER JOIN {local_reblibrary_classes} c ON ra.class_id = c.id";
    $whereclauses[] = "c.sublevel_id = :sublevelid";
    $params['sublevelid'] = $sublevelid;
} else if ($levelid) {
    $sql .= " INNER JOIN {local_reblibrary_res_assigns} ra ON r.id = ra.resource_id
              INNER JOIN {local_reblibrary_classes} c ON ra.class_id = c.id
              INNER JOIN {local_reblibrary_edu_sublevels} s ON c.sublevel_id = s.id";
    $whereclauses[] = "s.level_id = :levelid";
    $params['levelid'] = $levelid;
}

// Apply category filter.
if ($categoryid) {
    $sql .= " INNER JOIN {local_reblibrary_res_categories} rc ON r.id = rc.resource_id";
    $whereclauses[] = "rc.category_id = :categoryid";
    $params['categoryid'] = $categoryid;
}

// Apply search query filter.
if (!empty($searchquery)) {
    $whereclauses[] = "(r.title LIKE :searchquery1 OR
                        CONCAT(a.first_name, ' ', a.last_name) LIKE :searchquery2 OR
                        r.description LIKE :searchquery3)";
    $searchpattern = '%' . $DB->sql_like_escape($searchquery) . '%';
    $params['searchquery1'] = $searchpattern;
    $params['searchquery2'] = $searchpattern;
    $params['searchquery3'] = $searchpattern;
}

// Apply visibility filter - only show public resources.
$whereclauses[] = "r.visible = 1";

// Apply Reading Materials page label filtering based on plugin settings.
$includelabels = get_config('local_reblibrary', 'readingmaterials_include_labels');
$excludelabels = get_config('local_reblibrary', 'readingmaterials_exclude_labels');

// Parse comma-separated config values to arrays.
$includelabelids = $includelabels ? explode(',', $includelabels) : [];
$excludelabelids = $excludelabels ? explode(',', $excludelabels) : [];

// Apply include filter (if configured).
if (!empty($includelabelids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($includelabelids, SQL_PARAMS_NAMED, 'inclbl');
    $whereclauses[] = "EXISTS (
        SELECT 1 FROM {local_reblibrary_res_labels} rl_inc
        WHERE rl_inc.resource_id = r.id
        AND rl_inc.label_id $insql
    )";
    $params = array_merge($params, $inparams);
}

// Apply exclude filter (if configured) - uses NOT EXISTS for precedence.
if (!empty($excludelabelids)) {
    list($exsql, $exparams) = $DB->get_in_or_equal($excludelabelids, SQL_PARAMS_NAMED, 'exclbl');
    $whereclauses[] = "NOT EXISTS (
        SELECT 1 FROM {local_reblibrary_res_labels} rl_exc
        WHERE rl_exc.resource_id = r.id
        AND rl_exc.label_id $exsql
    )";
    $params = array_merge($params, $exparams);
}

// Add WHERE clause if filters are applied.
if (!empty($whereclauses)) {
    $sql .= " WHERE " . implode(' AND ', $whereclauses);
}

$sql .= " ORDER BY r.created_at DESC";

$resources = $DB->get_records_sql($sql, $params);

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

// Check if user has admin capabilities (for showing/hiding admin menu).
$isadmin = isloggedin() && !isguestuser() && local_reblibrary_user_can_admin($context);

// Prepare data for template.
$templatecontext = [
    'resources_json' => json_encode($resourcesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'levels_json' => json_encode($levelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'sublevels_json' => json_encode($sublevelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'classes_json' => json_encode($classesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'categories_json' => json_encode($categoriesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'is_admin' => $isadmin,
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_reblibrary/reading_materials', $templatecontext);
echo $OUTPUT->footer();
