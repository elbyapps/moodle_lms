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
 * External API for authors management.
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
 * External API for authors CRUD operations.
 */
class authors extends external_api {

    /**
     * Returns description of get_all parameters.
     *
     * @return external_function_parameters
     */
    public static function get_all_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Get all authors.
     *
     * @return array List of authors
     */
    public static function get_all() {
        global $DB;

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:view', $context);

        $authors = $DB->get_records('local_reblibrary_authors', null, 'last_name ASC, first_name ASC');

        $result = [];
        foreach ($authors as $author) {
            $result[] = [
                'id' => $author->id,
                'first_name' => $author->first_name,
                'last_name' => $author->last_name,
                'bio' => $author->bio,
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
                'id' => new external_value(PARAM_INT, 'Author ID'),
                'first_name' => new external_value(PARAM_TEXT, 'First name'),
                'last_name' => new external_value(PARAM_TEXT, 'Last name'),
                'bio' => new external_value(PARAM_RAW, 'Bio', VALUE_OPTIONAL),
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
            'id' => new external_value(PARAM_INT, 'Author ID'),
        ]);
    }

    /**
     * Get author by ID.
     *
     * @param int $id Author ID
     * @return array Author data
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

        $author = $DB->get_record('local_reblibrary_authors', ['id' => $params['id']], '*', MUST_EXIST);

        return [
            'id' => $author->id,
            'first_name' => $author->first_name,
            'last_name' => $author->last_name,
            'bio' => $author->bio,
        ];
    }

    /**
     * Returns description of get_by_id return value.
     *
     * @return external_single_structure
     */
    public static function get_by_id_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Author ID'),
            'first_name' => new external_value(PARAM_TEXT, 'First name'),
            'last_name' => new external_value(PARAM_TEXT, 'Last name'),
            'bio' => new external_value(PARAM_RAW, 'Bio', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Returns description of create parameters.
     *
     * @return external_function_parameters
     */
    public static function create_parameters() {
        return new external_function_parameters([
            'first_name' => new external_value(PARAM_TEXT, 'First name'),
            'last_name' => new external_value(PARAM_TEXT, 'Last name'),
            'bio' => new external_value(PARAM_RAW, 'Bio', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Create a new author.
     *
     * @param string $firstname
     * @param string $lastname
     * @param string $bio
     * @return array Created author
     */
    public static function create($firstname, $lastname, $bio = null) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::create_parameters(), [
            'first_name' => $firstname,
            'last_name' => $lastname,
            'bio' => $bio,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        $record = new \stdClass();
        $record->first_name = $params['first_name'];
        $record->last_name = $params['last_name'];
        $record->bio = $params['bio'] ?? null;

        $id = $DB->insert_record('local_reblibrary_authors', $record);

        $author = $DB->get_record('local_reblibrary_authors', ['id' => $id], '*', MUST_EXIST);

        return [
            'id' => $author->id,
            'first_name' => $author->first_name,
            'last_name' => $author->last_name,
            'bio' => $author->bio,
        ];
    }

    /**
     * Returns description of create return value.
     *
     * @return external_single_structure
     */
    public static function create_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Author ID'),
            'first_name' => new external_value(PARAM_TEXT, 'First name'),
            'last_name' => new external_value(PARAM_TEXT, 'Last name'),
            'bio' => new external_value(PARAM_RAW, 'Bio', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Returns description of update parameters.
     *
     * @return external_function_parameters
     */
    public static function update_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Author ID'),
            'first_name' => new external_value(PARAM_TEXT, 'First name', VALUE_OPTIONAL),
            'last_name' => new external_value(PARAM_TEXT, 'Last name', VALUE_OPTIONAL),
            'bio' => new external_value(PARAM_RAW, 'Bio', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Update an author.
     *
     * @param int $id
     * @param string $firstname
     * @param string $lastname
     * @param string $bio
     * @return array Updated author
     */
    public static function update($id, $firstname = null, $lastname = null, $bio = null) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::update_parameters(), [
            'id' => $id,
            'first_name' => $firstname,
            'last_name' => $lastname,
            'bio' => $bio,
        ]);

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check capability.
        require_capability('local/reblibrary:manageresources', $context);

        $author = $DB->get_record('local_reblibrary_authors', ['id' => $params['id']], '*', MUST_EXIST);

        if (isset($params['first_name'])) {
            $author->first_name = $params['first_name'];
        }
        if (isset($params['last_name'])) {
            $author->last_name = $params['last_name'];
        }
        if (isset($params['bio'])) {
            $author->bio = $params['bio'];
        }

        $DB->update_record('local_reblibrary_authors', $author);

        $updated = $DB->get_record('local_reblibrary_authors', ['id' => $params['id']], '*', MUST_EXIST);

        return [
            'id' => $updated->id,
            'first_name' => $updated->first_name,
            'last_name' => $updated->last_name,
            'bio' => $updated->bio,
        ];
    }

    /**
     * Returns description of update return value.
     *
     * @return external_single_structure
     */
    public static function update_returns() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Author ID'),
            'first_name' => new external_value(PARAM_TEXT, 'First name'),
            'last_name' => new external_value(PARAM_TEXT, 'Last name'),
            'bio' => new external_value(PARAM_RAW, 'Bio', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Returns description of delete parameters.
     *
     * @return external_function_parameters
     */
    public static function delete_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Author ID'),
        ]);
    }

    /**
     * Delete an author.
     *
     * @param int $id Author ID
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

        $DB->delete_records('local_reblibrary_authors', ['id' => $params['id']]);

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
