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
 * Root Preact application component for Elby Dashboard.
 *
 * @module     local_elby_dashboard/app
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState } from 'preact/hooks';
import './styles.css';
import Dashboard from './components/Dashboard';
import Sidebar from './components/Sidebar';
import CompletionReport from './components/CompletionReport';
import CoursesReport from './components/CoursesReport';
import SchoolDirectory from './components/SchoolDirectory';
import SchoolDetail from './components/SchoolDetail';
import StudentList from './components/StudentList';
import TrafficReport from './components/TrafficReport';
import AccessLog from './components/AccessLog';
import AdminPanel from './components/AdminPanel';
import BlendedLearning from './components/BlendedLearning';
import type { UserData, StatsData, PageId, SidenavConfig, ThemeConfig, CoursesReportData } from './types';

interface AppProps {
    user: UserData;
    stats: StatsData;
    activePage: PageId;
    sidenavConfig: SidenavConfig;
    themeConfig: ThemeConfig;
    coursesReportData?: CoursesReportData | null;
    schoolCode?: string;
}

// Header component with search and user profile
function Header({ user, activePage, themeConfig, onMenuClick }: {
    user: UserData;
    activePage: PageId;
    themeConfig: ThemeConfig;
    onMenuClick: () => void;
}) {
    const pageTitles: Record<string, string> = {
        home: 'Dashboard',
        completion: 'Completion Report',
        courses: 'Courses Report',
        schools: 'Schools Directory',
        school_detail: 'School Detail',
        students: 'Student List',
        teachers: 'Teacher List',
        traffic: 'Platform Traffic',
        accesslog: 'Access Log',
        blended_learning: 'Blended Learning',
        admin: 'Admin Panel',
    };
    const pageTitle = pageTitles[activePage] || 'Dashboard';

    return (
        <header className="bg-white border-b border-gray-100 px-2 sm:px-4 lg:px-6 py-3 sm:py-4">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                    {/* Hamburger Menu - Mobile only */}
                    <button
                        onClick={onMenuClick}
                        className="lg:hidden p-2 -ml-2 text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg"
                        aria-label="Toggle menu"
                    >
                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    {/* Page Title */}
                    <h1 className="text-xl font-semibold text-gray-800">{pageTitle}</h1>
                </div>

                <div className="flex items-center gap-4 lg:gap-6">
                    {/* Notification Bell */}
                    {themeConfig.showNotifications && (
                        <button className="relative text-gray-500 hover:text-gray-700">
                            <svg className="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                            </svg>
                            <span className="absolute -top-1 -right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                        </button>
                    )}

                    {/* User Profile */}
                    {themeConfig.showUserProfile && (
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white font-semibold text-sm overflow-hidden">
                                {user.avatar ? (
                                    <img src={user.avatar} alt={user.fullname} className="w-full h-full object-cover" />
                                ) : (
                                    <span>{user.firstname.charAt(0)}{user.lastname.charAt(0)}</span>
                                )}
                            </div>
                            <div className="hidden sm:block">
                                <p className="text-sm font-semibold text-gray-800">{user.fullname}</p>
                                <p className="text-xs text-gray-500">
                                    {user.isAdmin ? 'Admin' : user.roles.includes('teacher') ? 'Teacher' : 'User'}
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}

export default function App({ user, stats, activePage, sidenavConfig, themeConfig, coursesReportData, schoolCode }: AppProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);

    const toggleSidebar = () => setSidebarOpen(!sidebarOpen);
    const closeSidebar = () => setSidebarOpen(false);

    return (
        <div className="flex h-[calc(100vh-120px)] bg-gray-50">
            <Sidebar
                activePage={activePage}
                sidenavConfig={sidenavConfig}
                themeConfig={themeConfig}
                isOpen={sidebarOpen}
                onClose={closeSidebar}
            />
            <div className="flex-1 flex flex-col min-h-0">
                <Header user={user} activePage={activePage} themeConfig={themeConfig} onMenuClick={toggleSidebar} />
                <main className="flex-1 overflow-y-auto">
                    {activePage === 'home' && <Dashboard user={user} stats={stats} themeConfig={themeConfig} />}
                    {activePage === 'completion' && <CompletionReport />}
                    {activePage === 'courses' && coursesReportData && (
                        <CoursesReport data={coursesReportData} themeConfig={themeConfig} />
                    )}
                    {activePage === 'schools' && <SchoolDirectory />}
                    {activePage === 'school_detail' && schoolCode && <SchoolDetail schoolCode={schoolCode} />}
                    {activePage === 'students' && <StudentList initialSchoolCode={schoolCode} />}
                    {activePage === 'teachers' && <StudentList userType="teacher" user={user} initialSchoolCode={schoolCode} />}
                    {activePage === 'traffic' && <TrafficReport />}
                    {activePage === 'accesslog' && <AccessLog />}
                    {activePage === 'blended_learning' && <BlendedLearning />}
                    {activePage === 'admin' && <AdminPanel />}
                </main>
            </div>
        </div>
    );
}
