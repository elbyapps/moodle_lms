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
 * Elby Dashboard module with Preact integration.
 *
 * @module     local_elby_dashboard/dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h, render } from 'preact';
import App from './app';
import type { UserData, StatsData, PageId, SidenavConfig, ThemeConfig, CoursesReportData } from './types';

/**
 * Initialize the Elby Dashboard Preact application.
 *
 * Reads user and stats data from HTML data attributes and renders the app.
 *
 * @param {string} selector - CSS selector for the mount point
 */
export const init = (selector: string = '#elby-dashboard-root') => {
    const container = document.querySelector(selector);

    if (!container) {
        console.error(`Container not found: ${selector}`);
        return;
    }

    // Read data from HTML data attributes
    try {
        const userDataAttr = container.getAttribute('data-user');
        const statsDataAttr = container.getAttribute('data-stats');
        const sidenavDataAttr = container.getAttribute('data-sidenav');
        const themeDataAttr = container.getAttribute('data-theme');
        const coursesReportAttr = container.getAttribute('data-courses-report');
        const pageAttr = container.getAttribute('data-page') as PageId | null;

        // Parse user data
        const user: UserData = userDataAttr
            ? JSON.parse(userDataAttr)
            : {
                id: 0,
                fullname: 'Guest',
                firstname: 'Guest',
                lastname: '',
                email: '',
                avatar: '',
                roles: [],
            };

        // Parse stats data
        const stats: StatsData = statsDataAttr
            ? JSON.parse(statsDataAttr)
            : {
                totalCourses: 0,
                totalUsers: 0,
                totalEnrollments: 0,
                totalActivities: 0,
            };

        // Parse sidenav config
        const sidenavConfig: SidenavConfig = sidenavDataAttr
            ? JSON.parse(sidenavDataAttr)
            : {
                title: 'Dashboard',
                logoUrl: null,
            };

        // Parse theme config
        const themeConfig: ThemeConfig = themeDataAttr
            ? JSON.parse(themeDataAttr)
            : {
                sidenavAccentColor: '#005198',
                statCard1Color: '#cffafe',
                statCard2Color: '#fef3c7',
                statCard3Color: '#f3e8ff',
                statCard4Color: '#dcfce7',
                chartPrimaryColor: '#22d3ee',
                chartSecondaryColor: '#a78bfa',
                showSearchBar: true,
                showNotifications: true,
                showUserProfile: true,
                menuVisibility: {
                    courses: true,
                    presence: true,
                    communication: true,
                    event: true,
                    pedagogy: true,
                    message: true,
                    completion: true,
                    settings: true,
                },
            };

        // Parse courses report data (optional, only on courses page)
        const coursesReportData: CoursesReportData | null = coursesReportAttr
            ? JSON.parse(coursesReportAttr)
            : null;

        // Read school code for school detail page.
        const schoolCode = container.getAttribute('data-school-code') || undefined;

        // Get active page (default to 'home')
        const activePage: PageId = pageAttr || 'home';

        console.log('Elby Dashboard initialized:', { user: user.fullname, activePage });

        // Render the Preact app
        render(h(App, { user, stats, activePage, sidenavConfig, themeConfig, coursesReportData, schoolCode }), container);
    } catch (error) {
        console.error('Error initializing Elby Dashboard:', error);
    }
};
