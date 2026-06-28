/**
 * Library menu configuration for REB Library.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type { MenuItem } from '../types';

/**
 * Get library menu items.
 * @param activePage - The currently active page identifier
 * @returns Array of library menu items
 */
export function getLibraryMenuItems(activePage: string = ''): MenuItem[] {
    return [
        {
            name: "Resources",
            url: "/local/reblibrary/index.php",
            icon: "fa fa-book-open",
            active: activePage === 'home',
            children: [] // Will be populated with education structure in Sidebar component
        },
        {
            name: "Reading Materials",
            url: "/local/reblibrary/reading-materials.php",
            icon: "fa fa-book-reader",
            active: activePage === 'reading-materials',
            children: []
        }
    ];
}
