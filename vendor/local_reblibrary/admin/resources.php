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
 * Resources management page.
 *
 * This page is accessible only to users with system-level admin capabilities.
 * It provides a Preact-based interface for managing library resources and authors.
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

// The resources page also surfaces author management, so allow access if the
// user can manage either resources or authors. Each write web-service still
// enforces its own capability.
if (!has_any_capability([
    'local/reblibrary:manageresources',
    'local/reblibrary:manageauthors',
], $context)) {
    throw new required_capability_exception(
        $context,
        'local/reblibrary:manageresources',
        'nopermissions',
        ''
    );
}

$PAGE->set_url(new moodle_url('/local/reblibrary/admin/resources.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('resources_page_title', 'local_reblibrary'));
$PAGE->set_heading(get_string('resources_page_heading', 'local_reblibrary'));

// Add body classes for plugin-specific page styling.
$PAGE->add_body_class('local-reblibrary-plugin');
$PAGE->add_body_class('local-reblibrary-admin-page');
$PAGE->add_body_class('local-reblibrary-resources');

// Load custom CSS.
$PAGE->requires->css('/local/reblibrary/styles.css');

// Load custom JavaScript module.
$PAGE->requires->js_call_amd('local_reblibrary/resources', 'init');

// Add breadcrumb navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_reblibrary'));
$PAGE->navbar->add(get_string('resources_page_title', 'local_reblibrary'));

// Fetch resources data from database.
global $DB;

// Get all resources with author information.
$sql = "SELECT r.id, r.title, r.isbn, r.description, r.file_url, r.cover_image_url,
               r.author_id, r.visible, r.media_type, r.created_at,
               CONCAT(a.first_name, ' ', a.last_name) as author_name,
               a.first_name, a.last_name, a.bio
        FROM {local_reblibrary_resources} r
        LEFT JOIN {local_reblibrary_authors} a ON r.author_id = a.id
        ORDER BY r.created_at DESC";

$resources = $DB->get_records_sql($sql);
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
        'visible' => $resource->visible,
        'media_type' => $resource->media_type ?? 'text',
        'created_at' => $resource->created_at,
    ];
}

// Get all authors.
$authors = $DB->get_records('local_reblibrary_authors', null, 'last_name ASC, first_name ASC');
$authorsdata = [];
foreach ($authors as $author) {
    $authorsdata[] = [
        'id' => $author->id,
        'first_name' => $author->first_name,
        'last_name' => $author->last_name,
        'bio' => $author->bio ?? '',
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
    'resources_json' => json_encode($resourcesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'authors_json' => json_encode($authorsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'levels_json' => json_encode($levelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'sublevels_json' => json_encode($sublevelsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'classes_json' => json_encode($classesdata, JSON_HEX_QUOT | JSON_HEX_APOS),
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_reblibrary/admin_resources', $templatecontext);
echo $OUTPUT->footer();
