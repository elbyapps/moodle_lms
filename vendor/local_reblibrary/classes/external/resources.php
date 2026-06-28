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
 * External API for resources management.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reblibrary\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

/**
 * External API for resources CRUD operations.
 */
class resources extends external_api {

    /**
     * Returns description of get_all parameters.
     *
     * @return external_function_parameters
     */
    public static function get_all_parameters() {
        return new external_function_parameters([
            'search_query' => new external_value(PARAM_TEXT, 'Search query for title, author, or description', VALUE_DEFAULT, ''),
            'level_id' => new external_value(PARAM_INT, 'Filter by education level ID', VALUE_DEFAULT, 0),
            'sublevel_id' => new external_value(PARAM_INT, 'Filter by education sublevel ID', VALUE_DEFAULT, 0),
            'class_id' => new external_value(PARAM_INT, 'Filter by class ID', VALUE_DEFAULT, 0),
            'category_id' => new external_value(PARAM_INT, 'Filter by category ID', VALUE_DEFAULT, 0),
            'label_id' => new external_value(PARAM_INT, 'Filter by label ID', VALUE_DEFAULT, 0),
            'media_type' => new external_value(PARAM_TEXT, 'Filter by media type (text, audio, video)', VALUE_DEFAULT, ''),
            'page_context' => new external_value(PARAM_TEXT, 'Page context (home, reading-materials)', VALUE_DEFAULT, 'home'),
        ]);
    }

    /**
     * Get all resources with optional filters.
     *
     * @param string $searchquery Search query
     * @param int $levelid Level ID filter
     * @param int $sublevelid Sublevel ID filter
     * @param int $classid Class ID filter
     * @param int $categoryid Category ID filter
     * @param int $labelid Label ID filter
     * @param string $mediatype Media type filter
     * @param string $pagecontext Page context (home, reading-materials)
     * @return array List of resources
     */
    public static function get_all($searchquery = '', $levelid = 0, $sublevelid = 0, $classid = 0, $categoryid = 0, $labelid = 0, $mediatype = '', $pagecontext = 'home') {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_all_parameters(), [
            'search_query' => $searchquery,
            'level_id' => $levelid,
            'sublevel_id' => $sublevelid,
            'class_id' => $classid,
            'category_id' => $categoryid,
            'label_id' => $labelid,
            'media_type' => $mediatype,
            'page_context' => $pagecontext,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        // Build SQL query with filters (similar to index.php).
        $sql = "SELECT r.id, r.title, r.isbn, r.description, r.file_url, r.cover_image_url,
                       r.author_id, r.visible, r.media_type, r.created_at,
                       CONCAT(a.first_name, ' ', a.last_name) as author_name
                FROM {local_reblibrary_resources} r
                LEFT JOIN {local_reblibrary_authors} a ON r.author_id = a.id";

        $whereclauses = [];
        $sqlparams = [];

        // Apply class/sublevel/level filters via resource assignments.
        if ($params['class_id']) {
            $sql .= " INNER JOIN {local_reblibrary_res_assigns} ra ON r.id = ra.resource_id";
            $whereclauses[] = "ra.class_id = :classid";
            $sqlparams['classid'] = $params['class_id'];
        } else if ($params['sublevel_id']) {
            $sql .= " INNER JOIN {local_reblibrary_res_assigns} ra ON r.id = ra.resource_id
                      INNER JOIN {local_reblibrary_classes} c ON ra.class_id = c.id";
            $whereclauses[] = "c.sublevel_id = :sublevelid";
            $sqlparams['sublevelid'] = $params['sublevel_id'];
        } else if ($params['level_id']) {
            $sql .= " INNER JOIN {local_reblibrary_res_assigns} ra ON r.id = ra.resource_id
                      INNER JOIN {local_reblibrary_classes} c ON ra.class_id = c.id
                      INNER JOIN {local_reblibrary_edu_sublevels} s ON c.sublevel_id = s.id";
            $whereclauses[] = "s.level_id = :levelid";
            $sqlparams['levelid'] = $params['level_id'];
        }

        // Apply category filter.
        if ($params['category_id']) {
            $sql .= " INNER JOIN {local_reblibrary_res_categories} rc ON r.id = rc.resource_id";
            $whereclauses[] = "rc.category_id = :categoryid";
            $sqlparams['categoryid'] = $params['category_id'];
        }

        // Apply label filter.
        if ($params['label_id']) {
            $sql .= " INNER JOIN {local_reblibrary_res_labels} rl ON r.id = rl.resource_id";
            $whereclauses[] = "rl.label_id = :labelid";
            $sqlparams['labelid'] = $params['label_id'];
        }

        // Apply media type filter.
        if (!empty($params['media_type'])) {
            $whereclauses[] = "r.media_type = :mediatype";
            $sqlparams['mediatype'] = $params['media_type'];
        }

        // Apply search query filter.
        if (!empty($params['search_query'])) {
            $whereclauses[] = "(r.title LIKE :searchquery1 OR
                                CONCAT(a.first_name, ' ', a.last_name) LIKE :searchquery2 OR
                                r.description LIKE :searchquery3)";
            $searchpattern = '%' . $DB->sql_like_escape($params['search_query']) . '%';
            $sqlparams['searchquery1'] = $searchpattern;
            $sqlparams['searchquery2'] = $searchpattern;
            $sqlparams['searchquery3'] = $searchpattern;
        }

        // Apply visibility filter - only show public resources.
        $whereclauses[] = "r.visible = 1";

        // Apply label filtering based on page context and plugin settings.
        $labelprefix = ($params['page_context'] === 'reading-materials') ? 'readingmaterials' : 'homepage';
        $includelabels = get_config('local_reblibrary', $labelprefix . '_include_labels');
        $excludelabels = get_config('local_reblibrary', $labelprefix . '_exclude_labels');

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
            $sqlparams = array_merge($sqlparams, $inparams);
        }

        // Apply exclude filter (if configured) - uses NOT EXISTS for precedence.
        if (!empty($excludelabelids)) {
            list($exsql, $exparams) = $DB->get_in_or_equal($excludelabelids, SQL_PARAMS_NAMED, 'exclbl');
            $whereclauses[] = "NOT EXISTS (
                SELECT 1 FROM {local_reblibrary_res_labels} rl_exc
                WHERE rl_exc.resource_id = r.id
                AND rl_exc.label_id $exsql
            )";
            $sqlparams = array_merge($sqlparams, $exparams);
        }

        // Add WHERE clause if filters are applied.
        if (!empty($whereclauses)) {
            $sql .= " WHERE " . implode(' AND ', $whereclauses);
        }

        $sql .= " ORDER BY r.created_at DESC";

        $resources = $DB->get_records_sql($sql, $sqlparams);

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

        // Get label assignments for each resource.
        $labelassignments = $DB->get_records('local_reblibrary_res_labels', null, '', 'id, resource_id, label_id');
        $resourcelabels = [];
        foreach ($labelassignments as $assignment) {
            if (!isset($resourcelabels[$assignment->resource_id])) {
                $resourcelabels[$assignment->resource_id] = [];
            }
            $resourcelabels[$assignment->resource_id][] = $assignment->label_id;
        }

        // Build result with assignments.
        $result = [];
        foreach ($resources as $resource) {
            $result[] = [
                'id' => $resource->id,
                'title' => $resource->title,
                'isbn' => $resource->isbn ?? '',
                'author_id' => $resource->author_id,
                'author_name' => $resource->author_name ?? 'Unknown',
                'description' => $resource->description ?? '',
                'cover_image_url' => $resource->cover_image_url ?? '',
                'file_url' => $resource->file_url ?? '',
                'visible' => $resource->visible,
                'media_type' => $resource->media_type,
                'created_at' => $resource->created_at,
                'class_ids' => $resourceclasses[$resource->id] ?? [],
                'category_ids' => $resourcecategories[$resource->id] ?? [],
                'label_ids' => $resourcelabels[$resource->id] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Returns description of get_all return value.
     *
     * @return external_multiple_structure
     */
    public static function get_all_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Resource ID'),
                'title' => new external_value(PARAM_TEXT, 'Resource title'),
                'isbn' => new external_value(PARAM_TEXT, 'ISBN', VALUE_OPTIONAL),
                'author_id' => new external_value(PARAM_INT, 'Author ID'),
                'author_name' => new external_value(PARAM_TEXT, 'Author name', VALUE_OPTIONAL),
                'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
                'cover_image_url' => new external_value(PARAM_URL, 'Cover image URL', VALUE_OPTIONAL),
                'file_url' => new external_value(PARAM_URL, 'File URL', VALUE_OPTIONAL),
                'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)'),
                'media_type' => new external_value(PARAM_TEXT, 'Media type (text, audio, video)'),
                'created_at' => new external_value(PARAM_INT, 'Creation timestamp'),
                'class_ids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Class ID'),
                    'Assigned class IDs',
                    VALUE_OPTIONAL
                ),
                'category_ids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Category ID'),
                    'Assigned category IDs',
                    VALUE_OPTIONAL
                ),
                'label_ids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Label ID'),
                    'Assigned label IDs',
                    VALUE_OPTIONAL
                ),
            ])
        );
    }

    /**
     * Returns description of get_by_id parameters.
     *
     * @return external_function_parameters
     */
    public static function get_by_id_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Resource ID'),
        ]);
    }

    /**
     * Get resource by ID.
     *
     * @param int $id Resource ID
     * @return array Resource data
     */
    public static function get_by_id($id) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_by_id_parameters(), ['id' => $id]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        $resource = $DB->get_record('local_reblibrary_resources', ['id' => $params['id']], '*', MUST_EXIST);

        return [
            'id' => $resource->id,
            'title' => $resource->title,
            'isbn' => $resource->isbn,
            'author_id' => $resource->author_id,
            'description' => $resource->description,
            'cover_image_url' => $resource->cover_image_url,
            'file_url' => $resource->file_url,
            'visible' => $resource->visible,
            'media_type' => $resource->media_type,
            'created_at' => $resource->created_at,
        ];
    }

    /**
     * Returns description of get_by_id return value.
     *
     * @return external_single_structure
     */
    public static function get_by_id_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Resource ID'),
            'title' => new external_value(PARAM_TEXT, 'Resource title'),
            'isbn' => new external_value(PARAM_TEXT, 'ISBN', VALUE_OPTIONAL),
            'author_id' => new external_value(PARAM_INT, 'Author ID'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
            'cover_image_url' => new external_value(PARAM_URL, 'Cover image URL', VALUE_OPTIONAL),
            'file_url' => new external_value(PARAM_URL, 'File URL', VALUE_OPTIONAL),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)'),
            'media_type' => new external_value(PARAM_TEXT, 'Media type (text, audio, video)'),
            'created_at' => new external_value(PARAM_INT, 'Creation timestamp'),
        ]);
    }

    /**
     * Returns description of create parameters.
     *
     * @return external_function_parameters
     */
    public static function create_parameters() {
        return new external_function_parameters([
            'title' => new external_value(PARAM_TEXT, 'Resource title'),
            'isbn' => new external_value(PARAM_TEXT, 'ISBN', VALUE_OPTIONAL),
            'author_id' => new external_value(PARAM_INT, 'Author ID'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
            'cover_image_url' => new external_value(PARAM_URL, 'Cover image URL', VALUE_OPTIONAL),
            'file_url' => new external_value(PARAM_URL, 'File URL', VALUE_OPTIONAL),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)', VALUE_DEFAULT, 1),
            'media_type' => new external_value(PARAM_TEXT, 'Media type (text, audio, video)', VALUE_DEFAULT, 'text'),
        ]);
    }

    /**
     * Create a new resource.
     *
     * @param string $title
     * @param string $isbn
     * @param int $authorid
     * @param string $description
     * @param string $coverimageurl
     * @param string $fileurl
     * @param int $visible
     * @param string $mediatype
     * @return array Created resource
     */
    public static function create($title, $isbn = null, $authorid = 0, $description = null, $coverimageurl = null, $fileurl = null, $visible = 1, $mediatype = 'text') {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::create_parameters(), [
            'title' => $title,
            'isbn' => $isbn,
            'author_id' => $authorid,
            'description' => $description,
            'cover_image_url' => $coverimageurl,
            'file_url' => $fileurl,
            'visible' => $visible,
            'media_type' => $mediatype,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        $record = new \stdClass();
        $record->title = $params['title'];
        // Convert empty ISBN to NULL to avoid duplicate entry errors on unique constraint.
        $record->isbn = (!empty($params['isbn'])) ? $params['isbn'] : null;
        $record->author_id = $params['author_id'];
        $record->description = $params['description'] ?? null;
        $record->cover_image_url = $params['cover_image_url'] ?? null;
        $record->file_url = $params['file_url'] ?? null;
        $record->visible = $params['visible'];
        $record->media_type = $params['media_type'];
        $record->created_at = time();

        $id = $DB->insert_record('local_reblibrary_resources', $record);

        $resource = $DB->get_record('local_reblibrary_resources', ['id' => $id], '*', MUST_EXIST);

        return [
            'id' => $resource->id,
            'title' => $resource->title,
            'isbn' => $resource->isbn,
            'author_id' => $resource->author_id,
            'description' => $resource->description,
            'cover_image_url' => $resource->cover_image_url,
            'file_url' => $resource->file_url,
            'visible' => $resource->visible,
            'media_type' => $resource->media_type,
            'created_at' => $resource->created_at,
        ];
    }

    /**
     * Returns description of create return value.
     *
     * @return external_single_structure
     */
    public static function create_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Resource ID'),
            'title' => new external_value(PARAM_TEXT, 'Resource title'),
            'isbn' => new external_value(PARAM_TEXT, 'ISBN', VALUE_OPTIONAL),
            'author_id' => new external_value(PARAM_INT, 'Author ID'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
            'cover_image_url' => new external_value(PARAM_URL, 'Cover image URL', VALUE_OPTIONAL),
            'file_url' => new external_value(PARAM_URL, 'File URL', VALUE_OPTIONAL),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)'),
            'media_type' => new external_value(PARAM_TEXT, 'Media type (text, audio, video)'),
            'created_at' => new external_value(PARAM_INT, 'Creation timestamp'),
        ]);
    }

    /**
     * Returns description of update parameters.
     *
     * @return external_function_parameters
     */
    public static function update_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Resource ID'),
            'title' => new external_value(PARAM_TEXT, 'Resource title', VALUE_OPTIONAL),
            'isbn' => new external_value(PARAM_TEXT, 'ISBN', VALUE_OPTIONAL),
            'author_id' => new external_value(PARAM_INT, 'Author ID', VALUE_OPTIONAL),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
            'cover_image_url' => new external_value(PARAM_URL, 'Cover image URL', VALUE_OPTIONAL),
            'file_url' => new external_value(PARAM_URL, 'File URL', VALUE_OPTIONAL),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)', VALUE_OPTIONAL),
            'media_type' => new external_value(PARAM_TEXT, 'Media type (text, audio, video)', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Update a resource.
     *
     * @param int $id
     * @param string $title
     * @param string $isbn
     * @param int $authorid
     * @param string $description
     * @param string $coverimageurl
     * @param string $fileurl
     * @param int $visible
     * @param string $mediatype
     * @return array Updated resource
     */
    public static function update($id, $title = null, $isbn = null, $authorid = null, $description = null, $coverimageurl = null, $fileurl = null, $visible = null, $mediatype = null) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::update_parameters(), [
            'id' => $id,
            'title' => $title,
            'isbn' => $isbn,
            'author_id' => $authorid,
            'description' => $description,
            'cover_image_url' => $coverimageurl,
            'file_url' => $fileurl,
            'visible' => $visible,
            'media_type' => $mediatype,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        $resource = $DB->get_record('local_reblibrary_resources', ['id' => $params['id']], '*', MUST_EXIST);

        if (isset($params['title'])) {
            $resource->title = $params['title'];
        }
        if (isset($params['isbn'])) {
            // Convert empty ISBN to NULL to avoid duplicate entry errors on unique constraint.
            $resource->isbn = (!empty($params['isbn'])) ? $params['isbn'] : null;
        }
        if (isset($params['author_id'])) {
            $resource->author_id = $params['author_id'];
        }
        if (isset($params['description'])) {
            $resource->description = $params['description'];
        }
        if (isset($params['cover_image_url'])) {
            $resource->cover_image_url = $params['cover_image_url'];
        }
        if (isset($params['file_url'])) {
            $resource->file_url = $params['file_url'];
        }
        if (array_key_exists('visible', $params)) {
            $resource->visible = $params['visible'];
        }
        if (array_key_exists('media_type', $params)) {
            $resource->media_type = $params['media_type'];
        }

        $DB->update_record('local_reblibrary_resources', $resource);

        $updated = $DB->get_record('local_reblibrary_resources', ['id' => $params['id']], '*', MUST_EXIST);

        return [
            'id' => $updated->id,
            'title' => $updated->title,
            'isbn' => $updated->isbn,
            'author_id' => $updated->author_id,
            'description' => $updated->description,
            'cover_image_url' => $updated->cover_image_url,
            'file_url' => $updated->file_url,
            'visible' => $updated->visible,
            'media_type' => $updated->media_type,
            'created_at' => $updated->created_at,
        ];
    }

    /**
     * Returns description of update return value.
     *
     * @return external_single_structure
     */
    public static function update_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Resource ID'),
            'title' => new external_value(PARAM_TEXT, 'Resource title'),
            'isbn' => new external_value(PARAM_TEXT, 'ISBN', VALUE_OPTIONAL),
            'author_id' => new external_value(PARAM_INT, 'Author ID'),
            'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
            'cover_image_url' => new external_value(PARAM_URL, 'Cover image URL', VALUE_OPTIONAL),
            'file_url' => new external_value(PARAM_URL, 'File URL', VALUE_OPTIONAL),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)'),
            'media_type' => new external_value(PARAM_TEXT, 'Media type (text, audio, video)'),
            'created_at' => new external_value(PARAM_INT, 'Creation timestamp'),
        ]);
    }

    /**
     * Returns description of delete parameters.
     *
     * @return external_function_parameters
     */
    public static function delete_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Resource ID'),
        ]);
    }

    /**
     * Delete a resource.
     *
     * @param int $id Resource ID
     * @return array Success status
     */
    public static function delete($id) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::delete_parameters(), ['id' => $id]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        $DB->delete_records('local_reblibrary_resources', ['id' => $params['id']]);

        return ['success' => true];
    }

    /**
     * Returns description of delete return value.
     *
     * @return external_single_structure
     */
    public static function delete_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
        ]);
    }
}
