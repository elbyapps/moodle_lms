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

namespace local_syncqueue;

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting that includes JavaScript module.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_jsinclude extends \admin_setting {

    /** @var string AMD module name */
    protected $module;

    /** @var string Function to call */
    protected $function;

    /**
     * Constructor.
     *
     * @param string $name Unique setting name.
     * @param string $module AMD module name.
     * @param string $function Function to call.
     */
    public function __construct($name, $module, $function = 'init') {
        parent::__construct($name, '', '', '');
        $this->module = $module;
        $this->function = $function;
    }

    /**
     * Return the setting value.
     *
     * @return mixed Always returns true (setting not stored).
     */
    public function get_setting() {
        return true;
    }

    /**
     * Write the setting - not stored.
     *
     * @param mixed $data Unused.
     * @return string Always empty.
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Output the HTML for this setting - just adds the JS module.
     *
     * @param mixed $data Unused.
     * @param string $query Unused.
     * @return string Empty HTML, JS is added via requires.
     */
    public function output_html($data, $query = '') {
        global $PAGE;
        $PAGE->requires->js_call_amd($this->module, $this->function);
        return '';
    }
}
