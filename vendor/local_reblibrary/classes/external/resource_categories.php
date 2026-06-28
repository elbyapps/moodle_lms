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
 * External API for resource category assignments.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
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
 * External API for resource category assignments.
 */
class resource_categories extends external_api {

    /**
     * Returns description of get_all_categories parameters.
     *
     * @return external_function_parameters
     */
    public static function get_all_categories_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Get all categories with parent information.
     *
     * @return array List of categories
     */
    public static function get_all_categories() {
        global $DB;

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        $sql = "SELECT c.id, c.category_name, c.parent_category_id, c.description,
                       p.category_name as parent_name
                FROM {local_reblibrary_categories} c
                LEFT JOIN {local_reblibrary_categories} p ON c.parent_category_id = p.id
                ORDER BY COALESCE(p.category_name, c.category_name), c.category_name";

        $categories = $DB->get_records_sql($sql);

        $result = [];
        foreach ($categories as $category) {
            $result[] = [
                'id' => $category->id,
                'category_name' => $category->category_name,
                'parent_category_id' => $category->parent_category_id,
                'parent_name' => $category->parent_name,
                'description' => $category->description,
            ];
        }

        return $result;
    }

    /**
     * Returns description of get_all_categories return value.
     *
     * @return external_multiple_structure
     */
    public static function get_all_categories_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category ID'),
                'category_name' => new external_value(PARAM_TEXT, 'Category name'),
                'parent_category_id' => new external_value(PARAM_INT, 'Parent category ID', VALUE_OPTIONAL),
                'parent_name' => new external_value(PARAM_TEXT, 'Parent category name', VALUE_OPTIONAL),
                'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
            ])
        );
    }

    /**
     * Returns description of get_resource_categories parameters.
     *
     * @return external_function_parameters
     */
    public static function get_resource_categories_parameters() {
        return new external_function_parameters([
            'resource_id' => new external_value(PARAM_INT, 'Resource ID'),
        ]);
    }

    /**
     * Get categories assigned to a resource.
     *
     * @param int $resourceid Resource ID
     * @return array Category IDs
     */
    public static function get_resource_categories($resourceid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_resource_categories_parameters(), [
            'resource_id' => $resourceid
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        $assignments = $DB->get_records('local_reblibrary_res_categories', [
            'resource_id' => $params['resource_id']
        ], '', 'category_id');

        $categoryids = [];
        foreach ($assignments as $assignment) {
            $categoryids[] = $assignment->category_id;
        }

        return ['category_ids' => $categoryids];
    }

    /**
     * Returns description of get_resource_categories return value.
     *
     * @return external_single_structure
     */
    public static function get_resource_categories_returns() {
        return new external_single_structure([
            'category_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Category ID')
            ),
        ]);
    }

    /**
     * Returns description of assign_categories parameters.
     *
     * @return external_function_parameters
     */
    public static function assign_categories_parameters() {
        return new external_function_parameters([
            'resource_id' => new external_value(PARAM_INT, 'Resource ID'),
            'category_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Category ID')
            ),
        ]);
    }

    /**
     * Assign categories to a resource.
     *
     * @param int $resourceid Resource ID
     * @param array $categoryids Array of category IDs
     * @return array Success status
     */
    public static function assign_categories($resourceid, $categoryids) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::assign_categories_parameters(), [
            'resource_id' => $resourceid,
            'category_ids' => $categoryids,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        // Delete existing assignments.
        $DB->delete_records('local_reblibrary_res_categories', [
            'resource_id' => $params['resource_id']
        ]);

        // Insert new assignments.
        foreach ($params['category_ids'] as $categoryid) {
            $record = new \stdClass();
            $record->resource_id = $params['resource_id'];
            $record->category_id = $categoryid;
            $record->created_at = time();

            $DB->insert_record('local_reblibrary_res_categories', $record);
        }

        return ['success' => true];
    }

    /**
     * Returns description of assign_categories return value.
     *
     * @return external_single_structure
     */
    public static function assign_categories_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
        ]);
    }
}
