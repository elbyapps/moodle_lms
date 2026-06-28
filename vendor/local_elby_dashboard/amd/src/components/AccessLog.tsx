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
 * Access log component for Elby Dashboard.
 *
 * Paginated table showing who accessed what course, from which school, when.
 *
 * @module     local_elby_dashboard/components/AccessLog
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState, useEffect } from 'preact/hooks';
import type { AccessLogEntry, AccessLogResponse } from '../types';

// @ts-ignore
declare const require: (deps: string[], callback: (...args: any[]) => void) => void;

function ajaxCall(methodname: string, args: Record<string, any>): Promise<any> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (Ajax: any) => {
            Ajax.call([{ methodname, args }])[0].then(resolve).catch(reject);
        });
    });
}

function formatDateTime(timestamp: number): string {
    if (!timestamp) return '-';
    return new Date(timestamp * 1000).toLocaleString();
}

function toDateInputValue(timestamp: number): string {
    const d = new Date(timestamp * 1000);
    return d.toISOString().split('T')[0];
}

function fromDateInput(value: string): number {
    if (!value) return 0;
    return Math.floor(new Date(value).getTime() / 1000);
}

export default function AccessLog() {
    const [entries, setEntries] = useState<AccessLogEntry[]>([]);
    const [totalCount, setTotalCount] = useState(0);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(0);
    const [perpage] = useState(10);
    const [sort, setSort] = useState('access_time');
    const [order, setOrder] = useState('DESC');

    // Default: last 7 days.
    const now = Math.floor(Date.now() / 1000);
    const [dateFrom, setDateFrom] = useState(now - 7 * 86400);
    const [dateTo, setDateTo] = useState(now);
    const [schoolCode, setSchoolCode] = useState('');
    const [userType, setUserType] = useState('');
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');

    useEffect(() => {
        loadLog();
    }, [page, sort, order, dateFrom, dateTo, schoolCode, userType, search]);

    async function loadLog() {
        try {
            setLoading(true);
            const result: AccessLogResponse = await ajaxCall('local_elby_dashboard_get_user_access_log', {
                date_from: dateFrom,
                date_to: dateTo,
                school_code: schoolCode,
                courseid: 0,
                user_type: userType,
                search,
                sort,
                order,
                page,
                perpage,
            });
            setEntries(result.entries);
            setTotalCount(result.total_count);
        } catch (err) {
            console.error('Failed to load access log:', err);
        } finally {
            setLoading(false);
        }
    }

    function handleSort(field: string) {
        if (sort === field) {
            setOrder(order === 'ASC' ? 'DESC' : 'ASC');
        } else {
            setSort(field);
            setOrder('DESC');
        }
        setPage(0);
    }

    function handleSearch() {
        setSearch(searchInput);
        setPage(0);
    }

    function handleExportCsv() {
        const headers = ['User', 'SDMS ID', 'Type', 'School', 'School Code', 'Course', 'Action', 'Target', 'Time'];
        const rows = entries.map(e => [
            e.user_fullname,
            e.sdms_id,
            e.user_type,
            e.school_name,
            e.school_code,
            e.course_name,
            e.action,
            e.target,
            e.access_time ? new Date(e.access_time * 1000).toISOString() : '',
        ]);
        const csv = [headers, ...rows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'access_log.csv';
        a.click();
        URL.revokeObjectURL(url);
    }

    const totalPages = Math.ceil(totalCount / perpage);
    const startItem = page * perpage + 1;
    const endItem = Math.min((page + 1) * perpage, totalCount);

    // Sort icon helper
    function SortIcon({ field }: { field: string }) {
        if (field !== sort) {
            return (
                <svg className="w-3 h-3 text-gray-400 ml-1 inline" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M7 10l5-5 5 5H7zm0 4l5 5 5-5H7z" />
                </svg>
            );
        }
        return order === 'ASC' ? (
            <svg className="w-3 h-3 text-blue-600 ml-1 inline" fill="currentColor" viewBox="0 0 24 24">
                <path d="M7 14l5-5 5 5H7z" />
            </svg>
        ) : (
            <svg className="w-3 h-3 text-blue-600 ml-1 inline" fill="currentColor" viewBox="0 0 24 24">
                <path d="M7 10l5 5 5-5H7z" />
            </svg>
        );
    }

    const sortableHeader = (label: string, field: string) => (
        <th
            className="pb-3 font-medium cursor-pointer hover:text-gray-800 select-none whitespace-nowrap"
            onClick={() => handleSort(field)}
        >
            {label}
            <SortIcon field={field} />
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
                            placeholder="Search by name or SDMS ID..."
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

                    {/* Date range */}
                    <input
                        type="date"
                        className="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={toDateInputValue(dateFrom)}
                        onChange={(e) => {
                            setDateFrom(fromDateInput((e.target as HTMLInputElement).value));
                            setPage(0);
                        }}
                    />
                    <span className="text-gray-400 text-sm">to</span>
                    <input
                        type="date"
                        className="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={toDateInputValue(dateTo)}
                        onChange={(e) => {
                            setDateTo(fromDateInput((e.target as HTMLInputElement).value) + 86399);
                            setPage(0);
                        }}
                    />

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

                    {/* User type filter */}
                    <select
                        className="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={userType}
                        onChange={(e) => {
                            setUserType((e.target as HTMLSelectElement).value);
                            setPage(0);
                        }}
                    >
                        <option value="">All Users</option>
                        <option value="student">Students</option>
                        <option value="teacher">Teachers</option>
                    </select>

                    {/* Export */}
                    <button
                        onClick={handleExportCsv}
                        className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50"
                    >
                        Export CSV
                    </button>
                </div>
            </div>

            {/* Table */}
            <div className="bg-white rounded-xl shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b border-gray-100">
                                {sortableHeader('Time', 'access_time')}
                                {sortableHeader('User', 'user_fullname')}
                                <th className="pb-3 font-medium">SDMS ID</th>
                                <th className="pb-3 font-medium">Type</th>
                                {sortableHeader('School', 'school_name')}
                                {sortableHeader('Course', 'course_name')}
                                <th className="pb-3 font-medium">Action</th>
                                <th className="pb-3 font-medium">Target</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                Array.from({ length: 5 }).map((_, i) => (
                                    <tr key={i} className="border-b border-gray-50">
                                        {Array.from({ length: 8 }).map((_, j) => (
                                            <td key={j} className="py-3 px-2">
                                                <div className="h-4 bg-gray-200 rounded animate-pulse"></div>
                                            </td>
                                        ))}
                                    </tr>
                                ))
                            ) : entries.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="py-12 text-center text-gray-500">
                                        No access log entries found
                                    </td>
                                </tr>
                            ) : (
                                entries.map((entry, idx) => (
                                    <tr key={idx} className="border-b border-gray-50 hover:bg-gray-50">
                                        <td className="py-3 px-2 text-gray-600 whitespace-nowrap">{formatDateTime(entry.access_time)}</td>
                                        <td className="py-3 px-2 font-medium text-gray-800">{entry.user_fullname}</td>
                                        <td className="py-3 px-2 text-gray-600">{entry.sdms_id || '-'}</td>
                                        <td className="py-3 px-2">
                                            {entry.user_type ? (
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                                                    entry.user_type === 'teacher' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'
                                                }`}>
                                                    {entry.user_type}
                                                </span>
                                            ) : '-'}
                                        </td>
                                        <td className="py-3 px-2 text-gray-600">{entry.school_name || '-'}</td>
                                        <td className="py-3 px-2 text-gray-600">{entry.course_name || '-'}</td>
                                        <td className="py-3 px-2 text-gray-600">{entry.action}</td>
                                        <td className="py-3 px-2 text-gray-600">{entry.target}</td>
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
