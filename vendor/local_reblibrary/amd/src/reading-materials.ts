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
 * Reading Materials page module with Preact integration.
 *
 * @module     local_reblibrary/reading-materials
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h, render } from 'preact';
import ReadingMaterials from "./components/ReadingMaterials";
import type { Resource } from './services/resources';
import type { Category } from './services/categories';
import './styles.css';

/**
 * Initialize the Reading Materials Preact application.
 *
 * Reads resources, categories, and education structure data from HTML data attributes,
 * and renders the ReadingMaterials component.
 *
 * @param {string} selector - CSS selector for the mount point
 */
export const init = (selector: string = '#reading-materials-root') => {
    const container = document.querySelector(selector);

    if (!container) {
        console.error(`Container not found: ${selector}`);
        return;
    }

    // Read data from HTML data attributes
    try {
        const resourcesDataAttr = container.getAttribute('data-resources');
        const levelsDataAttr = container.getAttribute('data-levels');
        const sublevelsDataAttr = container.getAttribute('data-sublevels');
        const classesDataAttr = container.getAttribute('data-classes');
        const categoriesDataAttr = container.getAttribute('data-categories');

        // Parse data
        const initialResources: Resource[] = resourcesDataAttr ? JSON.parse(resourcesDataAttr) : [];
        const initialLevels = levelsDataAttr ? JSON.parse(levelsDataAttr) : [];
        const initialSublevels = sublevelsDataAttr ? JSON.parse(sublevelsDataAttr) : [];
        const initialClasses = classesDataAttr ? JSON.parse(classesDataAttr) : [];
        const initialCategories: Category[] = categoriesDataAttr ? JSON.parse(categoriesDataAttr) : [];

        console.log('Reading materials data loaded:', {
            resources: initialResources,
            levels: initialLevels,
            sublevels: initialSublevels,
            classes: initialClasses,
            categories: initialCategories
        });

        // Render the Preact app
        render(
            h(ReadingMaterials, {
                initialResources,
                initialLevels,
                initialSublevels,
                initialClasses,
                initialCategories
            }),
            container
        );
    } catch (error) {
        console.error('Error parsing reading materials data attributes:', error);
    }
};
