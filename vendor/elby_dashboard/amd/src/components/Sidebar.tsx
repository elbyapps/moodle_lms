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
 * Sidebar navigation component for Elby Dashboard.
 *
 * @module     local_elby_dashboard/components/Sidebar
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type { PageId, SidenavConfig, ThemeConfig } from '../types';

// @ts-ignore — Moodle global
declare const M: { cfg: { wwwroot: string } };

// Derive the base path from Moodle's wwwroot (e.g., "/new" from "https://example.com/new").
function getBasePath(): string {
    try {
        const url = new URL(M.cfg.wwwroot);
        // Remove trailing slash if present.
        return url.pathname.replace(/\/$/, '');
    } catch {
        return '';
    }
}

interface SidebarProps {
    activePage: PageId;
    sidenavConfig: SidenavConfig;
    themeConfig: ThemeConfig;
    isOpen: boolean;
    onClose: () => void;
}

// Menu items (paths are relative to Moodle root, basePath is prepended at render time).
const menuItems = [
    { id: 'home', name: 'Dashboard', icon: 'dashboard', path: '/local/elby_dashboard/index.php' },
    { id: 'courses', name: 'Courses', icon: 'courses', path: '/local/elby_dashboard/courses.php', capability: 'viewreports' },
    { id: 'schools', name: 'Schools', icon: 'pedagogy', path: '/local/elby_dashboard/schools.php', capability: 'viewreports' },
    { id: 'students', name: 'Students', icon: 'communication', path: '/local/elby_dashboard/students.php', capability: 'viewreports' },
    { id: 'teachers', name: 'Teachers', icon: 'presence', path: '/local/elby_dashboard/teachers.php', capability: 'viewreports' },
    { id: 'traffic', name: 'Traffic', icon: 'event', path: '/local/elby_dashboard/traffic.php', capability: 'viewreports' },
    { id: 'accesslog', name: 'Access Log', icon: 'courses', path: '/local/elby_dashboard/accesslog.php', capability: 'viewreports' },
    { id: 'blended_learning', name: 'Blended Learning', icon: 'blended', path: '/local/elby_dashboard/blended_learning.php', capability: 'viewreports' },
    { id: 'admin', name: 'Admin Panel', icon: 'settings', path: '/local/elby_dashboard/admin/index.php', capability: 'admin' },
];

// Icon components
const icons: Record<string, JSX.Element> = {
    dashboard: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M4 4h4v4H4V4zm6 0h4v4h-4V4zm6 0h4v4h-4V4zM4 10h4v4H4v-4zm6 0h4v4h-4v-4zm6 0h4v4h-4v-4zM4 16h4v4H4v-4zm6 0h4v4h-4v-4zm6 0h4v4h-4v-4z"/>
        </svg>
    ),
    courses: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9H9V9h10v2zm-4 4H9v-2h6v2zm4-8H9V5h10v2z"/>
        </svg>
    ),
    presence: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V9h14v10zM5 7V5h14v2H5zm2 4h10v2H7v-2zm0 4h7v2H7v-2z"/>
        </svg>
    ),
    communication: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
        </svg>
    ),
    event: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/>
        </svg>
    ),
    payment: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
        </svg>
    ),
    pedagogy: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3zm6.82 6L12 12.72 5.18 9 12 5.28 18.82 9zM17 15.99l-5 2.73-5-2.73v-3.72L12 15l5-2.73v3.72z"/>
        </svg>
    ),
    message: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
        </svg>
    ),
    completion: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
        </svg>
    ),
    blended: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14zM5 15h14v2H5v-2zm0-4h14v2H5v-2zm0-4h8v2H5V7z"/>
        </svg>
    ),
    settings: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
        </svg>
    ),
    logout: (
        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
        </svg>
    ),
};

// Default logo icon (fallback when no logo is uploaded)
const DefaultLogoIcon = () => (
    <svg className="w-8 h-8" viewBox="0 0 40 40" fill="none">
        <rect x="4" y="20" width="16" height="16" fill="#22d3ee" transform="rotate(-45 12 28)"/>
        <rect x="20" y="4" width="16" height="16" fill="#fbbf24" transform="rotate(-45 28 12)"/>
    </svg>
);

export default function Sidebar({ activePage, sidenavConfig, themeConfig, isOpen, onClose }: SidebarProps) {
    const basePath = getBasePath();

    // Filter menu items based on visibility settings
    const visibleMenuItems = menuItems.filter((item) => {
        // Dashboard (home) is always visible
        if (item.id === 'home') return true;
        // Check visibility from theme config
        return themeConfig.menuVisibility[item.id] !== false;
    });

    return (
        <>
            {/* Mobile backdrop overlay */}
            {isOpen && (
                <div
                    className="fixed inset-0 bg-black/50 z-40 lg:hidden"
                    onClick={onClose}
                />
            )}

            {/* Sidebar */}
            <aside className={`
                fixed lg:sticky inset-y-0 left-0 z-50 top-0
                w-72 h-screen bg-gray-50 border-r border-gray-200 py-6 pr-4 overflow-y-auto flex flex-col
                transform transition-transform duration-300 ease-in-out
                ${isOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}
            `}>
                {/* Header with logo and close button */}
                <div className="pl-3 pb-6 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        {sidenavConfig.logoUrl ? (
                            <img
                                src={sidenavConfig.logoUrl}
                                alt={sidenavConfig.title}
                                className="w-8 h-8 object-contain"
                            />
                        ) : (
                            <DefaultLogoIcon />
                        )}
                        <span className="text-lg font-bold text-gray-800">{sidenavConfig.title}</span>
                    </div>
                    {/* Close button - Mobile only */}
                    <button
                        onClick={onClose}
                        className="lg:hidden p-2 mr-2 text-gray-500 hover:text-gray-700 hover:bg-gray-200 rounded-lg"
                        aria-label="Close menu"
                    >
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Main Menu Section */}
                <div className="mb-8 flex-1">
                    <h6 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4 pl-3">
                        Menu
                    </h6>
                    <ul className="space-y-1 m-0 p-0 list-none">
                        {visibleMenuItems.map((item) => {
                            const isActive = item.id === activePage;
                            return (
                                <li key={item.id}>
                                    <a
                                        href={basePath + item.path}
                                        className={`flex items-center px-3 py-2 rounded text-sm transition-colors no-underline ${
                                            isActive
                                                ? 'text-white'
                                                : 'text-gray-700 hover:bg-gray-200'
                                        }`}
                                        style={isActive ? { backgroundColor: themeConfig.sidenavAccentColor } : undefined}
                                    >
                                        <span className={`w-5 mr-3 ${isActive ? 'text-white' : 'text-gray-500'}`}>
                                            {icons[item.icon]}
                                        </span>
                                        <span>{item.name}</span>
                                    </a>
                                </li>
                            );
                        })}
                    </ul>
                </div>

                {/* Account Section */}
                <div className="mt-auto">
                    <h6 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4 pl-3">
                        Account
                    </h6>
                    <ul className="space-y-1 m-0 p-0 list-none">
                        <li>
                            <a
                                href={basePath + '/login/logout.php'}
                                className="flex items-center px-3 py-2 rounded text-sm text-gray-700 hover:bg-gray-200 no-underline transition-colors"
                            >
                                <span className="w-5 mr-3 text-gray-500">{icons.logout}</span>
                                <span>Log out</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </aside>
        </>
    );
}
