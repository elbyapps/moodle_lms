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
 * RISE recruitment component for Elby Dashboard.
 *
 * Lists RISE campaigns; on selection lists applicants (filterable, paginated);
 * on applicant selection shows full details with NIDA validation and a NESA
 * eligibility review; attachments can be previewed.
 *
 * @module     local_elby_dashboard/components/Rise
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState, useEffect } from 'preact/hooks';
import type {
    RiseCampaign, RiseApplicant, RisePagination, RiseAttachment,
    NesaStatus, RiseNesaReview, RiseNidValidation, RiseNidField,
    RiseNesaCounts, RiseNesaStatsMap, RiseUserStatus, UserData,
    RiseSmsLogRow, RiseSmsLogResponse,
} from '../types';

// @ts-ignore — Moodle AMD loader global.
declare const require: (deps: string[], callback: (...args: any[]) => void) => void;

// Origin that serves RISE uploaded files (relative /uploads/... paths resolve here).
const RISE_FILES_ORIGIN = 'https://rise.elearning.reb.rw';

const BRAND = '#005198';

const PROVINCES: Record<string, string> = {
    '1': 'Kigali City',
    '2': 'Southern',
    '3': 'Western',
    '4': 'Northern',
    '5': 'Eastern',
};

const STATUSES = ['', 'PENDING', 'SHORTLISTED', 'HIRED', 'ENROLLED', 'REJECTED'];

// Rwanda's 30 districts are fixed; the RISE API filters by district name, so a static
// list lets the dropdown cover every district without fetching the full applicant set.
const RWANDA_DISTRICTS = [
    'Bugesera', 'Burera', 'Gakenke', 'Gasabo', 'Gatsibo', 'Gicumbi', 'Gisagara', 'Huye',
    'Kamonyi', 'Karongi', 'Kayonza', 'Kicukiro', 'Kirehe', 'Muhanga', 'Musanze', 'Ngoma',
    'Ngororero', 'Nyabihu', 'Nyagatare', 'Nyamagabe', 'Nyamasheke', 'Nyanza', 'Nyarugenge',
    'Nyaruguru', 'Rubavu', 'Ruhango', 'Rulindo', 'Rusizi', 'Rutsiro', 'Rwamagana',
];

// NESA decision metadata: short label + badge colour classes (used in the list table).
const NESA_META: Record<NesaStatus, { label: string; badge: string }> = {
    approved: { label: 'Approved', badge: 'bg-green-100 text-green-700' },
    rejected: { label: 'Rejected', badge: 'bg-red-100 text-red-700' },
    action_requested: { label: 'Action requested', badge: 'bg-amber-100 text-amber-700' },
    pending: { label: 'Pending', badge: 'bg-gray-100 text-gray-600' },
};

// Header NESA pill colours, aligned with the drawer design.
const NESA_PILL: Record<NesaStatus, { label: string; bg: string; fg: string; dot: string }> = {
    approved: { label: 'NESA Approved', bg: '#e6f4ec', fg: '#1a7f43', dot: '#1a9c52' },
    rejected: { label: 'NESA Rejected', bg: '#fbe0de', fg: '#b42318', dot: '#d4462f' },
    action_requested: { label: 'NESA Action requested', bg: '#fff1e0', fg: '#b5660b', dot: '#f79222' },
    pending: { label: 'NESA Pending', bg: '#fff1e0', fg: '#b5660b', dot: '#f79222' },
};

type NidaStatus = 'pending' | 'verified' | 'mismatch';

// Provisioning action codes the learner can fix themselves.
const FIXABLE_ACTIONS = ['nid_missing', 'nid_invalid', 'details_mismatch'];

const ACTION_NOTES: Record<string, string> = {
    nid_missing: 'National ID is missing — the learner must add it before they can be verified.',
    nid_invalid: 'National ID is not a valid 16-digit number — needs fixing before verification.',
    details_mismatch: "Details don't match NIDA records — the learner must correct their names or NID.",
    duplicate_nid: 'Another Moodle account already uses this National ID — resolve manually before provisioning.',
};

const NIDA_PILL: Record<NidaStatus, { label: string; bg: string; fg: string; icon: string }> = {
    verified: { label: 'Verified', bg: '#e6f4ec', fg: '#1a7f43', icon: '✓' },
    pending: { label: 'Pending', bg: '#fff1e0', fg: '#b5660b', icon: '◷' },
    mismatch: { label: 'Mismatch', bg: '#fbe0de', fg: '#b42318', icon: '✕' },
};

const DOT_FOR: Record<RiseNidField['status'], string> = {
    match: '#1a9c52',
    diff: '#d4462f',
    na: '#c9ccd2',
};

function ajaxCall(methodname: string, args: Record<string, any>): Promise<any> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (Ajax: any) => {
            Ajax.call([{ methodname, args }])[0].then(resolve).catch(reject);
        });
    });
}

// ---- URL state (deep links + browser back/forward) ------------------------
// The RISE view is a single-page Preact app on rise.php; navigation state is
// kept in the query string so the applicants page, applicant drawer and
// document preview are all linkable, shareable and survive a reload.

interface RiseUrlState {
    view: string;
    campaignid: string;
    applicantid: string;
    doc: string;
    status: string;
    gender: string;
    district: string;
    q: string;
    nesa: string;
    nida: string;
    page: string;
}

const RISE_URL_KEYS: (keyof RiseUrlState)[] = ['view', 'campaignid', 'applicantid', 'doc', 'status', 'gender', 'district', 'q', 'nesa', 'nida', 'page'];

const EMPTY_URL: RiseUrlState = { view: '', campaignid: '', applicantid: '', doc: '', status: '', gender: '', district: '', q: '', nesa: '', nida: '', page: '' };

function readRiseUrlState(): RiseUrlState {
    const p = new URLSearchParams(window.location.search);
    const out = { ...EMPTY_URL };
    for (const k of RISE_URL_KEYS) out[k] = p.get(k) || '';
    return out;
}

// Merge a partial patch into the current query string; empty values are removed
// so the URL stays clean. Other (non-RISE) query params are preserved.
function writeRiseUrl(patch: Partial<RiseUrlState>, replace = false): void {
    const p = new URLSearchParams(window.location.search);
    for (const k of Object.keys(patch) as (keyof RiseUrlState)[]) {
        const v = patch[k];
        if (v) p.set(k, v); else p.delete(k);
    }
    const qs = p.toString();
    const url = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
    if (replace) window.history.replaceState({}, '', url);
    else window.history.pushState({}, '', url);
}

function formatDate(value?: string | null): string {
    if (!value) return '-';
    const d = new Date(value);
    return isNaN(d.getTime()) ? '-' : d.toLocaleDateString();
}

function formatDateOnly(value?: string | null): string {
    if (!value) return '';
    const raw = String(value).trim();
    const iso = raw.match(/^(\d{4}-\d{2}-\d{2})/);
    if (iso) return iso[1];
    const d = new Date(raw);
    if (isNaN(d.getTime())) return raw.split(/[T\s]/)[0] || raw;
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function filenameTimestamp(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}${pad(d.getSeconds())}`;
}

function humanizeKey(key: string): string {
    return key
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase())
        .trim();
}

function initials(name?: string): string {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    const first = parts[0]?.[0] || '';
    const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
    return (first + last).toUpperCase() || '?';
}

function fileExt(url: string): string {
    const clean = url.split('?')[0];
    const dot = clean.lastIndexOf('.');
    return dot >= 0 ? clean.slice(dot + 1).toLowerCase() : '';
}

function toAbsolute(url: string): string {
    if (/^https?:\/\//i.test(url)) return url;
    return RISE_FILES_ORIGIN + (url.startsWith('/') ? '' : '/') + url;
}

// Collect previewable documents from an applicant (degreeLink + any /uploads/ values).
function extractAttachments(applicant: RiseApplicant): RiseAttachment[] {
    const seen = new Set<string>();
    const out: RiseAttachment[] = [];

    const add = (label: string, raw?: string | null) => {
        if (!raw || typeof raw !== 'string') return;
        if (!raw.includes('/uploads/')) return;
        const url = toAbsolute(raw);
        if (seen.has(url)) return;
        seen.add(url);
        out.push({ label, url, ext: fileExt(url) });
    };

    add('National ID', applicant.idCardLink);
    add('Degree / Certificate', applicant.degreeLink);
    const raw = applicant.rawFormData || {};
    Object.keys(raw).forEach((k) => {
        if (typeof raw[k] === 'string' && raw[k].includes('/uploads/')) {
            add(humanizeKey(k), raw[k]);
        }
    });
    return out;
}

// ---- Campaigns grid -------------------------------------------------------

// Per-card icon + colour palette, cycled by campaign index.
const CARD_STYLES = [
    { bg: '#e8f0f8', color: '#005198', icon: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75' },
    { bg: '#f3eafa', color: '#7b3fb0', icon: 'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9v.01M9 12v.01M9 15v.01M9 18v.01' },
    { bg: '#fff1e0', color: '#b5660b', icon: 'M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0zM12 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6z' },
    { bg: '#e6f4ec', color: '#1a7f43', icon: 'M22 10 12 5 2 10l10 5 10-5zM6 12v5c0 1 3 3 6 3s6-2 6-3v-5' },
];
const CAL_ICON = 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z';

function num(n?: number): string {
    return (n ?? 0).toLocaleString('en-US');
}

function pct(part: number, total: number): number {
    return total > 0 ? Math.round((part / total) * 100) : 0;
}

function applicantDistrict(a: RiseApplicant): string {
    return a.location?.districtName || a.district || '';
}

function applicantSector(a: RiseApplicant): string {
    return a.location?.sectorName || a.sector || '';
}

function nidaStatus(review?: RiseNesaReview): NidaStatus {
    if (review?.nidstatus === 'verified' || review?.nidstatus === 'mismatch') return review.nidstatus;
    return review?.nidverified ? 'verified' : 'pending';
}

function matchesApplicantSearch(a: RiseApplicant, query: string): boolean {
    const q = query.trim().toLowerCase();
    if (!q) return true;
    const haystack = [a.fullName, applicantDistrict(a), applicantSector(a), a.phone, a.nid, a.gender, a.status]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
    return haystack.includes(q);
}

type ExcelCell = { value: string | number; style?: string; type?: 'String' | 'Number' };

function escapeXml(value: any): string {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
}

function excelCell(cell: ExcelCell, fallbackStyle = 'Cell'): string {
    const value = cell.value === null || cell.value === undefined ? '' : cell.value;
    const type = cell.type || (typeof value === 'number' && Number.isFinite(value) ? 'Number' : 'String');
    return `<Cell ss:StyleID="${cell.style || fallbackStyle}"><Data ss:Type="${type}">${escapeXml(value)}</Data></Cell>`;
}

function downloadExcelWorkbook(filename: string, title: string, subtitle: string, headers: string[], rows: ExcelCell[][]) {
    const columns = [230, 86, 122, 120, 112, 68, 126, 112, 124, 142, 154, 114];
    while (columns.length < headers.length) columns.push(130);
    columns.length = headers.length;
    const colcount = headers.length;
    const headerXml = headers.map((h) => excelCell({ value: h, style: 'Header' })).join('');
    const rowXml = rows.map((row, i) => `
        <Row ss:Height="32">
            ${row.map((cell) => excelCell(cell, i % 2 === 0 ? 'Cell' : 'AltCell')).join('')}
        </Row>`).join('');

    const xml = `<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
    <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
        <Title>${escapeXml(title)}</Title>
        <Author>REB e-Learning</Author>
        <Created>${new Date().toISOString()}</Created>
    </DocumentProperties>
    <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
        <WindowHeight>9000</WindowHeight>
        <WindowWidth>18000</WindowWidth>
        <ProtectStructure>False</ProtectStructure>
        <ProtectWindows>False</ProtectWindows>
    </ExcelWorkbook>
    <Styles>
        <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="11" ss:Color="#1F2430"/></Style>
        <Style ss:ID="Title"><Font ss:FontName="Inter" ss:Size="22" ss:Bold="1" ss:Color="#161B26"/><Interior ss:Color="#F6F8FB" ss:Pattern="Solid"/></Style>
        <Style ss:ID="Subtitle"><Font ss:FontName="Inter" ss:Size="11" ss:Bold="1" ss:Color="#6B7280"/><Interior ss:Color="#F6F8FB" ss:Pattern="Solid"/></Style>
        <Style ss:ID="Header"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="10" ss:Bold="1" ss:Color="#8A909C"/><Interior ss:Color="#FAFBFC" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E6E9EF"/></Borders></Style>
        <Style ss:ID="Cell"><Alignment ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="11" ss:Color="#3B424F"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="AltCell"><Alignment ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="11" ss:Color="#3B424F"/><Interior ss:Color="#FCFDFE" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="NameCell"><Alignment ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="11" ss:Bold="1" ss:Color="#161B26"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="ScoreCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="11" ss:Bold="1" ss:Color="#161B26"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="StatusBlue"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="10" ss:Bold="1" ss:Color="#005198"/><Interior ss:Color="#E8F0F8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="PillGreen"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="10" ss:Bold="1" ss:Color="#1A7F43"/><Interior ss:Color="#E6F4EC" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="PillAmber"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="10" ss:Bold="1" ss:Color="#B5660B"/><Interior ss:Color="#FFF1E0" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="PillRed"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="10" ss:Bold="1" ss:Color="#B42318"/><Interior ss:Color="#FBE0DE" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
        <Style ss:ID="Muted"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Inter" ss:Size="10" ss:Bold="1" ss:Color="#AEB4BE"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#EEF0F4"/></Borders></Style>
    </Styles>
    <Worksheet ss:Name="Applicants">
        <Table ss:ExpandedColumnCount="${colcount}" ss:ExpandedRowCount="${rows.length + 4}" x:FullColumns="1" x:FullRows="1">
            ${columns.map((w) => `<Column ss:Width="${w}"/>`).join('')}
            <Row ss:Height="36"><Cell ss:MergeAcross="${colcount - 1}" ss:StyleID="Title"><Data ss:Type="String">${escapeXml(title)}</Data></Cell></Row>
            <Row ss:Height="24"><Cell ss:MergeAcross="${colcount - 1}" ss:StyleID="Subtitle"><Data ss:Type="String">${escapeXml(subtitle)}</Data></Cell></Row>
            <Row ss:Height="10"></Row>
            <Row ss:Height="34">${headerXml}</Row>
            ${rowXml}
        </Table>
        <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
            <FreezePanes/><FrozenNoSplit/><SplitHorizontal>4</SplitHorizontal><TopRowBottomPane>4</TopRowBottomPane>
            <Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight></Print>
            <Selected/>
        </WorksheetOptions>
    </Worksheet>
</Workbook>`;

    const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function SummaryChip({ label, value, color }: { label: string; value: string; color?: string }) {
    return (
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 15px', background: '#fff', border: '1px solid #ecedf1', borderRadius: 10 }}>
            <span style={{ fontSize: 11, color: '#9aa0ab', fontWeight: 600, letterSpacing: '.3px' }}>{label}</span>
            <span style={{ fontSize: 15, fontWeight: 700, color: color || '#161b26' }}>{value}</span>
        </div>
    );
}

function StatCell({ value, label, dot, border }: { value: string; label: string; dot: string; border: boolean }) {
    return (
        <div style={{ padding: '11px 12px', minWidth: 0, borderRight: border ? '1px solid #f0f1f4' : undefined }}>
            <div style={{ fontSize: 17, fontWeight: 700, color: '#161b26', lineHeight: 1, fontVariantNumeric: 'tabular-nums', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{value}</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 6, minWidth: 0 }}>
                <span style={{ flex: '0 0 auto', width: 6, height: 6, borderRadius: '50%', background: dot }} />
                <span style={{ fontSize: 10, letterSpacing: '.4px', color: '#9aa0ab', fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{label}</span>
            </div>
        </div>
    );
}

// NESA decision order + aggregate-card palette (Approved, Rejected, Action requested, Pending).
const NESA_ORDER: NesaStatus[] = ['approved', 'rejected', 'action_requested', 'pending'];
const NESA_STAT_STYLE: Record<NesaStatus, { label: string; bg: string; border: string; fg: string; dot: string }> = {
    approved: { label: 'Approved', bg: '#f0f9f3', border: '#cfe9d9', fg: '#1a7f43', dot: '#1a9c52' },
    rejected: { label: 'Rejected', bg: '#fdf3f3', border: '#f3c9c9', fg: '#b42318', dot: '#d4462f' },
    action_requested: { label: 'Action requested', bg: '#fff8ee', border: '#f3e1c0', fg: '#b5660b', dot: '#f79222' },
    pending: { label: 'Pending', bg: '#f8f9fb', border: '#e7e9ee', fg: '#5a616e', dot: '#9aa0ab' },
};

const EMPTY_NESA: RiseNesaCounts = { approved: 0, rejected: 0, action_requested: 0, pending: 0 };

function NesaStatCard({ status, value }: { status: NesaStatus; value: number }) {
    const s = NESA_STAT_STYLE[status];
    return (
        <div style={{ background: s.bg, border: `1px solid ${s.border}`, borderRadius: 12, padding: '13px 15px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 8 }}>
                <span style={{ width: 7, height: 7, borderRadius: '50%', background: s.dot }} />
                <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '.4px', textTransform: 'uppercase', color: s.fg }}>{s.label}</span>
            </div>
            <div style={{ fontSize: 24, fontWeight: 700, color: s.fg, lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{num(value)}</div>
        </div>
    );
}

function NesaMiniStat({ status, value }: { status: NesaStatus; value: number }) {
    const s = NESA_STAT_STYLE[status];
    return (
        <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 14, fontWeight: 700, color: '#161b26', lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{num(value)}</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 5 }}>
                <span style={{ flex: '0 0 auto', width: 6, height: 6, borderRadius: '50%', background: s.dot }} />
                <span style={{ fontSize: 9.5, fontWeight: 700, letterSpacing: '.3px', textTransform: 'uppercase', color: '#9aa0ab', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{s.label}</span>
            </div>
        </div>
    );
}

function CampaignList({ onSelect, onViewNotifications, canViewNotifications }: { onSelect: (c: RiseCampaign) => void; onViewNotifications: () => void; canViewNotifications: boolean }) {
    const [campaigns, setCampaigns] = useState<RiseCampaign[]>([]);
    const [nesaStats, setNesaStats] = useState<RiseNesaStatsMap>({});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        (async () => {
            try {
                setLoading(true);
                setError('');
                const raw = await ajaxCall('local_elby_dashboard_rise_get_campaigns', {});
                const data = JSON.parse(raw);
                setCampaigns(data.campaigns || []);
            } catch (e) {
                console.error('RISE campaigns load failed:', e);
                setError('Failed to load campaigns.');
            } finally {
                setLoading(false);
            }
        })();
    }, []);

    // NESA review stats are stored locally (independent of the remote campaigns API),
    // so load them separately; a failure here must not break the campaigns view.
    useEffect(() => {
        (async () => {
            try {
                const raw = await ajaxCall('local_elby_dashboard_rise_get_nesa_stats', {});
                setNesaStats(JSON.parse(raw) || {});
            } catch (e) {
                console.error('RISE NESA stats load failed:', e);
            }
        })();
    }, []);

    const totals = campaigns.reduce((acc, c) => {
        acc.total += c.stats?.total || 0;
        acc.shortlisted += c.stats?.shortlisted || 0;
        acc.enrolled += c.stats?.enrolled || 0;
        return acc;
    }, { total: 0, shortlisted: 0, enrolled: 0 });

    const nesaTotals = Object.values(nesaStats).reduce((acc, c) => {
        acc.approved += c.approved || 0;
        acc.rejected += c.rejected || 0;
        acc.action_requested += c.action_requested || 0;
        acc.pending += c.pending || 0;
        return acc;
    }, { ...EMPTY_NESA });
    const nesaReviewed = nesaTotals.approved + nesaTotals.rejected + nesaTotals.action_requested + nesaTotals.pending;

    return (
        <div style={{ padding: 'clamp(16px, 3vw, 30px) clamp(16px, 3vw, 34px) 40px' }}>
            <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap', marginBottom: 6 }}>
                <div>
                    <h1 style={{ margin: '0 0 5px', fontSize: 28, fontWeight: 700, letterSpacing: '-.5px', color: '#161b26' }}>RISE</h1>
                    <p style={{ margin: 0, fontSize: 14, color: '#6b7280' }}>Recruitment &amp; enrolment campaigns across the RISE programme.</p>
                </div>
                {canViewNotifications && (
                    <button onClick={onViewNotifications}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 16px', borderRadius: 11, border: '1px solid #e7e9ee', background: '#fff', color: '#005198', fontFamily: 'inherit', fontSize: 13.5, fontWeight: 700, cursor: 'pointer', boxShadow: '0 2px 8px rgba(20,28,46,.04)' }}>
                        <span style={{ fontSize: 15 }}>✉</span> SMS notifications
                    </button>
                )}
            </div>

            {loading ? (
                <div style={{ padding: 46, color: '#9aa0ab', fontSize: 14 }}>Loading campaigns…</div>
            ) : error ? (
                <div style={{ padding: 46, color: '#b42318', fontSize: 14 }}>{error}</div>
            ) : campaigns.length === 0 ? (
                <div style={{ padding: 46, color: '#9aa0ab', fontSize: 14 }}>No campaigns found.</div>
            ) : (
                <>
                    {/* SUMMARY STRIP */}
                    <div style={{ display: 'flex', gap: 10, margin: '22px 0 24px', flexWrap: 'wrap' }}>
                        <SummaryChip label="CAMPAIGNS" value={num(campaigns.length)} />
                        <SummaryChip label="TOTAL APPLICANTS" value={num(totals.total)} />
                        <SummaryChip label="SHORTLISTED" value={num(totals.shortlisted)} color="#b5660b" />
                        <SummaryChip label="ENROLLED" value={num(totals.enrolled)} color="#1a7f43" />
                    </div>

                    {/* NESA REVIEW STATS */}
                    <div style={{ background: '#fff', border: '1px solid #ecedf1', borderRadius: 16, padding: 20, marginBottom: 24 }}>
                        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap', marginBottom: 16 }}>
                            <div>
                                <h2 style={{ margin: 0, fontSize: 18, fontWeight: 700, color: '#161b26' }}>NESA review stats</h2>
                                <p style={{ margin: '4px 0 0', fontSize: 13, color: '#6b7280' }}>Eligibility review decisions across all RISE campaigns.</p>
                            </div>
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, borderRadius: 999, background: '#e3eef7', padding: '6px 12px', fontSize: 12, fontWeight: 600, color: '#005198' }}>
                                <span style={{ width: 7, height: 7, borderRadius: '50%', background: '#005198' }} />
                                {num(nesaReviewed)} reviewed
                            </span>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 14 }}>
                            {NESA_ORDER.map((s) => <NesaStatCard key={s} status={s} value={nesaTotals[s]} />)}
                        </div>
                    </div>

                    {/* CARDS GRID */}
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(340px, 1fr))', gap: 20 }}>
                        {campaigns.map((c, i) => {
                            const st = CARD_STYLES[i % CARD_STYLES.length];
                            const isActive = (c.status || '').toUpperCase() === 'ACTIVE';
                            const total = c.stats?.total || 0;
                            const enrolled = c.stats?.enrolled || 0;
                            const pct = total ? Math.round((enrolled / total) * 100) : 0;
                            const nesa = nesaStats[c._id] || EMPTY_NESA;
                            return (
                                <div key={c._id} onClick={() => onSelect(c)}
                                    style={{ background: '#fff', border: '1px solid #ecedf1', borderRadius: 16, padding: '22px 22px 20px', cursor: 'pointer', boxShadow: '0 1px 2px rgba(20,28,46,.04)', transition: 'box-shadow .16s, border-color .16s' }}
                                    onMouseEnter={(e) => { const el = e.currentTarget as HTMLElement; el.style.borderColor = '#005198'; el.style.boxShadow = '0 10px 28px rgba(0,81,152,.12)'; }}
                                    onMouseLeave={(e) => { const el = e.currentTarget as HTMLElement; el.style.borderColor = '#ecedf1'; el.style.boxShadow = '0 1px 2px rgba(20,28,46,.04)'; }}>
                                    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 16 }}>
                                        <span style={{ flex: '0 0 auto', width: 44, height: 44, borderRadius: 12, background: st.bg, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke={st.color} strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round"><path d={st.icon} /></svg>
                                        </span>
                                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 10px', borderRadius: 999, background: isActive ? '#e6f4ec' : '#f1f3f6', color: isActive ? '#1a7f43' : '#6b7280', fontSize: 10.5, fontWeight: 700, letterSpacing: '.4px' }}>
                                            {isActive && <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#1a9c52' }} />}
                                            {(c.status || 'UNKNOWN').toUpperCase()}
                                        </span>
                                    </div>
                                    <h3 style={{ margin: '0 0 7px', fontSize: 17.5, fontWeight: 700, letterSpacing: '-.3px', color: '#161b26', lineHeight: 1.25 }}>{c.name}</h3>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 12.5, color: '#8a909c', marginBottom: 18 }}>
                                        <span style={{ fontWeight: 600, color: '#6b7280' }}>{c.roleName || c.type || '—'}</span>
                                        <span style={{ width: 3, height: 3, borderRadius: '50%', background: '#cdd2d9' }} />
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#a7adb8" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" style={{ flex: '0 0 auto' }}><path d={CAL_ICON} /></svg>
                                        <span>{formatDate(c.deadline)}</span>
                                    </div>
                                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', border: '1px solid #f0f1f4', borderRadius: 11, overflow: 'hidden', marginBottom: 14 }}>
                                        <StatCell value={num(c.stats?.total)} label="TOTAL" dot="#005198" border />
                                        <StatCell value={num(c.stats?.shortlisted)} label="SHORTLISTED" dot="#f79222" border />
                                        <StatCell value={num(c.stats?.enrolled)} label="ENROLLED" dot="#1a9c52" border={false} />
                                    </div>
                                    <div style={{ background: '#f8f9fb', border: '1px solid #f0f1f4', borderRadius: 11, padding: 12, marginBottom: 14 }}>
                                        <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '.4px', color: '#8a909c', marginBottom: 10 }}>NESA REVIEW</div>
                                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: 10 }}>
                                            {NESA_ORDER.map((s) => <NesaMiniStat key={s} status={s} value={nesa[s]} />)}
                                        </div>
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                                        <span style={{ fontSize: 11.5, color: '#a7adb8' }}>{enrolled > 0 ? pct + '% enrolled' : 'Not yet enrolling'}</span>
                                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12.5, fontWeight: 600, color: '#005198' }}>View applicants <span style={{ fontSize: 14 }}>›</span></span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </>
            )}
        </div>
    );
}

// ---- Document preview modal ----------------------------------------------

function PreviewModal({ attachment, onClose }: { attachment: RiseAttachment; onClose: () => void }) {
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(attachment.ext);
    const isPdf = attachment.ext === 'pdf';

    return (
        <div className="fixed inset-0 z-[2100] bg-black/70 flex items-center justify-center p-4" onClick={onClose}>
            <div className="bg-white rounded-xl w-full max-w-4xl h-[85vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-center justify-between px-4 py-3 border-b border-gray-200">
                    <h4 className="font-semibold text-gray-800 truncate">{attachment.label}</h4>
                    <div className="flex items-center gap-2">
                        <a href={attachment.url} target="_blank" rel="noopener noreferrer"
                           className="text-sm text-blue-600 hover:underline">Open in new tab</a>
                        <button onClick={onClose} className="p-1.5 text-gray-500 hover:bg-gray-100 rounded-lg" aria-label="Close">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div className="flex-1 overflow-auto bg-gray-100 flex items-center justify-center">
                    {isImage && <img src={attachment.url} alt={attachment.label} className="max-w-full max-h-full object-contain" />}
                    {isPdf && <iframe src={attachment.url} title={attachment.label} className="w-full h-full border-0" />}
                    {!isImage && !isPdf && (
                        <div className="p-8 text-center text-gray-600">
                            <p className="mb-3">This file type can't be previewed inline.</p>
                            <a href={attachment.url} target="_blank" rel="noopener noreferrer"
                               className="text-blue-600 hover:underline">Download / open the document</a>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ---- NIDA validation -------------------------------------------------------

type NidState = {
    status: 'idle' | 'loading' | 'done' | 'error';
    result?: RiseNidValidation;
    message?: string;
};

function InfoCard({ children, tone }: { children: any; tone: 'gray' | 'amber' }) {
    const styles = tone === 'amber'
        ? { border: '#f0d9a8', background: '#fdf6e8', color: '#8a5a08' }
        : { border: '#ecedf1', background: '#f7f8fa', color: '#6b7280' };
    return (
        <div style={{ ...styles, borderWidth: 1, borderStyle: 'solid', borderRadius: 12, padding: '12px 16px', marginBottom: 22, fontSize: 13 }}>
            {children}
        </div>
    );
}

function NidaAlertRow({ f }: { f: RiseNidField }) {
    const ok = f.status === 'match';
    return (
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 9, fontSize: 12.5, lineHeight: 1.5, marginBottom: 8 }}>
            <span style={{
                flex: '0 0 auto', width: 18, height: 18, borderRadius: '50%', marginTop: 1,
                display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 700,
                background: ok ? '#dff3e6' : '#fbe0de', color: ok ? '#1a7f43' : '#b42318',
            }}>{ok ? '✓' : '✕'}</span>
            <div style={{ color: '#4a5160' }}>
                <span style={{ fontWeight: 600, color: '#2f3744' }}>{f.field}</span>
                {' — application '}<b style={{ color: ok ? '#161b26' : '#b42318' }}>{f.app || '—'}</b>
                {', NIDA '}<b style={{ color: ok ? '#161b26' : '#b42318' }}>{f.nida || '—'}</b>
            </div>
        </div>
    );
}

function NidaSection({ nid, state }: { nid?: string; state: NidState }) {
    const [open, setOpen] = useState(false);

    if (!nid) {
        return <InfoCard tone="gray">No National ID on file — can't validate against NIDA.</InfoCard>;
    }
    if (state.status === 'loading' || state.status === 'idle') {
        return <InfoCard tone="gray">Validating National ID against NIDA…</InfoCard>;
    }
    if (state.status === 'error') {
        return <InfoCard tone="amber">Couldn't validate the National ID: {state.message}</InfoCard>;
    }
    const r = state.result;
    if (!r) return null;

    const matched = r.match;
    const keyRows = r.fields.filter((f) => f.field === 'Name' || f.field === 'Date of birth');
    const alertBorder = matched ? '#bfe2cd' : '#f3c9c9';
    const alertBg = matched ? '#f1faf4' : '#fdf3f3';
    const titleColor = matched ? '#1a7f43' : '#b42318';

    return (
        <div style={{ marginBottom: 22 }}>
            <div style={{ border: `1px solid ${alertBorder}`, background: alertBg, borderRadius: 12, padding: '14px 16px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontWeight: 700, fontSize: 13.5, color: titleColor, marginBottom: 10 }}>
                    <span style={{ fontSize: 14 }}>{matched ? '✓' : '⚠'}</span>
                    {matched ? 'Matches NIDA records' : 'Does not match NIDA records'}
                </div>
                {keyRows.map((f) => <NidaAlertRow key={f.field} f={f} />)}

                <button
                    onClick={() => setOpen((o) => !o)}
                    aria-expanded={open}
                    style={{
                        marginTop: 4, display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px',
                        border: `1px solid ${matched ? '#c4e0cd' : '#e7c4c2'}`, borderRadius: 8, background: '#fff',
                        color: matched ? '#1a7f43' : '#9a3027', fontSize: 12, fontWeight: 600, cursor: 'pointer',
                    }}
                >
                    {open ? 'Hide' : 'View'} full NIDA record
                    <span style={{ fontSize: 10, transition: 'transform .18s', transform: open ? 'rotate(180deg)' : 'rotate(0deg)' }}>▼</span>
                </button>
            </div>

            {open && (
                <div style={{ border: '1px solid #ecedf1', borderRadius: 12, overflow: 'hidden', marginTop: 10 }}>
                    <div style={{ display: 'grid', gridTemplateColumns: '1.1fr 1fr 1fr', padding: '11px 16px', background: '#f7f8fa', borderBottom: '1px solid #eceef2' }}>
                        <div style={{ fontSize: 10, letterSpacing: '.5px', fontWeight: 700, color: '#9aa0ab' }}>FIELD</div>
                        <div style={{ fontSize: 10, letterSpacing: '.5px', fontWeight: 700, color: '#9aa0ab' }}>APPLICATION</div>
                        <div style={{ fontSize: 10, letterSpacing: '.5px', fontWeight: 700, color: BRAND }}>NIDA RECORD</div>
                    </div>
                    {r.fields.map((f, i) => (
                        <div key={f.field} style={{
                            display: 'grid', gridTemplateColumns: '1.1fr 1fr 1fr', alignItems: 'center',
                            padding: '11px 16px', borderTop: i === 0 ? 'none' : '1px solid #f2f3f5',
                        }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 12, color: '#5a616e' }}>
                                <span style={{ flex: '0 0 auto', width: 7, height: 7, borderRadius: '50%', background: DOT_FOR[f.status] }} />
                                {f.field}
                            </div>
                            <div style={{ fontSize: 12.5, color: f.status === 'na' ? '#b9bdc6' : (f.status === 'diff' ? '#b42318' : '#3b424f'), fontWeight: 500 }}>{f.app || '—'}</div>
                            <div style={{ fontSize: 12.5, color: f.status === 'diff' ? '#b42318' : '#1f2430', fontWeight: f.status === 'diff' ? 600 : 500 }}>{f.nida || '—'}</div>
                        </div>
                    ))}
                    <div style={{ padding: '9px 16px', background: '#f9fafb', borderTop: '1px solid #f2f3f5', fontSize: 11, color: '#9aa0ab', display: 'flex', alignItems: 'center', gap: 14 }}>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 7, height: 7, borderRadius: '50%', background: '#1a9c52' }} />Match</span>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 7, height: 7, borderRadius: '50%', background: '#d4462f' }} />Mismatch</span>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 7, height: 7, borderRadius: '50%', background: '#c9ccd2' }} />NIDA only</span>
                    </div>
                </div>
            )}
        </div>
    );
}

// ---- Collapsible section (responses / reviews) ---------------------------

function Collapsible({ title, count, children }: { title: string; count: number; children: any }) {
    const [open, setOpen] = useState(false);
    return (
        <div style={{ border: '1px solid #ecedf1', borderRadius: 12, overflow: 'hidden', marginBottom: 12 }}>
            <button
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                style={{ width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '15px 16px', border: 'none', background: '#fff', cursor: 'pointer', textAlign: 'left' }}
            >
                <span style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                    <span style={{ fontSize: 13.5, fontWeight: 600, color: '#1f2430' }}>{title}</span>
                    <span style={{ padding: '1px 8px', borderRadius: 999, background: '#f1f3f6', color: '#5a616e', fontSize: 11, fontWeight: 600 }}>{count}</span>
                </span>
                <span style={{ fontSize: 12, color: '#9aa0ab', transition: 'transform .18s', transform: open ? 'rotate(180deg)' : 'rotate(0deg)' }}>▼</span>
            </button>
            {open && <div style={{ borderTop: '1px solid #f0f1f4' }}>{children}</div>}
        </div>
    );
}

// ---- NESA eligibility review (sticky footer) -----------------------------

function NesaReviewSection({ applicant, campaignId, review, nidValidated, canManageRiseUsers, onSaved }: {
    applicant: RiseApplicant;
    campaignId: string;
    review?: RiseNesaReview;
    nidValidated?: boolean;
    canManageRiseUsers: boolean;
    onSaved: (applicantId: string, review: RiseNesaReview) => void;
}) {
    const [status, setStatus] = useState<NesaStatus>(review?.nesastatus || 'pending');
    const [indexNumber, setIndexNumber] = useState<string>(review?.nesaindexnumber || '');
    const [nidVerified, setNidVerified] = useState<boolean>(!!review?.nidverified);
    const [comment, setComment] = useState<string>(review?.comment || '');
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState(0);
    const [error, setError] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);

    useEffect(() => {
        setStatus(review?.nesastatus || 'pending');
        setIndexNumber(review?.nesaindexnumber || '');
        setNidVerified(!!review?.nidverified);
        setComment(review?.comment || '');
        setSavedAt(0);
        setError('');
        setConfirmOpen(false);
    }, [applicant._id]);

    useEffect(() => {
        if (nidValidated) setNidVerified(true);
    }, [nidValidated]);

    const decisions: { key: NesaStatus; label: string; solid: string; accent: string; tint: string; confirmBg: string }[] = [
        { key: 'approved', label: 'Approved', solid: '#1a7f43', accent: '#1a7f43', tint: '#bfe2cd', confirmBg: '#e6f4ec' },
        { key: 'rejected', label: 'Rejected', solid: '#b42318', accent: '#b42318', tint: '#eec3bd', confirmBg: '#fee7e3' },
        { key: 'action_requested', label: 'Action requested', solid: '#b6720a', accent: '#b6720a', tint: '#e8d3a3', confirmBg: '#fff1d6' },
    ];
    const selectedDecision = decisions.find((d) => d.key === status);
    const hasDecision = status !== 'pending';
    const needsIndex = status === 'approved';
    const needsComment = status === 'rejected' || status === 'action_requested';

    function validate(): boolean {
        if (!hasDecision) {
            setError('Select a NESA decision before saving.');
            return false;
        }
        if (needsIndex && indexNumber.trim() === '') {
            setError('NESA index number is required when approving a learner.');
            return false;
        }
        if (needsComment && comment.trim() === '') {
            setError('Comment is required for rejected or action-requested reviews.');
            return false;
        }
        setError('');
        return true;
    }

    function requestSave() {
        setSavedAt(0);
        if (validate()) setConfirmOpen(true);
    }

    async function save() {
        try {
            setSaving(true);
            setError('');
            const raw = await ajaxCall('local_elby_dashboard_rise_save_review', {
                campaignid: campaignId,
                applicantid: applicant._id,
                nesastatus: status,
                nesaindexnumber: indexNumber.trim(),
                nidverified: nidVerified ? 1 : 0,
                comment,
                applicantdata: JSON.stringify(applicant),
            });
            const saved = JSON.parse(raw) as RiseNesaReview;
            onSaved(applicant._id, saved);
            setSavedAt(Date.now());
            setConfirmOpen(false);
        } catch (e: any) {
            console.error('RISE save review failed:', e);
            setError(e?.message || 'Could not save the review. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <>
            <div style={{ flex: '0 0 auto', borderTop: '1px solid #eceef2', background: '#fbfbfc', padding: '20px 26px 20px' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 15 }}>
                    <div style={{ fontSize: 13.5, fontWeight: 800, letterSpacing: '.5px', color: '#161b26' }}>NESA ELIGIBILITY REVIEW</div>
                    {/* Read-only: NIDA verification is server-derived (from the National ID
                        check above), never toggled by the reviewer. */}
                    <span title="National ID verification is set automatically by the NIDA check — it can't be toggled manually."
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 7, fontSize: 12.5, fontWeight: 700,
                                   padding: '4px 11px', borderRadius: 999,
                                   background: nidVerified ? '#e6f4ec' : '#f1f3f6',
                                   color: nidVerified ? '#1a7f43' : '#6b7280' }}>
                        <span style={{ fontSize: 12, lineHeight: 1 }}>{nidVerified ? '✓' : '◷'}</span>
                        {nidVerified ? 'National ID verified' : 'National ID not verified'}
                    </span>
                </div>

                <label style={{ display: 'block', fontSize: 11.5, fontWeight: 800, letterSpacing: '.6px', color: '#6b7280', marginBottom: 7 }}>
                    NESA SENIOR 3 CONFIRMATION — INDEX NUMBER{needsIndex ? ' *' : ''}
                </label>
                <input
                    value={indexNumber}
                    onInput={(e) => setIndexNumber((e.target as HTMLInputElement).value)}
                    placeholder="Enter NESA index number"
                    style={{ width: '100%', height: 38, border: '1px solid #dfe3ea', borderRadius: 10, padding: '0 13px', fontSize: 13.5, fontFamily: 'inherit', color: '#161b26', marginBottom: 12, outline: 'none', background: '#fff' }}
                />

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, marginBottom: 11 }}>
                    {decisions.map((d) => {
                        const selected = status === d.key;
                        // Approval creates the Moodle account, so it needs the manage capability.
                        const locked = d.key === 'approved' && !canManageRiseUsers;
                        return (
                            <button
                                key={d.key}
                                disabled={locked}
                                title={locked ? 'You need the "Provision RISE users" capability to approve.' : undefined}
                                onClick={() => { if (!locked) setStatus((s) => (s === d.key ? 'pending' : d.key)); }}
                                style={{
                                    padding: '12px 8px', borderRadius: 10, fontSize: 13, fontWeight: 700,
                                    cursor: locked ? 'not-allowed' : 'pointer',
                                    border: `1.5px solid ${selected ? d.solid : d.tint}`,
                                    background: selected ? d.solid : '#fff',
                                    color: selected ? '#fff' : d.accent,
                                    opacity: locked ? 0.45 : 1,
                                }}
                            >{d.label}</button>
                        );
                    })}
                </div>

                <textarea
                    value={comment}
                    onInput={(e) => setComment((e.target as HTMLTextAreaElement).value)}
                    placeholder={needsComment ? 'Add a comment (required)…' : 'Add a comment (optional)…'}
                    style={{ width: '100%', minHeight: 50, resize: 'none', border: '1px solid #e1e3e8', borderRadius: 10, padding: '10px 12px', fontSize: 13.5, fontFamily: 'inherit', color: '#1f2430', marginBottom: 13, outline: 'none', background: '#fff' }}
                />

                {error && <div style={{ margin: '-3px 0 10px', fontSize: 12.5, fontWeight: 600, color: '#b42318' }}>{error}</div>}

                <button
                    onClick={requestSave}
                    disabled={!hasDecision || saving}
                    style={{
                        width: '100%', padding: 13, border: 'none', borderRadius: 10, color: '#fff',
                        fontSize: 14, fontWeight: 700, cursor: hasDecision && !saving ? 'pointer' : 'not-allowed',
                        background: hasDecision ? BRAND : '#a6c0d6',
                    }}
                >{saving ? 'Saving…' : 'Save review'}</button>
                {savedAt > 0 && !error && <div style={{ marginTop: 8, fontSize: 12.5, color: '#1a7f43' }}>Saved.</div>}
            </div>

            {confirmOpen && selectedDecision && (
                <div className="fixed inset-0 z-[2300]" style={{ background: 'rgba(17,24,39,.48)', backdropFilter: 'blur(3px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
                    <div style={{ width: 380, maxWidth: '100%', borderRadius: 17, background: '#fff', boxShadow: '0 24px 70px rgba(20,28,46,.28)', padding: 24 }}>
                        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14, marginBottom: 17 }}>
                            <span style={{ flex: '0 0 auto', width: 44, height: 44, borderRadius: 12, background: selectedDecision.confirmBg, color: selectedDecision.accent, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 26, fontWeight: 800 }}>✓</span>
                            <div>
                                <div style={{ fontSize: 18, fontWeight: 800, letterSpacing: '-.2px', color: '#161b26', marginBottom: 2 }}>Submit this review?</div>
                                <div style={{ fontSize: 13.5, color: '#6b7280', lineHeight: 1.35 }}>This will be recorded against the applicant.</div>
                            </div>
                        </div>
                        <div style={{ border: '1px solid #e5e7ec', background: '#f9fafb', borderRadius: 13, padding: '14px 16px', marginBottom: 20 }}>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 12, alignItems: 'center', marginBottom: 13 }}>
                                <div style={{ fontSize: 12, fontWeight: 800, color: '#6b7280', letterSpacing: '.5px' }}>DECISION</div>
                                <span style={{ padding: '5px 13px', borderRadius: 999, background: selectedDecision.confirmBg, color: selectedDecision.accent, fontSize: 13, fontWeight: 800 }}>{selectedDecision.label}</span>
                            </div>
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 12, alignItems: 'center' }}>
                                <div style={{ fontSize: 12, fontWeight: 800, color: '#6b7280', letterSpacing: '.5px' }}>NESA INDEX</div>
                                <div style={{ fontSize: 13.5, fontWeight: 800, color: '#161b26' }}>{indexNumber.trim() || '—'}</div>
                            </div>
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.35fr', gap: 12 }}>
                            <button onClick={() => setConfirmOpen(false)} disabled={saving}
                                style={{ padding: '12px 14px', borderRadius: 11, border: '1px solid #dfe3ea', background: '#fff', color: '#3b424f', fontSize: 14, fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer' }}>Cancel</button>
                            <button onClick={save} disabled={saving}
                                style={{ padding: '12px 14px', borderRadius: 11, border: 'none', background: BRAND, color: '#fff', fontSize: 14, fontWeight: 700, cursor: saving ? 'not-allowed' : 'pointer' }}>{saving ? 'Submitting…' : 'Confirm & submit'}</button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

// ---- Applicant detail drawer ---------------------------------------------

function ApplicantDetail({ applicant, campaignId, review, accountStatus, canManageRiseUsers, creatingAccount, createError, onCreateAccount, onClose, onPreview, onReviewSaved }: {
    applicant: RiseApplicant;
    campaignId: string;
    review?: RiseNesaReview;
    accountStatus?: RiseUserStatus;
    canManageRiseUsers: boolean;
    creatingAccount: boolean;
    createError?: string;
    onCreateAccount: () => void;
    onClose: () => void;
    onPreview: (a: RiseAttachment) => void;
    onReviewSaved: (applicantId: string, review: RiseNesaReview) => void;
}) {
    const [confirmCreateOpen, setConfirmCreateOpen] = useState(false);
    const attachments = extractAttachments(applicant);
    const responses = applicant.formResponses || {};
    const responseKeys = Object.keys(responses);
    const reviews = applicant.reviews || [];
    const nesaStatus: NesaStatus = review?.nesastatus || 'pending';
    const pill = NESA_PILL[nesaStatus];

    const [nidVal, setNidVal] = useState<NidState>({ status: 'idle' });

    // Validate the National ID against TMIS/NIDA when the drawer opens, unless already verified.
    useEffect(() => {
        if (!applicant.nid) {
            setNidVal({ status: 'idle' });
            return;
        }

        if (nidaStatus(review) === 'verified') {
            const dob = formatDateOnly(applicant.dateOfBirth);
            setNidVal({
                status: 'done',
                result: {
                    found: true,
                    match: true,
                    namematch: true,
                    dobmatch: dob ? true : null,
                    fields: [
                        { field: 'Name', app: applicant.fullName || '', nida: applicant.fullName || '', status: 'match' },
                        { field: 'National ID', app: applicant.nid || '', nida: applicant.nid || '', status: 'match' },
                        { field: 'Date of birth', app: dob, nida: dob, status: dob ? 'match' : 'na' },
                    ],
                },
            });
            return;
        }

        let cancelled = false;
        setNidVal({ status: 'loading' });
        // Ids only: the backend re-fetches the applicant's NID/name/DOB from RISE
        // server-side — browser state never feeds the NIDA comparison.
        ajaxCall('local_elby_dashboard_rise_validate_nid', {
            campaignid: campaignId,
            applicantid: applicant._id,
        }).then((raw: string) => {
            if (cancelled) return;
            const result = JSON.parse(raw) as RiseNidValidation;
            setNidVal({ status: 'done', result });
            onReviewSaved(applicant._id, {
                nesastatus: review?.nesastatus || 'pending',
                nesaindexnumber: review?.nesaindexnumber || '',
                nidstatus: result.match ? 'verified' : 'mismatch',
                nidverified: result.match ? 1 : 0,
                comment: review?.comment || '',
            });
        }).catch((e: any) => {
            if (cancelled) return;
            setNidVal({ status: 'error', message: e?.message || 'NID validation failed.' });
        });
        return () => { cancelled = true; };
    }, [applicant._id, review?.nidstatus, review?.nidverified]);

    const detailRows: [string, string, string, string][] = [
        ['Gender', applicant.gender || '-', 'Phone', applicant.phone || '-'],
        ['National ID', applicant.nid || '-', 'Date of Birth', formatDateOnly(applicant.dateOfBirth) || '-'],
        ['District', applicant.location?.districtName || applicant.district || '-', 'Sector', applicant.location?.sectorName || applicant.sector || '-'],
        ['Province', applicant.location?.provinceCode ? (PROVINCES[applicant.location.provinceCode] || '-') : '-', 'Applied', formatDate(applicant.createdAt)],
    ];

    const sectionLabel = { fontSize: 11, fontWeight: 700, letterSpacing: '.8px', color: '#8a909c', marginBottom: 12 } as const;

    return (
        <div className="fixed inset-0 z-[2000] flex justify-end" onClick={onClose}>
            <div className="absolute inset-0 bg-black/40" />
            <div
                onClick={(e) => e.stopPropagation()}
                style={{ position: 'relative', width: 680, maxWidth: '100%', height: '100%', background: '#fff', fontFamily: "'Inter',system-ui,sans-serif", color: '#1f2430', display: 'flex', flexDirection: 'column', boxShadow: '-12px 0 40px rgba(20,28,46,.16)', overflow: 'hidden' }}
            >
                {/* HEADER */}
                <div style={{ flex: '0 0 auto', padding: '22px 26px 18px', borderBottom: '1px solid #eceef2', display: 'flex', alignItems: 'flex-start', gap: 16 }}>
                    <div style={{ flex: '1 1 auto', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 11 }}>
                            <div style={{ width: 38, height: 38, borderRadius: '50%', background: BRAND, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 600, fontSize: 14, letterSpacing: '.5px', flex: '0 0 auto' }}>{initials(applicant.fullName)}</div>
                            <h1 style={{ margin: 0, fontSize: 21, fontWeight: 700, letterSpacing: '-.2px', lineHeight: 1.15, color: '#161b26' }}>{applicant.fullName || 'Applicant'}</h1>
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7 }}>
                            <span style={{ display: 'inline-flex', alignItems: 'center', padding: '4px 10px', borderRadius: 999, background: '#e3eef7', color: BRAND, fontSize: 11.5, fontWeight: 600, letterSpacing: '.3px' }}>{applicant.status || '-'}</span>
                            <span style={{ display: 'inline-flex', alignItems: 'center', padding: '4px 10px', borderRadius: 999, background: '#f1f3f6', color: '#3b424f', fontSize: 11.5, fontWeight: 600 }}>Score&nbsp;{applicant.totalScore ?? '-'}</span>
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 10px', borderRadius: 999, background: pill.bg, color: pill.fg, fontSize: 11.5, fontWeight: 600 }}>
                                <span style={{ width: 6, height: 6, borderRadius: '50%', background: pill.dot, display: 'inline-block' }} />{pill.label}
                            </span>
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        style={{ flex: '0 0 auto', width: 30, height: 30, border: 'none', borderRadius: 8, background: '#f3f4f7', color: '#6b7280', fontSize: 16, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', lineHeight: 1 }}
                    >✕</button>
                </div>

                {/* SCROLL BODY */}
                <div style={{ flex: '1 1 auto', overflowY: 'auto', padding: '18px 26px 24px' }}>
                    <NidaSection nid={applicant.nid} state={nidVal} />

                    {/* MOODLE ACCOUNT */}
                    <div style={sectionLabel}>MOODLE ACCOUNT</div>
                    <div style={{ border: '1px solid #ecedf1', borderRadius: 12, padding: '14px 16px', marginBottom: 24 }}>
                        {accountStatus?.hasaccount ? (
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                                <div>
                                    <div style={{ fontSize: 14, fontWeight: 700, color: '#161b26' }}>
                                        {accountStatus.username}
                                        {accountStatus.suspended && (
                                            <span style={{ marginLeft: 8, padding: '2px 9px', borderRadius: 999, background: '#fff1e0', color: '#b5660b', fontSize: 10.5, fontWeight: 700 }}>Suspended</span>
                                        )}
                                    </div>
                                    <div style={{ fontSize: 12, color: '#9aa0ab', marginTop: 2 }}>
                                        {accountStatus.linked ? 'Linked to this applicant' : 'Matched by National ID (not yet linked)'}
                                    </div>
                                </div>
                                <a href={accountStatus.profileurl} target="_blank" rel="noopener noreferrer"
                                   style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, background: '#e6f4ec', color: '#1a7f43', fontSize: 12.5, fontWeight: 700, textDecoration: 'none' }}>
                                    View profile ›
                                </a>
                            </div>
                        ) : accountStatus?.provisioningaction === 'duplicate_nid' ? (
                            <div style={{ fontSize: 13, color: '#b42318', fontWeight: 600 }}>{ACTION_NOTES.duplicate_nid}</div>
                        ) : canManageRiseUsers && review?.nesastatus === 'approved' ? (
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                                <div style={{ fontSize: 13, color: '#6b7280' }}>No Moodle account yet.</div>
                                <button onClick={() => setConfirmCreateOpen(true)} disabled={creatingAccount}
                                    style={{ padding: '10px 16px', borderRadius: 10, border: 'none', background: creatingAccount ? '#a6c0d6' : BRAND, color: '#fff', fontSize: 13, fontWeight: 700, cursor: creatingAccount ? 'wait' : 'pointer', fontFamily: 'inherit' }}>
                                    {creatingAccount ? 'Creating…' : 'Create Moodle account'}
                                </button>
                            </div>
                        ) : canManageRiseUsers ? (
                            <div style={{ fontSize: 13, color: '#9aa0ab' }}>
                                No Moodle account yet. Accounts are created once the NESA review is <b>approved</b>.
                            </div>
                        ) : (
                            <div style={{ fontSize: 13, color: '#9aa0ab' }}>No Moodle account yet.</div>
                        )}
                        {createError && (
                            <div style={{ marginTop: 12, padding: '10px 13px', border: '1px solid #f3c9c9', background: '#fdf3f3', borderRadius: 10, fontSize: 12.5, color: '#b42318' }}>
                                ✕ {createError}
                            </div>
                        )}
                        {accountStatus && FIXABLE_ACTIONS.includes(accountStatus.provisioningaction) && (
                            <div style={{ marginTop: 12, padding: '10px 13px', border: '1px solid #f3e1c0', background: '#fff8ee', borderRadius: 10, fontSize: 12.5, color: '#8a5a08' }}>
                                ⚠ {ACTION_NOTES[accountStatus.provisioningaction]}
                            </div>
                        )}
                        {accountStatus?.risesync === 'conflict' && (
                            <div style={{ marginTop: 12, padding: '10px 13px', border: '1px solid #f3c9c9', background: '#fdf3f3', borderRadius: 10, fontSize: 12.5, color: '#b42318' }}>
                                ⚠ RISE already reports a different linked user for this applicant — resolve the conflict manually before relying on the link.
                            </div>
                        )}
                        {accountStatus?.correctionstatus === 'resubmitted' && (
                            <div style={{ marginTop: 12, padding: '12px 14px', border: '1px solid #e3d1f2', background: '#f8f3fd', borderRadius: 10, fontSize: 12.5, color: '#4a3060' }}>
                                <div style={{ fontWeight: 800, color: '#7b3fb0', marginBottom: 8 }}>Learner resubmitted corrected details — please re-review</div>
                                {accountStatus.correction ? (
                                    <>
                                        <div style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '4px 12px', marginBottom: 8 }}>
                                            <span style={{ color: '#8a6aa8', fontWeight: 700 }}>Name</span>
                                            <span>{accountStatus.correction.lastname} {accountStatus.correction.firstname}</span>
                                            <span style={{ color: '#8a6aa8', fontWeight: 700 }}>National ID</span>
                                            <span style={{ fontVariantNumeric: 'tabular-nums' }}>{accountStatus.correction.nid || '—'}</span>
                                            {accountStatus.correction.note && (<>
                                                <span style={{ color: '#8a6aa8', fontWeight: 700 }}>Note</span>
                                                <span>{accountStatus.correction.note}</span>
                                            </>)}
                                        </div>
                                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                                            {accountStatus.correction.idcardurl && (
                                                <a href={accountStatus.correction.idcardurl} target="_blank" rel="noopener noreferrer"
                                                   style={{ padding: '5px 11px', borderRadius: 999, background: '#fff', border: '1px solid #d9c4ee', color: '#7b3fb0', fontSize: 11.5, fontWeight: 700, textDecoration: 'none' }}>▣ ID document</a>
                                            )}
                                            {accountStatus.correction.nesaresulturl && (
                                                <a href={accountStatus.correction.nesaresulturl} target="_blank" rel="noopener noreferrer"
                                                   style={{ padding: '5px 11px', borderRadius: 999, background: '#fff', border: '1px solid #d9c4ee', color: '#7b3fb0', fontSize: 11.5, fontWeight: 700, textDecoration: 'none' }}>▣ NESA result</a>
                                            )}
                                            <span style={{ fontSize: 11, color: accountStatus.correction.risesynced ? '#1a7f43' : '#b5660b', fontWeight: 700 }}>
                                                {accountStatus.correction.risesynced ? '✓ Pushed to RISE' : '⚠ Stored locally only (RISE not updated)'}
                                            </span>
                                        </div>
                                    </>
                                ) : (
                                    <div>Correction details could not be loaded.</div>
                                )}
                            </div>
                        )}
                    </div>

                    {confirmCreateOpen && (
                        <div className="fixed inset-0 z-[2300]" style={{ background: 'rgba(17,24,39,.48)', backdropFilter: 'blur(3px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
                            <div style={{ width: 380, maxWidth: '100%', borderRadius: 17, background: '#fff', boxShadow: '0 24px 70px rgba(20,28,46,.28)', padding: 24 }}>
                                <div style={{ fontSize: 18, fontWeight: 800, color: '#161b26', marginBottom: 6 }}>Create Moodle account?</div>
                                <div style={{ fontSize: 13.5, color: '#6b7280', lineHeight: 1.4, marginBottom: 18 }}>
                                    An account will be created (or linked) for <b>{applicant.fullName || 'this learner'}</b> and a welcome SMS with a set-password link will be sent to their phone.
                                </div>
                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.35fr', gap: 12 }}>
                                    <button onClick={() => setConfirmCreateOpen(false)}
                                        style={{ padding: '12px 14px', borderRadius: 11, border: '1px solid #dfe3ea', background: '#fff', color: '#3b424f', fontSize: 14, fontWeight: 700, cursor: 'pointer' }}>Cancel</button>
                                    <button onClick={() => { setConfirmCreateOpen(false); onCreateAccount(); }}
                                        style={{ padding: '12px 14px', borderRadius: 11, border: 'none', background: BRAND, color: '#fff', fontSize: 14, fontWeight: 700, cursor: 'pointer' }}>Confirm &amp; create</button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* APPLICANT DETAILS */}
                    <div style={sectionLabel}>APPLICANT DETAILS</div>
                    <div style={{ border: '1px solid #ecedf1', borderRadius: 12, overflow: 'hidden', marginBottom: 24 }}>
                        {detailRows.map((row, i) => (
                            <div key={row[0]} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', borderTop: i === 0 ? 'none' : '1px solid #f0f1f4' }}>
                                <div style={{ padding: '13px 16px', borderRight: '1px solid #f0f1f4' }}>
                                    <div style={{ fontSize: 10.5, letterSpacing: '.4px', textTransform: 'uppercase', color: '#9aa0ab', marginBottom: 4 }}>{row[0]}</div>
                                    <div style={{ fontSize: 13.5, fontWeight: 500, color: '#1f2430' }}>{row[1]}</div>
                                </div>
                                <div style={{ padding: '13px 16px' }}>
                                    <div style={{ fontSize: 10.5, letterSpacing: '.4px', textTransform: 'uppercase', color: '#9aa0ab', marginBottom: 4 }}>{row[2]}</div>
                                    <div style={{ fontSize: 13.5, fontWeight: 500, color: '#1f2430' }}>{row[3]}</div>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* ATTACHMENTS */}
                    <div style={sectionLabel}>ATTACHMENTS</div>
                    {attachments.length === 0 ? (
                        <p style={{ fontSize: 13, color: '#9aa0ab', marginBottom: 24 }}>No documents uploaded.</p>
                    ) : (
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 24 }}>
                            {attachments.map((a) => (
                                <button
                                    key={a.url}
                                    onClick={() => onPreview(a)}
                                    style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '12px 14px', border: '1px solid #e4e6eb', borderRadius: 11, background: '#fff', cursor: 'pointer', textAlign: 'left' }}
                                >
                                    <span style={{ flex: '0 0 auto', width: 34, height: 34, borderRadius: 8, background: '#e8f0f8', color: BRAND, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 15 }}>▣</span>
                                    <span style={{ minWidth: 0 }}>
                                        <span style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#1f2430' }}>{a.label}</span>
                                        <span style={{ display: 'block', fontSize: 11, color: '#9aa0ab', marginTop: 2 }}>{a.ext ? a.ext.toUpperCase() + ' document' : 'Document'}</span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}

                    {/* COLLAPSIBLE SECTIONS */}
                    {responseKeys.length > 0 && (
                        <Collapsible title="Application responses" count={responseKeys.length}>
                            <div style={{ padding: '2px 16px 16px' }}>
                                {responseKeys.map((k, i) => (
                                    <div key={k} style={{ padding: '11px 0', borderTop: i === 0 ? 'none' : '1px solid #f4f5f7' }}>
                                        <div style={{ fontSize: 11.5, color: '#8a909c', marginBottom: 3 }}>{k}</div>
                                        <div style={{ fontSize: 13, color: '#1f2430', fontWeight: 500 }}>{String(responses[k])}</div>
                                    </div>
                                ))}
                            </div>
                        </Collapsible>
                    )}

                    {reviews.length > 0 && (
                        <Collapsible title="Reviews" count={reviews.length}>
                            <div style={{ padding: '2px 16px 8px' }}>
                                {reviews.map((rv, i) => (
                                    <div key={i} style={{ padding: '13px 0', borderTop: i === 0 ? 'none' : '1px solid #f4f5f7' }}>
                                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, marginBottom: 5 }}>
                                            <span style={{ fontSize: 12.5, fontWeight: 600, color: '#1f2430', wordBreak: 'break-word' }}>{rv.reviewerName || 'Reviewer'}</span>
                                            <span style={{ flex: '0 0 auto', padding: '2px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 600, background: '#f1f3f6', color: '#5a616e' }}>{formatDate(rv.date)}</span>
                                        </div>
                                        <div style={{ fontSize: 12.5, color: '#5a616e', lineHeight: 1.5 }}>{rv.comment || '—'}</div>
                                    </div>
                                ))}
                            </div>
                        </Collapsible>
                    )}
                </div>

                {/* STICKY FOOTER: NESA REVIEW */}
                <NesaReviewSection
                    applicant={applicant}
                    campaignId={campaignId}
                    review={review}
                    nidValidated={nidVal.status === 'done' && nidVal.result?.match === true}
                    canManageRiseUsers={canManageRiseUsers}
                    onSaved={onReviewSaved}
                />
            </div>
        </div>
    );
}

// ---- Styled filter dropdown ----------------------------------------------

function Select({ value, onChange, minWidth, children }: {
    value: string;
    onChange: (v: string) => void;
    minWidth: number;
    children: any;
}) {
    return (
        <div style={{ position: 'relative' }}>
            <select
                value={value}
                onChange={(e) => onChange((e.target as HTMLSelectElement).value)}
                style={{ appearance: 'none', padding: '10px 36px 10px 14px', border: '1px solid #e2e5ea', borderRadius: 10, background: '#fff', fontFamily: 'inherit', fontSize: 13, fontWeight: 600, color: '#1f2430', cursor: 'pointer', minWidth }}
            >
                {children}
            </select>
            <span style={{ position: 'absolute', right: 13, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none', color: '#9aa0ab', fontSize: 10 }}>▼</span>
        </div>
    );
}

function ReviewMetricCard({ label, value, sublabel, accent, icon, tone }: {
    label: string;
    value: string;
    sublabel: string;
    accent?: string;
    icon: string;
    tone?: 'blue' | 'green' | 'red';
}) {
    const chipBg = tone === 'green' ? '#e6f4ec' : tone === 'red' ? '#fbe0de' : '#e8f0f8';
    const chipFg = tone === 'green' ? '#1a7f43' : tone === 'red' ? '#b42318' : BRAND;
    return (
        <div style={{ position: 'relative', minHeight: 128, background: '#fff', border: '1px solid #ecedf1', borderRadius: 16, padding: '24px 26px 22px', boxShadow: '0 10px 28px rgba(20,28,46,.07)' }}>
            <div style={{ fontSize: 11, fontWeight: 800, letterSpacing: '.8px', color: '#8a909c', textTransform: 'uppercase', marginBottom: 22 }}>{label}</div>
            <div style={{ fontSize: 31, lineHeight: 1, fontWeight: 800, letterSpacing: '-.8px', color: '#161b26', fontVariantNumeric: 'tabular-nums' }}>{value}</div>
            <div style={{ marginTop: 10, fontSize: 13.5, lineHeight: 1.15, fontWeight: 700, color: accent || '#9aa0ab' }}>{sublabel}</div>
            <span style={{ position: 'absolute', right: 22, top: 22, width: 38, height: 38, borderRadius: 11, background: chipBg, color: chipFg, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 17, fontWeight: 800 }}>{icon}</span>
        </div>
    );
}

function NidaStatusPill({ status }: { status: NidaStatus }) {
    const meta = NIDA_PILL[status];
    return (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 12px', borderRadius: 999, background: meta.bg, color: meta.fg, fontSize: 11.5, fontWeight: 700, whiteSpace: 'nowrap' }}>
            <span style={{ fontSize: 12, lineHeight: 1 }}>{meta.icon}</span>{meta.label}
        </span>
    );
}

// ---- Moodle account column -------------------------------------------------

function accountActionLabel(st: RiseUserStatus): string {
    if (st.correctionstatus === 'resubmitted') return 'Resubmitted';
    if (st.suspended) return 'Suspended';
    if (st.risesync === 'conflict') return 'RISE conflict';
    if (FIXABLE_ACTIONS.includes(st.provisioningaction)) return 'Action needed';
    if (st.provisioningaction === 'duplicate_nid') return 'Duplicate NID';
    return '';
}

function AccountCell({ st, canManage, approved, busy, error, onCreate }: {
    st?: RiseUserStatus;
    canManage: boolean;
    approved: boolean;
    busy: boolean;
    error?: string;
    onCreate: (e: Event) => void;
}) {
    if (!st) {
        return <span style={{ fontSize: 12, color: '#c0c4cc' }}>…</span>;
    }
    const flag = accountActionLabel(st);
    if (st.hasaccount) {
        return (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, minWidth: 0 }} onClick={(e) => e.stopPropagation()}>
                <a href={st.profileurl} target="_blank" rel="noopener noreferrer" title={`Open profile of ${st.username}`}
                   style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 11px', borderRadius: 999, background: '#e6f4ec', color: '#1a7f43', fontSize: 10.5, fontWeight: 700, textDecoration: 'none', whiteSpace: 'nowrap' }}>
                    ✓ {st.username}
                </a>
                {flag && (
                    <span title={ACTION_NOTES[st.provisioningaction] || flag}
                          style={{ display: 'inline-flex', padding: '4px 9px', borderRadius: 999, background: flag === 'Resubmitted' ? '#f3eafa' : '#fff1e0', color: flag === 'Resubmitted' ? '#7b3fb0' : '#b5660b', fontSize: 10.5, fontWeight: 700, whiteSpace: 'nowrap' }}>
                        {flag}
                    </span>
                )}
            </span>
        );
    }
    if (st.provisioningaction === 'duplicate_nid') {
        return (
            <span title={ACTION_NOTES.duplicate_nid}
                  style={{ display: 'inline-flex', padding: '4px 11px', borderRadius: 999, background: '#fbe0de', color: '#b42318', fontSize: 10.5, fontWeight: 700, whiteSpace: 'nowrap' }}>
                Duplicate NID
            </span>
        );
    }
    if (!canManage) {
        return <span style={{ fontSize: 12, color: '#9aa0ab' }}>No account</span>;
    }
    if (!approved) {
        // Accounts are created on NESA approval; manual create is a recovery
        // path for approved reviews only.
        return (
            <span title="Approve the NESA review first — accounts are only created for approved learners."
                  style={{ fontSize: 12, color: '#9aa0ab', cursor: 'help' }}>Approve first</span>
        );
    }
    return (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            <button
                onClick={(e) => { e.stopPropagation(); onCreate(e); }}
                disabled={busy}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 12px', borderRadius: 999, border: `1px solid ${error ? '#b42318' : BRAND}`, background: busy ? '#a6c0d6' : '#fff', color: busy ? '#fff' : (error ? '#b42318' : BRAND), fontSize: 11, fontWeight: 700, cursor: busy ? 'wait' : 'pointer', whiteSpace: 'nowrap', fontFamily: 'inherit' }}>
                {busy ? 'Creating…' : (error ? 'Retry create' : '+ Create account')}
            </button>
            {error && (
                <span title={error} onClick={(e) => e.stopPropagation()}
                      style={{ fontSize: 12, color: '#b42318', fontWeight: 800, cursor: 'help' }}>⚠</span>
            )}
        </span>
    );
}

// ---- Applicants view ------------------------------------------------------

function ApplicantList({ campaign, user, onBack, deepApplicantId, deepDoc, onSelectApplicant, onSelectDoc }: {
    campaign: RiseCampaign;
    user?: UserData;
    onBack: () => void;
    deepApplicantId: string;
    deepDoc: string;
    onSelectApplicant: (id: string | null) => void;
    onSelectDoc: (label: string | null) => void;
}) {
    const canManageRiseUsers = !!user?.canManageRiseUsers;
    const [applicants, setApplicants] = useState<RiseApplicant[]>([]);
    const [allApplicants, setAllApplicants] = useState<RiseApplicant[]>([]);
    const [pagination, setPagination] = useState<RisePagination>({ page: 1, limit: 10, total: 0, totalPages: 0 });
    const [loading, setLoading] = useState(true);
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState('');

    const initialUrl = readRiseUrlState();
    // Deep-linking straight to an applicant must search every status, so the
    // target is present in the loaded list; otherwise default to enrolled.
    const [status, setStatus] = useState(initialUrl.status || (deepApplicantId ? '' : 'ENROLLED'));
    const [district, setDistrict] = useState(initialUrl.district);
    const [gender, setGender] = useState(initialUrl.gender);
    const [nesaFilter, setNesaFilter] = useState(initialUrl.nesa);
    const [nidaFilter, setNidaFilter] = useState(initialUrl.nida);
    const [searchInput, setSearchInput] = useState(initialUrl.q);
    const [search, setSearch] = useState(initialUrl.q);
    const [page, setPage] = useState(Math.max(1, parseInt(initialUrl.page || '1', 10) || 1));

    const [selected, setSelected] = useState<RiseApplicant | null>(null);
    const [preview, setPreview] = useState<RiseAttachment | null>(null);
    const [reviews, setReviews] = useState<Record<string, RiseNesaReview>>({});
    const [userStatus, setUserStatus] = useState<Record<string, RiseUserStatus>>({});
    const [creating, setCreating] = useState<Record<string, boolean>>({});
    const [createErrors, setCreateErrors] = useState<Record<string, string>>({});
    const [sortKey, setSortKey] = useState<'name' | 'score' | null>(null);
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');

    // Fetch account status for a set of applicants and merge it into state.
    async function fetchUserStatus(rows: RiseApplicant[]): Promise<Record<string, RiseUserStatus>> {
        const merged: Record<string, RiseUserStatus> = {};
        // Batch in chunks so an export-sized set stays within request limits.
        for (let i = 0; i < rows.length; i += 100) {
            const pairs = rows.slice(i, i + 100).map((a) => ({ applicantid: a._id, nid: a.nid || '' }));
            const raw = await ajaxCall('local_elby_dashboard_rise_get_user_status', {
                campaignid: campaign._id,
                pairs,
            });
            Object.assign(merged, JSON.parse(raw) || {});
        }
        setUserStatus((prev) => ({ ...prev, ...merged }));
        return merged;
    }

    // Account status for the visible page (re-fetched whenever the page data changes).
    useEffect(() => {
        if (!applicants.length) return;
        fetchUserStatus(applicants).catch((e) => console.error('RISE user status load failed:', e));
    }, [applicants]);

    async function createAccount(a: RiseApplicant) {
        if (creating[a._id]) return;
        setCreating((prev) => ({ ...prev, [a._id]: true }));
        setCreateErrors((prev) => ({ ...prev, [a._id]: '' }));
        try {
            const raw = await ajaxCall('local_elby_dashboard_rise_create_user', {
                campaignid: campaign._id,
                applicantid: a._id,
            });
            const result = JSON.parse(raw);
            if (!result.success) {
                setCreateErrors((prev) => ({
                    ...prev,
                    [a._id]: result.message || 'Could not create the account. Please try again.',
                }));
            }
        } catch (e: any) {
            console.error('RISE create user failed:', e);
            setCreateErrors((prev) => ({
                ...prev,
                [a._id]: e?.message || 'Could not create the account. Please try again.',
            }));
        } finally {
            // Always re-resolve from the backend so the pill reflects stored state
            // (created, linked, blocked on duplicate NID, ...).
            try {
                await fetchUserStatus([a]);
            } catch (e) {
                console.error('RISE user status refresh failed:', e);
            }
            setCreating((prev) => ({ ...prev, [a._id]: false }));
        }
    }

    useEffect(() => {
        // Skip the initial run (and any no-op) so a deep-linked page/search isn't reset.
        if (searchInput === search) return;
        const handle = window.setTimeout(() => {
            setSearch(searchInput);
            setPage(1);
            writeRiseUrl({ q: searchInput, page: '' }, true);
        }, 300);
        return () => window.clearTimeout(handle);
    }, [searchInput]);

    // Make the default ENROLLED status explicit in the URL on first entry.
    useEffect(() => {
        const u = readRiseUrlState();
        if (!u.status && !deepApplicantId && status) writeRiseUrl({ status }, true);
    }, []);

    useEffect(() => {
        (async () => {
            try {
                const raw = await ajaxCall('local_elby_dashboard_rise_get_reviews', { campaignid: campaign._id });
                setReviews(JSON.parse(raw) || {});
            } catch (e) {
                console.error('RISE reviews load failed:', e);
            }
        })();
    }, [campaign._id]);

    function onReviewSaved(applicantId: string, review: RiseNesaReview) {
        setReviews((prev) => ({ ...prev, [applicantId]: review }));
        // Saving an approved review may auto-provision the account server-side —
        // re-resolve this applicant's status so the ACCOUNT pill doesn't go stale.
        if (review.nesastatus === 'approved') {
            const pool = allApplicants.length ? allApplicants : applicants;
            const a = pool.find((x) => x._id === applicantId);
            if (a) {
                fetchUserStatus([a]).catch((e) => console.error('RISE user status refresh failed:', e));
            }
        }
    }

    // Open/close the applicant drawer to match the URL (deep link + back/forward).
    useEffect(() => {
        if (!deepApplicantId) { setSelected(null); return; }
        if (selected && selected._id === deepApplicantId) return;
        const pool = allApplicants.length ? allApplicants : applicants;
        const match = pool.find((a) => a._id === deepApplicantId);
        if (match) setSelected(match);
    }, [deepApplicantId, allApplicants, applicants]);

    // Open/close the document preview to match the URL.
    useEffect(() => {
        if (!deepDoc || !selected) { setPreview(null); return; }
        const att = extractAttachments(selected).find((a) => a.label === deepDoc) || null;
        setPreview(att);
    }, [deepDoc, selected]);

    const openApplicant = (a: RiseApplicant) => { setSelected(a); onSelectApplicant(a._id); };

    useEffect(() => {
        (async () => {
            try {
                setLoading(true);
                setError('');
                const raw = await ajaxCall('local_elby_dashboard_rise_get_applicants', {
                    campaignid: campaign._id,
                    status,
                    provincecode: '',
                    district,
                    gender,
                    nesa: nesaFilter,
                    nida: nidaFilter,
                    search,
                    page,
                    limit: 10,
                });
                const data = JSON.parse(raw);
                setApplicants(data.applicants || []);
                setPagination(data.pagination || { page, limit: 10, total: 0, totalPages: 0 });
            } catch (e) {
                console.error('RISE applicants load failed:', e);
                setError('Failed to load applicants.');
            } finally {
                setLoading(false);
            }
        })();
    }, [campaign._id, status, district, gender, nesaFilter, nidaFilter, search, page]);

    // Reset to page 1 whenever a filter changes.
    function onFilter(setter: (v: string) => void, value: string, urlKey: keyof RiseUrlState) {
        setter(value);
        setPage(1);
        writeRiseUrl({ [urlKey]: value, page: '' }, true);
    }

    // Page lives in the URL too, so a paged view is shareable and survives reload.
    const goToPage = (p: number) => {
        const next = Math.max(1, p);
        setPage(next);
        writeRiseUrl({ page: next > 1 ? String(next) : '' }, true);
    };

    const avatarColors = (g?: string) => g === 'Female'
        ? { bg: '#f3eafa', fg: '#7b3fb0' }
        : { bg: '#e8f0f8', fg: '#005198' };

    const sortApplicants = (rows: RiseApplicant[]) => [...rows].sort((a, b) => {
        if (!sortKey) return 0;
        const dir = sortDir === 'asc' ? 1 : -1;
        if (sortKey === 'score') return ((a.totalScore ?? 0) - (b.totalScore ?? 0)) * dir;
        return (a.fullName || '').localeCompare(b.fullName || '') * dir;
    });

    // The table is fully server-driven now (status/gender/district/nesa/nida/search all
    // filtered + paginated by the backend); we only sort the current page locally.
    const visibleRows = sortApplicants(applicants);
    const displayTotal = pagination.total;
    const displayPage = pagination.page || page;
    const displayTotalPages = pagination.totalPages || 1;
    // District options are sourced from the background full list (also used for export).
    // Rwanda's districts are a fixed set, so the dropdown uses the static list (no fetch).
    const districtOptions = (district && !RWANDA_DISTRICTS.includes(district))
        ? [district, ...RWANDA_DISTRICTS]
        : RWANDA_DISTRICTS;

    const reviewRows = Object.values(reviews);
    const totalForMetrics = pagination.total || campaign.stats?.total || allApplicants.length || applicants.length;
    const reviewedCount = reviewRows.filter((r) => r.nesastatus && r.nesastatus !== 'pending').length;
    const nidaVerifiedCount = reviewRows.filter((r) => nidaStatus(r) === 'verified').length;
    const mismatchCount = reviewRows.filter((r) => nidaStatus(r) === 'mismatch').length;

    async function fetchAllFilteredApplicants(extra: Record<string, unknown> = {}): Promise<RiseApplicant[]> {
        const baseArgs = {
            campaignid: campaign._id,
            status,
            provincecode: '',
            district: '',
            gender,
            limit: 100,
            ...extra,
        };
        const firstRaw = await ajaxCall('local_elby_dashboard_rise_get_applicants', { ...baseArgs, page: 1 });
        const first = JSON.parse(firstRaw);
        const totalPages = Math.max(1, first.pagination?.totalPages || 1);
        const out: RiseApplicant[] = [...(first.applicants || [])];

        // Fetch every remaining page deliberately for export. Do not rely on the paged table or
        // background cache, otherwise quick clicks can export only the first visible page.
        const concurrency = 4;
        for (let start = 2; start <= totalPages; start += concurrency) {
            const pageNumbers = Array.from({ length: Math.min(concurrency, totalPages - start + 1) }, (_, i) => start + i);
            const chunks = await Promise.all(pageNumbers.map(async (pageno) => {
                const raw = await ajaxCall('local_elby_dashboard_rise_get_applicants', { ...baseArgs, page: pageno });
                return JSON.parse(raw).applicants || [];
            }));
            chunks.forEach((chunk) => out.push(...chunk));
        }
        return out;
    }

    async function exportApplicants() {
        try {
            setExporting(true);
            // Export honours every active filter by asking the backend for the fully filtered
            // set (review filters resolve from the dashboard DB, not the RISE applicants API).
            const source = await fetchAllFilteredApplicants({
                district,
                nesa: nesaFilter,
                nida: nidaFilter,
                search,
            });
            const rows = sortApplicants(source);
            // The export set is built independently of the visible page, so fetch
            // account status for every exported row (not just the loaded page).
            let exportStatus: Record<string, RiseUserStatus> = {};
            let statusFetchFailed = false;
            try {
                exportStatus = await fetchUserStatus(rows);
            } catch (e) {
                console.error('RISE export status fetch failed:', e);
                statusFetchFailed = true;
            }
            const exportedAt = new Date().toLocaleString();
            downloadExcelWorkbook(
                `RISE-applicants-${filenameTimestamp()}.xls`,
                campaign.name || 'RISE applicants',
                `${rows.length.toLocaleString()} exported rows · Generated ${exportedAt}`,
                ['Name', 'Gender', 'District', 'Sector', 'Phone', 'Score', 'Status', 'NIDA', 'NESA', 'NESA index number', 'National ID', 'Date of birth', 'Account', 'Account action'],
                rows.map((a) => {
                    const rev = reviews[a._id];
                    const nida = nidaStatus(rev);
                    const nesa = rev?.nesastatus;
                    const nesaLabel = rev ? NESA_META[rev.nesastatus].label : '—';
                    const nesaStyle = nesa === 'approved' ? 'PillGreen'
                        : nesa === 'rejected' ? 'PillRed'
                            : (nesa === 'action_requested' || nesa === 'pending') ? 'PillAmber' : 'Muted';
                    const nidaStyle = nida === 'verified' ? 'PillGreen' : nida === 'mismatch' ? 'PillRed' : 'PillAmber';
                    const st = exportStatus[a._id];
                    // A failed status fetch must export as unknown, never as "No account".
                    const accountLabel = st ? (st.hasaccount ? st.username : 'No account')
                        : (statusFetchFailed ? 'Unknown' : '—');
                    const accountAction = st ? accountActionLabel(st) : '';
                    return [
                        { value: a.fullName || '', style: 'NameCell' },
                        { value: a.gender || '' },
                        { value: applicantDistrict(a) },
                        { value: applicantSector(a) },
                        { value: a.phone || '', type: 'String' },
                        { value: a.totalScore ?? '', style: 'ScoreCell', type: typeof a.totalScore === 'number' ? 'Number' : 'String' },
                        { value: a.status || '', style: 'StatusBlue' },
                        { value: NIDA_PILL[nida].icon + ' ' + NIDA_PILL[nida].label, style: nidaStyle },
                        { value: nesaLabel, style: nesaStyle },
                        { value: rev?.nesaindexnumber || '', type: 'String' },
                        { value: a.nid || '', type: 'String' },
                        { value: formatDateOnly(a.dateOfBirth), type: 'String' },
                        { value: accountLabel, style: st?.hasaccount ? 'PillGreen' : 'Muted', type: 'String' },
                        { value: accountAction, style: accountAction ? 'PillAmber' : 'Muted', type: 'String' }
                    ];
                })
            );
        } catch (e) {
            console.error('RISE applicant export failed:', e);
            setError('Could not export applicants. Please try again.');
        } finally {
            setExporting(false);
        }
    }

    const toggleSort = (k: 'name' | 'score') => {
        if (sortKey === k) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        else { setSortKey(k); setSortDir('asc'); }
    };
    const arrow = (k: 'name' | 'score') => (sortKey === k ? (sortDir === 'asc' ? '▲' : '▼') : '↕');
    const arrowColor = (k: 'name' | 'score') => (sortKey === k ? '#005198' : '#c4c8d0');

    const GRID = '2.3fr 0.85fr 1.05fr 1.2fr 0.75fr 1.1fr 1.05fr 1.45fr 1.05fr';
    const headCell = { fontSize: 10.5, fontWeight: 700, letterSpacing: '.7px', color: '#8a909c' } as const;

    return (
        <div className="p-4 sm:p-6">
            <button onClick={onBack} className="flex items-center gap-1 text-sm text-blue-600 hover:underline mb-3">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Back to campaigns
            </button>

            <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 20, marginBottom: 22, flexWrap: 'wrap' }}>
                <h1 style={{ margin: 0, fontSize: 30, fontWeight: 800, letterSpacing: '-.7px', color: '#161b26' }}>{campaign.name}</h1>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 16px', borderRadius: 12, background: '#fff', border: '1px solid #e7e9ee', fontSize: 15, color: '#6b7280', fontWeight: 700, boxShadow: '0 2px 8px rgba(20,28,46,.04)' }}>
                        <b style={{ color: '#161b26', fontWeight: 800 }}>{pagination.total.toLocaleString()}</b>&nbsp;applicants
                    </span>
                    <button onClick={exportApplicants} disabled={exporting}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 10, padding: '13px 18px', borderRadius: 12, border: 'none', background: exporting ? '#7fa687' : '#337a43', color: '#fff', fontFamily: 'inherit', fontSize: 14.5, fontWeight: 800, cursor: exporting ? 'not-allowed' : 'pointer', boxShadow: '0 6px 16px rgba(51,122,67,.18)' }}>
                        {exporting ? (
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="rgba(255,255,255,.38)" strokeWidth="3" />
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="#fff" strokeWidth="3" strokeLinecap="round">
                                    <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite" />
                                </path>
                            </svg>
                        ) : <span style={{ fontSize: 18, lineHeight: 1 }}>⇩</span>} {exporting ? 'Preparing export' : 'Export to Excel'}
                    </button>
                </div>
            </div>

            {/* REVIEW METRICS */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 18, marginBottom: 22 }}>
                <ReviewMetricCard
                    label="Total applicants"
                    value={num(totalForMetrics)}
                    sublabel={`Page ${displayPage} of ${displayTotalPages}`}
                    icon="◍"
                    tone="blue"
                />
                <ReviewMetricCard
                    label="Reviewed"
                    value={num(reviewedCount)}
                    sublabel={`${pct(reviewedCount, totalForMetrics)}% complete`}
                    accent="#1a7f43"
                    icon="✓"
                    tone="green"
                />
                <ReviewMetricCard
                    label="NIDA verified"
                    value={num(nidaVerifiedCount)}
                    sublabel={`${pct(nidaVerifiedCount, totalForMetrics)}% of applicants`}
                    icon="▣"
                    tone="blue"
                />
                <ReviewMetricCard
                    label="NIDA mismatches"
                    value={num(mismatchCount)}
                    sublabel="Failed NIDA check"
                    accent="#b42318"
                    icon="⚠"
                    tone="red"
                />
            </div>

            {/* FILTERS */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 11, marginBottom: 16, flexWrap: 'wrap' }}>
                <Select value={status} onChange={(v) => onFilter(setStatus, v, 'status')} minWidth={148}>
                    {STATUSES.map((s) => <option value={s}>{s || 'All statuses'}</option>)}
                </Select>
                <Select value={gender} onChange={(v) => onFilter(setGender, v, 'gender')} minWidth={140}>
                    <option value="">All genders</option>
                    <option value="Female">Female</option>
                    <option value="Male">Male</option>
                </Select>
                <Select value={district} onChange={(v) => onFilter(setDistrict, v, 'district')} minWidth={170}>
                    <option value="">All districts</option>
                    {districtOptions.map((d) => <option value={d}>{d}</option>)}
                </Select>
                <Select value={nesaFilter} onChange={(v) => onFilter(setNesaFilter, v, 'nesa')} minWidth={155}>
                    <option value="">All NESA</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="action_requested">Action requested</option>
                    <option value="pending">Pending</option>
                </Select>
                <Select value={nidaFilter} onChange={(v) => onFilter(setNidaFilter, v, 'nida')} minWidth={150}>
                    <option value="">All NIDA</option>
                    <option value="verified">Verified</option>
                    <option value="mismatch">Mismatch</option>
                    <option value="pending">Pending</option>
                </Select>
                <div style={{ position: 'relative', flex: '1 1 200px', minWidth: 180, maxWidth: 320 }}>
                    <span style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', color: '#b6bcc6', fontSize: 13 }}>⌕</span>
                    <input value={searchInput} placeholder="Search name or district…"
                           onInput={(e) => setSearchInput((e.target as HTMLInputElement).value)}
                           style={{ padding: '10px 38px 10px 34px', border: '1px solid #e2e5ea', borderRadius: 10, background: '#fff', fontFamily: 'inherit', fontSize: 13, color: '#1f2430', width: '100%', boxSizing: 'border-box', outline: 'none' }} />
                    {searchInput && (
                        <button
                            type="button"
                            aria-label="Clear search"
                            onClick={() => { setSearchInput(''); setSearch(''); setPage(1); }}
                            style={{ position: 'absolute', right: 9, top: '50%', transform: 'translateY(-50%)', width: 22, height: 22, border: 'none', borderRadius: '50%', background: '#eef1f5', color: '#7b8390', fontSize: 14, fontWeight: 800, lineHeight: 1, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                        >×</button>
                    )}
                </div>
                <div style={{ flex: '1 1 auto' }} />
                <div style={{ fontSize: 12.5, color: '#9aa0ab' }}>Showing <b style={{ color: '#5a616e' }}>{visibleRows.length}</b> of {displayTotal.toLocaleString()}</div>
            </div>

            {/* TABLE */}
            <div style={{ background: '#fff', border: '1px solid #ecedf1', borderRadius: 14, overflowX: 'auto', boxShadow: '0 1px 2px rgba(20,28,46,.04), 0 6px 24px rgba(20,28,46,.05)' }}>
                <div style={{ display: 'grid', gridTemplateColumns: GRID, minWidth: 980, alignItems: 'center', padding: '0 22px', height: 46, background: '#fafbfc', borderBottom: '1px solid #eceef2' }}>
                    <button onClick={() => toggleSort('name')} style={{ display: 'flex', alignItems: 'center', gap: 5, border: 'none', background: 'none', padding: 0, cursor: 'pointer', fontFamily: 'inherit', textAlign: 'left', ...headCell }}>
                        NAME <span style={{ fontSize: 9, color: arrowColor('name') }}>{arrow('name')}</span>
                    </button>
                    <div style={headCell}>GENDER</div>
                    <div style={headCell}>DISTRICT</div>
                    <div style={headCell}>PHONE</div>
                    <button onClick={() => toggleSort('score')} style={{ display: 'flex', alignItems: 'center', gap: 5, border: 'none', background: 'none', padding: 0, cursor: 'pointer', fontFamily: 'inherit', ...headCell }}>
                        SCORE <span style={{ fontSize: 9, color: arrowColor('score') }}>{arrow('score')}</span>
                    </button>
                    <div style={headCell}>STATUS</div>
                    <div style={headCell}>NIDA</div>
                    <div style={headCell}>ACCOUNT</div>
                    <div style={headCell}>NESA</div>
                </div>

                {loading ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#9aa0ab', fontSize: 13.5 }}>Loading applicants…</div>
                ) : error ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#b42318', fontSize: 13.5 }}>{error}</div>
                ) : visibleRows.length === 0 ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#9aa0ab', fontSize: 13.5 }}>No applicants match these filters.</div>
                ) : visibleRows.map((a) => {
                    const av = avatarColors(a.gender);
                    const isSel = selected?._id === a._id;
                    const score = a.totalScore;
                    const rev = reviews[a._id];
                    const np = rev ? NESA_PILL[rev.nesastatus] : null;
                    const npLabel = rev ? NESA_META[rev.nesastatus].label : '—';
                    const nida = nidaStatus(rev);
                    return (
                        <div key={a._id} onClick={() => openApplicant(a)}
                            style={{ display: 'grid', gridTemplateColumns: GRID, minWidth: 980, alignItems: 'center', padding: '0 22px', minHeight: 56, borderBottom: '1px solid #f3f4f7', cursor: 'pointer', background: isSel ? '#f1f6fb' : '#fff', boxShadow: isSel ? 'inset 3px 0 0 #005198' : 'none' }}
                            onMouseEnter={(e) => { if (!isSel) (e.currentTarget as HTMLElement).style.background = '#f7f9fb'; }}
                            onMouseLeave={(e) => { if (!isSel) (e.currentTarget as HTMLElement).style.background = '#fff'; }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0, paddingRight: 14 }}>
                                <span style={{ flex: '0 0 auto', width: 33, height: 33, borderRadius: '50%', background: av.bg, color: av.fg, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11.5, fontWeight: 600 }}>{initials(a.fullName)}</span>
                                <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontSize: 13.5, fontWeight: 600, color: '#1f2430' }}>{a.fullName || '-'}</span>
                            </div>
                            <div style={{ fontSize: 13, color: '#5a616e' }}>{a.gender || '-'}</div>
                            <div style={{ fontSize: 13, color: '#3b424f' }}>{a.location?.districtName || a.district || '-'}</div>
                            <div style={{ fontSize: 13, color: '#3b424f', fontVariantNumeric: 'tabular-nums' }}>{a.phone || '-'}</div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                                <span style={{ fontSize: 13.5, fontWeight: 700, color: '#161b26', fontVariantNumeric: 'tabular-nums' }}>{score ?? '-'}</span>
                                <span style={{ flex: '0 0 auto', width: 32, height: 5, borderRadius: 999, background: '#edeff2', overflow: 'hidden' }}>
                                    <span style={{ display: 'block', height: '100%', width: Math.min(100, score || 0) + '%', background: (score || 0) >= 60 ? '#1a9c52' : '#005198', borderRadius: 999 }} />
                                </span>
                            </div>
                            <div>
                                <span style={{ display: 'inline-flex', alignItems: 'center', padding: '4px 11px', borderRadius: 999, background: '#e8f0f8', color: '#005198', fontSize: 10.5, fontWeight: 600, letterSpacing: '.2px' }}>{a.status || '-'}</span>
                            </div>
                            <div><NidaStatusPill status={nida} /></div>
                            <div style={{ paddingRight: 10 }}>
                                <AccountCell
                                    st={userStatus[a._id]}
                                    canManage={canManageRiseUsers}
                                    approved={rev?.nesastatus === 'approved'}
                                    busy={!!creating[a._id]}
                                    error={createErrors[a._id]}
                                    onCreate={() => createAccount(a)}
                                />
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
                                {np ? (
                                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 11px', borderRadius: 999, background: np.bg, color: np.fg, fontSize: 10.5, fontWeight: 600 }}>
                                        <span style={{ width: 6, height: 6, borderRadius: '50%', background: np.dot, display: 'inline-block' }} />{npLabel}
                                    </span>
                                ) : (
                                    <span style={{ fontSize: 12, color: '#c0c4cc' }}>—</span>
                                )}
                                <span style={{ color: '#c4c8d0', fontSize: 14 }}>›</span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* PAGINATION */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, marginTop: 18 }}>
                <div style={{ fontSize: 13, color: '#8a909c' }}>Page <b style={{ color: '#5a616e' }}>{displayPage}</b> of {displayTotalPages} · {displayTotal.toLocaleString()} applicants</div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <button disabled={page <= 1} onClick={() => goToPage(page - 1)}
                        style={{ padding: '9px 16px', border: '1px solid #e2e5ea', borderRadius: 9, background: '#fff', fontFamily: 'inherit', fontSize: 13, fontWeight: 500, color: page <= 1 ? '#b6bcc6' : '#3b424f', cursor: page <= 1 ? 'not-allowed' : 'pointer' }}>‹ Previous</button>
                    <button disabled={page >= displayTotalPages} onClick={() => goToPage(page + 1)}
                        style={{ padding: '9px 16px', border: '1px solid #005198', borderRadius: 9, background: page >= displayTotalPages ? '#a6c0d6' : '#005198', fontFamily: 'inherit', fontSize: 13, fontWeight: 600, color: '#fff', cursor: page >= displayTotalPages ? 'not-allowed' : 'pointer' }}>Next ›</button>
                </div>
            </div>

            {selected && (
                <ApplicantDetail
                    applicant={selected}
                    campaignId={campaign._id}
                    review={reviews[selected._id]}
                    accountStatus={userStatus[selected._id]}
                    canManageRiseUsers={canManageRiseUsers}
                    creatingAccount={!!creating[selected._id]}
                    createError={createErrors[selected._id]}
                    onCreateAccount={() => createAccount(selected)}
                    onClose={() => { setSelected(null); onSelectApplicant(null); }}
                    onPreview={(att) => { setPreview(att); onSelectDoc(att.label); }}
                    onReviewSaved={onReviewSaved}
                />
            )}
            {preview && (
                <PreviewModal attachment={preview} onClose={() => { setPreview(null); onSelectDoc(null); }} />
            )}
        </div>
    );
}

// ---- Root -----------------------------------------------------------------

// ---- SMS notification log report -----------------------------------------

const SMS_STATUS_META: Record<string, { label: string; bg: string; fg: string; dot: string }> = {
    sent: { label: 'Sent', bg: '#e6f4ec', fg: '#1a7f43', dot: '#1a9c52' },
    failed: { label: 'Failed', bg: '#fbe0de', fg: '#b42318', dot: '#d4462f' },
    skipped: { label: 'Skipped', bg: '#fff1e0', fg: '#b5660b', dot: '#f79222' },
};

const SMS_PURPOSE_LABEL: Record<string, string> = {
    welcome: 'Welcome / set password',
    action: 'Action needed',
    correction: 'Correction',
};

function formatUnix(ts: number): string {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return isNaN(d.getTime()) ? '-' : d.toLocaleString();
}

// A clickable summary tile that also acts as the status filter.
function SmsSummaryTile({ label, value, tone, active, onClick }: {
    label: string; value: number; tone: 'blue' | 'green' | 'red' | 'amber'; active: boolean; onClick: () => void;
}) {
    const palette = {
        blue: { bg: '#f1f6fb', border: '#cfe0f2', fg: '#005198', dot: '#005198' },
        green: { bg: '#f0f9f3', border: '#cfe9d9', fg: '#1a7f43', dot: '#1a9c52' },
        red: { bg: '#fdf3f3', border: '#f3c9c9', fg: '#b42318', dot: '#d4462f' },
        amber: { bg: '#fff8ee', border: '#f3e1c0', fg: '#b5660b', dot: '#f79222' },
    }[tone];
    return (
        <button onClick={onClick}
            style={{ textAlign: 'left', background: palette.bg, border: `1.5px solid ${active ? palette.fg : palette.border}`,
                borderRadius: 12, padding: '13px 16px', cursor: 'pointer', fontFamily: 'inherit',
                boxShadow: active ? `0 0 0 1px ${palette.fg}` : 'none' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 6 }}>
                <span style={{ width: 7, height: 7, borderRadius: '50%', background: palette.dot }} />
                <span style={{ fontSize: 11, fontWeight: 700, letterSpacing: '.4px', textTransform: 'uppercase', color: palette.fg }}>{label}</span>
            </div>
            <div style={{ fontSize: 23, fontWeight: 800, color: palette.fg, lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{num(value)}</div>
        </button>
    );
}

function NotificationsReport({ onBack }: { onBack: () => void }) {
    const [campaigns, setCampaigns] = useState<RiseCampaign[]>([]);
    const [campaignId, setCampaignId] = useState('');
    const [status, setStatus] = useState('');
    const [purpose, setPurpose] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const [data, setData] = useState<RiseSmsLogResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    // Campaign dropdown source (best-effort; a failure just leaves "All campaigns").
    useEffect(() => {
        (async () => {
            try {
                const raw = await ajaxCall('local_elby_dashboard_rise_get_campaigns', {});
                setCampaigns(JSON.parse(raw).campaigns || []);
            } catch (e) { console.error('RISE campaigns load failed:', e); }
        })();
    }, []);

    // Debounce the free-text search.
    useEffect(() => {
        const h = window.setTimeout(() => { setSearch(searchInput); setPage(1); }, 300);
        return () => window.clearTimeout(h);
    }, [searchInput]);

    const dateToTs = (v: string, endOfDay = false): number => {
        if (!v) return 0;
        const d = new Date(v + 'T00:00:00');
        if (isNaN(d.getTime())) return 0;
        return Math.floor(d.getTime() / 1000) + (endOfDay ? 86400 : 0);
    };

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                setLoading(true); setError('');
                const raw = await ajaxCall('local_elby_dashboard_rise_get_sms_log', {
                    campaignid: campaignId, status, purpose,
                    datefrom: dateToTs(dateFrom), dateto: dateToTs(dateTo, true),
                    search, page, limit: 50,
                });
                if (!cancelled) setData(JSON.parse(raw));
            } catch (e) {
                console.error('RISE SMS log load failed:', e);
                if (!cancelled) setError('Failed to load the notification log.');
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => { cancelled = true; };
    }, [campaignId, status, purpose, dateFrom, dateTo, search, page]);

    const onFilter = (setter: (v: string) => void) => (v: string) => { setter(v); setPage(1); };
    const summary = data?.summary || { sent: 0, failed: 0, skipped: 0, total: 0 };
    const rows = data?.rows || [];
    const pag = data?.pagination || { page: 1, limit: 50, total: 0, totalPages: 1 };
    const campaignName = (id: string) => campaigns.find((c) => c._id === id)?.name || id;

    const GRID = '1.4fr 1.7fr 1.1fr 1.2fr 0.9fr 2fr';
    const headCell = { fontSize: 10.5, fontWeight: 700, letterSpacing: '.7px', color: '#8a909c' } as const;

    return (
        <div className="p-4 sm:p-6" style={{ padding: 'clamp(16px, 3vw, 30px) clamp(16px, 3vw, 34px) 40px' }}>
            <button onClick={onBack} className="flex items-center gap-1 text-sm text-blue-600 hover:underline mb-3"
                style={{ display: 'inline-flex', alignItems: 'center', gap: 4, border: 'none', background: 'none', color: '#005198', cursor: 'pointer', fontSize: 13.5, marginBottom: 12 }}>
                <span style={{ fontSize: 15 }}>‹</span> Back to campaigns
            </button>

            <div style={{ marginBottom: 18 }}>
                <h1 style={{ margin: '0 0 5px', fontSize: 26, fontWeight: 800, letterSpacing: '-.5px', color: '#161b26' }}>SMS notifications</h1>
                <p style={{ margin: 0, fontSize: 13.5, color: '#6b7280' }}>
                    Delivery log of welcome, action-needed and correction messages. <b>Sent</b> means the gateway accepted the message (not a handset delivery receipt); <b>failed</b> is retried nightly; <b>skipped</b> is a permanent no-send (bad/missing phone or the gateway is off).
                </p>
            </div>

            {/* SUMMARY TILES (also act as the status filter) */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12, marginBottom: 18 }}>
                <SmsSummaryTile label="Total" value={summary.total} tone="blue" active={status === ''} onClick={() => onFilter(setStatus)('')} />
                <SmsSummaryTile label="Sent" value={summary.sent} tone="green" active={status === 'sent'} onClick={() => onFilter(setStatus)(status === 'sent' ? '' : 'sent')} />
                <SmsSummaryTile label="Failed" value={summary.failed} tone="red" active={status === 'failed'} onClick={() => onFilter(setStatus)(status === 'failed' ? '' : 'failed')} />
                <SmsSummaryTile label="Skipped" value={summary.skipped} tone="amber" active={status === 'skipped'} onClick={() => onFilter(setStatus)(status === 'skipped' ? '' : 'skipped')} />
            </div>

            {/* FILTER BAR */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 11, marginBottom: 16, flexWrap: 'wrap' }}>
                <Select value={campaignId} onChange={onFilter(setCampaignId)} minWidth={200}>
                    <option value="">All campaigns</option>
                    {campaigns.map((c) => <option value={c._id}>{c.name}</option>)}
                </Select>
                <Select value={purpose} onChange={onFilter(setPurpose)} minWidth={170}>
                    <option value="">All types</option>
                    <option value="welcome">Welcome / set password</option>
                    <option value="action">Action needed</option>
                    <option value="correction">Correction</option>
                </Select>
                <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12.5, color: '#6b7280' }}>
                    From <input type="date" value={dateFrom} onInput={(e) => onFilter(setDateFrom)((e.target as HTMLInputElement).value)}
                        style={{ padding: '9px 10px', border: '1px solid #e2e5ea', borderRadius: 9, fontFamily: 'inherit', fontSize: 13 }} />
                </label>
                <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12.5, color: '#6b7280' }}>
                    To <input type="date" value={dateTo} onInput={(e) => onFilter(setDateTo)((e.target as HTMLInputElement).value)}
                        style={{ padding: '9px 10px', border: '1px solid #e2e5ea', borderRadius: 9, fontFamily: 'inherit', fontSize: 13 }} />
                </label>
                <div style={{ position: 'relative', flex: '1 1 200px', minWidth: 180, maxWidth: 320 }}>
                    <span style={{ position: 'absolute', left: 14, top: '50%', transform: 'translateY(-50%)', color: '#b6bcc6', fontSize: 13 }}>⌕</span>
                    <input value={searchInput} placeholder="Phone, applicant or name…"
                        onInput={(e) => setSearchInput((e.target as HTMLInputElement).value)}
                        style={{ padding: '10px 14px 10px 34px', border: '1px solid #e2e5ea', borderRadius: 10, background: '#fff', fontFamily: 'inherit', fontSize: 13, color: '#1f2430', width: '100%', boxSizing: 'border-box', outline: 'none' }} />
                </div>
            </div>

            {/* TABLE */}
            <div style={{ background: '#fff', border: '1px solid #ecedf1', borderRadius: 14, overflowX: 'auto', boxShadow: '0 1px 2px rgba(20,28,46,.04)' }}>
                <div style={{ display: 'grid', gridTemplateColumns: GRID, minWidth: 920, alignItems: 'center', padding: '0 20px', height: 46, background: '#fafbfc', borderBottom: '1px solid #eceef2' }}>
                    <div style={headCell}>TIME</div>
                    <div style={headCell}>LEARNER</div>
                    <div style={headCell}>PHONE</div>
                    <div style={headCell}>TYPE</div>
                    <div style={headCell}>STATUS</div>
                    <div style={headCell}>DETAIL</div>
                </div>
                {loading ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#9aa0ab', fontSize: 13.5 }}>Loading…</div>
                ) : error ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#b42318', fontSize: 13.5 }}>{error}</div>
                ) : rows.length === 0 ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#9aa0ab', fontSize: 13.5 }}>No notifications match these filters.</div>
                ) : rows.map((r: RiseSmsLogRow) => {
                    const sm = SMS_STATUS_META[r.status] || { label: r.status, bg: '#f1f3f6', fg: '#5a616e', dot: '#9aa0ab' };
                    return (
                        <div key={r.id} style={{ display: 'grid', gridTemplateColumns: GRID, minWidth: 920, alignItems: 'center', padding: '11px 20px', borderBottom: '1px solid #f3f4f7' }}>
                            <div style={{ fontSize: 12.5, color: '#5a616e', fontVariantNumeric: 'tabular-nums' }}>{formatUnix(r.timecreated)}</div>
                            <div style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {r.userid ? (
                                    <a href={`/user/profile.php?id=${r.userid}`} target="_blank" rel="noopener noreferrer"
                                        style={{ fontSize: 13, fontWeight: 600, color: '#005198', textDecoration: 'none' }}>{r.fullname || r.applicantid}</a>
                                ) : (
                                    <span style={{ fontSize: 13, fontWeight: 600, color: '#1f2430' }}>{r.fullname || r.applicantid || '-'}</span>
                                )}
                                {campaignId === '' && <div style={{ fontSize: 11, color: '#9aa0ab', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{campaignName(r.campaignid)}</div>}
                            </div>
                            <div style={{ fontSize: 12.5, color: '#3b424f', fontVariantNumeric: 'tabular-nums' }}>{r.phone || '-'}</div>
                            <div style={{ fontSize: 12.5, color: '#5a616e' }}>{SMS_PURPOSE_LABEL[r.purpose] || r.purpose}</div>
                            <div>
                                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 999, background: sm.bg, color: sm.fg, fontSize: 10.5, fontWeight: 700 }}>
                                    <span style={{ width: 6, height: 6, borderRadius: '50%', background: sm.dot }} />{sm.label}
                                </span>
                            </div>
                            <div title={r.error} style={{ fontSize: 12, color: r.status === 'sent' ? '#9aa0ab' : '#b42318', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.error || (r.status === 'sent' ? 'Delivered to gateway' : '-')}</div>
                        </div>
                    );
                })}
            </div>

            {/* PAGINATION */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, marginTop: 16 }}>
                <div style={{ fontSize: 13, color: '#8a909c' }}>Page <b style={{ color: '#5a616e' }}>{pag.page}</b> of {pag.totalPages} · {pag.total.toLocaleString()} messages</div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <button disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}
                        style={{ padding: '9px 16px', border: '1px solid #e2e5ea', borderRadius: 9, background: '#fff', fontFamily: 'inherit', fontSize: 13, color: page <= 1 ? '#b6bcc6' : '#3b424f', cursor: page <= 1 ? 'not-allowed' : 'pointer' }}>‹ Previous</button>
                    <button disabled={page >= pag.totalPages} onClick={() => setPage((p) => p + 1)}
                        style={{ padding: '9px 16px', border: '1px solid #005198', borderRadius: 9, background: page >= pag.totalPages ? '#a6c0d6' : '#005198', fontFamily: 'inherit', fontSize: 13, fontWeight: 600, color: '#fff', cursor: page >= pag.totalPages ? 'not-allowed' : 'pointer' }}>Next ›</button>
                </div>
            </div>
        </div>
    );
}

export default function Rise({ user }: { user?: UserData }) {
    const [campaign, setCampaign] = useState<RiseCampaign | null>(null);
    const [urlState, setUrlState] = useState<RiseUrlState>(readRiseUrlState);
    const [resolving, setResolving] = useState<boolean>(!!readRiseUrlState().campaignid);

    // Reflect browser back/forward into component state.
    useEffect(() => {
        const onPop = () => setUrlState(readRiseUrlState());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    // Resolve the campaign object for the id in the URL (deep link / reload / back-forward).
    // The remote API has no single-campaign route, so match against the campaigns list.
    useEffect(() => {
        let cancelled = false;
        const cid = urlState.campaignid;
        if (!cid) { setCampaign(null); setResolving(false); return; }
        if (campaign && campaign._id === cid) { setResolving(false); return; }
        setResolving(true);
        (async () => {
            try {
                const raw = await ajaxCall('local_elby_dashboard_rise_get_campaigns', {});
                const data = JSON.parse(raw);
                const found = (data.campaigns || []).find((c: RiseCampaign) => c._id === cid) || null;
                if (!cancelled) {
                    setCampaign(found);
                    if (!found) writeRiseUrl({ campaignid: '', applicantid: '', doc: '' }, true);
                }
            } catch (e) {
                console.error('RISE campaign resolve failed:', e);
                if (!cancelled) setCampaign(null);
            } finally {
                if (!cancelled) setResolving(false);
            }
        })();
        return () => { cancelled = true; };
    }, [urlState.campaignid]);

    const openCampaign = (c: RiseCampaign) => {
        setCampaign(c);
        const ns: RiseUrlState = { ...EMPTY_URL, campaignid: c._id, status: 'ENROLLED' };
        setUrlState(ns); writeRiseUrl(ns);
    };
    const backToCampaigns = () => {
        setCampaign(null);
        setUrlState(EMPTY_URL); writeRiseUrl(EMPTY_URL);
    };
    const openNotifications = () => {
        setCampaign(null);
        const ns: RiseUrlState = { ...EMPTY_URL, view: 'notifications' };
        setUrlState(ns); writeRiseUrl(ns);
    };
    const selectApplicant = (id: string | null) => {
        setUrlState((s) => { const ns = { ...s, applicantid: id || '', doc: '' }; writeRiseUrl({ applicantid: id || '', doc: '' }); return ns; });
    };
    const selectDoc = (label: string | null) => {
        setUrlState((s) => { const ns = { ...s, doc: label || '' }; writeRiseUrl({ doc: label || '' }); return ns; });
    };

    if (urlState.view === 'notifications' && user?.canManageRiseUsers) {
        return <NotificationsReport onBack={backToCampaigns} />;
    }
    if (campaign) {
        return <ApplicantList
            key={campaign._id}
            campaign={campaign}
            user={user}
            onBack={backToCampaigns}
            deepApplicantId={urlState.applicantid}
            deepDoc={urlState.doc}
            onSelectApplicant={selectApplicant}
            onSelectDoc={selectDoc}
        />;
    }
    if (resolving && urlState.campaignid) {
        return <div style={{ padding: 46, color: '#9aa0ab', fontSize: 14 }}>Loading campaign…</div>;
    }
    return <CampaignList onSelect={openCampaign} onViewNotifications={openNotifications} canViewNotifications={!!user?.canManageRiseUsers} />;
}
