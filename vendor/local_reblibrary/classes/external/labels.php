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
 * External API for label management.
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
 * External API for resource labels.
 */
class labels extends external_api {

    /**
     * Returns description of get_all_labels() parameters.
     *
     * @return external_function_parameters
     */
    public static function get_all_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Get all labels.
     *
     * @return array List of labels
     */
    public static function get_all() {
        global $DB;

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        $labels = $DB->get_records('local_reblibrary_labels', null, 'label_name ASC');

        $result = [];
        foreach ($labels as $label) {
            $result[] = [
                'id' => $label->id,
                'label_name' => $label->label_name,
                'description' => $label->description ?? '',
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
                'id' => new external_value(PARAM_INT, 'Label ID'),
                'label_name' => new external_value(PARAM_TEXT, 'Label name'),
                'description' => new external_value(PARAM_TEXT, 'Description', VALUE_OPTIONAL),
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
            'id' => new external_value(PARAM_INT, 'Label ID'),
        ]);
    }

    /**
     * Get label by ID.
     *
     * @param int $id Label ID
     * @return array Label data
     */
    public static function get_by_id($id) {
        global $DB;

        $params = self::validate_parameters(self::get_by_id_parameters(), ['id' => $id]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:view', $context);

        $label = $DB->get_record('local_reblibrary_labels', ['id' => $params['id']], '*', MUST_EXIST);

        return [
            'id' => $label->id,
            'label_name' => $label->label_name,
            'description' => $label->description ?? '',
        ];
    }

    /**
     * Returns description of get_by_id return value.
     *
     * @return external_single_structure
     */
    public static function get_by_id_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Label ID'),
            'label_name' => new external_value(PARAM_TEXT, 'Label name'),
            'description' => new external_value(PARAM_TEXT, 'Description', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Returns description of create_label() parameters.
     *
     * @return external_function_parameters
     */
    public static function create_parameters() {
        return new external_function_parameters([
            'label_name' => new external_value(PARAM_TEXT, 'Label name'),
            'description' => new external_value(PARAM_TEXT, 'Label description', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Create a new label.
     *
     * @param string $labelname Label name
     * @param string $description Description
     * @return array Label data
     */
    public static function create($labelname, $description = '') {
        global $DB;

        $params = self::validate_parameters(self::create_parameters(), [
            'label_name' => $labelname,
            'description' => $description,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:managecategories', $context);

        $record = new \stdClass();
        $record->label_name = trim($params['label_name']);
        $record->description = trim($params['description']);

        $id = $DB->insert_record('local_reblibrary_labels', $record);

        return [
            'id' => $id,
            'label_name' => $record->label_name,
            'description' => $record->description,
        ];
    }

    /**
     * Returns description of create return value.
     *
     * @return external_single_structure
     */
    public static function create_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Label ID'),
            'label_name' => new external_value(PARAM_TEXT, 'Label name'),
            'description' => new external_value(PARAM_TEXT, 'Description'),
        ]);
    }

    /**
     * Returns description of update() parameters.
     *
     * @return external_function_parameters
     */
    public static function update_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Label ID'),
            'label_name' => new external_value(PARAM_TEXT, 'Label name'),
            'description' => new external_value(PARAM_TEXT, 'Label description', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Update an existing label.
     *
     * @param int $id Label ID
     * @param string $labelname Label name
     * @param string $description Description
     * @return array Updated label data
     */
    public static function update($id, $labelname, $description = '') {
        global $DB;

        $params = self::validate_parameters(self::update_parameters(), [
            'id' => $id,
            'label_name' => $labelname,
            'description' => $description,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:managecategories', $context);

        $record = $DB->get_record('local_reblibrary_labels', ['id' => $params['id']], '*', MUST_EXIST);

        $record->label_name = trim($params['label_name']);
        $record->description = trim($params['description']);

        $DB->update_record('local_reblibrary_labels', $record);

        return [
            'id' => $record->id,
            'label_name' => $record->label_name,
            'description' => $record->description,
        ];
    }

    /**
     * Returns description of update return value.
     *
     * @return external_single_structure
     */
    public static function update_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Label ID'),
            'label_name' => new external_value(PARAM_TEXT, 'Label name'),
            'description' => new external_value(PARAM_TEXT, 'Description'),
        ]);
    }

    /**
     * Returns description of delete() parameters.
     *
     * @return external_function_parameters
     */
    public static function delete_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Label ID'),
        ]);
    }

    /**
     * Delete a label.
     *
     * @param int $id Label ID
     * @return array Success status
     */
    public static function delete($id) {
        global $DB;

        $params = self::validate_parameters(self::delete_parameters(), ['id' => $id]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:managecategories', $context);

        $DB->delete_records('local_reblibrary_labels', ['id' => $params['id']]);

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
