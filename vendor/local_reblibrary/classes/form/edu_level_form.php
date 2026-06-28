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
 * Education Level form for REB Library plugin.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reblibrary\form;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Form for adding/editing education levels.
 */
class edu_level_form extends \moodleform {

    /**
     * Define the form elements.
     */
    public function definition() {
        $mform = $this->_form;

        // Hidden field for ID (when editing).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // Level name field.
        $mform->addElement('text', 'level_name', get_string('form_level_name', 'local_reblibrary'), ['size' => '50']);
        $mform->setType('level_name', PARAM_TEXT);
        $mform->addRule('level_name', get_string('required'), 'required', null, 'client');
        $mform->addRule('level_name', get_string('maximumchars', '', 50), 'maxlength', 50, 'client');
        $mform->addHelpButton('level_name', 'form_level_name', 'local_reblibrary');

        // Action buttons.
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validate the form data.
     *
     * @param array $data Form data
     * @param array $files Uploaded files
     * @return array Validation errors
     */
    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);

        // Check for duplicate level name (excluding current record if editing).
        $params = ['level_name' => $data['level_name']];
        $sql = "SELECT id FROM {local_reblibrary_edu_levels} WHERE level_name = :level_name";

        if (!empty($data['id'])) {
            $sql .= " AND id != :id";
            $params['id'] = $data['id'];
        }

        if ($DB->record_exists_sql($sql, $params)) {
            $errors['level_name'] = get_string('error_duplicate_level_name', 'local_reblibrary');
        }

        return $errors;
    }
}
