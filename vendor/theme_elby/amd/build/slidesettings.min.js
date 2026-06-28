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
 * Dynamic slide settings visibility control for theme_elby.
 *
 * @module     theme_elby/slidesettings
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    return {
        /**
         * Initialize the slide settings visibility control.
         */
        init: function() {
            var slideCountInput = $('#id_s_theme_elby_heroslidecount');
            if (!slideCountInput.length) {
                return;
            }

            /**
             * Update visibility of slide settings based on count.
             */
            function updateSlideVisibility() {
                var count = parseInt(slideCountInput.val()) || 1;
                count = Math.max(1, Math.min(10, count));

                $('.elby-slide-heading').each(function() {
                    var heading = $(this);
                    var slideNum = parseInt(heading.data('slide'));

                    // Find the container row for this heading.
                    var container = heading.closest('.row');
                    if (!container.length) {
                        container = heading.closest('div').parent();
                    }

                    // Collect all elements for this slide (heading + 6 settings).
                    var elements = container;
                    var current = container;
                    for (var i = 0; i < 6; i++) {
                        var next = current.next();
                        if (next.length) {
                            elements = elements.add(next);
                            current = next;
                        }
                    }

                    // Show or hide based on slide number.
                    if (slideNum <= count) {
                        elements.show();
                    } else {
                        elements.hide();
                    }
                });
            }

            // Initial update on page load.
            updateSlideVisibility();

            // Update when input changes.
            slideCountInput.on('input change keyup', updateSlideVisibility);
        }
    };
});
