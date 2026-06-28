/**
 * Admin menu configuration for REB Library.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type { MenuItem } from '../types';

/**
 * Get admin menu items.
 * @param activePage - The currently active page identifier
 * @returns Array of admin menu items
 */
export function getAdminMenuItems(activePage: string = ''): MenuItem[] {
    return [
        {
            name: "Dashboard",
            url: "/local/reblibrary/admin/index.php",
            icon: "fa fa-tachometer-alt",
            active: activePage === 'dashboard'
        },
        {
            name: "Education Structure",
            url: "/local/reblibrary/admin/ed_structure.php",
            icon: "fa fa-graduation-cap",
            active: activePage === 'education'
        },
        {
            name: "Resources & Authors",
            url: "/local/reblibrary/admin/resources.php",
            icon: "fa fa-book",
            active: activePage === 'resources'
        },
        {
            name: "Labels & Categories",
            url: "/local/reblibrary/admin/categories.php",
            icon: "fa fa-tags",
            active: activePage === 'categories'
        },
    ];
}
