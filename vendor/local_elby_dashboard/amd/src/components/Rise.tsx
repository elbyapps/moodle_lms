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

function formatDate(value?: string | null): string {
    if (!value) return '-';
    const d = new Date(value);
    return isNaN(d.getTime()) ? '-' : d.toLocaleDateString();
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
        <div style={{ padding: '11px 13px', borderRight: border ? '1px solid #f0f1f4' : undefined }}>
            <div style={{ fontSize: 18, fontWeight: 700, color: '#161b26', lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{value}</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 5, marginTop: 6 }}>
                <span style={{ width: 6, height: 6, borderRadius: '50%', background: dot }} />
                <span style={{ fontSize: 10, letterSpacing: '.4px', color: '#9aa0ab', fontWeight: 600 }}>{label}</span>
            </div>
        </div>
    );
}

function CampaignList({ onSelect }: { onSelect: (c: RiseCampaign) => void }) {
    const [campaigns, setCampaigns] = useState<RiseCampaign[]>([]);
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

    const totals = campaigns.reduce((acc, c) => {
        acc.total += c.stats?.total || 0;
        acc.shortlisted += c.stats?.shortlisted || 0;
        acc.enrolled += c.stats?.enrolled || 0;
        return acc;
    }, { total: 0, shortlisted: 0, enrolled: 0 });

    return (
        <div style={{ padding: '30px 34px 40px' }}>
            <div style={{ marginBottom: 6 }}>
                <h1 style={{ margin: '0 0 5px', fontSize: 28, fontWeight: 700, letterSpacing: '-.5px', color: '#161b26' }}>RISE</h1>
                <p style={{ margin: 0, fontSize: 14, color: '#6b7280' }}>Recruitment &amp; enrolment campaigns across the RISE programme.</p>
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

                    {/* CARDS GRID */}
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 20 }}>
                        {campaigns.map((c, i) => {
                            const st = CARD_STYLES[i % CARD_STYLES.length];
                            const isActive = (c.status || '').toUpperCase() === 'ACTIVE';
                            const total = c.stats?.total || 0;
                            const enrolled = c.stats?.enrolled || 0;
                            const pct = total ? Math.round((enrolled / total) * 100) : 0;
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

function NesaReviewSection({ applicant, campaignId, review, nidValidated, onSaved }: {
    applicant: RiseApplicant;
    campaignId: string;
    review?: RiseNesaReview;
    nidValidated?: boolean;
    onSaved: (applicantId: string, review: RiseNesaReview) => void;
}) {
    const [status, setStatus] = useState<NesaStatus>(review?.nesastatus || 'pending');
    const [nidVerified, setNidVerified] = useState<boolean>(!!review?.nidverified);
    const [comment, setComment] = useState<string>(review?.comment || '');
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState(0);
    const [error, setError] = useState('');

    useEffect(() => {
        setStatus(review?.nesastatus || 'pending');
        setNidVerified(!!review?.nidverified);
        setComment(review?.comment || '');
        setSavedAt(0);
        setError('');
    }, [applicant._id]);

    useEffect(() => {
        if (nidValidated) setNidVerified(true);
    }, [nidValidated]);

    const decisions: { key: NesaStatus; label: string; solid: string; accent: string; tint: string }[] = [
        { key: 'approved', label: 'Approved', solid: '#1a7f43', accent: '#1a7f43', tint: '#bfe2cd' },
        { key: 'rejected', label: 'Rejected', solid: '#b42318', accent: '#b42318', tint: '#eec3bd' },
        { key: 'action_requested', label: 'Action requested', solid: '#b6720a', accent: '#b6720a', tint: '#e8d3a3' },
    ];
    const hasDecision = status !== 'pending';

    async function save() {
        try {
            setSaving(true);
            setError('');
            const raw = await ajaxCall('local_elby_dashboard_rise_save_review', {
                campaignid: campaignId,
                applicantid: applicant._id,
                nesastatus: status,
                nidverified: nidVerified ? 1 : 0,
                comment,
                applicantdata: JSON.stringify(applicant),
            });
            const saved = JSON.parse(raw) as RiseNesaReview;
            onSaved(applicant._id, saved);
            setSavedAt(Date.now());
        } catch (e) {
            console.error('RISE save review failed:', e);
            setError('Could not save the review. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <div style={{ flex: '0 0 auto', borderTop: '1px solid #eceef2', background: '#fbfbfc', padding: '16px 26px 18px' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 12 }}>
                <div style={{ fontSize: 13, fontWeight: 700, letterSpacing: '.4px', color: '#161b26' }}>NESA ELIGIBILITY REVIEW</div>
                <label style={{ display: 'flex', alignItems: 'center', gap: 7, cursor: 'pointer', fontSize: 12.5, color: '#3b424f', userSelect: 'none' }}
                       onClick={() => setNidVerified((v) => !v)}>
                    <span style={{
                        width: 17, height: 17, borderRadius: 5, display: 'flex', alignItems: 'center', justifyContent: 'center',
                        color: '#fff', fontSize: 11, lineHeight: 1,
                        border: `1.5px solid ${nidVerified ? BRAND : '#c4c8d0'}`, background: nidVerified ? BRAND : '#fff',
                    }}>{nidVerified ? '✓' : ''}</span>
                    National ID verified
                </label>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, marginBottom: 11 }}>
                {decisions.map((d) => {
                    const selected = status === d.key;
                    return (
                        <button
                            key={d.key}
                            onClick={() => setStatus((s) => (s === d.key ? 'pending' : d.key))}
                            style={{
                                padding: '10px 8px', borderRadius: 9, fontSize: 12.5, fontWeight: 600, cursor: 'pointer',
                                border: `1.5px solid ${selected ? d.solid : d.tint}`,
                                background: selected ? d.solid : '#fff',
                                color: selected ? '#fff' : d.accent,
                            }}
                        >{d.label}</button>
                    );
                })}
            </div>

            <textarea
                value={comment}
                onInput={(e) => setComment((e.target as HTMLTextAreaElement).value)}
                placeholder="Add a comment (optional)…"
                style={{ width: '100%', minHeight: 46, resize: 'none', border: '1px solid #e1e3e8', borderRadius: 9, padding: '10px 12px', fontSize: 13, fontFamily: 'inherit', color: '#1f2430', marginBottom: 12, outline: 'none' }}
            />

            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <button
                    onClick={save}
                    disabled={!hasDecision || saving}
                    style={{
                        flex: '1 1 auto', padding: 12, border: 'none', borderRadius: 10, color: '#fff',
                        fontSize: 13.5, fontWeight: 600, cursor: hasDecision && !saving ? 'pointer' : 'not-allowed',
                        background: hasDecision ? BRAND : '#a6c0d6',
                    }}
                >{saving ? 'Saving…' : 'Save review'}</button>
                {savedAt > 0 && !error && <span style={{ fontSize: 13, color: '#1a7f43', flex: '0 0 auto' }}>Saved.</span>}
                {error && <span style={{ fontSize: 13, color: '#b42318', flex: '0 0 auto' }}>{error}</span>}
            </div>
        </div>
    );
}

// ---- Applicant detail drawer ---------------------------------------------

function ApplicantDetail({ applicant, campaignId, review, onClose, onPreview, onReviewSaved }: {
    applicant: RiseApplicant;
    campaignId: string;
    review?: RiseNesaReview;
    onClose: () => void;
    onPreview: (a: RiseAttachment) => void;
    onReviewSaved: (applicantId: string, review: RiseNesaReview) => void;
}) {
    const attachments = extractAttachments(applicant);
    const responses = applicant.formResponses || {};
    const responseKeys = Object.keys(responses);
    const reviews = applicant.reviews || [];
    const nesaStatus: NesaStatus = review?.nesastatus || 'pending';
    const pill = NESA_PILL[nesaStatus];

    const [nidVal, setNidVal] = useState<NidState>({ status: 'idle' });

    // Validate the National ID against TMIS/NIDA when the drawer opens.
    useEffect(() => {
        if (!applicant.nid) {
            setNidVal({ status: 'idle' });
            return;
        }
        let cancelled = false;
        setNidVal({ status: 'loading' });
        ajaxCall('local_elby_dashboard_rise_validate_nid', {
            campaignid: campaignId,
            applicantid: applicant._id,
            nid: applicant.nid,
            fullname: applicant.fullName || '',
            dateofbirth: applicant.dateOfBirth || '',
            applicantdata: JSON.stringify(applicant),
        }).then((raw: string) => {
            if (cancelled) return;
            const result = JSON.parse(raw) as RiseNidValidation;
            setNidVal({ status: 'done', result });
            if (result.match) {
                onReviewSaved(applicant._id, {
                    nesastatus: review?.nesastatus || 'pending',
                    nidverified: 1,
                    comment: review?.comment || '',
                });
            }
        }).catch((e: any) => {
            if (cancelled) return;
            setNidVal({ status: 'error', message: e?.message || 'NID validation failed.' });
        });
        return () => { cancelled = true; };
    }, [applicant._id]);

    const detailRows: [string, string, string, string][] = [
        ['Gender', applicant.gender || '-', 'Phone', applicant.phone || '-'],
        ['National ID', applicant.nid || '-', 'Date of Birth', formatDate(applicant.dateOfBirth)],
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
                style={{ appearance: 'none', padding: '10px 36px 10px 14px', border: '1px solid #e2e5ea', borderRadius: 10, background: '#fff', fontFamily: 'inherit', fontSize: 13, fontWeight: 500, color: '#1f2430', cursor: 'pointer', minWidth }}
            >
                {children}
            </select>
            <span style={{ position: 'absolute', right: 13, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none', color: '#9aa0ab', fontSize: 10 }}>▼</span>
        </div>
    );
}

// ---- Applicants view ------------------------------------------------------

function ApplicantList({ campaign, onBack }: { campaign: RiseCampaign; onBack: () => void }) {
    const [applicants, setApplicants] = useState<RiseApplicant[]>([]);
    const [pagination, setPagination] = useState<RisePagination>({ page: 1, limit: 12, total: 0, totalPages: 0 });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const [status, setStatus] = useState('SHORTLISTED');
    const [provinceCode, setProvinceCode] = useState('');
    const [district, setDistrict] = useState('');
    const [gender, setGender] = useState('');
    const [page, setPage] = useState(1);

    const [selected, setSelected] = useState<RiseApplicant | null>(null);
    const [preview, setPreview] = useState<RiseAttachment | null>(null);
    const [reviews, setReviews] = useState<Record<string, RiseNesaReview>>({});
    const [sortKey, setSortKey] = useState<'name' | 'score' | null>(null);
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');

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
    }

    useEffect(() => {
        (async () => {
            try {
                setLoading(true);
                setError('');
                const raw = await ajaxCall('local_elby_dashboard_rise_get_applicants', {
                    campaignid: campaign._id,
                    status,
                    provincecode: provinceCode,
                    district,
                    gender,
                    page,
                    limit: 12,
                });
                const data = JSON.parse(raw);
                setApplicants(data.applicants || []);
                setPagination(data.pagination || { page, limit: 12, total: 0, totalPages: 0 });
            } catch (e) {
                console.error('RISE applicants load failed:', e);
                setError('Failed to load applicants.');
            } finally {
                setLoading(false);
            }
        })();
    }, [campaign._id, status, provinceCode, district, gender, page]);

    // Reset to page 1 whenever a filter changes.
    function onFilter(setter: (v: string) => void, value: string) {
        setter(value);
        setPage(1);
    }

    const avatarColors = (g?: string) => g === 'Female'
        ? { bg: '#f3eafa', fg: '#7b3fb0' }
        : { bg: '#e8f0f8', fg: '#005198' };

    const sorted = [...applicants].sort((a, b) => {
        if (!sortKey) return 0;
        const dir = sortDir === 'asc' ? 1 : -1;
        if (sortKey === 'score') return ((a.totalScore ?? 0) - (b.totalScore ?? 0)) * dir;
        return (a.fullName || '').localeCompare(b.fullName || '') * dir;
    });

    const toggleSort = (k: 'name' | 'score') => {
        if (sortKey === k) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        else { setSortKey(k); setSortDir('asc'); }
    };
    const arrow = (k: 'name' | 'score') => (sortKey === k ? (sortDir === 'asc' ? '▲' : '▼') : '↕');
    const arrowColor = (k: 'name' | 'score') => (sortKey === k ? '#005198' : '#c4c8d0');

    const GRID = '2.4fr 0.9fr 1.1fr 1.3fr 0.8fr 1.1fr 1fr';
    const headCell = { fontSize: 10.5, fontWeight: 700, letterSpacing: '.7px', color: '#8a909c' } as const;

    return (
        <div className="p-4 sm:p-6">
            <button onClick={onBack} className="flex items-center gap-1 text-sm text-blue-600 hover:underline mb-3">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Back to campaigns
            </button>

            <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: 20, marginBottom: 18, flexWrap: 'wrap' }}>
                <h1 style={{ margin: 0, fontSize: 26, fontWeight: 700, letterSpacing: '-.4px', color: '#161b26' }}>{campaign.name}</h1>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px', borderRadius: 9, background: '#fff', border: '1px solid #e7e9ee', fontSize: 13, color: '#6b7280', fontWeight: 500 }}>
                    <b style={{ color: '#161b26', fontWeight: 700 }}>{pagination.total.toLocaleString()}</b>&nbsp;applicants
                </span>
            </div>

            {/* FILTERS */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 11, marginBottom: 16, flexWrap: 'wrap' }}>
                <Select value={status} onChange={(v) => onFilter(setStatus, v)} minWidth={148}>
                    {STATUSES.map((s) => <option value={s}>{s || 'All statuses'}</option>)}
                </Select>
                <Select value={provinceCode} onChange={(v) => onFilter(setProvinceCode, v)} minWidth={140}>
                    <option value="">All provinces</option>
                    {Object.keys(PROVINCES).map((code) => <option value={code}>{PROVINCES[code]}</option>)}
                </Select>
                <div style={{ position: 'relative' }}>
                    <span style={{ position: 'absolute', left: 13, top: '50%', transform: 'translateY(-50%)', color: '#b6bcc6', fontSize: 13 }}>⌕</span>
                    <input value={district} placeholder="District (e.g. Gasabo)"
                           onInput={(e) => onFilter(setDistrict, (e.target as HTMLInputElement).value)}
                           style={{ padding: '10px 14px 10px 32px', border: '1px solid #e2e5ea', borderRadius: 10, background: '#fff', fontFamily: 'inherit', fontSize: 13, color: '#1f2430', width: 220, outline: 'none' }} />
                </div>
                <Select value={gender} onChange={(v) => onFilter(setGender, v)} minWidth={140}>
                    <option value="">All genders</option>
                    <option value="Female">Female</option>
                    <option value="Male">Male</option>
                </Select>
                <div style={{ flex: '1 1 auto' }} />
                <div style={{ fontSize: 12.5, color: '#9aa0ab' }}>Showing <b style={{ color: '#5a616e' }}>{applicants.length}</b> of {pagination.total.toLocaleString()}</div>
            </div>

            {/* TABLE */}
            <div style={{ background: '#fff', border: '1px solid #ecedf1', borderRadius: 14, overflow: 'hidden', boxShadow: '0 1px 2px rgba(20,28,46,.04), 0 6px 24px rgba(20,28,46,.05)' }}>
                <div style={{ display: 'grid', gridTemplateColumns: GRID, alignItems: 'center', padding: '0 22px', height: 46, background: '#fafbfc', borderBottom: '1px solid #eceef2' }}>
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
                    <div style={headCell}>NESA</div>
                </div>

                {loading ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#9aa0ab', fontSize: 13.5 }}>Loading applicants…</div>
                ) : error ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#b42318', fontSize: 13.5 }}>{error}</div>
                ) : sorted.length === 0 ? (
                    <div style={{ padding: 46, textAlign: 'center', color: '#9aa0ab', fontSize: 13.5 }}>No applicants match these filters.</div>
                ) : sorted.map((a) => {
                    const av = avatarColors(a.gender);
                    const isSel = selected?._id === a._id;
                    const score = a.totalScore;
                    const rev = reviews[a._id];
                    const np = rev ? NESA_PILL[rev.nesastatus] : null;
                    const npLabel = rev ? NESA_META[rev.nesastatus].label : '—';
                    return (
                        <div key={a._id} onClick={() => setSelected(a)}
                            style={{ display: 'grid', gridTemplateColumns: GRID, alignItems: 'center', padding: '0 22px', minHeight: 56, borderBottom: '1px solid #f3f4f7', cursor: 'pointer', background: isSel ? '#f1f6fb' : '#fff', boxShadow: isSel ? 'inset 3px 0 0 #005198' : 'none' }}
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
                <div style={{ fontSize: 13, color: '#8a909c' }}>Page <b style={{ color: '#5a616e' }}>{pagination.page}</b> of {pagination.totalPages || 1} · {pagination.total.toLocaleString()} applicants</div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <button disabled={page <= 1} onClick={() => setPage(page - 1)}
                        style={{ padding: '9px 16px', border: '1px solid #e2e5ea', borderRadius: 9, background: '#fff', fontFamily: 'inherit', fontSize: 13, fontWeight: 500, color: page <= 1 ? '#b6bcc6' : '#3b424f', cursor: page <= 1 ? 'not-allowed' : 'pointer' }}>‹ Previous</button>
                    <button disabled={page >= (pagination.totalPages || 1)} onClick={() => setPage(page + 1)}
                        style={{ padding: '9px 16px', border: '1px solid #005198', borderRadius: 9, background: page >= (pagination.totalPages || 1) ? '#a6c0d6' : '#005198', fontFamily: 'inherit', fontSize: 13, fontWeight: 600, color: '#fff', cursor: page >= (pagination.totalPages || 1) ? 'not-allowed' : 'pointer' }}>Next ›</button>
                </div>
            </div>

            {selected && (
                <ApplicantDetail
                    applicant={selected}
                    campaignId={campaign._id}
                    review={reviews[selected._id]}
                    onClose={() => setSelected(null)}
                    onPreview={setPreview}
                    onReviewSaved={onReviewSaved}
                />
            )}
            {preview && (
                <PreviewModal attachment={preview} onClose={() => setPreview(null)} />
            )}
        </div>
    );
}

// ---- Root -----------------------------------------------------------------

export default function Rise() {
    const [campaign, setCampaign] = useState<RiseCampaign | null>(null);

    return campaign
        ? <ApplicantList campaign={campaign} onBack={() => setCampaign(null)} />
        : <CampaignList onSelect={setCampaign} />;
}
