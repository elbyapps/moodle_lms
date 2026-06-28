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
 * Menu configuration for Elby Dashboard.
 *
 * @module     local_elby_dashboard/config/menu
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type { MenuItem } from '../types';

/**
 * Get the main dashboard menu items.
 */
export function getMenuItems(): MenuItem[] {
    return [
        {
            id: 'home',
            name: 'Home',
            url: '/local/elby_dashboard/index.php',
            icon: 'fa fa-home',
        },
        {
            id: 'completion',
            name: 'Completion Report',
            url: '/local/elby_dashboard/completion.php',
            icon: 'fa fa-check-circle',
        },
    ];
}
