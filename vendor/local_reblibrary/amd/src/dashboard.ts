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
 * Example REB Library module with Preact integration.
 *
 * @module     local_reblibrary/example-with-preact
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h, render } from 'preact';
import App from "./app";
import { userSignal, statsSignal } from './store';
import type { UserData, StatsData, EducationLevel, EducationSublevel, EducationClass } from './types';
import './styles.css';

/**
 * Initialize the REB Library Preact application.
 *
 * Reads user, stats, and education structure data from HTML data attributes,
 * initializes Preact signals, and renders the app.
 *
 * @param {string} selector - CSS selector for the mount point
 */
export const init = (selector: string = '#reb-library-root') => {
    const container = document.querySelector(selector);

    if (!container) {
        console.error(`Container not found: ${selector}`);
        return;
    }

    // Read data from HTML data attributes
    try {
        const userDataAttr = container.getAttribute('data-user');
        const statsDataAttr = container.getAttribute('data-stats');
        const levelsDataAttr = container.getAttribute('data-levels');
        const sublevelsDataAttr = container.getAttribute('data-sublevels');
        const classesDataAttr = container.getAttribute('data-classes');

        // Parse and initialize user signal
        if (userDataAttr) {
            const userData: UserData = JSON.parse(userDataAttr);
            userSignal.value = userData;
            console.log('User data loaded:', userData);
        }

        // Parse and initialize stats signal
        if (statsDataAttr) {
            const statsData: StatsData = JSON.parse(statsDataAttr);
            statsSignal.value = statsData;
            console.log('Stats data loaded:', statsData);
        }

        // Parse education structure data
        const levels: EducationLevel[] = levelsDataAttr ? JSON.parse(levelsDataAttr) : [];
        const sublevels: EducationSublevel[] = sublevelsDataAttr ? JSON.parse(sublevelsDataAttr) : [];
        const classes: EducationClass[] = classesDataAttr ? JSON.parse(classesDataAttr) : [];

        console.log('Education structure loaded:', {
            levels: levels.length,
            sublevels: sublevels.length,
            classes: classes.length
        });

        // Render the Preact app with education structure data
        render(h(App, { levels, sublevels, classes }), container);
    } catch (error) {
        console.error('Error parsing data attributes:', error);
    }
};
