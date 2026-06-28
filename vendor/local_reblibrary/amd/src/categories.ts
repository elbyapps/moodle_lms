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
 * Categories management module with Preact integration.
 *
 * @module     local_reblibrary/categories
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h, render } from 'preact';
import Categories from "./components/admin/Categories";
import type { Category } from './services/categories';
import type { Label } from './services/labels';
import type { EducationLevel, EducationSublevel, EducationClass } from './types';
import './styles.css';

/**
 * Initialize the Categories Preact application.
 *
 * Reads categories, labels, and education structure data from HTML data attributes,
 * and renders the Categories component.
 *
 * @param {string} selector - CSS selector for the mount point
 */
export const init = (selector: string = '#categories-root') => {
    const container = document.querySelector(selector);

    if (!container) {
        console.error(`Container not found: ${selector}`);
        return;
    }

    // Read data from HTML data attributes
    try {
        const categoriesDataAttr = container.getAttribute('data-categories');
        const labelsDataAttr = container.getAttribute('data-labels');
        const levelsDataAttr = container.getAttribute('data-levels');
        const sublevelsDataAttr = container.getAttribute('data-sublevels');
        const classesDataAttr = container.getAttribute('data-classes');

        // Parse data
        const initialCategories: Category[] = categoriesDataAttr ? JSON.parse(categoriesDataAttr) : [];
        const initialLabels: Label[] = labelsDataAttr ? JSON.parse(labelsDataAttr) : [];
        const eduLevels: EducationLevel[] = levelsDataAttr ? JSON.parse(levelsDataAttr) : [];
        const eduSublevels: EducationSublevel[] = sublevelsDataAttr ? JSON.parse(sublevelsDataAttr) : [];
        const eduClasses: EducationClass[] = classesDataAttr ? JSON.parse(classesDataAttr) : [];

        console.log('Categories & Labels data loaded:', {
            categories: initialCategories.length,
            labels: initialLabels.length,
            levels: eduLevels.length,
            sublevels: eduSublevels.length,
            classes: eduClasses.length
        });

        // Render the Preact app
        render(
            h(Categories, {
                initialCategories,
                initialLabels,
                eduLevels,
                eduSublevels,
                eduClasses
            }),
            container
        );
    } catch (error) {
        console.error('Error parsing categories data attributes:', error);
    }
};
