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
 * Education structure management module with Preact integration.
 *
 * @module     local_reblibrary/ed-structure
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h, render } from 'preact';
import EdStructure from "./components/education/EdStructure";
import type { EduLevel, EduSublevel, EduClass, EduSection } from './services/edu-structure';
import './styles.css';

/**
 * Initialize the Education Structure Preact application.
 *
 * Reads education structure data from HTML data attributes,
 * and renders the EdStructure component.
 *
 * @param {string} selector - CSS selector for the mount point
 */
export const init = (selector: string = '#ed-structure-root') => {
    const container = document.querySelector(selector);

    if (!container) {
        console.error(`Container not found: ${selector}`);
        return;
    }

    // Read data from HTML data attributes
    try {
        const levelsDataAttr = container.getAttribute('data-levels');
        const sublevelsDataAttr = container.getAttribute('data-sublevels');
        const classesDataAttr = container.getAttribute('data-classes');
        const sectionsDataAttr = container.getAttribute('data-sections');

        // Parse data
        const initialLevels: EduLevel[] = levelsDataAttr ? JSON.parse(levelsDataAttr) : [];
        const initialSublevels: EduSublevel[] = sublevelsDataAttr ? JSON.parse(sublevelsDataAttr) : [];
        const initialClasses: EduClass[] = classesDataAttr ? JSON.parse(classesDataAttr) : [];
        const initialSections: EduSection[] = sectionsDataAttr ? JSON.parse(sectionsDataAttr) : [];

        console.log('Education structure data loaded:', {
            levels: initialLevels.length,
            sublevels: initialSublevels.length,
            classes: initialClasses.length,
            sections: initialSections.length
        });

        // Render the Preact app
        render(
            h(EdStructure, {
                initialLevels,
                initialSublevels,
                initialClasses,
                initialSections
            }),
            container
        );
    } catch (error) {
        console.error('Error parsing education structure data attributes:', error);
    }
};
