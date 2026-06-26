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
 * Admin setting that outputs JavaScript for slide count control.
 *
 * @package    theme_elby
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Admin setting class that outputs JavaScript for controlling slide visibility.
 */
class theme_elby_admin_setting_slidecountjs extends admin_setting {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->nosave = true;
        parent::__construct('theme_elby/slidecountjs', '', '', '');
    }

    /**
     * Returns the setting - not used.
     *
     * @return string
     */
    public function get_setting() {
        return '';
    }

    /**
     * Writes the setting - not used.
     *
     * @param mixed $data Unused.
     * @return string Empty string.
     */
    public function write_setting($data) {
        return '';
    }

    /**
     * Output the HTML for this setting - includes JavaScript.
     *
     * @param mixed $data Unused.
     * @param string $query Unused.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        global $PAGE;

        // Add the JavaScript via AMD.
        $PAGE->requires->js_amd_inline('
            require(["jquery"], function($) {
                function initSlideVisibility() {
                    var slideCountInput = $("#id_s_theme_elby_heroslidecount");
                    if (!slideCountInput.length) {
                        // Try again after a short delay if not found.
                        setTimeout(initSlideVisibility, 500);
                        return;
                    }

                    function updateSlideVisibility() {
                        var count = parseInt(slideCountInput.val()) || 1;
                        count = Math.max(1, Math.min(10, count));

                        for (var i = 1; i <= 10; i++) {
                            // Find all elements for slide i.
                            var slideElements = $("[id*=\'heroslide" + i + "\']").closest(".row, .form-group, tr");
                            // Also find the heading.
                            var heading = $(".elby-slide-heading[data-slide=\'" + i + "\']").closest(".row, .form-group, tr");

                            var allElements = slideElements.add(heading);

                            if (i <= count) {
                                allElements.show();
                            } else {
                                allElements.hide();
                            }
                        }
                    }

                    updateSlideVisibility();
                    slideCountInput.on("input change keyup", updateSlideVisibility);
                }

                // Initialize when DOM is ready.
                $(document).ready(function() {
                    initSlideVisibility();
                });
            });
        ');

        return '';
    }
}
