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
 * Dashboard component for Elby Dashboard.
 *
 * @module     local_elby_dashboard/components/Dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState, useEffect } from 'preact/hooks';
import type { UserData, StatsData, ThemeConfig, SchoolUserCounts, TrafficDataPoint } from '../types';

interface DashboardProps {
    user: UserData;
    stats: StatsData;
    themeConfig: ThemeConfig;
}

function ajaxCall(methodname: string, args: Record<string, any>): Promise<any> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (Ajax: any) => {
            Ajax.call([{ methodname, args }])[0].then(resolve).catch(reject);
        });
    });
}

// Stat Card Component
function StatCard({ icon, value, label, customBgColor }: { icon: JSX.Element; value: string; label: string; customBgColor?: string }) {
    return (
        <div
            className="rounded-xl p-4 flex items-center gap-4"
            style={customBgColor ? { backgroundColor: customBgColor } : undefined}
        >
            <div className="w-12 h-12 rounded-full bg-white/30 flex items-center justify-center">
                {icon}
            </div>
            <div>
                <p className="text-xs text-gray-600 font-medium">{label}</p>
                <p className="text-xl font-bold text-gray-800">{value}</p>
            </div>
        </div>
    );
}

// Engagement Donut Chart
function EngagementDonut({ activeThisWeek, atRisk, neverLoggedIn }: { activeThisWeek: number; atRisk: number; neverLoggedIn: number }) {
    const total = activeThisWeek + atRisk + neverLoggedIn;
    if (total === 0) {
        return (
            <div className="relative w-36 h-36">
                <svg viewBox="0 0 100 100" className="w-full h-full">
                    <circle cx="50" cy="50" r="40" fill="none" stroke="#e5e7eb" strokeWidth="14" />
                </svg>
                <div className="absolute inset-0 flex items-center justify-center">
                    <span className="text-2xl font-bold text-gray-400">0</span>
                </div>
            </div>
        );
    }

    const circumference = 2 * Math.PI * 40; // ~251.2
    const activeLen = (activeThisWeek / total) * circumference;
    const atRiskLen = (atRisk / total) * circumference;
    const neverLen = (neverLoggedIn / total) * circumference;

    return (
        <div className="relative w-36 h-36">
            <svg viewBox="0 0 100 100" className="w-full h-full transform -rotate-90">
                {/* Background */}
                <circle cx="50" cy="50" r="40" fill="none" stroke="#e5e7eb" strokeWidth="14" />
                {/* Active this week - green */}
                <circle
                    cx="50" cy="50" r="40" fill="none"
                    stroke="#22c55e" strokeWidth="14"
                    strokeDasharray={`${activeLen} ${circumference - activeLen}`}
                    strokeDashoffset="0"
                />
                {/* At risk - amber */}
                <circle
                    cx="50" cy="50" r="40" fill="none"
                    stroke="#f59e0b" strokeWidth="14"
                    strokeDasharray={`${atRiskLen} ${circumference - atRiskLen}`}
                    strokeDashoffset={`${-activeLen}`}
                />
                {/* Never logged in - red */}
                <circle
                    cx="50" cy="50" r="40" fill="none"
                    stroke="#ef4444" strokeWidth="14"
                    strokeDasharray={`${neverLen} ${circumference - neverLen}`}
                    strokeDashoffset={`${-(activeLen + atRiskLen)}`}
                />
            </svg>
            <div className="absolute inset-0 flex items-center justify-center">
                <span className="text-2xl font-bold text-gray-800">{total}</span>
            </div>
        </div>
    );
}

// Gender Donut Chart
function GenderDonut({ male, female }: { male: number; female: number }) {
    const total = male + female;
    if (total === 0) {
        return (
            <div className="relative w-36 h-36">
                <svg viewBox="0 0 100 100" className="w-full h-full">
                    <circle cx="50" cy="50" r="40" fill="none" stroke="#e5e7eb" strokeWidth="14" />
                </svg>
                <div className="absolute inset-0 flex items-center justify-center">
                    <span className="text-2xl font-bold text-gray-400">0</span>
                </div>
            </div>
        );
    }

    const circumference = 2 * Math.PI * 40;
    const maleLen = (male / total) * circumference;
    const femaleLen = (female / total) * circumference;

    return (
        <div className="relative w-36 h-36">
            <svg viewBox="0 0 100 100" className="w-full h-full transform -rotate-90">
                <circle cx="50" cy="50" r="40" fill="none" stroke="#e5e7eb" strokeWidth="14" />
                {/* Male - blue */}
                <circle
                    cx="50" cy="50" r="40" fill="none"
                    stroke="#3b82f6" strokeWidth="14"
                    strokeDasharray={`${maleLen} ${circumference - maleLen}`}
                    strokeDashoffset="0"
                />
                {/* Female - pink */}
                <circle
                    cx="50" cy="50" r="40" fill="none"
                    stroke="#ec4899" strokeWidth="14"
                    strokeDasharray={`${femaleLen} ${circumference - femaleLen}`}
                    strokeDashoffset={`${-maleLen}`}
                />
            </svg>
            <div className="absolute inset-0 flex items-center justify-center">
                <span className="text-2xl font-bold text-gray-800">{total}</span>
            </div>
        </div>
    );
}

// Icons
const StudentsIcon = () => (
    <svg className="w-6 h-6 text-cyan-600" viewBox="0 0 24 24" fill="currentColor">
        <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
    </svg>
);

const TeacherIcon = () => (
    <svg className="w-6 h-6 text-amber-600" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3zm6.82 6L12 12.72 5.18 9 12 5.28 18.82 9zM17 15.99l-5 2.73-5-2.73v-3.72L12 15l5-2.73v3.72z"/>
    </svg>
);

const EmployeeIcon = () => (
    <svg className="w-6 h-6 text-purple-600" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
    </svg>
);

const CoursesIcon = () => (
    <svg className="w-6 h-6 text-green-600" viewBox="0 0 24 24" fill="currentColor">
        <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-1 9H9V9h10v2zm-4 4H9v-2h6v2zm4-8H9V5h10v2z"/>
    </svg>
);

const SchoolsIcon = () => (
    <svg className="w-6 h-6 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3zm6.82 6L12 12.72 5.18 9 12 5.28 18.82 9zM17 15.99l-5 2.73-5-2.73v-3.72L12 15l5-2.73v3.72z"/>
    </svg>
);

export default function Dashboard({ user, stats, themeConfig }: DashboardProps) {
    const [schools, setSchools] = useState<SchoolUserCounts[]>([]);
    const [schoolsLoading, setSchoolsLoading] = useState(true);
    const [traffic, setTraffic] = useState<TrafficDataPoint[]>([]);
    const [trafficLoading, setTrafficLoading] = useState(true);

    // Default date range: last 7 days.
    const todayStr = new Date().toISOString().slice(0, 10);
    const weekAgoStr = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
    const [trafficFrom, setTrafficFrom] = useState(weekAgoStr);
    const [trafficTo, setTrafficTo] = useState(todayStr);

    useEffect(() => {
        // Fetch school user counts
        ajaxCall('local_elby_dashboard_get_school_user_counts', {})
            .then((resp: { schools: SchoolUserCounts[] }) => {
                const data = resp.schools || [];
                // Sort by student_count descending, take top 8
                const sorted = [...data].sort((a, b) => b.student_count - a.student_count).slice(0, 8);
                setSchools(sorted);
            })
            .catch(() => setSchools([]))
            .finally(() => setSchoolsLoading(false));
    }, []);

    // Fetch platform traffic whenever date range changes.
    // Auto-select period granularity based on range size.
    useEffect(() => {
        setTrafficLoading(true);
        const fromTs = Math.floor(new Date(trafficFrom + 'T00:00:00').getTime() / 1000);
        const toTs = Math.floor(new Date(trafficTo + 'T23:59:59').getTime() / 1000);
        const rangeDays = Math.round((toTs - fromTs) / 86400);
        const period = rangeDays > 90 ? 'monthly' : rangeDays > 14 ? 'weekly' : 'daily';
        ajaxCall('local_elby_dashboard_get_platform_traffic', { period, from_date: fromTs, to_date: toTs })
            .then((resp: { data: TrafficDataPoint[] }) => setTraffic(resp.data || []))
            .catch(() => setTraffic([]))
            .finally(() => setTrafficLoading(false));
    }, [trafficFrom, trafficTo]);

    const schoolMaxCount = schools.length > 0 ? Math.max(...schools.map(s => s.student_count)) : 1;
    const trafficMax = traffic.length > 0 ? Math.max(...traffic.map(t => t.unique_users), 1) : 1;

    return (
        <div className="p-2 sm:p-4 lg:p-6 bg-gray-50 min-h-screen">
            {/* Row 1: Stats Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <StatCard
                    icon={<SchoolsIcon />}
                    value={stats.totalSchools.toLocaleString()}
                    label="Schools"
                    customBgColor="#dbeafe"
                />
                <StatCard
                    icon={<StudentsIcon />}
                    value={stats.totalStudents.toLocaleString()}
                    label="Students"
                    customBgColor={themeConfig.statCard1Color}
                />
                <StatCard
                    icon={<TeacherIcon />}
                    value={stats.totalTeachers.toLocaleString()}
                    label="Teachers"
                    customBgColor={themeConfig.statCard2Color}
                />
                <StatCard
                    icon={<EmployeeIcon />}
                    value={stats.totalUsers.toLocaleString()}
                    label="Total Users"
                    customBgColor={themeConfig.statCard3Color}
                />
                <StatCard
                    icon={<CoursesIcon />}
                    value={stats.totalCourses.toLocaleString()}
                    label="Courses"
                    customBgColor={themeConfig.statCard4Color}
                />
            </div>

            {/* Row 2: Schools Overview & Engagement Summary */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                {/* Section A: Schools Overview */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-semibold text-gray-800">Schools Overview</h3>
                        <span className="text-sm text-gray-500">Top {schools.length} by students</span>
                    </div>
                    {schoolsLoading ? (
                        <div className="space-y-3">
                            {[...Array(5)].map((_, i) => (
                                <div key={i} className="h-6 bg-gray-100 rounded animate-pulse" />
                            ))}
                        </div>
                    ) : schools.length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            <p>No school data available</p>
                        </div>
                    ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
                            {schools.map((school) => (
                                <div key={school.school_code} className="flex items-center gap-3">
                                    <div className="w-28 text-xs text-gray-600 truncate flex-shrink-0" title={school.school_name}>
                                        {school.school_name}
                                    </div>
                                    <div className="flex-1 h-6 bg-gray-100 rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-blue-500 rounded-full transition-all opacity-80 hover:opacity-100"
                                            style={{ width: `${(school.student_count / schoolMaxCount) * 100}%` }}
                                        />
                                    </div>
                                    <span className="text-xs font-medium text-gray-700 w-10 text-right flex-shrink-0">
                                        {school.student_count}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Section B: User Overview */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <h3 className="text-lg font-semibold text-gray-800 mb-4">User Overview</h3>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        {/* Gender Demographics */}
                        <div>
                            <h4 className="text-sm font-medium text-gray-600 mb-3">Student Demographics</h4>
                            <div className="flex flex-col items-center gap-3">
                                <GenderDonut
                                    male={stats.maleStudents}
                                    female={stats.femaleStudents}
                                />
                                <div className="flex items-center gap-3 flex-wrap justify-center">
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                                        <span className="text-xs text-gray-600">Male: {stats.maleStudents.toLocaleString()}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2.5 h-2.5 rounded-full bg-pink-500"></span>
                                        <span className="text-xs text-gray-600">Female: {stats.femaleStudents.toLocaleString()}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Engagement Status */}
                        <div>
                            <h4 className="text-sm font-medium text-gray-600 mb-3">Engagement Status</h4>
                            <div className="flex flex-col items-center gap-3">
                                <EngagementDonut
                                    activeThisWeek={stats.activeThisWeek}
                                    atRisk={stats.atRisk}
                                    neverLoggedIn={stats.neverLoggedIn}
                                />
                                <div className="flex items-center gap-3 flex-wrap justify-center">
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                                        <span className="text-xs text-gray-500">Active</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                                        <span className="text-xs text-gray-500">Inactive</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2.5 h-2.5 rounded-full bg-red-500"></span>
                                        <span className="text-xs text-gray-500">Never</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Stat Badges */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 border-t border-gray-100">
                        <div className="bg-emerald-50 rounded-lg p-3">
                            <p className="text-xs text-gray-500">Active Today</p>
                            <p className="text-lg font-bold text-emerald-700">{stats.activeToday.toLocaleString()}</p>
                        </div>
                        <div className="bg-green-50 rounded-lg p-3">
                            <p className="text-xs text-gray-500">Active This Week</p>
                            <p className="text-lg font-bold text-green-700">{stats.activeThisWeek.toLocaleString()}</p>
                        </div>
                        <div className="bg-amber-50 rounded-lg p-3">
                            <p className="text-xs text-gray-500">Inactive</p>
                            <p className="text-lg font-bold text-amber-700">{stats.atRisk.toLocaleString()}</p>
                        </div>
                        <div className="bg-red-50 rounded-lg p-3">
                            <p className="text-xs text-gray-500">Never Logged In</p>
                            <p className="text-lg font-bold text-red-700">{stats.neverLoggedIn.toLocaleString()}</p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Row 3: Students by Grade & Students by Program/Trade */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                {/* Students by Grade Level */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-semibold text-gray-800">Students by Grade Level</h3>
                        <span className="text-sm text-gray-500">{stats.gradeDistribution.reduce((s, d) => s + d.count, 0).toLocaleString()} total</span>
                    </div>
                    {stats.gradeDistribution.length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            <p>No grade data available yet</p>
                        </div>
                    ) : (
                        <div className="flex flex-col" style={{ gap: '16px' }}>
                            {(() => {
                                const gradeMax = Math.max(...stats.gradeDistribution.map(d => d.count), 1);
                                return stats.gradeDistribution.map((d) => (
                                    <div key={d.label} className="flex items-center gap-3">
                                        <div className="w-20 text-xs text-gray-600 truncate flex-shrink-0" title={d.label}>
                                            {d.label}
                                        </div>
                                        <div className="flex-1 bg-gray-100 rounded-full overflow-hidden" style={{ height: '20px' }}>
                                            <div
                                                className="h-full bg-indigo-500 rounded-full transition-all opacity-80 hover:opacity-100"
                                                style={{ width: `${(d.count / gradeMax) * 100}%` }}
                                            />
                                        </div>
                                        <span className="text-xs font-medium text-gray-700 w-12 text-right flex-shrink-0">
                                            {d.count.toLocaleString()}
                                        </span>
                                    </div>
                                ));
                            })()}
                        </div>
                    )}
                </div>

                {/* Students by Program/Trade */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-semibold text-gray-800">Students by Trade</h3>
                        <span className="text-sm text-gray-500">Top {stats.programDistribution.length}</span>
                    </div>
                    {stats.programDistribution.length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            <p>No program data available yet</p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {(() => {
                                const progMax = Math.max(...stats.programDistribution.map(d => d.count), 1);
                                return stats.programDistribution.map((d) => (
                                    <div key={d.label} className="flex items-center gap-3">
                                        <div className="w-28 text-xs text-gray-600 truncate flex-shrink-0" title={d.label}>
                                            {d.label}
                                        </div>
                                        <div className="flex-1 h-5 bg-gray-100 rounded-full overflow-hidden">
                                            <div
                                                className="h-full bg-teal-500 rounded-full transition-all opacity-80 hover:opacity-100"
                                                style={{ width: `${(d.count / progMax) * 100}%` }}
                                            />
                                        </div>
                                        <span className="text-xs font-medium text-gray-700 w-12 text-right flex-shrink-0">
                                            {d.count.toLocaleString()}
                                        </span>
                                    </div>
                                ));
                            })()}
                        </div>
                    )}
                </div>
            </div>

            {/* Row 4: Teacher Overview */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                {/* Teacher Demographics */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <h3 className="text-lg font-semibold text-gray-800 mb-4">Teacher Overview</h3>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        {/* Gender */}
                        <div>
                            <h4 className="text-sm font-medium text-gray-600 mb-3">Gender</h4>
                            <div className="flex flex-col items-center gap-3">
                                <GenderDonut
                                    male={stats.maleTeachers}
                                    female={stats.femaleTeachers}
                                />
                                <div className="flex items-center gap-3 flex-wrap justify-center">
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                                        <span className="text-xs text-gray-600">Male: {stats.maleTeachers.toLocaleString()}</span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-2.5 h-2.5 rounded-full bg-pink-500"></span>
                                        <span className="text-xs text-gray-600">Female: {stats.femaleTeachers.toLocaleString()}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Summary stats */}
                        <div>
                            <h4 className="text-sm font-medium text-gray-600 mb-3">Summary</h4>
                            <div className="space-y-3">
                                <div className="bg-amber-50 rounded-lg p-3">
                                    <p className="text-xs text-gray-500">Total Teachers</p>
                                    <p className="text-lg font-bold text-amber-700">{stats.totalTeachers.toLocaleString()}</p>
                                </div>
                                <div className="bg-blue-50 rounded-lg p-3">
                                    <p className="text-xs text-gray-500">Positions</p>
                                    <p className="text-lg font-bold text-blue-700">{stats.positionDistribution.length}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Teacher Position Distribution */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-semibold text-gray-800">Teachers by Position</h3>
                        <span className="text-sm text-gray-500">{stats.positionDistribution.reduce((s, d) => s + d.count, 0).toLocaleString()} total</span>
                    </div>
                    {stats.positionDistribution.length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            <p>No teacher data available yet</p>
                        </div>
                    ) : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
                            {(() => {
                                const posMax = Math.max(...stats.positionDistribution.map(d => d.count), 1);
                                return stats.positionDistribution.map((d) => (
                                    <div key={d.label} className="flex items-center gap-3">
                                        <div className="w-28 text-xs text-gray-600 truncate flex-shrink-0" title={d.label}>
                                            {d.label}
                                        </div>
                                        <div className="flex-1 h-6 bg-gray-100 rounded-full overflow-hidden">
                                            <div
                                                className="h-full bg-amber-500 rounded-full transition-all opacity-80 hover:opacity-100"
                                                style={{ width: `${(d.count / posMax) * 100}%` }}
                                            />
                                        </div>
                                        <span className="text-xs font-medium text-gray-700 w-12 text-right flex-shrink-0">
                                            {d.count.toLocaleString()}
                                        </span>
                                    </div>
                                ));
                            })()}
                        </div>
                    )}
                </div>
            </div>

            {/* Row 5: Platform Traffic */}
            <div className="gap-6">
                {/* Platform Traffic */}
                <div className="bg-white rounded-xl p-6 shadow-sm overflow-hidden">
                    <div className="flex items-center justify-between mb-4 flex-wrap gap-2">
                        <h3 className="text-lg font-semibold text-gray-800">Platform Traffic</h3>
                        <div className="flex items-center gap-2">
                            <label className="flex items-center gap-1 text-xs text-gray-500">
                                From
                                <input
                                    type="date"
                                    value={trafficFrom}
                                    max={trafficTo}
                                    onChange={(e) => setTrafficFrom((e.target as HTMLInputElement).value)}
                                    className="border border-gray-200 rounded-md px-2 py-1 text-xs text-gray-700 bg-white"
                                />
                            </label>
                            <label className="flex items-center gap-1 text-xs text-gray-500">
                                To
                                <input
                                    type="date"
                                    value={trafficTo}
                                    min={trafficFrom}
                                    max={todayStr}
                                    onChange={(e) => setTrafficTo((e.target as HTMLInputElement).value)}
                                    className="border border-gray-200 rounded-md px-2 py-1 text-xs text-gray-700 bg-white"
                                />
                            </label>
                        </div>
                    </div>
                    {trafficLoading ? (
                        <div className="flex items-end justify-between h-56 gap-2 px-2">
                            {[...Array(7)].map((_, i) => (
                                <div key={i} className="flex-1 bg-gray-100 rounded-t animate-pulse" style={{ height: `${30 + Math.random() * 50}%` }} />
                            ))}
                        </div>
                    ) : traffic.length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            <p>No traffic data available</p>
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <div className="flex items-end h-56 gap-2 px-2" style={{ minWidth: traffic.length > 15 ? `${traffic.length * 3}rem` : undefined }}>
                                    {traffic.map((point) => (
                                        <div key={point.period_label} className="flex flex-col items-center gap-1 flex-1" style={{ minWidth: '2rem' }}>
                                            <span className="text-xs font-medium text-gray-600 whitespace-nowrap">{point.unique_users}</span>
                                            <div className="w-full flex justify-center" style={{ height: '10rem' }}>
                                                <div className="flex items-end h-full w-full max-w-[2.5rem]">
                                                    <div
                                                        className="w-full bg-blue-500 rounded-t opacity-80 hover:opacity-100 transition-opacity"
                                                        style={{ height: `${(point.unique_users / trafficMax) * 100}%`, minHeight: point.unique_users > 0 ? '4px' : '0' }}
                                                    />
                                                </div>
                                            </div>
                                            <span className="text-xs text-gray-500 whitespace-nowrap">{point.period_label}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <div className="flex items-center gap-4 mt-3 pt-3 border-t border-gray-100">
                                <div className="flex items-center gap-1.5">
                                    <span className="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                                    <span className="text-xs text-gray-500">Unique Users</span>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
