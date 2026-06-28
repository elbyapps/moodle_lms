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
 * Traffic report component for Elby Dashboard.
 *
 * Shows daily/weekly/monthly platform traffic trends as a bar chart,
 * with heatmap, top users, school breakdown, and action type donut.
 *
 * @module     local_elby_dashboard/components/TrafficReport
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState, useEffect } from 'preact/hooks';
import type { TrafficDataPoint, HeatmapDataPoint, TopActiveUser, TrafficBySchool, ActionBreakdown } from '../types';

// @ts-ignore
declare const require: (deps: string[], callback: (...args: any[]) => void) => void;

function ajaxCall(methodname: string, args: Record<string, any>): Promise<any> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (Ajax: any) => {
            Ajax.call([{ methodname, args }])[0].then(resolve).catch(reject);
        });
    });
}

type Period = 'daily' | 'weekly' | 'monthly';

// ─── Sub-components ───

const DONUT_COLORS = ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#6b7280'];

function ActionDonut({ data }: { data: ActionBreakdown[] }) {
    const total = data.reduce((sum, d) => sum + d.action_count, 0);
    if (total === 0) {
        return <div className="text-center py-12 text-gray-500">No data</div>;
    }

    const top = data.slice(0, 7);
    const otherCount = data.slice(7).reduce((s, d) => s + d.action_count, 0);
    const segments = otherCount > 0
        ? [...top, { component: 'other', label: 'Other', action_count: otherCount }]
        : top;

    const circumference = 2 * Math.PI * 40;
    const offsets: number[] = [];
    let cumulative = 0;
    for (const seg of segments) {
        offsets.push(cumulative);
        cumulative += (seg.action_count / total) * circumference;
    }

    return (
        <div className="flex flex-col items-center gap-4">
            <div className="relative w-44 h-44">
                <svg viewBox="0 0 100 100" className="w-full h-full" style={{ transform: 'rotate(-90deg)' }}>
                    <circle cx="50" cy="50" r="40" fill="none" stroke="#e5e7eb" strokeWidth="14" />
                    {segments.map((seg, i) => {
                        const len = (seg.action_count / total) * circumference;
                        return (
                            <circle key={i} cx="50" cy="50" r="40" fill="none"
                                stroke={DONUT_COLORS[i % DONUT_COLORS.length]} strokeWidth="14"
                                strokeDasharray={`${len} ${circumference - len}`}
                                strokeDashoffset={`${-offsets[i]}`}
                            />
                        );
                    })}
                </svg>
                <div className="absolute inset-0 flex items-center justify-center">
                    <span className="text-2xl font-bold text-gray-800">{total.toLocaleString()}</span>
                </div>
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-1 justify-center">
                {segments.map((seg, i) => (
                    <div key={i} className="flex items-center gap-1.5">
                        <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ backgroundColor: DONUT_COLORS[i % DONUT_COLORS.length] }} />
                        <span className="text-xs text-gray-600">{seg.label} ({seg.action_count.toLocaleString()})</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function SchoolTrafficBars({ data }: { data: TrafficBySchool[] }) {
    const maxActions = data.length > 0 ? Math.max(...data.map(d => d.total_actions)) : 1;
    return (
        <div className="space-y-3">
            {data.map((school) => (
                <div key={school.school_code} className="flex items-center gap-3">
                    <div className="w-28 text-xs text-gray-600 truncate flex-shrink-0" title={school.school_name}>
                        {school.school_name.length > 18 ? school.school_name.slice(0, 18) + '...' : school.school_name}
                    </div>
                    <div className="flex-1 h-6 bg-gray-100 rounded-full overflow-hidden">
                        <div
                            className="h-full bg-indigo-500 rounded-full transition-all opacity-80 hover:opacity-100"
                            style={{ width: `${(school.total_actions / maxActions) * 100}%`, minWidth: '4px' }}
                            title={`${school.total_actions.toLocaleString()} actions, ${school.unique_users} users`}
                        />
                    </div>
                    <span className="text-xs font-medium text-gray-700 w-14 text-right flex-shrink-0">
                        {school.total_actions.toLocaleString()}
                    </span>
                </div>
            ))}
        </div>
    );
}

function PeakHoursHeatmap({ data }: { data: HeatmapDataPoint[] }) {
    const dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const maxCount = Math.max(...data.map(d => d.action_count), 1);

    const grid: number[][] = Array.from({ length: 7 }, () => Array(24).fill(0));
    data.forEach(d => { grid[d.day_of_week][d.hour_of_day] = d.action_count; });

    function getColor(count: number): string {
        if (count === 0) return '#f3f4f6';
        const intensity = count / maxCount;
        if (intensity < 0.25) return '#dbeafe';
        if (intensity < 0.5) return '#93c5fd';
        if (intensity < 0.75) return '#3b82f6';
        return '#1d4ed8';
    }

    return (
        <div className="overflow-x-auto">
            <div style={{ display: 'grid', gridTemplateColumns: '48px repeat(24, 1fr)', gap: '2px' }}>
                {/* Header row */}
                <div />
                {Array.from({ length: 24 }, (_, h) => (
                    <div key={h} className="text-center text-[10px] text-gray-400 pb-1">
                        {h.toString().padStart(2, '0')}
                    </div>
                ))}
                {/* Data rows */}
                {dayLabels.map((label, day) => (
                    <div key={day} style={{ display: 'contents' }}>
                        <div className="text-xs text-gray-600 flex items-center pr-2 justify-end">
                            {label}
                        </div>
                        {Array.from({ length: 24 }, (_, hour) => {
                            const count = grid[day][hour];
                            return (
                                <div
                                    key={hour}
                                    className="rounded-sm hover:ring-2 hover:ring-blue-400 cursor-default"
                                    style={{ backgroundColor: getColor(count), aspectRatio: '1', minHeight: '16px' }}
                                    title={`${label} ${hour}:00 — ${count.toLocaleString()} actions`}
                                />
                            );
                        })}
                    </div>
                ))}
            </div>
            <div className="flex items-center gap-2 mt-3 text-xs text-gray-500">
                <span>Less</span>
                {['#f3f4f6', '#dbeafe', '#93c5fd', '#3b82f6', '#1d4ed8'].map((c, i) => (
                    <div key={i} className="w-4 h-4 rounded-sm" style={{ backgroundColor: c }} />
                ))}
                <span>More</span>
            </div>
        </div>
    );
}

function TopUsersTable({ data }: { data: TopActiveUser[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-left text-gray-500 border-b border-gray-100">
                        <th className="pb-2 font-medium w-8">#</th>
                        <th className="pb-2 font-medium">Name</th>
                        <th className="pb-2 font-medium">School</th>
                        <th className="pb-2 font-medium">Type</th>
                        <th className="pb-2 font-medium text-right">Actions</th>
                        <th className="pb-2 font-medium text-right">Active Days</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((user, i) => (
                        <tr key={user.userid} className="border-b border-gray-50 hover:bg-gray-50">
                            <td className="py-2 text-gray-400">{i + 1}</td>
                            <td className="py-2 text-gray-800 font-medium">{user.fullname}</td>
                            <td className="py-2 text-gray-600">{user.school_name || '-'}</td>
                            <td className="py-2 text-gray-600 capitalize">{user.user_type || '-'}</td>
                            <td className="py-2 text-right text-gray-800">{user.total_actions.toLocaleString()}</td>
                            <td className="py-2 text-right text-gray-800">{user.active_days}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function LoadingBlock({ height = 'h-48' }: { height?: string }) {
    return <div className={`${height} bg-gray-100 rounded-xl animate-pulse`} />;
}

// ─── Main component ───

export default function TrafficReport() {
    const [data, setData] = useState<TrafficDataPoint[]>([]);
    const [loading, setLoading] = useState(true);
    const [period, setPeriod] = useState<Period>('daily');
    const [daysBack, setDaysBack] = useState(30);
    const [schoolCode, setSchoolCode] = useState('');
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');

    const [heatmapData, setHeatmapData] = useState<HeatmapDataPoint[]>([]);
    const [heatmapLoading, setHeatmapLoading] = useState(true);
    const [topUsers, setTopUsers] = useState<TopActiveUser[]>([]);
    const [topUsersLoading, setTopUsersLoading] = useState(true);
    const [schoolTraffic, setSchoolTraffic] = useState<TrafficBySchool[]>([]);
    const [schoolTrafficLoading, setSchoolTrafficLoading] = useState(true);
    const [actionBreakdown, setActionBreakdown] = useState<ActionBreakdown[]>([]);
    const [actionBreakdownLoading, setActionBreakdownLoading] = useState(true);

    // Compute effective days_back from date range if set.
    function getDateParams(): { days_back: number; from_date: number; to_date: number } {
        if (fromDate) {
            const from = Math.floor(new Date(fromDate).getTime() / 1000);
            const to = toDate ? Math.floor(new Date(toDate + 'T23:59:59').getTime() / 1000) : 0;
            return { days_back: 0, from_date: from, to_date: to };
        }
        return { days_back: daysBack, from_date: 0, to_date: 0 };
    }

    function getEffectiveDaysBack(): number {
        if (fromDate) {
            const from = new Date(fromDate).getTime();
            const to = toDate ? new Date(toDate).getTime() : Date.now();
            return Math.max(1, Math.ceil((to - from) / 86400000));
        }
        return daysBack;
    }

    useEffect(() => {
        loadTraffic();
    }, [period, daysBack, schoolCode, fromDate, toDate]);

    useEffect(() => {
        loadHeatmap();
        loadTopUsers();
        loadSchoolTraffic();
        loadActionBreakdown();
    }, [daysBack, schoolCode, fromDate, toDate]);

    async function loadTraffic() {
        try {
            setLoading(true);
            const dateParams = getDateParams();
            const result = await ajaxCall('local_elby_dashboard_get_platform_traffic', {
                period,
                ...dateParams,
                school_code: schoolCode,
            });
            setData(result.data || []);
        } catch (err) {
            console.error('Failed to load traffic data:', err);
        } finally {
            setLoading(false);
        }
    }

    async function loadHeatmap() {
        setHeatmapLoading(true);
        try {
            const result = await ajaxCall('local_elby_dashboard_get_traffic_heatmap', {
                days_back: getEffectiveDaysBack(), school_code: schoolCode,
            });
            setHeatmapData(result.data || []);
        } catch (err) { console.error('Heatmap load failed:', err); }
        finally { setHeatmapLoading(false); }
    }

    async function loadTopUsers() {
        setTopUsersLoading(true);
        try {
            const result = await ajaxCall('local_elby_dashboard_get_top_active_users', {
                days_back: getEffectiveDaysBack(), limit_count: 10, school_code: schoolCode,
            });
            setTopUsers(result.data || []);
        } catch (err) { console.error('Top users load failed:', err); }
        finally { setTopUsersLoading(false); }
    }

    async function loadSchoolTraffic() {
        setSchoolTrafficLoading(true);
        try {
            const result = await ajaxCall('local_elby_dashboard_get_traffic_by_school', {
                days_back: getEffectiveDaysBack(), limit_count: 10,
            });
            setSchoolTraffic(result.data || []);
        } catch (err) { console.error('School traffic load failed:', err); }
        finally { setSchoolTrafficLoading(false); }
    }

    async function loadActionBreakdown() {
        setActionBreakdownLoading(true);
        try {
            const result = await ajaxCall('local_elby_dashboard_get_traffic_action_breakdown', {
                days_back: getEffectiveDaysBack(), school_code: schoolCode,
            });
            setActionBreakdown(result.data || []);
        } catch (err) { console.error('Action breakdown load failed:', err); }
        finally { setActionBreakdownLoading(false); }
    }

    // Compute max for bar scaling.
    const maxActions = Math.max(...data.map(d => d.total_actions), 1);
    const totalActions = data.reduce((sum, d) => sum + d.total_actions, 0);
    const totalUniqueUsers = data.length > 0 ? Math.max(...data.map(d => d.unique_users)) : 0;
    const avgActions = data.length > 0 ? Math.round(totalActions / data.length) : 0;

    function handleExportCsv() {
        const headers = ['Period', 'Total Actions', 'Unique Users'];
        const rows = data.map(d => [d.period_label, String(d.total_actions), String(d.unique_users)]);
        const csv = [headers, ...rows].map(r => r.map(c => `"${c}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `traffic_${period}_${daysBack}d.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }

    return (
        <div className="p-4 lg:p-6">
            {/* Summary Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl p-4 shadow-sm">
                    <p className="text-xs text-gray-500 font-medium">Total Actions</p>
                    <p className="text-2xl font-bold text-gray-800">{totalActions.toLocaleString()}</p>
                </div>
                <div className="bg-white rounded-xl p-4 shadow-sm">
                    <p className="text-xs text-gray-500 font-medium">Peak Unique Users</p>
                    <p className="text-2xl font-bold text-blue-600">{totalUniqueUsers.toLocaleString()}</p>
                </div>
                <div className="bg-white rounded-xl p-4 shadow-sm">
                    <p className="text-xs text-gray-500 font-medium">Avg Actions / {period === 'daily' ? 'Day' : period === 'weekly' ? 'Week' : 'Month'}</p>
                    <p className="text-2xl font-bold text-green-600">{avgActions.toLocaleString()}</p>
                </div>
            </div>

            {/* Controls */}
            <div className="bg-white rounded-xl p-4 shadow-sm mb-6">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex gap-1 bg-gray-100 rounded-lg p-1">
                        {(['daily', 'weekly', 'monthly'] as Period[]).map(p => (
                            <button
                                key={p}
                                onClick={() => setPeriod(p)}
                                className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                                    period === p ? 'bg-white shadow-sm text-blue-600 font-medium' : 'text-gray-600 hover:text-gray-800'
                                }`}
                            >
                                {p.charAt(0).toUpperCase() + p.slice(1)}
                            </button>
                        ))}
                    </div>

                    <select
                        className="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={fromDate ? 'custom' : daysBack}
                        onChange={(e) => {
                            const val = (e.target as HTMLSelectElement).value;
                            if (val !== 'custom') {
                                setFromDate('');
                                setToDate('');
                                setDaysBack(Number(val));
                            }
                        }}
                    >
                        <option value={7}>Last 7 days</option>
                        <option value={30}>Last 30 days</option>
                        <option value={90}>Last 90 days</option>
                        <option value={180}>Last 6 months</option>
                        <option value={365}>Last year</option>
                        {fromDate && <option value="custom">Custom range</option>}
                    </select>

                    {/* Date range picker */}
                    <div className="flex items-center gap-1.5">
                        <input
                            type="date"
                            className="px-2 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value={fromDate}
                            onInput={(e) => setFromDate((e.target as HTMLInputElement).value)}
                            title="From date"
                        />
                        <span className="text-gray-400 text-sm">to</span>
                        <input
                            type="date"
                            className="px-2 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value={toDate}
                            onInput={(e) => setToDate((e.target as HTMLInputElement).value)}
                            title="To date"
                        />
                    </div>

                    {/* School filter */}
                    <input
                        type="text"
                        placeholder="School code"
                        className="w-32 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={schoolCode}
                        onInput={(e) => setSchoolCode((e.target as HTMLInputElement).value)}
                    />

                    <button
                        onClick={handleExportCsv}
                        className="px-4 py-2 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 ml-auto"
                    >
                        Export CSV
                    </button>
                </div>
            </div>

            {/* Time-series Chart */}
            <div className="bg-white rounded-xl p-4 shadow-sm">
                {loading ? (
                    <LoadingBlock height="h-64" />
                ) : data.length === 0 ? (
                    <div className="text-center py-12 text-gray-500">No traffic data available for this period</div>
                ) : (
                    <div>
                        <div className="flex items-center gap-4 mb-4 text-xs text-gray-500">
                            <div className="flex items-center gap-1">
                                <div className="w-3 h-3 rounded bg-blue-500" />
                                <span>Total Actions</span>
                            </div>
                            <div className="flex items-center gap-1">
                                <div className="w-3 h-3 rounded bg-green-500" />
                                <span>Unique Users</span>
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <div className="flex items-end gap-1 min-w-fit" style={{ height: '240px' }}>
                                {data.map((d, i) => (
                                    <div key={i} className="flex flex-col items-center gap-1 flex-1 min-w-[24px] max-w-[48px]" style={{ height: '100%' }}>
                                        <div className="flex-1 flex items-end gap-0.5 w-full">
                                            <div
                                                className="flex-1 bg-blue-500 rounded-t opacity-80 hover:opacity-100 transition-opacity"
                                                style={{ height: `${(d.total_actions / maxActions) * 100}%`, minHeight: '2px' }}
                                                title={`${d.period_label}: ${d.total_actions.toLocaleString()} actions`}
                                            />
                                            <div
                                                className="flex-1 bg-green-500 rounded-t opacity-80 hover:opacity-100 transition-opacity"
                                                style={{ height: `${(d.unique_users / maxActions) * 100}%`, minHeight: '2px' }}
                                                title={`${d.period_label}: ${d.unique_users.toLocaleString()} users`}
                                            />
                                        </div>
                                        <span className="text-[9px] text-gray-400 truncate w-full text-center" title={d.period_label}>
                                            {d.period_label.length > 5 ? d.period_label.slice(-5) : d.period_label}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <details className="mt-4">
                            <summary className="text-sm text-gray-500 cursor-pointer hover:text-gray-700">Show data table</summary>
                            <table className="w-full text-sm mt-2">
                                <thead>
                                    <tr className="text-left text-gray-500 border-b border-gray-100">
                                        <th className="pb-2 font-medium">Period</th>
                                        <th className="pb-2 font-medium text-right">Actions</th>
                                        <th className="pb-2 font-medium text-right">Unique Users</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.map((d, i) => (
                                        <tr key={i} className="border-b border-gray-50">
                                            <td className="py-2 text-gray-700">{d.period_label}</td>
                                            <td className="py-2 text-right text-gray-800">{d.total_actions.toLocaleString()}</td>
                                            <td className="py-2 text-right text-gray-800">{d.unique_users.toLocaleString()}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </details>
                    </div>
                )}
            </div>

            {/* Action Breakdown + Traffic by School */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
                <div className="bg-white rounded-xl p-4 shadow-sm">
                    <h3 className="text-lg font-semibold text-gray-800 mb-4">Action Type Breakdown</h3>
                    {actionBreakdownLoading ? <LoadingBlock /> : actionBreakdown.length === 0 ? (
                        <div className="text-center py-12 text-gray-500">No data available</div>
                    ) : (
                        <ActionDonut data={actionBreakdown} />
                    )}
                </div>

                <div className="bg-white rounded-xl p-4 shadow-sm">
                    <h3 className="text-lg font-semibold text-gray-800 mb-4">Traffic by School</h3>
                    {schoolTrafficLoading ? (
                        <div className="space-y-3">
                            {[...Array(5)].map((_, i) => <div key={i} className="h-6 bg-gray-100 rounded animate-pulse" />)}
                        </div>
                    ) : schoolTraffic.length === 0 ? (
                        <div className="text-center py-12 text-gray-500">No school traffic data available</div>
                    ) : (
                        <SchoolTrafficBars data={schoolTraffic} />
                    )}
                </div>
            </div>

            {/* Peak Hours Heatmap */}
            <div className="bg-white rounded-xl p-4 shadow-sm mt-6">
                <h3 className="text-lg font-semibold text-gray-800 mb-4">Peak Hours</h3>
                {heatmapLoading ? <LoadingBlock /> : heatmapData.length === 0 ? (
                    <div className="text-center py-12 text-gray-500">No heatmap data available</div>
                ) : (
                    <PeakHoursHeatmap data={heatmapData} />
                )}
            </div>

            {/* Top Active Users */}
            <div className="bg-white rounded-xl p-4 shadow-sm mt-6">
                <h3 className="text-lg font-semibold text-gray-800 mb-4">Top Active Users</h3>
                {topUsersLoading ? (
                    <div className="space-y-3">
                        {[...Array(5)].map((_, i) => <div key={i} className="h-8 bg-gray-100 rounded animate-pulse" />)}
                    </div>
                ) : topUsers.length === 0 ? (
                    <div className="text-center py-12 text-gray-500">No user activity data available</div>
                ) : (
                    <TopUsersTable data={topUsers} />
                )}
            </div>
        </div>
    );
}
