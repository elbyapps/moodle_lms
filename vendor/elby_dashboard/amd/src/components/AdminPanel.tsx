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
 * Admin panel component for Elby Dashboard.
 *
 * Displays sync status, logs, manual triggers, and cache stats.
 *
 * @module     local_elby_dashboard/components/AdminPanel
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState, useEffect, useRef } from 'preact/hooks';
import type { UnlinkedUser, EnrollmentCoverageEntry, EnrollmentCoverageSummary, EnrollmentLogEntry, EnrollmentLogsSummary } from '../types';

// @ts-ignore
declare const require: (deps: string[], callback: (...args: any[]) => void) => void;

function ajaxCall(methodname: string, args: Record<string, any>): Promise<any> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (Ajax: any) => {
            Ajax.call([{ methodname, args }])[0].then(resolve).catch(reject);
        });
    });
}

interface AdminStats {
    linked_users: number;
    stale_users: number;
    error_users: number;
    total_schools: number;
    user_metrics_count: number;
    school_metrics_count: number;
}

interface SyncLogEntry {
    id: number;
    sync_type: string;
    entity_id: string;
    operation: string;
    error_message: string | null;
    triggered_by: string;
    timecreated: number;
}

interface LookupPreview {
    sdms_id: string;
    status: string;
    academic_year: string;
    school_name: string;
    student_name: string;
    program: string;
    position: string;
    user_type: string;
}

function formatTimestamp(ts: number): string {
    if (!ts) return 'Never';
    return new Date(ts * 1000).toLocaleString();
}

function StatCard({ label, value, icon, color }: { label: string; value: number | string; icon: JSX.Element; color: string }) {
    return (
        <div className={`rounded-xl p-4 ${color}`}>
            <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-full bg-white/40 flex items-center justify-center">
                    {icon}
                </div>
                <div>
                    <p className="text-2xl font-bold text-gray-800">{value}</p>
                    <p className="text-xs text-gray-600">{label}</p>
                </div>
            </div>
        </div>
    );
}

function OperationBadge({ operation }: { operation: string }) {
    const colors: Record<string, string> = {
        create: 'bg-green-100 text-green-700',
        update: 'bg-blue-100 text-blue-700',
        skip: 'bg-gray-100 text-gray-700',
        error: 'bg-red-100 text-red-700',
    };
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colors[operation] || 'bg-gray-100 text-gray-700'}`}>
            {operation}
        </span>
    );
}

function LinkUserSection({ onLinked }: { onLinked: () => void }) {
    const [search, setSearch] = useState('');
    const [users, setUsers] = useState<UnlinkedUser[]>([]);
    const [searching, setSearching] = useState(false);
    const [totalCount, setTotalCount] = useState(0);
    const [selectedUser, setSelectedUser] = useState<UnlinkedUser | null>(null);
    const [sdmsCode, setSdmsCode] = useState('');
    const [userType, setUserType] = useState('student');
    const [lookupPreview, setLookupPreview] = useState<LookupPreview | null>(null);
    const [lookingUp, setLookingUp] = useState(false);
    const [linking, setLinking] = useState(false);
    const [linkNotification, setLinkNotification] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    function handleSearchChange(value: string) {
        setSearch(value);
        setSelectedUser(null);
        setLookupPreview(null);
        setLinkNotification(null);

        if (searchTimer.current) clearTimeout(searchTimer.current);
        if (value.trim().length < 2) {
            setUsers([]);
            setTotalCount(0);
            return;
        }
        searchTimer.current = setTimeout(() => doSearch(value.trim()), 400);
    }

    async function doSearch(term: string) {
        setSearching(true);
        try {
            const result = await ajaxCall('local_elby_dashboard_search_unlinked_users', {
                search: term, page: 0, perpage: 20
            });
            setUsers(result.users || []);
            setTotalCount(result.total_count || 0);
        } catch {
            setUsers([]);
        } finally {
            setSearching(false);
        }
    }

    async function handleLookup() {
        if (!sdmsCode.trim()) return;
        setLookingUp(true);
        setLookupPreview(null);
        setLinkNotification(null);
        try {
            const result = await ajaxCall('local_elby_dashboard_lookup_sdms_user', {
                sdms_code: sdmsCode.trim(), user_type: userType
            });
            if (!result.success) {
                setLinkNotification({ type: 'error', message: result.error || 'Not found in SDMS' });
                return;
            }
            const name = result.student_data?.names || '';
            setLookupPreview({
                sdms_id: result.sdms_id,
                status: result.status || '',
                academic_year: result.academic_year || '',
                school_name: result.school?.school_name || '',
                student_name: name,
                program: result.student_data?.program || '',
                position: result.staff_data?.position || '',
                user_type: userType,
            });
        } catch (err: any) {
            setLinkNotification({ type: 'error', message: err?.message || 'Lookup failed' });
        } finally {
            setLookingUp(false);
        }
    }

    async function handleLink() {
        if (!selectedUser || !sdmsCode.trim()) return;
        setLinking(true);
        setLinkNotification(null);
        try {
            const result = await ajaxCall('local_elby_dashboard_link_user', {
                userid: selectedUser.userid, sdms_code: sdmsCode.trim(), user_type: userType
            });
            if (result.success) {
                setLinkNotification({ type: 'success', message: `Successfully linked ${selectedUser.fullname} to ${sdmsCode}` });
                setSelectedUser(null);
                setSdmsCode('');
                setLookupPreview(null);
                // Remove linked user from list.
                setUsers(prev => prev.filter(u => u.userid !== selectedUser.userid));
                onLinked();
            } else {
                setLinkNotification({ type: 'error', message: result.error || 'Link failed' });
            }
        } catch (err: any) {
            setLinkNotification({ type: 'error', message: err?.message || 'Link failed' });
        } finally {
            setLinking(false);
        }
    }

    return (
        <div className="bg-white rounded-xl p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-800 mb-4">Link User to SDMS</h3>

            {linkNotification && (
                <div className={`mb-4 px-4 py-3 rounded-lg text-sm ${
                    linkNotification.type === 'success'
                        ? 'bg-green-50 text-green-800 border border-green-200'
                        : 'bg-red-50 text-red-800 border border-red-200'
                }`}>
                    {linkNotification.message}
                    <button onClick={() => setLinkNotification(null)} className="float-right text-lg leading-none opacity-60 hover:opacity-100">&times;</button>
                </div>
            )}

            {/* Search unlinked users */}
            <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-2">Search Unlinked Users</label>
                <input
                    type="text"
                    value={search}
                    onInput={(e) => handleSearchChange((e.target as HTMLInputElement).value)}
                    placeholder="Search by name, username, or email..."
                    className="w-full px-4 py-3 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
                {searching && <p className="text-xs text-gray-400 mt-1">Searching...</p>}
                {!searching && totalCount > 0 && (
                    <p className="text-xs text-gray-500 mt-1">{totalCount} unlinked user{totalCount !== 1 ? 's' : ''} found</p>
                )}
            </div>

            {/* Results list */}
            {users.length > 0 && !selectedUser && (
                <div className="mb-4 max-h-48 overflow-y-auto border border-gray-200 rounded-lg">
                    {users.map(user => (
                        <button
                            key={user.userid}
                            onClick={() => { setSelectedUser(user); setSdmsCode(''); setLookupPreview(null); setLinkNotification(null); }}
                            className="w-full text-left px-4 py-2 hover:bg-gray-50 border-b border-gray-100 last:border-b-0 transition-colors"
                        >
                            <p className="text-sm font-medium text-gray-800">{user.fullname}</p>
                            <p className="text-xs text-gray-500">{user.username} &middot; {user.email}</p>
                        </button>
                    ))}
                </div>
            )}

            {/* Selected user - link form */}
            {selectedUser && (
                <div className="border border-blue-200 bg-blue-50 rounded-lg p-4 mb-4">
                    <div className="flex items-center justify-between mb-3">
                        <div>
                            <p className="text-sm font-semibold text-gray-800">{selectedUser.fullname}</p>
                            <p className="text-xs text-gray-500">{selectedUser.username} &middot; {selectedUser.email}</p>
                        </div>
                        <button
                            onClick={() => { setSelectedUser(null); setLookupPreview(null); }}
                            className="text-gray-400 hover:text-gray-600 text-lg"
                        >&times;</button>
                    </div>

                    <div className="flex gap-2 mb-3">
                        <input
                            type="text"
                            value={sdmsCode}
                            onInput={(e) => setSdmsCode((e.target as HTMLInputElement).value)}
                            placeholder="Enter SDMS code..."
                            className="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm"
                        />
                        <select
                            value={userType}
                            onChange={(e) => setUserType((e.target as HTMLSelectElement).value)}
                            className="px-3 py-2 border border-gray-300 rounded-lg text-sm"
                        >
                            <option value="student">Student</option>
                            <option value="staff">Staff</option>
                        </select>
                        <button
                            onClick={handleLookup}
                            disabled={lookingUp || !sdmsCode.trim()}
                            className="px-4 py-2 bg-gray-600 text-white rounded-lg text-sm hover:bg-gray-700 disabled:opacity-50"
                        >
                            {lookingUp ? '...' : 'Lookup'}
                        </button>
                    </div>

                    {/* Lookup preview */}
                    {lookupPreview && (
                        <div className="bg-white rounded-lg p-3 mb-3 border border-gray-200">
                            <p className="text-sm font-medium text-gray-800 mb-1">SDMS Preview</p>
                            <div className="grid grid-cols-2 gap-1 text-xs">
                                <span className="text-gray-500">SDMS ID:</span>
                                <span className="text-gray-800">{lookupPreview.sdms_id}</span>
                                {lookupPreview.student_name && <>
                                    <span className="text-gray-500">Name:</span>
                                    <span className="text-gray-800">{lookupPreview.student_name}</span>
                                </>}
                                <span className="text-gray-500">School:</span>
                                <span className="text-gray-800">{lookupPreview.school_name || '-'}</span>
                                <span className="text-gray-500">Status:</span>
                                <span className="text-gray-800">{lookupPreview.status || '-'}</span>
                                {lookupPreview.program && <>
                                    <span className="text-gray-500">Program:</span>
                                    <span className="text-gray-800">{lookupPreview.program}</span>
                                </>}
                                {lookupPreview.position && <>
                                    <span className="text-gray-500">Position:</span>
                                    <span className="text-gray-800">{lookupPreview.position}</span>
                                </>}
                            </div>
                        </div>
                    )}

                    {lookupPreview && (
                        <button
                            onClick={handleLink}
                            disabled={linking}
                            className="w-full px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50"
                        >
                            {linking ? 'Linking...' : `Link ${selectedUser.fullname} to ${sdmsCode}`}
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

function EnrollmentCoverageSection() {
    const [entries, setEntries] = useState<EnrollmentCoverageEntry[]>([]);
    const [summary, setSummary] = useState<EnrollmentCoverageSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState<'all' | 'mapped' | 'unmapped' | 'partial'>('all');

    useEffect(() => {
        loadCoverage();
    }, []);

    async function loadCoverage() {
        try {
            setLoading(true);
            const result = await ajaxCall('local_elby_dashboard_get_enrollment_coverage', {});
            setEntries(result.entries || []);
            setSummary(result.summary || null);
        } catch (err) {
            console.error('Failed to load enrollment coverage:', err);
        } finally {
            setLoading(false);
        }
    }

    const filtered = filter === 'all' ? entries : entries.filter(e => e.coverage_status === filter);

    const statusColors: Record<string, string> = {
        mapped: 'bg-green-100 text-green-700',
        unmapped: 'bg-red-100 text-red-700',
        partial: 'bg-yellow-100 text-yellow-700',
    };

    return (
        <div className="bg-white rounded-xl p-6 shadow-sm mb-6">
            <h3 className="text-lg font-semibold text-gray-800 mb-4">Enrollment Coverage</h3>

            {loading ? (
                <div className="animate-pulse space-y-3">
                    <div className="h-6 bg-gray-200 rounded w-64"></div>
                    <div className="h-48 bg-gray-200 rounded"></div>
                </div>
            ) : (
                <>
                    {summary && (
                        <div className="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
                            <div className="bg-gray-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-gray-800">{summary.total_combos}</p>
                                <p className="text-xs text-gray-500">Total Combos</p>
                            </div>
                            <div className="bg-green-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-green-700">{summary.mapped_combos}</p>
                                <p className="text-xs text-gray-500">Mapped</p>
                            </div>
                            <div className="bg-red-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-red-700">{summary.unmapped_combos}</p>
                                <p className="text-xs text-gray-500">Unmapped</p>
                            </div>
                            <div className="bg-blue-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-blue-700">{summary.total_sdms_students.toLocaleString()}</p>
                                <p className="text-xs text-gray-500">SDMS Students</p>
                            </div>
                            <div className="bg-cyan-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-cyan-700">{summary.total_enrolled_students.toLocaleString()}</p>
                                <p className="text-xs text-gray-500">Enrolled</p>
                            </div>
                        </div>
                    )}

                    <div className="flex gap-2 mb-4">
                        {(['all', 'mapped', 'unmapped', 'partial'] as const).map(f => (
                            <button
                                key={f}
                                onClick={() => setFilter(f)}
                                className={`px-3 py-1.5 text-xs rounded-full transition-colors ${
                                    filter === f
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                }`}
                            >
                                {f.charAt(0).toUpperCase() + f.slice(1)} ({f === 'all' ? entries.length : entries.filter(e => e.coverage_status === f).length})
                            </button>
                        ))}
                    </div>

                    {filtered.length === 0 ? (
                        <div className="text-center text-gray-400 py-6 text-sm">No entries found</div>
                    ) : (
                        <div className="overflow-x-auto max-h-96 overflow-y-auto">
                            <table className="w-full text-xs">
                                <thead className="sticky top-0 bg-white">
                                    <tr className="text-left text-gray-500 border-b border-gray-200">
                                        <th className="pb-2 font-medium">Trade</th>
                                        <th className="pb-2 font-medium">Level</th>
                                        <th className="pb-2 font-medium text-right">SDMS Students</th>
                                        <th className="pb-2 font-medium">Category</th>
                                        <th className="pb-2 font-medium text-right">Courses</th>
                                        <th className="pb-2 font-medium text-right">Enrolled</th>
                                        <th className="pb-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filtered.map((entry, i) => (
                                        <tr key={i} className={`border-b border-gray-50 ${entry.coverage_status === 'unmapped' ? 'bg-red-50/50' : ''}`}>
                                            <td className="py-2 text-gray-800">{entry.combination_name}</td>
                                            <td className="py-2 text-gray-600">{entry.level_name}</td>
                                            <td className="py-2 text-gray-600 text-right">{entry.sdms_student_count}</td>
                                            <td className="py-2 text-gray-600 truncate max-w-[120px]">{entry.category_name || '-'}</td>
                                            <td className="py-2 text-gray-600 text-right">{entry.course_count}</td>
                                            <td className="py-2 text-gray-600 text-right">{entry.enrolled_student_count}</td>
                                            <td className="py-2">
                                                <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${statusColors[entry.coverage_status] || 'bg-gray-100 text-gray-700'}`}>
                                                    {entry.coverage_status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function EnrollmentLogsSection() {
    const [logs, setLogs] = useState<EnrollmentLogEntry[]>([]);
    const [summary, setSummary] = useState<EnrollmentLogsSummary | null>(null);
    const [totalCount, setTotalCount] = useState(0);
    const [page, setPage] = useState(0);
    const [loading, setLoading] = useState(true);
    const [operationFilter, setOperationFilter] = useState('');
    const perpage = 25;

    useEffect(() => {
        loadLogs();
    }, [page, operationFilter]);

    async function loadLogs() {
        try {
            setLoading(true);
            const result = await ajaxCall('local_elby_dashboard_get_enrollment_logs', {
                page,
                perpage,
                operation: operationFilter,
            });
            setLogs(result.logs || []);
            setTotalCount(result.total_count || 0);
            setSummary(result.summary || null);
        } catch (err) {
            console.error('Failed to load enrollment logs:', err);
        } finally {
            setLoading(false);
        }
    }

    const totalPages = Math.ceil(totalCount / perpage);

    return (
        <div className="bg-white rounded-xl p-6 shadow-sm mb-6">
            <h3 className="text-lg font-semibold text-gray-800 mb-4">Auto-enrollment Logs</h3>

            {loading && !summary ? (
                <div className="animate-pulse space-y-3">
                    <div className="h-6 bg-gray-200 rounded w-64"></div>
                    <div className="h-48 bg-gray-200 rounded"></div>
                </div>
            ) : (
                <>
                    {summary && (
                        <div className="grid grid-cols-3 gap-3 mb-4">
                            <div className="bg-green-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-green-700">{summary.total_enrollments.toLocaleString()}</p>
                                <p className="text-xs text-gray-500">Auto-enrollments</p>
                            </div>
                            <div className="bg-gray-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-gray-700">{summary.total_skips.toLocaleString()}</p>
                                <p className="text-xs text-gray-500">Skipped</p>
                            </div>
                            <div className="bg-blue-50 rounded-lg p-3 text-center">
                                <p className="text-lg font-bold text-blue-700">
                                    {summary.last_enrollment_time ? formatTimestamp(summary.last_enrollment_time) : 'Never'}
                                </p>
                                <p className="text-xs text-gray-500">Last Enrollment</p>
                            </div>
                        </div>
                    )}

                    <div className="flex gap-2 mb-4">
                        {[
                            { value: '', label: 'All' },
                            { value: 'create', label: 'Enrolled' },
                            { value: 'skip', label: 'Skipped' },
                        ].map(opt => (
                            <button
                                key={opt.value}
                                onClick={() => { setOperationFilter(opt.value); setPage(0); }}
                                className={`px-3 py-1.5 text-xs rounded-full transition-colors ${
                                    operationFilter === opt.value
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                }`}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </div>

                    {logs.length === 0 ? (
                        <div className="text-center text-gray-400 py-6 text-sm">No enrollment logs found</div>
                    ) : (
                        <div className="overflow-x-auto max-h-80 overflow-y-auto">
                            <table className="w-full text-xs">
                                <thead className="sticky top-0 bg-white">
                                    <tr className="text-left text-gray-500 border-b border-gray-200">
                                        <th className="pb-2 font-medium">Time</th>
                                        <th className="pb-2 font-medium">Student</th>
                                        <th className="pb-2 font-medium">Trade:Level</th>
                                        <th className="pb-2 font-medium">Status</th>
                                        <th className="pb-2 font-medium">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.map(log => (
                                        <tr key={log.id} className="border-b border-gray-50">
                                            <td className="py-2 text-gray-500 whitespace-nowrap">{formatTimestamp(log.timecreated)}</td>
                                            <td className="py-2 text-gray-800">{log.user_fullname}</td>
                                            <td className="py-2 text-gray-600">{log.entity_id || '-'}</td>
                                            <td className="py-2"><OperationBadge operation={log.operation} /></td>
                                            <td className="py-2 text-gray-500 truncate max-w-[200px]">{log.details || '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {totalPages > 1 && (
                        <div className="flex items-center justify-between mt-4 pt-3 border-t border-gray-100">
                            <p className="text-xs text-gray-500">
                                Showing {page * perpage + 1}-{Math.min((page + 1) * perpage, totalCount)} of {totalCount}
                            </p>
                            <div className="flex gap-2">
                                <button
                                    onClick={() => setPage(p => Math.max(0, p - 1))}
                                    disabled={page === 0}
                                    className="px-3 py-1.5 text-xs bg-gray-100 rounded hover:bg-gray-200 disabled:opacity-50"
                                >
                                    Previous
                                </button>
                                <button
                                    onClick={() => setPage(p => Math.min(totalPages - 1, p + 1))}
                                    disabled={page >= totalPages - 1}
                                    className="px-3 py-1.5 text-xs bg-gray-100 rounded hover:bg-gray-200 disabled:opacity-50"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

export default function AdminPanel() {
    const [stats, setStats] = useState<AdminStats | null>(null);
    const [logs, setLogs] = useState<SyncLogEntry[]>([]);
    const [loading, setLoading] = useState(true);
    const [syncing, setSyncing] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

    useEffect(() => {
        loadAdminData();
    }, []);

    async function loadAdminData() {
        try {
            setLoading(true);
            // Read pre-loaded admin stats from data attributes.
            const container = document.getElementById('elby-dashboard-root');
            const adminDataAttr = container?.getAttribute('data-admin');
            if (adminDataAttr) {
                try {
                    const data = JSON.parse(adminDataAttr);
                    setStats(data.stats);
                    setLogs(data.logs || []);
                } catch (e) {
                    console.error('Failed to parse admin data:', e);
                }
            }
        } finally {
            setLoading(false);
        }
    }

    async function handleManualSync(type: string) {
        setSyncing(type);
        setNotification(null);
        try {
            const taskMap: Record<string, string> = {
                user_metrics: 'compute_user_metrics',
                school_metrics: 'aggregate_school_metrics',
                sdms_cache: 'refresh_sdms_cache',
                auto_link: 'auto_link_by_email',
            };
            const taskName = taskMap[type];
            if (!taskName) return;

            const result = await ajaxCall('local_elby_dashboard_trigger_task', { task_name: taskName });
            if (result.success) {
                setNotification({ type: 'success', message: result.message });
                loadAdminData();
            } else {
                setNotification({ type: 'error', message: result.message });
            }
        } catch (err: any) {
            setNotification({ type: 'error', message: err?.message || 'Failed to run task' });
        } finally {
            setSyncing(null);
        }
    }

    if (loading) {
        return (
            <div className="p-6 animate-pulse space-y-4">
                <div className="h-8 bg-gray-200 rounded w-48"></div>
                <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
                    {[1, 2, 3, 4, 5, 6].map(i => <div key={i} className="h-20 bg-gray-200 rounded-xl"></div>)}
                </div>
            </div>
        );
    }

    return (
        <div className="p-4 lg:p-6">
            {/* Stats Cards */}
            {stats && (
                <div className="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                    <StatCard
                        label="Linked Users"
                        value={stats.linked_users}
                        color="bg-cyan-50"
                        icon={<svg className="w-5 h-5 text-cyan-600" fill="currentColor" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>}
                    />
                    <StatCard
                        label="Stale Records"
                        value={stats.stale_users}
                        color="bg-yellow-50"
                        icon={<svg className="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>}
                    />
                    <StatCard
                        label="Sync Errors"
                        value={stats.error_users}
                        color="bg-red-50"
                        icon={<svg className="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>}
                    />
                    <StatCard
                        label="Schools"
                        value={stats.total_schools}
                        color="bg-blue-50"
                        icon={<svg className="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 24 24"><path d="M12 3L1 9l4 2.18v6L12 21l7-3.82v-6l2-1.09V17h2V9L12 3z"/></svg>}
                    />
                    <StatCard
                        label="User Metrics"
                        value={stats.user_metrics_count.toLocaleString()}
                        color="bg-green-50"
                        icon={<svg className="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>}
                    />
                    <StatCard
                        label="School Metrics"
                        value={stats.school_metrics_count.toLocaleString()}
                        color="bg-purple-50"
                        icon={<svg className="w-5 h-5 text-purple-600" fill="currentColor" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>}
                    />
                </div>
            )}

            {notification && (
                <div className={`mb-4 px-4 py-3 rounded-lg text-sm ${
                    notification.type === 'success'
                        ? 'bg-green-50 text-green-800 border border-green-200'
                        : 'bg-red-50 text-red-800 border border-red-200'
                }`}>
                    {notification.message}
                    <button onClick={() => setNotification(null)} className="float-right text-lg leading-none opacity-60 hover:opacity-100">&times;</button>
                </div>
            )}

            {/* Link User to SDMS */}
            <div className="mb-6">
                <LinkUserSection onLinked={loadAdminData} />
            </div>

            {/* Enrollment Coverage Report */}
            <EnrollmentCoverageSection />

            {/* Auto-enrollment Logs */}
            <EnrollmentLogsSection />

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Manual Sync Triggers */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <h3 className="text-lg font-semibold text-gray-800 mb-4">Manual Sync Triggers</h3>
                    <p className="text-sm text-gray-500 mb-4">
                        Run scheduled tasks manually from Site Admin &gt; Server &gt; Scheduled Tasks, or use the buttons below.
                    </p>
                    <div className="space-y-3">
                        <button
                            onClick={() => handleManualSync('user_metrics')}
                            disabled={syncing !== null}
                            className="w-full flex items-center justify-between px-4 py-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-50"
                        >
                            <div>
                                <p className="text-sm font-medium text-gray-800">Compute User Metrics</p>
                                <p className="text-xs text-gray-500">Hourly task - aggregates logstore data</p>
                            </div>
                            {syncing === 'user_metrics' && (
                                <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                            )}
                        </button>
                        <button
                            onClick={() => handleManualSync('school_metrics')}
                            disabled={syncing !== null}
                            className="w-full flex items-center justify-between px-4 py-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-50"
                        >
                            <div>
                                <p className="text-sm font-medium text-gray-800">Aggregate School Metrics</p>
                                <p className="text-xs text-gray-500">Daily task - rolls up to school level</p>
                            </div>
                            {syncing === 'school_metrics' && (
                                <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                            )}
                        </button>
                        <button
                            onClick={() => handleManualSync('sdms_cache')}
                            disabled={syncing !== null}
                            className="w-full flex items-center justify-between px-4 py-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-50"
                        >
                            <div>
                                <p className="text-sm font-medium text-gray-800">Refresh SDMS Cache</p>
                                <p className="text-xs text-gray-500">Daily task - refreshes stale SDMS records</p>
                            </div>
                            {syncing === 'sdms_cache' && (
                                <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                            )}
                        </button>
                        <button
                            onClick={() => handleManualSync('auto_link')}
                            disabled={syncing !== null}
                            className="w-full flex items-center justify-between px-4 py-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-50"
                        >
                            <div>
                                <p className="text-sm font-medium text-gray-800">Auto-Link Users by Email</p>
                                <p className="text-xs text-gray-500">Links unlinked users whose email contains their SDMS code</p>
                            </div>
                            {syncing === 'auto_link' && (
                                <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                            )}
                        </button>
                    </div>
                </div>

                {/* Recent Sync Logs */}
                <div className="bg-white rounded-xl p-6 shadow-sm">
                    <h3 className="text-lg font-semibold text-gray-800 mb-4">Recent Sync Logs</h3>
                    {logs.length === 0 ? (
                        <div className="text-center text-gray-400 py-8">
                            <p>No sync logs found</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto max-h-80 overflow-y-auto">
                            <table className="w-full text-xs">
                                <thead className="sticky top-0 bg-white">
                                    <tr className="text-left text-gray-500 border-b border-gray-100">
                                        <th className="pb-2 font-medium">Type</th>
                                        <th className="pb-2 font-medium">Entity</th>
                                        <th className="pb-2 font-medium">Op</th>
                                        <th className="pb-2 font-medium">Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.map(log => (
                                        <tr key={log.id} className="border-b border-gray-50">
                                            <td className="py-2 text-gray-600">{log.sync_type}</td>
                                            <td className="py-2 text-gray-600 truncate max-w-[80px]">{log.entity_id || '-'}</td>
                                            <td className="py-2"><OperationBadge operation={log.operation} /></td>
                                            <td className="py-2 text-gray-500">{formatTimestamp(log.timecreated)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
