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
 * AJAX autocomplete handler for the course-form Module field.
 *
 * Searches curriculum modules via the local_elby_dashboard_search_modules
 * webservice, scoped to the currently-selected trade when one is chosen.
 *
 * @module     local_elby_dashboard/module_selector
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    return {
        /**
         * Map the webservice rows to the {value, label} pairs form-autocomplete expects.
         *
         * @param {String} selector The autocomplete select selector.
         * @param {Array} results Rows returned by transport().
         * @return {Array}
         */
        processResults: function(selector, results) {
            return (results || []).map(function(module) {
                return {value: String(module.value), label: module.label};
            });
        },

        /**
         * Fetch matching modules, scoped to the selected trade code if present.
         *
         * @param {String} selector The autocomplete select selector.
         * @param {String} query The user's search text.
         * @param {Function} success Success callback.
         * @param {Function} failure Failure callback.
         */
        transport: function(selector, query, success, failure) {
            var tradecode = '';
            var tradeEl = document.getElementById('id_elbytrade');
            if (tradeEl) {
                tradecode = tradeEl.value || '';
            }
            Ajax.call([{
                methodname: 'local_elby_dashboard_search_modules',
                args: {query: query || '', tradecode: tradecode}
            }])[0].then(function(response) {
                success(response.modules || []);
                return response;
            }).catch(failure);
        }
    };
});
