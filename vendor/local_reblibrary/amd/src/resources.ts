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
 * Resources management module with Preact integration.
 *
 * @module     local_reblibrary/resources
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h, render } from 'preact';
import Resources from "./components/admin/Resources";
import type { Resource } from './services/resources';
import type { Author } from './services/authors';
import type { EducationLevel, EducationSublevel, EducationClass } from './types';
import './styles.css';

/**
 * Initialize the Resources Preact application.
 *
 * Reads resources, authors, and education structure data from HTML data attributes,
 * and renders the Resources component.
 *
 * @param {string} selector - CSS selector for the mount point
 */
export const init = (selector: string = '#resources-root') => {
    const container = document.querySelector(selector);

    if (!container) {
        console.error(`Container not found: ${selector}`);
        return;
    }

    // Read data from HTML data attributes
    try {
        const resourcesDataAttr = container.getAttribute('data-resources');
        const authorsDataAttr = container.getAttribute('data-authors');
        const levelsDataAttr = container.getAttribute('data-levels');
        const sublevelsDataAttr = container.getAttribute('data-sublevels');
        const classesDataAttr = container.getAttribute('data-classes');

        // Parse data
        const initialResources: Resource[] = resourcesDataAttr ? JSON.parse(resourcesDataAttr) : [];
        const initialAuthors: Author[] = authorsDataAttr ? JSON.parse(authorsDataAttr) : [];
        const eduLevels: EducationLevel[] = levelsDataAttr ? JSON.parse(levelsDataAttr) : [];
        const eduSublevels: EducationSublevel[] = sublevelsDataAttr ? JSON.parse(sublevelsDataAttr) : [];
        const eduClasses: EducationClass[] = classesDataAttr ? JSON.parse(classesDataAttr) : [];


        // Render the Preact app
        render(
            h(Resources, {
                initialResources,
                initialAuthors,
                eduLevels,
                eduSublevels,
                eduClasses
            }),
            container
        );
    } catch (error) {
        console.error('Error parsing resources data attributes:', error);
    }
};
