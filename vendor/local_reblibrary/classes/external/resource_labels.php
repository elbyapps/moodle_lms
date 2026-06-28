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
 * External API for resource-label assignment management.
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
 * External API for resource-label assignments.
 */
class resource_labels extends external_api {

    /**
     * Returns description of get_resource_labels() parameters.
     *
     * @return external_function_parameters
     */
    public static function get_resource_labels_parameters() {
        return new external_function_parameters([
            'resource_id' => new external_value(PARAM_INT, 'Resource ID'),
        ]);
    }

    /**
     * Get labels assigned to a resource.
     *
     * @param int $resourceid Resource ID
     * @return array List of label IDs
     */
    public static function get_resource_labels($resourceid) {
        global $DB;

        $params = self::validate_parameters(self::get_resource_labels_parameters(), [
            'resource_id' => $resourceid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:view', $context);

        // Verify resource exists.
        $DB->get_record('local_reblibrary_resources', ['id' => $params['resource_id']], '*', MUST_EXIST);

        $assignments = $DB->get_records('local_reblibrary_res_labels', ['resource_id' => $params['resource_id']]);

        $labelids = [];
        foreach ($assignments as $assignment) {
            $labelids[] = $assignment->label_id;
        }

        return ['label_ids' => $labelids];
    }

    /**
     * Returns description of get_resource_labels return value.
     *
     * @return external_single_structure
     */
    public static function get_resource_labels_returns() {
        return new external_single_structure([
            'label_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Label ID'),
                'Assigned label IDs'
            ),
        ]);
    }

    /**
     * Returns description of assign_labels() parameters.
     *
     * @return external_function_parameters
     */
    public static function assign_labels_parameters() {
        return new external_function_parameters([
            'resource_id' => new external_value(PARAM_INT, 'Resource ID'),
            'label_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Label ID'),
                'Array of label IDs to assign'
            ),
        ]);
    }

    /**
     * Assign labels to a resource (replaces existing assignments).
     *
     * @param int $resourceid Resource ID
     * @param array $labelids Array of label IDs
     * @return array Success status
     */
    public static function assign_labels($resourceid, $labelids) {
        global $DB;

        $params = self::validate_parameters(self::assign_labels_parameters(), [
            'resource_id' => $resourceid,
            'label_ids' => $labelids,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/reblibrary:manageresources', $context);

        // Verify resource exists.
        $DB->get_record('local_reblibrary_resources', ['id' => $params['resource_id']], '*', MUST_EXIST);

        // Delete existing assignments.
        $DB->delete_records('local_reblibrary_res_labels', ['resource_id' => $params['resource_id']]);

        // Insert new assignments.
        foreach ($params['label_ids'] as $labelid) {
            // Verify label exists.
            if (!$DB->record_exists('local_reblibrary_labels', ['id' => $labelid])) {
                throw new \moodle_exception('invalidlabelid', 'local_reblibrary');
            }

            $record = new \stdClass();
            $record->resource_id = $params['resource_id'];
            $record->label_id = $labelid;

            $DB->insert_record('local_reblibrary_res_labels', $record);
        }

        return ['success' => true];
    }

    /**
     * Returns description of assign_labels return value.
     *
     * @return external_single_structure
     */
    public static function assign_labels_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
        ]);
    }
}
