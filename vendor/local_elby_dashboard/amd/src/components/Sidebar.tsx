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

import type { PageId, SidenavConfig, ThemeConfig, UserData } from '../types';

// @ts-ignore — Moodle global
declare const M: { cfg: { wwwroot: string } };

// Derive the base path from Moodle's wwwroot (e.g., "/new" from "https://example.com/new").
function getBasePath(): string {
    try {
        const url = new URL(M.cfg.wwwroot);
        return url.pathname.replace(/\/$/, '');
    } catch {
        return '';
    }
}

interface SidebarProps {
    user: UserData;
    activePage: PageId;
    sidenavConfig: SidenavConfig;
    themeConfig: ThemeConfig;
    isOpen: boolean;
    onClose: () => void;
}

function userInitials(name?: string): string {
    if (!name) return '?';
    const p = name.trim().split(/\s+/).filter(Boolean);
    return (((p[0] || '')[0] || '') + ((p[1] || '')[0] || '')).toUpperCase() || '?';
}

// Menu items (paths are relative to Moodle root, basePath is prepended at render time).
const menuItems = [
    { id: 'home', name: 'Dashboard', icon: 'dashboard', path: '/local/elby_dashboard/index.php' },
    { id: 'courses', name: 'Courses', icon: 'courses', path: '/local/elby_dashboard/courses.php', capability: 'viewreports' },
    { id: 'schools', name: 'Schools', icon: 'schools', path: '/local/elby_dashboard/schools.php', capability: 'viewreports' },
    { id: 'students', name: 'Students', icon: 'students', path: '/local/elby_dashboard/students.php', capability: 'viewreports' },
    { id: 'teachers', name: 'Teachers', icon: 'teachers', path: '/local/elby_dashboard/teachers.php', capability: 'viewreports' },
    { id: 'traffic', name: 'Traffic', icon: 'traffic', path: '/local/elby_dashboard/traffic.php', capability: 'viewreports' },
    { id: 'accesslog', name: 'Access Log', icon: 'access', path: '/local/elby_dashboard/accesslog.php', capability: 'viewreports' },
    { id: 'blended_learning', name: 'Blended Learning', icon: 'blended', path: '/local/elby_dashboard/blended_learning.php', capability: 'viewreports' },
    { id: 'rise', name: 'RISE', icon: 'rise', path: '/local/elby_dashboard/rise.php', capability: 'viewreports' },
    { id: 'admin', name: 'Admin Panel', icon: 'admin', path: '/local/elby_dashboard/admin/index.php', capability: 'admin' },
];

// Stroke-style icon paths keyed by icon name (matches the RISE screen design).
const NAV_PATHS: Record<string, string> = {
    dashboard: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    courses: 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5V5a2 2 0 0 1 2-2h14v14M6.5 17H20',
    schools: 'M22 10 12 5 2 10l10 5 10-5zM6 12v5c0 1 3 3 6 3s6-2 6-3v-5',
    students: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    teachers: 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z',
    traffic: 'M22 12h-4l-3 9L9 3l-3 9H2',
    access: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    blended: 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
    rise: 'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6',
    admin: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
};

function NavIcon({ name, color }: { name: string; color: string }) {
    return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={color}
             strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" style={{ flex: '0 0 auto' }}>
            <path d={NAV_PATHS[name] || NAV_PATHS.dashboard} />
        </svg>
    );
}

// Default logo icon (fallback when no logo is uploaded) — REB diamond, matching the design.
const DefaultLogoIcon = () => (
    <span style={{ flex: '0 0 auto', width: 30, height: 30, position: 'relative', display: 'inline-block' }}>
        <span style={{ position: 'absolute', top: 2, right: 8, bottom: 10, left: 0, background: '#f79222', transform: 'rotate(45deg)', borderRadius: 4 }} />
        <span style={{ position: 'absolute', top: 10, right: 0, bottom: 0, left: 8, background: '#005198', transform: 'rotate(45deg)', borderRadius: 4 }} />
    </span>
);

export default function Sidebar({ user, activePage, sidenavConfig, themeConfig, isOpen, onClose }: SidebarProps) {
    const basePath = getBasePath();
    // REB navy, matching the RISE screen design (not the generic theme accent).
    const accent = '#005198';

    // Filter menu items based on visibility settings.
    const visibleMenuItems = menuItems.filter((item) => {
        if (item.id === 'home') return true;
        return themeConfig.menuVisibility[item.id] !== false;
    });

    const sectionLabel = 'text-[10.5px] font-bold tracking-[1px] text-[#a7adb8] px-2';

    return (
        <>
            {/* Mobile backdrop overlay */}
            {isOpen && (
                <div className="fixed inset-0 bg-black/50 z-40 lg:hidden" onClick={onClose} />
            )}

            {/* Sidebar */}
            <aside className={`
                fixed lg:sticky inset-y-0 left-0 z-50 top-0
                w-64 h-screen bg-white border-r border-[#ecedf1] py-[22px] px-4 overflow-y-auto flex flex-col
                transform transition-transform duration-300 ease-in-out
                ${isOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}
            `}>
                {/* Header with logo and close button */}
                <div className="px-2 pb-1 flex items-center justify-between">
                    <div className="flex items-center gap-[11px]">
                        {sidenavConfig.logoUrl ? (
                            <img src={sidenavConfig.logoUrl} alt={sidenavConfig.title} className="w-[30px] h-[30px] object-contain" />
                        ) : (
                            <DefaultLogoIcon />
                        )}
                        <span className="text-[17px] font-bold tracking-[-.3px] text-[#161b26]">{sidenavConfig.title}</span>
                    </div>
                    <button
                        onClick={onClose}
                        className="lg:hidden p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg"
                        aria-label="Close menu"
                    >
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Main Menu Section */}
                <div className={`${sectionLabel} pt-6 pb-3`} style={{ lineHeight: 1 }}>MENU</div>
                <nav className="flex flex-col gap-1.5">
                    {visibleMenuItems.map((item) => {
                        const isActive = item.id === activePage;
                        return (
                            <a
                                key={item.id}
                                href={basePath + item.path}
                                className="flex items-center gap-3 px-4 rounded-full no-underline transition-colors"
                                style={isActive
                                    ? { height: 44, background: accent, boxShadow: '0 6px 16px rgba(0,81,152,.28)' }
                                    : { height: 44 }}
                                onMouseEnter={(e) => { if (!isActive) (e.currentTarget as HTMLElement).style.background = '#f3f5f8'; }}
                                onMouseLeave={(e) => { if (!isActive) (e.currentTarget as HTMLElement).style.background = 'transparent'; }}
                            >
                                <NavIcon name={item.icon} color={isActive ? '#fff' : '#7a818d'} />
                                <span className="flex-1 text-[13px]" style={{ lineHeight: 1, color: isActive ? '#fff' : '#3b424f', fontWeight: isActive ? 600 : 500 }}>
                                    {item.name}
                                </span>
                            </a>
                        );
                    })}
                </nav>

                <div className="flex-1" />

                {/* Account Section */}
                <div className={`${sectionLabel} pt-[18px] pb-3`} style={{ lineHeight: 1 }}>ACCOUNT</div>
                <a
                    href={basePath + '/user/profile.php'}
                    className="flex items-center gap-[11px] px-2.5 py-[9px] rounded-[11px] no-underline bg-[#f8f9fb] border border-[#eef0f3]"
                >
                    {user.avatar
                        ? <img src={user.avatar} alt={user.fullname} className="w-8 h-8 rounded-full object-cover shrink-0" />
                        : <span className="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-[11.5px] font-semibold text-white" style={{ background: accent }}>{userInitials(user.fullname)}</span>}
                    <span className="flex-1 min-w-0">
                        <span className="block text-[12.5px] font-semibold text-[#1f2430] truncate">{user.fullname || 'User'}</span>
                        <span className="block text-[10.5px] text-[#9aa0ab] mt-px">{user.isAdmin ? 'Administrator' : (user.roles?.[0] || 'User')}</span>
                    </span>
                </a>
            </aside>
        </>
    );
}
