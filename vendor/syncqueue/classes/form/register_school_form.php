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

namespace local_syncqueue\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for registering a new school.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register_school_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'schooldetails', get_string('schooldetails', 'local_syncqueue'));

        // School ID.
        $mform->addElement('text', 'schoolid', get_string('schoolid', 'local_syncqueue'), ['size' => 50]);
        $mform->setType('schoolid', PARAM_ALPHANUMEXT);
        $mform->addRule('schoolid', get_string('required'), 'required', null, 'client');
        $mform->addRule('schoolid', get_string('maximumchars', '', 50), 'maxlength', 50, 'client');
        $mform->addHelpButton('schoolid', 'schoolid', 'local_syncqueue');

        // School name.
        $mform->addElement('text', 'name', get_string('schoolname', 'local_syncqueue'), ['size' => 100]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Contact email.
        $mform->addElement('text', 'contactemail', get_string('contactemail', 'local_syncqueue'), ['size' => 100]);
        $mform->setType('contactemail', PARAM_EMAIL);

        // Description.
        $mform->addElement('textarea', 'description', get_string('description'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('registerschool', 'local_syncqueue'));
    }

    /**
     * Validate form data.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Check if school ID is unique.
        $schoolmanager = new \local_syncqueue\school_manager();
        if ($schoolmanager->school_exists($data['schoolid'])) {
            $errors['schoolid'] = get_string('schoolidexists', 'local_syncqueue');
        }

        // Validate email if provided.
        if (!empty($data['contactemail']) && !validate_email($data['contactemail'])) {
            $errors['contactemail'] = get_string('invalidemail');
        }

        return $errors;
    }
}
