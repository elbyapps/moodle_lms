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
 * Settings page dynamic show/hide for mode-specific settings.
 *
 * @module     local_syncqueue/settings
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    return {
        /**
         * Initialize the settings page dynamic behavior.
         */
        init: function() {
            var modeSelect = document.getElementById('id_s_local_syncqueue_mode');
            if (!modeSelect) {
                return;
            }

            /**
             * Get all settings rows between two headings.
             *
             * @param {string} headingName The setting name of the heading.
             * @return {Array} Array of setting rows.
             */
            function getSettingsForHeading(headingName) {
                var rows = [];
                var headingId = 'admin-' + headingName.replace('local_syncqueue/', '');
                var headingRow = document.getElementById(headingId);

                if (!headingRow) {
                    return rows;
                }

                // Find the parent row element.
                var currentRow = headingRow.closest('.form-item');
                if (!currentRow) {
                    // Try admin table format.
                    currentRow = headingRow.closest('tr');
                }
                if (!currentRow) {
                    return rows;
                }

                // Collect all sibling rows until we hit another heading.
                var nextRow = currentRow.nextElementSibling;
                while (nextRow) {
                    // Check if this is another heading.
                    var isHeading = nextRow.querySelector('.admin_colheader, [id^="admin-"][id$="heading"]');
                    if (isHeading) {
                        break;
                    }
                    rows.push(nextRow);
                    nextRow = nextRow.nextElementSibling;
                }

                return rows;
            }

            /**
             * Toggle visibility of settings sections based on mode.
             */
            function toggleSections() {
                var mode = modeSelect.value;
                var schoolRows = getSettingsForHeading('local_syncqueue/schoolheading');
                var centralRows = getSettingsForHeading('local_syncqueue/centralheading');

                // Also get the heading rows themselves.
                var schoolHeading = document.getElementById('admin-schoolheading');
                var centralHeading = document.getElementById('admin-centralheading');

                if (mode === 'school') {
                    // Show school settings, hide central settings.
                    schoolRows.forEach(function(row) {
                        row.style.display = '';
                    });
                    if (schoolHeading) {
                        var schoolHeadingRow = schoolHeading.closest('.form-item') || schoolHeading.closest('tr');
                        if (schoolHeadingRow) {
                            schoolHeadingRow.style.display = '';
                        }
                    }

                    centralRows.forEach(function(row) {
                        row.style.display = 'none';
                    });
                    if (centralHeading) {
                        var centralHeadingRow = centralHeading.closest('.form-item') || centralHeading.closest('tr');
                        if (centralHeadingRow) {
                            centralHeadingRow.style.display = 'none';
                        }
                    }
                } else {
                    // Show central settings, hide school settings.
                    schoolRows.forEach(function(row) {
                        row.style.display = 'none';
                    });
                    if (schoolHeading) {
                        var schoolHeadingRowHide = schoolHeading.closest('.form-item') || schoolHeading.closest('tr');
                        if (schoolHeadingRowHide) {
                            schoolHeadingRowHide.style.display = 'none';
                        }
                    }

                    centralRows.forEach(function(row) {
                        row.style.display = '';
                    });
                    if (centralHeading) {
                        var centralHeadingRowShow = centralHeading.closest('.form-item') || centralHeading.closest('tr');
                        if (centralHeadingRowShow) {
                            centralHeadingRowShow.style.display = '';
                        }
                    }
                }
            }

            // Listen for mode changes.
            modeSelect.addEventListener('change', toggleSections);

            // Set initial state.
            toggleSections();
        }
    };
});
