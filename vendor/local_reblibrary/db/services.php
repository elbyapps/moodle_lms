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
 * Web service definitions for the REB Library plugin.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // Resources web services.
    'local_reblibrary_get_all_resources' => [
        'classname' => 'local_reblibrary\external\resources',
        'methodname' => 'get_all',
        'classpath' => '',
        'description' => 'Get all resources',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_get_resource_by_id' => [
        'classname' => 'local_reblibrary\external\resources',
        'methodname' => 'get_by_id',
        'classpath' => '',
        'description' => 'Get resource by ID',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_create_resource' => [
        'classname' => 'local_reblibrary\external\resources',
        'methodname' => 'create',
        'classpath' => '',
        'description' => 'Create a new resource',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    'local_reblibrary_update_resource' => [
        'classname' => 'local_reblibrary\external\resources',
        'methodname' => 'update',
        'classpath' => '',
        'description' => 'Update a resource',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    'local_reblibrary_delete_resource' => [
        'classname' => 'local_reblibrary\external\resources',
        'methodname' => 'delete',
        'classpath' => '',
        'description' => 'Delete a resource',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    // Education structure web services.

    // Education levels.
    'local_reblibrary_get_all_levels' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'get_all_levels',
        'classpath' => '',
        'description' => 'Get all education levels',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedulevels',
    ],

    'local_reblibrary_create_level' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'create_level',
        'classpath' => '',
        'description' => 'Create a new education level',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedulevels',
    ],

    'local_reblibrary_update_level' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'update_level',
        'classpath' => '',
        'description' => 'Update an education level',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedulevels',
    ],

    'local_reblibrary_delete_level' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'delete_level',
        'classpath' => '',
        'description' => 'Delete an education level',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedulevels',
    ],

    // Education sublevels.
    'local_reblibrary_get_all_sublevels' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'get_all_sublevels',
        'classpath' => '',
        'description' => 'Get all education sublevels',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedusublevels',
    ],

    'local_reblibrary_create_sublevel' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'create_sublevel',
        'classpath' => '',
        'description' => 'Create a new education sublevel',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedusublevels',
    ],

    'local_reblibrary_update_sublevel' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'update_sublevel',
        'classpath' => '',
        'description' => 'Update an education sublevel',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedusublevels',
    ],

    'local_reblibrary_delete_sublevel' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'delete_sublevel',
        'classpath' => '',
        'description' => 'Delete an education sublevel',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedusublevels',
    ],

    // Classes.
    'local_reblibrary_get_all_classes' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'get_all_classes',
        'classpath' => '',
        'description' => 'Get all classes',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageclasses',
    ],

    'local_reblibrary_create_class' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'create_class',
        'classpath' => '',
        'description' => 'Create a new class',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageclasses',
    ],

    'local_reblibrary_update_class' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'update_class',
        'classpath' => '',
        'description' => 'Update a class',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageclasses',
    ],

    'local_reblibrary_delete_class' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'delete_class',
        'classpath' => '',
        'description' => 'Delete a class',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageclasses',
    ],

    // Sections.
    'local_reblibrary_get_all_sections' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'get_all_sections',
        'classpath' => '',
        'description' => 'Get all sections',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managesections',
    ],

    'local_reblibrary_create_section' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'create_section',
        'classpath' => '',
        'description' => 'Create a new section',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managesections',
    ],

    'local_reblibrary_update_section' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'update_section',
        'classpath' => '',
        'description' => 'Update a section',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managesections',
    ],

    'local_reblibrary_delete_section' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'delete_section',
        'classpath' => '',
        'description' => 'Delete a section',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managesections',
    ],

    // Education structure reorder (drag-and-drop) endpoints.
    'local_reblibrary_reorder_levels' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'reorder_levels',
        'classpath' => '',
        'description' => 'Persist a new sort order for education levels',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedulevels',
    ],

    'local_reblibrary_reorder_sublevels' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'reorder_sublevels',
        'classpath' => '',
        'description' => 'Persist a new sort order for education sublevels',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageedusublevels',
    ],

    'local_reblibrary_reorder_classes' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'reorder_classes',
        'classpath' => '',
        'description' => 'Persist a new sort order for classes',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageclasses',
    ],

    'local_reblibrary_reorder_sections' => [
        'classname' => 'local_reblibrary\external\edu_structure',
        'methodname' => 'reorder_sections',
        'classpath' => '',
        'description' => 'Persist a new sort order for sections',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managesections',
    ],

    // Categories web services.
    'local_reblibrary_create_category' => [
        'classname' => 'local_reblibrary\external\categories',
        'methodname' => 'create_category',
        'classpath' => '',
        'description' => 'Create a new category',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managecategories',
    ],

    'local_reblibrary_update_category' => [
        'classname' => 'local_reblibrary\external\categories',
        'methodname' => 'update_category',
        'classpath' => '',
        'description' => 'Update a category',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managecategories',
    ],

    'local_reblibrary_delete_category' => [
        'classname' => 'local_reblibrary\external\categories',
        'methodname' => 'delete_category',
        'classpath' => '',
        'description' => 'Delete a category',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managecategories',
    ],

    // Authors web services.
    'local_reblibrary_get_all_authors' => [
        'classname' => 'local_reblibrary\external\authors',
        'methodname' => 'get_all',
        'classpath' => '',
        'description' => 'Get all authors',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_get_author_by_id' => [
        'classname' => 'local_reblibrary\external\authors',
        'methodname' => 'get_by_id',
        'classpath' => '',
        'description' => 'Get author by ID',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_create_author' => [
        'classname' => 'local_reblibrary\external\authors',
        'methodname' => 'create',
        'classpath' => '',
        'description' => 'Create a new author',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    'local_reblibrary_update_author' => [
        'classname' => 'local_reblibrary\external\authors',
        'methodname' => 'update',
        'classpath' => '',
        'description' => 'Update an author',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    'local_reblibrary_delete_author' => [
        'classname' => 'local_reblibrary\external\authors',
        'methodname' => 'delete',
        'classpath' => '',
        'description' => 'Delete an author',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    // S3 upload web services.
    'local_reblibrary_upload_resource_file' => [
        'classname' => 'local_reblibrary\external\upload_resource_file',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Upload resource file (PDF + cover) to S3-compatible storage',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    // Resource assignment web services.
    'local_reblibrary_get_classes_for_assignment' => [
        'classname' => 'local_reblibrary\external\assignments',
        'methodname' => 'get_classes',
        'classpath' => '',
        'description' => 'Get all classes with sublevel info for assignment',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_get_sections_for_assignment' => [
        'classname' => 'local_reblibrary\external\assignments',
        'methodname' => 'get_sections',
        'classpath' => '',
        'description' => 'Get all sections with sublevel info for assignment',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_get_resource_assignments' => [
        'classname' => 'local_reblibrary\external\assignments',
        'methodname' => 'get_resource_assignments',
        'classpath' => '',
        'description' => 'Get current assignments for a resource',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_assign_to_classes' => [
        'classname' => 'local_reblibrary\external\assignments',
        'methodname' => 'assign_to_classes',
        'classpath' => '',
        'description' => 'Assign resource to classes',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    'local_reblibrary_assign_to_sections' => [
        'classname' => 'local_reblibrary\external\assignments',
        'methodname' => 'assign_to_sections',
        'classpath' => '',
        'description' => 'Assign resource to sections',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    // Resource category assignment web services.
    'local_reblibrary_get_all_categories_with_parent' => [
        'classname' => 'local_reblibrary\external\resource_categories',
        'methodname' => 'get_all_categories',
        'classpath' => '',
        'description' => 'Get all categories with parent information',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_get_resource_categories' => [
        'classname' => 'local_reblibrary\external\resource_categories',
        'methodname' => 'get_resource_categories',
        'classpath' => '',
        'description' => 'Get categories assigned to a resource',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_assign_categories' => [
        'classname' => 'local_reblibrary\external\resource_categories',
        'methodname' => 'assign_categories',
        'classpath' => '',
        'description' => 'Assign categories to a resource',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

    // Labels web services.
    'local_reblibrary_get_all_labels' => [
        'classname' => 'local_reblibrary\external\labels',
        'methodname' => 'get_all',
        'classpath' => '',
        'description' => 'Get all labels',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_get_label_by_id' => [
        'classname' => 'local_reblibrary\external\labels',
        'methodname' => 'get_by_id',
        'classpath' => '',
        'description' => 'Get label by ID',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_create_label' => [
        'classname' => 'local_reblibrary\external\labels',
        'methodname' => 'create',
        'classpath' => '',
        'description' => 'Create a new label',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managecategories',
    ],

    'local_reblibrary_update_label' => [
        'classname' => 'local_reblibrary\external\labels',
        'methodname' => 'update',
        'classpath' => '',
        'description' => 'Update a label',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managecategories',
    ],

    'local_reblibrary_delete_label' => [
        'classname' => 'local_reblibrary\external\labels',
        'methodname' => 'delete',
        'classpath' => '',
        'description' => 'Delete a label',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:managecategories',
    ],

    // Resource label assignment web services.
    'local_reblibrary_get_resource_labels' => [
        'classname' => 'local_reblibrary\external\resource_labels',
        'methodname' => 'get_resource_labels',
        'classpath' => '',
        'description' => 'Get labels assigned to a resource',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:view',
    ],

    'local_reblibrary_assign_labels' => [
        'classname' => 'local_reblibrary\external\resource_labels',
        'methodname' => 'assign_labels',
        'classpath' => '',
        'description' => 'Assign labels to a resource',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reblibrary:manageresources',
    ],

];
