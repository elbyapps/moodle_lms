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
 * External API for resource assignments management.
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
 * External API for resource assignments to classes and sections.
 */
class assignments extends external_api {

    /**
     * Returns description of get_classes parameters.
     *
     * @return external_function_parameters
     */
    public static function get_classes_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Get all classes with sublevel information.
     *
     * @return array List of classes
     */
    public static function get_classes() {
        global $DB;

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        $sql = "SELECT c.id, c.class_name, c.class_code, c.sublevel_id,
                       s.sublevel_name, l.level_name
                FROM {local_reblibrary_classes} c
                JOIN {local_reblibrary_edu_sublevels} s ON c.sublevel_id = s.id
                JOIN {local_reblibrary_edu_levels} l ON s.level_id = l.id
                ORDER BY l.level_name, s.sublevel_name, c.class_name";

        $classes = $DB->get_records_sql($sql);

        $result = [];
        foreach ($classes as $class) {
            $result[] = [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'class_code' => $class->class_code,
                'sublevel_id' => $class->sublevel_id,
                'sublevel_name' => $class->sublevel_name,
                'level_name' => $class->level_name,
            ];
        }

        return $result;
    }

    /**
     * Returns description of get_classes return value.
     *
     * @return external_multiple_structure
     */
    public static function get_classes_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Class ID'),
                'class_name' => new external_value(PARAM_TEXT, 'Class name'),
                'class_code' => new external_value(PARAM_TEXT, 'Class code'),
                'sublevel_id' => new external_value(PARAM_INT, 'Sublevel ID'),
                'sublevel_name' => new external_value(PARAM_TEXT, 'Sublevel name'),
                'level_name' => new external_value(PARAM_TEXT, 'Level name'),
            ])
        );
    }

    /**
     * Returns description of get_sections parameters.
     *
     * @return external_function_parameters
     */
    public static function get_sections_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Get all sections with sublevel information.
     *
     * @return array List of sections
     */
    public static function get_sections() {
        global $DB;

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        $sql = "SELECT sec.id, sec.section_name, sec.section_code, sec.sublevel_id,
                       s.sublevel_name, l.level_name
                FROM {local_reblibrary_sections} sec
                JOIN {local_reblibrary_edu_sublevels} s ON sec.sublevel_id = s.id
                JOIN {local_reblibrary_edu_levels} l ON s.level_id = l.id
                ORDER BY l.level_name, s.sublevel_name, sec.section_name";

        $sections = $DB->get_records_sql($sql);

        $result = [];
        foreach ($sections as $section) {
            $result[] = [
                'id' => $section->id,
                'section_name' => $section->section_name,
                'section_code' => $section->section_code,
                'sublevel_id' => $section->sublevel_id,
                'sublevel_name' => $section->sublevel_name,
                'level_name' => $section->level_name,
            ];
        }

        return $result;
    }

    /**
     * Returns description of get_sections return value.
     *
     * @return external_multiple_structure
     */
    public static function get_sections_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Section ID'),
                'section_name' => new external_value(PARAM_TEXT, 'Section name'),
                'section_code' => new external_value(PARAM_TEXT, 'Section code'),
                'sublevel_id' => new external_value(PARAM_INT, 'Sublevel ID'),
                'sublevel_name' => new external_value(PARAM_TEXT, 'Sublevel name'),
                'level_name' => new external_value(PARAM_TEXT, 'Level name'),
            ])
        );
    }

    /**
     * Returns description of get_resource_assignments parameters.
     *
     * @return external_function_parameters
     */
    public static function get_resource_assignments_parameters() {
        return new external_function_parameters([
            'resource_id' => new external_value(PARAM_INT, 'Resource ID'),
        ]);
    }

    /**
     * Get current assignments for a resource.
     *
     * @param int $resourceid Resource ID
     * @return array Assignment data
     */
    public static function get_resource_assignments($resourceid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_resource_assignments_parameters(), [
            'resource_id' => $resourceid
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        // Get class assignments.
        $classassignments = $DB->get_records('local_reblibrary_res_assigns', [
            'resource_id' => $params['resource_id'],
        ], '', 'class_id, section_id');

        $classids = [];
        $sectionids = [];

        foreach ($classassignments as $assignment) {
            if (!empty($assignment->class_id)) {
                $classids[] = $assignment->class_id;
            }
            if (!empty($assignment->section_id)) {
                $sectionids[] = $assignment->section_id;
            }
        }

        return [
            'class_ids' => array_values(array_unique($classids)),
            'section_ids' => array_values(array_unique($sectionids)),
        ];
    }

    /**
     * Returns description of get_resource_assignments return value.
     *
     * @return external_single_structure
     */
    public static function get_resource_assignments_returns() {
        return new external_single_structure([
            'class_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Class ID')
            ),
            'section_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Section ID')
            ),
        ]);
    }

    /**
     * Returns description of assign_to_classes parameters.
     *
     * @return external_function_parameters
     */
    public static function assign_to_classes_parameters() {
        return new external_function_parameters([
            'resource_id' => new external_value(PARAM_INT, 'Resource ID'),
            'class_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Class ID')
            ),
        ]);
    }

    /**
     * Assign resource to classes.
     *
     * @param int $resourceid Resource ID
     * @param array $classids Array of class IDs
     * @return array Success status
     */
    public static function assign_to_classes($resourceid, $classids) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::assign_to_classes_parameters(), [
            'resource_id' => $resourceid,
            'class_ids' => $classids,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        // Delete existing class assignments.
        $DB->delete_records_select(
            'local_reblibrary_res_assigns',
            'resource_id = :resource_id AND class_id IS NOT NULL',
            ['resource_id' => $params['resource_id']]
        );

        // Insert new assignments.
        foreach ($params['class_ids'] as $classid) {
            $record = new \stdClass();
            $record->resource_id = $params['resource_id'];
            $record->class_id = $classid;
            $record->section_id = null;
            $record->created_at = time();

            $DB->insert_record('local_reblibrary_res_assigns', $record);
        }

        return ['success' => true];
    }

    /**
     * Returns description of assign_to_classes return value.
     *
     * @return external_single_structure
     */
    public static function assign_to_classes_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
        ]);
    }

    /**
     * Returns description of assign_to_sections parameters.
     *
     * @return external_function_parameters
     */
    public static function assign_to_sections_parameters() {
        return new external_function_parameters([
            'resource_id' => new external_value(PARAM_INT, 'Resource ID'),
            'section_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Section ID')
            ),
        ]);
    }

    /**
     * Assign resource to sections.
     *
     * @param int $resourceid Resource ID
     * @param array $sectionids Array of section IDs
     * @return array Success status
     */
    public static function assign_to_sections($resourceid, $sectionids) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::assign_to_sections_parameters(), [
            'resource_id' => $resourceid,
            'section_ids' => $sectionids,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        // Delete existing section assignments.
        $DB->delete_records_select(
            'local_reblibrary_res_assigns',
            'resource_id = :resource_id AND section_id IS NOT NULL',
            ['resource_id' => $params['resource_id']]
        );

        // Insert new assignments.
        foreach ($params['section_ids'] as $sectionid) {
            $record = new \stdClass();
            $record->resource_id = $params['resource_id'];
            $record->class_id = null;
            $record->section_id = $sectionid;
            $record->created_at = time();

            $DB->insert_record('local_reblibrary_res_assigns', $record);
        }

        return ['success' => true];
    }

    /**
     * Returns description of assign_to_sections return value.
     *
     * @return external_single_structure
     */
    public static function assign_to_sections_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
        ]);
    }
}
