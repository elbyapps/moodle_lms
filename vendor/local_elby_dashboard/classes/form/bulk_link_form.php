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
 * Bulk SDMS link upload form.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class bulk_link_form extends \moodleform {

    /**
     * Define the form elements.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'uploadheader', get_string('bulk_link_upload_header', 'local_elby_dashboard'));

        // CSV file upload.
        $mform->addElement('filepicker', 'csvfile', get_string('bulk_link_csvfile', 'local_elby_dashboard'), null, [
            'maxbytes' => 1048576, // 1MB.
            'accepted_types' => ['.csv'],
        ]);
        $mform->addRule('csvfile', null, 'required');

        // Delimiter selector.
        $delimiters = [
            'comma' => get_string('bulk_link_delimiter_comma', 'local_elby_dashboard'),
            'semicolon' => get_string('bulk_link_delimiter_semicolon', 'local_elby_dashboard'),
            'tab' => get_string('bulk_link_delimiter_tab', 'local_elby_dashboard'),
        ];
        $mform->addElement('select', 'delimiter', get_string('bulk_link_delimiter', 'local_elby_dashboard'), $delimiters);
        $mform->setDefault('delimiter', 'comma');

        // Help text.
        $mform->addElement('static', 'csvhelp', '',
            get_string('bulk_link_csv_help', 'local_elby_dashboard'));

        $this->add_action_buttons(true, get_string('bulk_link_upload_btn', 'local_elby_dashboard'));
    }
}
