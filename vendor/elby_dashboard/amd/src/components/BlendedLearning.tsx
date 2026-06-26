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
 * Blended Learning report component for Elby Dashboard.
 *
 * Displays 12 blended learning indicators for courses under a configured
 * parent category, plus tabs for schools, students, and teachers.
 *
 * @module     local_elby_dashboard/components/BlendedLearning
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState, useEffect } from 'preact/hooks';
import type {
    BlendedLearningMetrics,
    BlendedLearningSchool,
    BlendedLearningStudent,
    BlendedLearningTeacher,
} from '../types';

// @ts-ignore
declare const require: (deps: string[], callback: (...args: any[]) => void) => void;

function ajaxCall(methodname: string, args: Record<string, any>): Promise<any> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (Ajax: any) => {
            Ajax.call([{ methodname, args }])[0].then(resolve).catch(reject);
        });
    });
}

type TabId = 'overview' | 'schools' | 'students' | 'teachers';
type DaysBack = 7 | 30 | 90 | 0;

// Color coding for rate metrics
function rateColor(value: number): string {
    if (value >= 70) return 'text-green-600';
    if (value >= 40) return 'text-amber-600';
    return 'text-red-600';
}

function rateBg(value: number): string {
    if (value >= 70) return 'bg-green-100';
    if (value >= 40) return 'bg-amber-100';
    return 'bg-red-100';
}

function rateBarColor(value: number): string {
    if (value >= 70) return 'bg-green-500';
    if (value >= 40) return 'bg-amber-500';
    return 'bg-red-500';
}

function formatDate(ts: number): string {
    if (!ts) return 'Never';
    return new Date(ts * 1000).toLocaleDateString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
    });
}

// ─── Metric Card ───

function MetricCard({ label, value, suffix, detail, isRate }: {
    label: string;
    value: number;
    suffix?: string;
    detail?: string;
    isRate?: boolean;
}) {
    const displayValue = suffix === '%' ? `${value}%` : value.toLocaleString();
    return (
        <div className={`rounded-lg border p-4 ${isRate ? rateBg(value) : 'bg-white border-gray-200'}`}>
            <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{label}</p>
            <p className={`text-2xl font-bold ${isRate ? rateColor(value) : 'text-gray-800'}`}>
                {displayValue}
                {suffix && suffix !== '%' && <span className="text-sm font-normal text-gray-500 ml-1">{suffix}</span>}
            </p>
            {isRate && (
                <div className="mt-2 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                    <div className={`h-full rounded-full ${rateBarColor(value)}`} style={{ width: `${Math.min(100, value)}%` }} />
                </div>
            )}
            {detail && <p className="text-xs text-gray-500 mt-1">{detail}</p>}
        </div>
    );
}

// ─── Loading skeleton ───

function LoadingSkeleton() {
    return (
        <div className="space-y-4 animate-pulse p-6">
            <div className="h-8 bg-gray-200 rounded w-48" />
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {Array.from({ length: 12 }).map((_, i) => (
                    <div key={i} className="h-24 bg-gray-200 rounded-lg" />
                ))}
            </div>
        </div>
    );
}

// ─── Overview Tab ───

function OverviewTab({ metrics }: { metrics: BlendedLearningMetrics }) {
    return (
        <div className="space-y-6">
            {/* Summary header */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div className="bg-blue-50 rounded-lg p-4 text-center">
                    <p className="text-2xl font-bold text-blue-700">{metrics.total_courses}</p>
                    <p className="text-xs text-blue-600">Total Courses</p>
                </div>
                <div className="bg-cyan-50 rounded-lg p-4 text-center">
                    <p className="text-2xl font-bold text-cyan-700">{metrics.total_enrolled_students.toLocaleString()}</p>
                    <p className="text-xs text-cyan-600">Students</p>
                </div>
                <div className="bg-amber-50 rounded-lg p-4 text-center">
                    <p className="text-2xl font-bold text-amber-700">{metrics.total_teachers.toLocaleString()}</p>
                    <p className="text-xs text-amber-600">Teachers</p>
                </div>
                <div className="bg-purple-50 rounded-lg p-4 text-center">
                    <p className="text-2xl font-bold text-purple-700">{metrics.category_name}</p>
                    <p className="text-xs text-purple-600">Category</p>
                </div>
            </div>

            {/* Student Metrics */}
            <div>
                <h3 className="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Student Indicators</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <MetricCard label="Active Student Rate" value={metrics.active_student_rate} suffix="%" isRate
                        detail={`${metrics.active_students} of ${metrics.total_enrolled_students} students logged in`} />
                    <MetricCard label="Activity Participation Rate" value={metrics.activity_participation_rate} suffix="%" isRate
                        detail={`${metrics.students_with_activity} students completed activities`} />
                    <MetricCard label="Avg Login Frequency" value={metrics.avg_login_frequency} suffix="per student" />
                    <MetricCard label="Avg Learning Time" value={metrics.avg_learning_time_minutes} suffix="min" />
                    <MetricCard label="Digital Assessment Score" value={metrics.digital_assessment_performance} suffix="%" isRate />
                    <MetricCard label="Assignment Submission Rate" value={metrics.assignment_submission_rate} suffix="%" isRate
                        detail={`${metrics.students_submitted_assignments} students submitted`} />
                    <MetricCard label="Quiz Participation Rate" value={metrics.quiz_participation_rate} suffix="%" isRate
                        detail={`${metrics.students_attempted_quizzes} students attempted quizzes`} />
                    <MetricCard label="Course Completion Rate" value={metrics.course_completion_rate} suffix="%" isRate
                        detail={`${metrics.students_completed_courses} students completed courses`} />
                    <MetricCard label="Forum Interaction Rate" value={metrics.forum_interaction_rate} suffix="%" isRate
                        detail={`${metrics.students_with_forum_posts} students posted in forums`} />
                </div>
            </div>

            {/* Teacher Metrics */}
            <div>
                <h3 className="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Teacher Indicators</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <MetricCard label="Teacher LMS Usage Rate" value={metrics.teacher_lms_usage_rate} suffix="%" isRate
                        detail={`${metrics.teachers_uploading_content} teachers uploading content`} />
                    <MetricCard label="Teachers with Active Courses" value={metrics.teachers_with_active_courses} />
                    <MetricCard label="Teacher LMS Activity Rate" value={metrics.teacher_lms_activity_rate} suffix="%" isRate
                        detail={`${metrics.teachers_with_activity} of ${metrics.total_teachers} teachers active`} />
                </div>
            </div>

            {/* Course Metrics */}
            <div>
                <h3 className="text-sm font-semibold text-gray-600 uppercase tracking-wide mb-3">Course Indicators</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <MetricCard label="Digital Course Availability" value={metrics.digital_course_availability} suffix="%" isRate
                        detail={`${metrics.courses_with_materials} of ${metrics.total_courses} courses have materials`} />
                </div>
            </div>
        </div>
    );
}

// ─── Schools Tab ───

function SchoolsTab({ schools, loading, searchQuery, schoolFilter }: { schools: BlendedLearningSchool[]; loading: boolean; searchQuery: string; schoolFilter: string }) {
    const [sortKey, setSortKey] = useState<keyof BlendedLearningSchool>('student_count');
    const [sortAsc, setSortAsc] = useState(false);

    if (loading) return <LoadingSkeleton />;
    if (schools.length === 0) return <div className="text-center py-12 text-gray-500">No schools found</div>;

    const filtered = schools.filter((s) => {
        if (schoolFilter && s.school_code !== schoolFilter) return false;
        if (searchQuery) {
            const q = searchQuery.toLowerCase();
            return s.school_name.toLowerCase().includes(q) || s.school_code.toLowerCase().includes(q);
        }
        return true;
    });

    const sorted = [...filtered].sort((a, b) => {
        const av = a[sortKey], bv = b[sortKey];
        const cmp = typeof av === 'number' ? (av as number) - (bv as number) : String(av).localeCompare(String(bv));
        return sortAsc ? cmp : -cmp;
    });

    const toggleSort = (key: keyof BlendedLearningSchool) => {
        if (sortKey === key) setSortAsc(!sortAsc);
        else { setSortKey(key); setSortAsc(false); }
    };

    const SortHeader = ({ label, field }: { label: string; field: keyof BlendedLearningSchool }) => (
        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
            onClick={() => toggleSort(field)}>
            {label} {sortKey === field && (sortAsc ? '\u2191' : '\u2193')}
        </th>
    );

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-12">#</th>
                        <SortHeader label="School" field="school_name" />
                        <SortHeader label="Students" field="student_count" />
                        <SortHeader label="Teachers" field="teacher_count" />
                        <SortHeader label="Completion Rate" field="avg_completion_rate" />
                        <SortHeader label="Active Rate" field="active_student_rate" />
                    </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                    {sorted.map((s, i) => (
                        <tr key={s.school_code} className="hover:bg-gray-50">
                            <td className="px-4 py-3 text-sm text-gray-500">{i + 1}</td>
                            <td className="px-4 py-3 text-sm font-medium text-gray-900">{s.school_name}</td>
                            <td className="px-4 py-3 text-sm text-gray-600">{s.student_count}</td>
                            <td className="px-4 py-3 text-sm text-gray-600">{s.teacher_count}</td>
                            <td className="px-4 py-3 text-sm">
                                <span className={`font-medium ${rateColor(s.avg_completion_rate)}`}>{s.avg_completion_rate}%</span>
                            </td>
                            <td className="px-4 py-3 text-sm">
                                <span className={`font-medium ${rateColor(s.active_student_rate)}`}>{s.active_student_rate}%</span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ─── Students Tab ───

function StudentsTab({ daysBack, schoolFilter, searchQuery }: { daysBack: DaysBack; schoolFilter: string; searchQuery: string }) {
    const [students, setStudents] = useState<BlendedLearningStudent[]>([]);
    const [totalCount, setTotalCount] = useState(0);
    const [page, setPage] = useState(0);
    const [loading, setLoading] = useState(true);
    const perpage = 50;

    useEffect(() => {
        setPage(0);
    }, [daysBack, schoolFilter]);

    useEffect(() => {
        setLoading(true);
        ajaxCall('local_elby_dashboard_get_blended_learning_students', {
            days_back: daysBack, school_code: schoolFilter, page, perpage,
        }).then((res: any) => {
            setStudents(res.students);
            setTotalCount(res.total_count);
        }).catch(() => setStudents([]))
          .finally(() => setLoading(false));
    }, [daysBack, schoolFilter, page]);

    if (loading) return <LoadingSkeleton />;

    const filteredStudents = searchQuery
        ? students.filter((s) => {
            const q = searchQuery.toLowerCase();
            return s.fullname.toLowerCase().includes(q) || s.school_name.toLowerCase().includes(q);
        })
        : students;

    const totalPages = Math.ceil(totalCount / perpage);

    return (
        <div>
            <p className="text-sm text-gray-500 mb-3">
                {searchQuery ? `${filteredStudents.length} of ${totalCount}` : totalCount} students found
            </p>
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-12">#</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">School</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Courses</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Completion</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Access</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {filteredStudents.map((s, i) => (
                            <tr key={s.userid} className="hover:bg-gray-50">
                                <td className="px-4 py-3 text-sm text-gray-500">{page * perpage + i + 1}</td>
                                <td className="px-4 py-3 text-sm font-medium text-gray-900">{s.fullname}</td>
                                <td className="px-4 py-3 text-sm text-gray-600">{s.school_name || '-'}</td>
                                <td className="px-4 py-3 text-sm text-gray-600">{s.courses_enrolled}</td>
                                <td className="px-4 py-3 text-sm">
                                    <span className={`font-medium ${rateColor(s.completion_pct)}`}>{s.completion_pct}%</span>
                                </td>
                                <td className="px-4 py-3 text-sm text-gray-600">{formatDate(s.last_access)}</td>
                            </tr>
                        ))}
                        {students.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-500">No students found</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
            {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                    <button onClick={() => setPage(Math.max(0, page - 1))} disabled={page === 0}
                        className="px-3 py-1 text-sm border rounded disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50">
                        Previous
                    </button>
                    <span className="text-sm text-gray-600">Page {page + 1} of {totalPages}</span>
                    <button onClick={() => setPage(Math.min(totalPages - 1, page + 1))} disabled={page >= totalPages - 1}
                        className="px-3 py-1 text-sm border rounded disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50">
                        Next
                    </button>
                </div>
            )}
        </div>
    );
}

// ─── Teachers Tab ───

function TeachersTab({ daysBack, schoolFilter, searchQuery }: { daysBack: DaysBack; schoolFilter: string; searchQuery: string }) {
    const [teachers, setTeachers] = useState<BlendedLearningTeacher[]>([]);
    const [totalCount, setTotalCount] = useState(0);
    const [page, setPage] = useState(0);
    const [loading, setLoading] = useState(true);
    const perpage = 50;

    useEffect(() => {
        setPage(0);
    }, [daysBack, schoolFilter]);

    useEffect(() => {
        setLoading(true);
        ajaxCall('local_elby_dashboard_get_blended_learning_teachers', {
            days_back: daysBack, school_code: schoolFilter, page, perpage,
        }).then((res: any) => {
            setTeachers(res.teachers);
            setTotalCount(res.total_count);
        }).catch(() => setTeachers([]))
          .finally(() => setLoading(false));
    }, [daysBack, schoolFilter, page]);

    if (loading) return <LoadingSkeleton />;

    const filteredTeachers = searchQuery
        ? teachers.filter((t) => {
            const q = searchQuery.toLowerCase();
            return t.fullname.toLowerCase().includes(q) || t.school_name.toLowerCase().includes(q);
        })
        : teachers;

    const totalPages = Math.ceil(totalCount / perpage);

    return (
        <div>
            <p className="text-sm text-gray-500 mb-3">
                {searchQuery ? `${filteredTeachers.length} of ${totalCount}` : totalCount} teachers found
            </p>
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-12">#</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">School</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Courses</th>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Activity Rate</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {filteredTeachers.map((t, i) => (
                            <tr key={t.userid} className="hover:bg-gray-50">
                                <td className="px-4 py-3 text-sm text-gray-500">{page * perpage + i + 1}</td>
                                <td className="px-4 py-3 text-sm font-medium text-gray-900">{t.fullname}</td>
                                <td className="px-4 py-3 text-sm text-gray-600">{t.school_name || '-'}</td>
                                <td className="px-4 py-3 text-sm text-gray-600">{t.courses_teaching}</td>
                                <td className="px-4 py-3 text-sm">
                                    <span className={`font-medium ${rateColor(t.activity_rate)}`}>{t.activity_rate}%</span>
                                </td>
                            </tr>
                        ))}
                        {teachers.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-500">No teachers found</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
            {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                    <button onClick={() => setPage(Math.max(0, page - 1))} disabled={page === 0}
                        className="px-3 py-1 text-sm border rounded disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50">
                        Previous
                    </button>
                    <span className="text-sm text-gray-600">Page {page + 1} of {totalPages}</span>
                    <button onClick={() => setPage(Math.min(totalPages - 1, page + 1))} disabled={page >= totalPages - 1}
                        className="px-3 py-1 text-sm border rounded disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50">
                        Next
                    </button>
                </div>
            )}
        </div>
    );
}

// ─── Main Component ───

export default function BlendedLearning() {
    const [activeTab, setActiveTab] = useState<TabId>('overview');
    const [daysBack, setDaysBack] = useState<DaysBack>(30);
    const [metrics, setMetrics] = useState<BlendedLearningMetrics | null>(null);
    const [schools, setSchools] = useState<BlendedLearningSchool[]>([]);
    const [loading, setLoading] = useState(true);
    const [schoolsLoading, setSchoolsLoading] = useState(false);
    const [error, setError] = useState('');
    const [schoolFilter, setSchoolFilter] = useState('');
    const [searchQuery, setSearchQuery] = useState('');

    // Fetch metrics on mount and when period changes.
    useEffect(() => {
        setLoading(true);
        setError('');
        ajaxCall('local_elby_dashboard_get_blended_learning_metrics', { days_back: daysBack })
            .then((res: BlendedLearningMetrics) => {
                setMetrics(res);
            })
            .catch((err: any) => {
                setError(err?.message || 'Failed to load metrics');
            })
            .finally(() => setLoading(false));
    }, [daysBack]);

    // Fetch schools when schools tab is activated.
    useEffect(() => {
        if (activeTab === 'schools' || activeTab === 'students' || activeTab === 'teachers') {
            setSchoolsLoading(true);
            ajaxCall('local_elby_dashboard_get_blended_learning_schools', { days_back: daysBack })
                .then((res: any) => setSchools(res.schools))
                .catch(() => setSchools([]))
                .finally(() => setSchoolsLoading(false));
        }
    }, [activeTab, daysBack]);

    const tabs: { id: TabId; label: string }[] = [
        { id: 'overview', label: 'Overview' },
        { id: 'schools', label: 'Schools' },
        { id: 'students', label: 'Students' },
        { id: 'teachers', label: 'Teachers' },
    ];

    const periods: { value: DaysBack; label: string }[] = [
        { value: 7, label: '7 Days' },
        { value: 30, label: '30 Days' },
        { value: 90, label: '90 Days' },
        { value: 0, label: 'All Time' },
    ];

    if (error) {
        return (
            <div className="p-6">
                <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
                    <p className="text-red-700 font-medium">{error}</p>
                    <p className="text-red-500 text-sm mt-2">Please check the Blended Learning category setting in plugin configuration.</p>
                </div>
            </div>
        );
    }

    return (
        <div className="p-4 sm:p-6 pb-16 space-y-6">
            {/* Toolbar */}
            <div className="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
                <div className="flex flex-wrap items-center gap-3">
                    {/* Period selector */}
                    <div className="flex items-center gap-2">
                        <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">Period</span>
                        <div className="flex rounded-lg border border-gray-200 overflow-hidden">
                            {periods.map((p) => (
                                <button
                                    key={p.value}
                                    onClick={() => setDaysBack(p.value)}
                                    className={`px-4 py-2 text-sm font-medium transition-colors border-r border-gray-200 last:border-r-0 ${
                                        daysBack === p.value
                                            ? 'bg-blue-600 text-white'
                                            : 'bg-white text-gray-600 hover:bg-gray-50'
                                    }`}
                                >
                                    {p.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* School filter */}
                    {activeTab !== 'overview' && schools.length > 0 && (
                        <div className="flex items-center gap-2">
                            <span className="text-xs font-medium text-gray-500 uppercase tracking-wide">School</span>
                            <div className="relative">
                                <select
                                    value={schoolFilter}
                                    onChange={(e) => setSchoolFilter((e.target as HTMLSelectElement).value)}
                                    className="appearance-none pl-4 pr-8 py-2 text-sm border border-gray-200 rounded-lg bg-white text-gray-700 hover:border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer"
                                >
                                    <option value="">All Schools</option>
                                    {schools.map((s) => (
                                        <option key={s.school_code} value={s.school_code}>{s.school_name}</option>
                                    ))}
                                </select>
                                <svg className="absolute right-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clipRule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    )}

                    {/* Search input */}
                    {activeTab !== 'overview' && (
                        <div className="flex items-center gap-2 ml-auto">
                            <div className="relative">
                                <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clipRule="evenodd" />
                                </svg>
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery((e.target as HTMLInputElement).value)}
                                    placeholder={`Search ${activeTab}...`}
                                    className="pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg bg-white text-gray-700 placeholder-gray-400 hover:border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-56"
                                />
                                {searchQuery && (
                                    <button
                                        onClick={() => setSearchQuery('')}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                    >
                                        <svg className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                        </svg>
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Tabs */}
            <div className="border-b border-gray-200">
                <nav className="flex gap-6">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={`pb-3 text-sm font-medium border-b-2 transition-colors ${
                                activeTab === tab.id
                                    ? 'border-blue-600 text-blue-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            </div>

            {/* Tab content */}
            {loading && activeTab === 'overview' ? (
                <LoadingSkeleton />
            ) : (
                <>
                    {activeTab === 'overview' && metrics && <OverviewTab metrics={metrics} />}
                    {activeTab === 'schools' && <SchoolsTab schools={schools} loading={schoolsLoading} searchQuery={searchQuery} schoolFilter={schoolFilter} />}
                    {activeTab === 'students' && <StudentsTab daysBack={daysBack} schoolFilter={schoolFilter} searchQuery={searchQuery} />}
                    {activeTab === 'teachers' && <TeachersTab daysBack={daysBack} schoolFilter={schoolFilter} searchQuery={searchQuery} />}
                </>
            )}
        </div>
    );
}
