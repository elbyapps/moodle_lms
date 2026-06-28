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
 * External API for category management.
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
use context_system;

/**
 * External API for resource categories.
 */
class categories extends external_api {

    /**
     * Returns description of create_category() parameters.
     *
     * @return external_function_parameters
     */
    public static function create_category_parameters() {
        return new external_function_parameters([
            'category_name' => new external_value(PARAM_TEXT, 'Category name'),
            'parent_category_id' => new external_value(PARAM_INT, 'Parent category ID (0 for top-level)', VALUE_DEFAULT, null),
            'description' => new external_value(PARAM_TEXT, 'Category description', VALUE_DEFAULT, ''),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Create a new category.
     *
     * @param string $categoryname Category name
     * @param int|null $parentcategoryid Parent category ID
     * @param string $description Description
     * @param int $visible Visibility
     * @return array Category data
     */
    public static function create_category($categoryname, $parentcategoryid, $description, $visible = 1) {
        global $DB;

        $params = self::validate_parameters(self::create_category_parameters(), [
            'category_name' => $categoryname,
            'parent_category_id' => $parentcategoryid,
            'description' => $description,
            'visible' => $visible,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:managecategories', $context);

        // Validate parent category exists if provided.
        if ($params['parent_category_id']) {
            if (!$DB->record_exists('local_reblibrary_categories', ['id' => $params['parent_category_id']])) {
                throw new \moodle_exception('invalidparentcategory', 'local_reblibrary');
            }
        }

        $record = new \stdClass();
        $record->category_name = trim($params['category_name']);
        $record->parent_category_id = $params['parent_category_id'] ?: null;
        $record->description = trim($params['description']);
        $record->visible = $params['visible'];

        $id = $DB->insert_record('local_reblibrary_categories', $record);

        return [
            'id' => $id,
            'category_name' => $record->category_name,
            'parent_category_id' => $record->parent_category_id,
            'description' => $record->description,
            'visible' => $record->visible,
        ];
    }

    /**
     * Returns description of create_category() return value.
     *
     * @return external_single_structure
     */
    public static function create_category_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Category ID'),
            'category_name' => new external_value(PARAM_TEXT, 'Category name'),
            'parent_category_id' => new external_value(PARAM_INT, 'Parent category ID', VALUE_OPTIONAL),
            'description' => new external_value(PARAM_TEXT, 'Description'),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)'),
        ]);
    }

    /**
     * Returns description of update_category() parameters.
     *
     * @return external_function_parameters
     */
    public static function update_category_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Category ID'),
            'category_name' => new external_value(PARAM_TEXT, 'Category name'),
            'parent_category_id' => new external_value(PARAM_INT, 'Parent category ID', VALUE_DEFAULT, null),
            'description' => new external_value(PARAM_TEXT, 'Category description', VALUE_DEFAULT, ''),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Update an existing category.
     *
     * @param int $id Category ID
     * @param string $categoryname Category name
     * @param int|null $parentcategoryid Parent category ID
     * @param string $description Description
     * @param int $visible Visibility
     * @return array Updated category data
     */
    public static function update_category($id, $categoryname, $parentcategoryid, $description, $visible = null) {
        global $DB;

        $params = self::validate_parameters(self::update_category_parameters(), [
            'id' => $id,
            'category_name' => $categoryname,
            'parent_category_id' => $parentcategoryid,
            'description' => $description,
            'visible' => $visible,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:managecategories', $context);

        $record = $DB->get_record('local_reblibrary_categories', ['id' => $params['id']], '*', MUST_EXIST);

        // Prevent circular references.
        if ($params['parent_category_id'] && $params['parent_category_id'] == $params['id']) {
            throw new \moodle_exception('cannotbeparentofself', 'local_reblibrary');
        }

        // Validate parent category exists if provided.
        if ($params['parent_category_id']) {
            if (!$DB->record_exists('local_reblibrary_categories', ['id' => $params['parent_category_id']])) {
                throw new \moodle_exception('invalidparentcategory', 'local_reblibrary');
            }
        }

        $record->category_name = trim($params['category_name']);
        $record->parent_category_id = $params['parent_category_id'] ?: null;
        $record->description = trim($params['description']);
        if (isset($params['visible'])) {
            $record->visible = $params['visible'];
        }

        $DB->update_record('local_reblibrary_categories', $record);

        return [
            'id' => $record->id,
            'category_name' => $record->category_name,
            'parent_category_id' => $record->parent_category_id,
            'description' => $record->description,
            'visible' => $record->visible,
        ];
    }

    /**
     * Returns description of update_category() return value.
     *
     * @return external_single_structure
     */
    public static function update_category_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Category ID'),
            'category_name' => new external_value(PARAM_TEXT, 'Category name'),
            'parent_category_id' => new external_value(PARAM_INT, 'Parent category ID', VALUE_OPTIONAL),
            'description' => new external_value(PARAM_TEXT, 'Description'),
            'visible' => new external_value(PARAM_INT, 'Visibility (1=visible, 0=hidden)'),
        ]);
    }

    /**
     * Returns description of delete_category() parameters.
     *
     * @return external_function_parameters
     */
    public static function delete_category_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Category ID'),
        ]);
    }

    /**
     * Delete a category.
     *
     * @param int $id Category ID
     * @return array Success status
     */
    public static function delete_category($id) {
        global $DB;

        $params = self::validate_parameters(self::delete_category_parameters(), ['id' => $id]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:managecategories', $context);

        $record = $DB->get_record('local_reblibrary_categories', ['id' => $params['id']], '*', MUST_EXIST);

        // Check if category has children.
        $haschildren = $DB->record_exists('local_reblibrary_categories', ['parent_category_id' => $params['id']]);
        if ($haschildren) {
            throw new \moodle_exception('categoryhaschildren', 'local_reblibrary');
        }

        $DB->delete_records('local_reblibrary_categories', ['id' => $params['id']]);

        return ['success' => true];
    }

    /**
     * Returns description of delete_category() return value.
     *
     * @return external_single_structure
     */
    public static function delete_category_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
        ]);
    }
}
