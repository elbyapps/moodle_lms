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
 * Student list component for Elby Dashboard.
 *
 * Paginated, sortable student metrics table with filters and CSV export.
 *
 * @module     local_elby_dashboard/components/StudentList
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState, useEffect } from 'preact/hooks';
import type { StudentMetric, StudentListResponse, StudentListSummary, ProgramOption, UserData } from '../types';

// @ts-ignore
declare const require: (deps: string[], callback: (...args: any[]) => void) => void;

function ajaxCall(methodname: string, args: Record<string, any>): Promise<any> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (Ajax: any) => {
            Ajax.call([{ methodname, args }])[0].then(resolve).catch(reject);
        });
    });
}

function formatTimeAgo(timestamp: number): string {
    if (!timestamp) return 'Never';
    const diff = Math.floor(Date.now() / 1000) - timestamp;
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return new Date(timestamp * 1000).toLocaleDateString();
}

function StatusBadge({ status }: { status: string }) {
    const isActive = status === 'active';
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
            isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
        }`}>
            {isActive ? 'Active' : 'Inactive'}
        </span>
    );
}

// Sort icon
function SortIcon({ field, currentSort, currentOrder }: { field: string; currentSort: string; currentOrder: string }) {
    if (field !== currentSort) {
        return (
            <svg className="w-3 h-3 text-gray-400 ml-1 inline" fill="currentColor" viewBox="0 0 24 24">
                <path d="M7 10l5-5 5 5H7zm0 4l5 5 5-5H7z" />
            </svg>
        );
    }
    return currentOrder === 'ASC' ? (
        <svg className="w-3 h-3 text-blue-600 ml-1 inline" fill="currentColor" viewBox="0 0 24 24">
            <path d="M7 14l5-5 5 5H7z" />
        </svg>
    ) : (
        <svg className="w-3 h-3 text-blue-600 ml-1 inline" fill="currentColor" viewBox="0 0 24 24">
            <path d="M7 10l5 5 5-5H7z" />
        </svg>
    );
}

interface SchoolOption {
    school_code: string;
    school_name: string;
}

interface StudentListProps {
    initialSchoolCode?: string;
    initialCourseId?: number;
    userType?: 'student' | 'teacher';
    user?: UserData;
}

export default function StudentList({ initialSchoolCode = '', initialCourseId = 0, userType = 'student', user }: StudentListProps) {
    const isTeacher = userType === 'teacher';
    const canManage = isTeacher && !!user?.canManage;
    const [students, setStudents] = useState<StudentMetric[]>([]);
    const [totalCount, setTotalCount] = useState(0);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(0);
    const [perpage] = useState(10);
    const [sort, setSort] = useState('lastname');
    const [order, setOrder] = useState('ASC');
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [schoolCode, setSchoolCode] = useState(initialSchoolCode);
    const [engagementLevel, setEngagementLevel] = useState('');
    const [programCode, setProgramCode] = useState('');
    const [summary, setSummary] = useState<StudentListSummary | null>(null);
    const [programs, setPrograms] = useState<ProgramOption[]>([]);

    // School edit state (admin only).
    const [schoolsList, setSchoolsList] = useState<SchoolOption[]>([]);
    const [editingUserId, setEditingUserId] = useState<number | null>(null);
    const [editingSchoolCode, setEditingSchoolCode] = useState('');
    const [saving, setSaving] = useState(false);

    // Fetch schools list for admin dropdown.
    useEffect(() => {
        if (!canManage) return;
        ajaxCall('local_elby_dashboard_get_schools_list', {})
            .then((result: { schools: SchoolOption[] }) => setSchoolsList(result.schools))
            .catch((err: any) => console.error('Failed to load schools list:', err));
    }, [canManage]);

    useEffect(() => {
        loadStudents();
    }, [page, sort, order, search, schoolCode, engagementLevel, programCode]);

    async function loadStudents() {
        try {
            setLoading(true);
            const result: StudentListResponse = await ajaxCall('local_elby_dashboard_get_student_list', {
                school_code: schoolCode,
                courseid: initialCourseId,
                sort,
                order,
                page,
                perpage,
                search,
                engagement_level: engagementLevel,
                user_type: userType,
                program_code: programCode,
            });
            setStudents(result.students);
            setTotalCount(result.total_count);
            if (result.summary) {
                setSummary(result.summary);
            }
            if (result.programs && result.programs.length > 0) {
                setPrograms(result.programs);
            }
        } catch (err) {
            console.error('Failed to load students:', err);
        } finally {
            setLoading(false);
        }
    }

    async function handleSaveSchool(userid: number) {
        if (!editingSchoolCode) return;
        setSaving(true);
        try {
            const result = await ajaxCall('local_elby_dashboard_update_user_school', {
                userid,
                school_code: editingSchoolCode,
            });
            if (result.success) {
                setStudents(prev => prev.map(s =>
                    s.userid === userid
                        ? { ...s, school_code: result.school_code, school_name: result.school_name }
                        : s
                ));
                setEditingUserId(null);
            } else {
                console.error('Failed to update school:', result.error);
            }
        } catch (err) {
            console.error('Failed to update school:', err);
        } finally {
            setSaving(false);
        }
    }

    function handleSort(field: string) {
        if (sort === field) {
            setOrder(order === 'ASC' ? 'DESC' : 'ASC');
        } else {
            setSort(field);
            setOrder('ASC');
        }
        setPage(0);
    }

    function handleSearch() {
        setSearch(searchInput);
        setPage(0);
    }

    function handleExportCsv() {
        const roleLabel = isTeacher ? 'Position' : 'Trade';
        const headers = ['Name', 'SDMS ID', roleLabel, ...(isTeacher ? [] : ['Level']), 'Gender', 'School Name', 'School Code', 'Last Active', 'Active Days', 'Total Actions', 'Quiz Avg', 'Progress', ...(isTeacher ? [] : ['Enrolled Courses']), 'Status'];
        const rows = students.map(s => [
            s.fullname,
            s.sdms_id,
            isTeacher ? (s.position || '') : (s.program || ''),
            ...(isTeacher ? [] : [s.class_grade || '']),
            s.gender || '',
            s.school_name,
            s.school_code,
            s.last_access ? new Date(s.last_access * 1000).toISOString() : 'Never',
            String(s.active_days),
            String(s.total_actions),
            s.quizzes_avg_score !== null && s.quizzes_avg_score !== undefined ? String(s.quizzes_avg_score) : '',
            s.course_progress !== null && s.course_progress !== undefined ? String(s.course_progress) : '',
            ...(isTeacher ? [] : [String(s.enrolled_courses)]),
            s.status,
        ]);
        const csv = [headers, ...rows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = isTeacher ? 'teacher_metrics.csv' : 'student_metrics.csv';
        a.click();
        URL.revokeObjectURL(url);
    }

    const totalPages = Math.ceil(totalCount / perpage);
    const startItem = page * perpage + 1;
    const endItem = Math.min((page + 1) * perpage, totalCount);

    const sortableHeader = (label: string, field: string) => (
        <th
            className="pb-3 font-medium cursor-pointer hover:text-gray-800 select-none whitespace-nowrap"
            onClick={() => handleSort(field)}
        >
            <span className="inline-flex items-center gap-0.5">
                {label}
                <SortIcon field={field} currentSort={sort} currentOrder={order} />
            </span>
        </th>
    );

    return (
        <div className="p-4 lg:p-6">
            {/* Filters */}
            <div className="bg-white rounded-xl p-4 shadow-sm mb-6">
                <div className="flex flex-wrap items-center gap-3">
                    {/* Search */}
                    <div className="flex-1 min-w-[200px] flex gap-2">
                        <input
                            type="text"
                            placeholder={isTeacher ? "Search teachers..." : "Search students..."}
                            className="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value={searchInput}
                            onInput={(e) => setSearchInput((e.target as HTMLInputElement).value)}
                            onKeyDown={(e) => { if (e.key === 'Enter') handleSearch(); }}
                        />
                        <button
                            onClick={handleSearch}
                            className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700"
                        >
                            Search
                        </button>
                    </div>

                    {/* School filter */}
                    <input
                        type="text"
                        placeholder="School code"
                        className="w-32 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={schoolCode}
                        onInput={(e) => {
                            setSchoolCode((e.target as HTMLInputElement).value);
                            setPage(0);
                        }}
                    />

                    {/* Engagement filter */}
                    <select
                        className="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={engagementLevel}
                        onChange={(e) => {
                            setEngagementLevel((e.target as HTMLSelectElement).value);
                            setPage(0);
                        }}
                    >
                        <option value="">All Engagement</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                        <option value="at_risk">Inactive</option>
                    </select>

                    {/* Program/Trade filter */}
                    {!isTeacher && programs.length > 0 && (
                        <select
                            className="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value={programCode}
                            onChange={(e) => {
                                setProgramCode((e.target as HTMLSelectElement).value);
                                setPage(0);
                            }}
                        >
                            <option value="">All Programs</option>
                            {programs.map(p => (
                                <option key={p.code} value={p.code}>{p.name}</option>
                            ))}
                        </select>
                    )}

                    {/* Export */}
                    <button
                        onClick={handleExportCsv}
                        className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50"
                    >
                        Export CSV
                    </button>
                </div>
            </div>

            {/* Summary Cards */}
            {summary && (
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div className="bg-white rounded-xl p-4 shadow-sm">
                        <div className="text-2xl font-bold text-gray-800">{summary.total.toLocaleString()}</div>
                        <div className="text-sm text-gray-500">{isTeacher ? 'Total Teachers' : 'Total Students'}</div>
                    </div>
                    <div className="bg-white rounded-xl p-4 shadow-sm">
                        <div className="text-2xl font-bold text-green-600">{summary.active_count.toLocaleString()}</div>
                        <div className="text-sm text-gray-500">Active</div>
                    </div>
                    <div className="bg-white rounded-xl p-4 shadow-sm">
                        <div className="text-2xl font-bold text-red-600">{summary.at_risk_count.toLocaleString()}</div>
                        <div className="text-sm text-gray-500">Inactive</div>
                    </div>
                    <div className="bg-white rounded-xl p-4 shadow-sm">
                        <div className="text-2xl font-bold text-blue-600">
                            {summary.avg_quiz_score !== null && summary.avg_quiz_score !== undefined
                                ? `${summary.avg_quiz_score}%` : '-'}
                        </div>
                        <div className="text-sm text-gray-500">Avg Quiz Score</div>
                    </div>
                </div>
            )}

            {/* Table */}
            <div className="bg-white rounded-xl shadow-sm overflow-hidden">
                <div className="overflow-x-auto p-4">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b border-gray-100">
                                {sortableHeader('Name', 'lastname')}
                                <th className="pb-3 font-medium">SDMS ID</th>
                                <th className="pb-3 font-medium">{isTeacher ? 'Position' : 'Trade'}</th>
                                {!isTeacher && <th className="pb-3 font-medium">Level</th>}
                                <th className="pb-3 font-medium">Gender</th>
                                <th className="pb-3 font-medium">School Name</th>
                                <th className="pb-3 font-medium">School Code</th>
                                {sortableHeader('Last Active', 'last_access')}
                                {sortableHeader('Active Days', 'active_days')}
                                {sortableHeader('Actions', 'total_actions')}
                                {sortableHeader('Quiz Avg', 'quizzes_avg_score')}
                                {sortableHeader('Progress', 'course_progress')}
                                {!isTeacher && sortableHeader('Enrolled Courses', 'enrolled_courses')}
                                <th className="pb-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                Array.from({ length: 5 }).map((_, i) => (
                                    <tr key={i} className="border-b border-gray-50">
                                        {Array.from({ length: isTeacher ? 12 : 14 }).map((_, j) => (
                                            <td key={j} className="py-3 px-2">
                                                <div className="h-4 bg-gray-200 rounded animate-pulse"></div>
                                            </td>
                                        ))}
                                    </tr>
                                ))
                            ) : students.length === 0 ? (
                                <tr>
                                    <td colSpan={isTeacher ? 12 : 14} className="py-12 text-center text-gray-500">
                                        {isTeacher ? 'No teachers found' : 'No students found'}
                                    </td>
                                </tr>
                            ) : (
                                students.map(student => (
                                    <tr key={student.userid} className="border-b border-gray-50 hover:bg-gray-50">
                                        <td className="py-3 px-2 font-medium text-gray-800">{student.fullname}</td>
                                        <td className="py-3 px-2 text-gray-600">{student.sdms_id}</td>
                                        <td className="py-3 px-2 text-gray-600">{isTeacher ? (student.position || '-') : (student.program || '-')}</td>
                                        {!isTeacher && <td className="py-3 px-2 text-gray-600">{student.class_grade || '-'}</td>}
                                        <td className="py-3 px-2 text-gray-600">{student.gender || '-'}</td>
                                        <td className="py-3 px-2 text-gray-600">{student.school_name || '-'}</td>
                                        <td className="py-3 px-2 text-gray-600">
                                            {canManage && editingUserId === student.userid ? (
                                                <div className="flex items-center gap-1">
                                                    <select
                                                        className="px-2 py-1 border border-gray-300 rounded text-xs focus:outline-none focus:ring-1 focus:ring-blue-500"
                                                        value={editingSchoolCode}
                                                        onChange={(e) => setEditingSchoolCode((e.target as HTMLSelectElement).value)}
                                                        disabled={saving}
                                                    >
                                                        <option value="">Select school...</option>
                                                        {schoolsList.map(s => (
                                                            <option key={s.school_code} value={s.school_code}>
                                                                {s.school_code} - {s.school_name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <button
                                                        onClick={() => handleSaveSchool(student.userid)}
                                                        disabled={saving || !editingSchoolCode}
                                                        className="px-2 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
                                                    >
                                                        {saving ? '...' : 'Save'}
                                                    </button>
                                                    <button
                                                        onClick={() => setEditingUserId(null)}
                                                        disabled={saving}
                                                        className="px-2 py-1 text-xs border border-gray-300 rounded hover:bg-gray-100 disabled:opacity-50"
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                            ) : canManage ? (
                                                <button
                                                    className="text-blue-600 hover:text-blue-800 hover:underline cursor-pointer text-left"
                                                    onClick={() => {
                                                        setEditingUserId(student.userid);
                                                        setEditingSchoolCode(student.school_code || '');
                                                    }}
                                                    title="Click to change school"
                                                >
                                                    {student.school_code || '-'}
                                                </button>
                                            ) : (
                                                student.school_code || '-'
                                            )}
                                        </td>
                                        <td className="py-3 px-2 text-gray-600">{formatTimeAgo(student.last_access)}</td>
                                        <td className="py-3 px-2 text-gray-800">{student.active_days}</td>
                                        <td className="py-3 px-2 text-gray-800">{student.total_actions}</td>
                                        <td className="py-3 px-2 text-gray-800">
                                            {student.quizzes_avg_score !== null && student.quizzes_avg_score !== undefined
                                                ? `${student.quizzes_avg_score}%` : '-'}
                                        </td>
                                        <td className="py-3 px-2">
                                            {student.course_progress !== null && student.course_progress !== undefined ? (
                                                <div className="flex items-center gap-2">
                                                    <div className="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                                        <div
                                                            className="h-full bg-blue-500 rounded-full"
                                                            style={{ width: `${student.course_progress}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-xs text-gray-600">{student.course_progress}%</span>
                                                </div>
                                            ) : '-'}
                                        </td>
                                        {!isTeacher && (
                                            <td className="py-3 px-2 text-gray-800">{student.enrolled_courses}</td>
                                        )}
                                        <td className="py-3 px-2">
                                            <StatusBadge status={student.status} />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {totalCount > 0 && (
                    <div className="flex items-center justify-between px-4 py-3 border-t border-gray-100">
                        <span className="text-sm text-gray-600">
                            {startItem}-{endItem} of {totalCount.toLocaleString()}
                        </span>
                        <div className="flex gap-2">
                            <button
                                onClick={() => setPage(Math.max(0, page - 1))}
                                disabled={page === 0}
                                className="px-3 py-1 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Previous
                            </button>
                            <span className="px-3 py-1 text-sm text-gray-600">
                                Page {page + 1} of {totalPages}
                            </span>
                            <button
                                onClick={() => setPage(Math.min(totalPages - 1, page + 1))}
                                disabled={page >= totalPages - 1}
                                className="px-3 py-1 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
