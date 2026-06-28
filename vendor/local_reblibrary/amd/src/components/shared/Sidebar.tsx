import { h } from "preact";
import { useState, useMemo } from "preact/hooks";
import type { MenuItem, EducationLevel, EducationSublevel, EducationClass } from "../../types";
import CollapsibleMenuItem from "./CollapsibleMenuItem";

interface SidebarProps {
    adminMenuItems: MenuItem[];
    libraryMenuItems: MenuItem[];
    levels?: EducationLevel[];
    sublevels?: EducationSublevel[];
    classes?: EducationClass[];
}

export default function Sidebar({
    adminMenuItems,
    libraryMenuItems,
    levels = [],
    sublevels = [],
    classes = []
}: SidebarProps) {
    // Get current URL params to determine active filters
    const urlParams = useMemo(() => {
        const params = new URLSearchParams(window.location.search);
        return {
            level_id: params.get('level_id'),
            sublevel_id: params.get('sublevel_id'),
            class_id: params.get('class_id')
        };
    }, [window.location.search]);

    // Mobile sidebar open/close state
    const [mobileOpen, setMobileOpen] = useState(false);

    // Close mobile sidebar whenever a link is followed (full page nav handles it but
    // any internal toggle should also close to clean up overlay state on bfcache).
    const closeMobile = () => setMobileOpen(false);

    // State for expand/collapse - use localStorage for persistence
    const getStoredExpandedState = (key: string, defaultValue: boolean = false): boolean => {
        const stored = localStorage.getItem(`sidebar_expanded_${key}`);
        return stored !== null ? stored === 'true' : defaultValue;
    };

    const setStoredExpandedState = (key: string, value: boolean) => {
        localStorage.setItem(`sidebar_expanded_${key}`, String(value));
    };

    // Sublevel expand states (levels are always expanded)
    const [expandedSublevels, setExpandedSublevels] = useState<Set<string>>(() => {
        const expanded = new Set<string>();
        if (urlParams.sublevel_id) {
            expanded.add(urlParams.sublevel_id);
        }
        sublevels.forEach(sublevel => {
            if (getStoredExpandedState(`sublevel_${sublevel.id}`)) {
                expanded.add(String(sublevel.id));
            }
        });
        return expanded;
    });

    // Toggle handlers
    const toggleSublevel = (sublevelId: string) => {
        const newExpanded = new Set(expandedSublevels);
        if (newExpanded.has(sublevelId)) {
            newExpanded.delete(sublevelId);
            setStoredExpandedState(`sublevel_${sublevelId}`, false);
        } else {
            newExpanded.add(sublevelId);
            setStoredExpandedState(`sublevel_${sublevelId}`, true);
        }
        setExpandedSublevels(newExpanded);
    };

    // Build URL with filter params
    const buildFilterUrl = (params: { level_id?: string; sublevel_id?: string; class_id?: string }) => {
        const url = new URL('/local/reblibrary/index.php', window.location.origin);
        if (params.level_id) url.searchParams.set('level_id', params.level_id);
        if (params.sublevel_id) url.searchParams.set('sublevel_id', params.sublevel_id);
        if (params.class_id) url.searchParams.set('class_id', params.class_id);
        return url.pathname + url.search;
    };

    // Group sublevels by level
    const sublevelsByLevel = useMemo(() => {
        const grouped = new Map<string, EducationSublevel[]>();
        sublevels.forEach(sublevel => {
            const levelId = String(sublevel.level_id);
            if (!grouped.has(levelId)) {
                grouped.set(levelId, []);
            }
            grouped.get(levelId)!.push(sublevel);
        });
        return grouped;
    }, [sublevels]);

    // Group classes by sublevel
    const classesBySublevel = useMemo(() => {
        const grouped = new Map<string, EducationClass[]>();
        classes.forEach(cls => {
            const sublevelId = String(cls.sublevel_id);
            if (!grouped.has(sublevelId)) {
                grouped.set(sublevelId, []);
            }
            grouped.get(sublevelId)!.push(cls);
        });
        return grouped;
    }, [classes]);

    return (
        <>
            {/* Mobile hamburger toggle (only visible below lg).
                Position is offset by --elby-navbar-height so the button sits
                just below the theme navbar instead of covering its logo.
                Falls back to 70px (mobile elby navbar height) when the var
                isn't defined (e.g. plugin used under another theme). */}
            <button
                type="button"
                onClick={() => setMobileOpen(true)}
                className="lg:hidden fixed left-3 bg-reb-blue text-white rounded-md shadow-lg p-2.5 hover:opacity-90 flex items-center justify-center"
                style={{
                    top: 'calc(var(--elby-navbar-height, 70px) + 8px)',
                    zIndex: 1040,
                    width: '40px',
                    height: '40px',
                }}
                aria-label="Open library menu"
            >
                <i className="fa fa-bars"></i>
            </button>

            {/* Mobile backdrop */}
            {mobileOpen && (
                <div
                    className="lg:hidden fixed inset-0 bg-black/50"
                    style={{ zIndex: 1050 }}
                    onClick={closeMobile}
                    aria-hidden="true"
                />
            )}

            <aside
                className={`fixed lg:static inset-y-0 left-0 lg:z-auto w-72 max-w-[85vw] bg-gray-50 border-r border-gray-200 py-6 pr-4 overflow-y-auto flex-shrink-0 transform transition-transform duration-200 ease-in-out ${mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}`}
                style={{ zIndex: 1060 }}
            >
                {/* Mobile close button */}
                <button
                    type="button"
                    onClick={closeMobile}
                    className="lg:hidden absolute top-3 right-3 text-gray-500 hover:text-gray-900 p-2"
                    aria-label="Close menu"
                >
                    <i className="fa fa-times"></i>
                </button>

            {/* Library Menu Section */}
            <div className="mb-8">
                <h6 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4 pl-3">
                    Library
                </h6>
                <ul className="space-y-1 m-0 p-0">
                    {libraryMenuItems.map((item, index) => (
                        <li key={index}>
                            {index === 0 ? (
                                // Resources menu with education structure tree (always expanded)
                                <div>
                                    {/* Resources link - clickable, goes to home page without filters */}
                                    <a
                                        href="/local/reblibrary/index.php"
                                        className={`flex items-center px-3 py-2 rounded text-sm font-medium transition-colors ${
                                            item.active && !urlParams.level_id && !urlParams.sublevel_id && !urlParams.class_id
                                                ? "bg-reb-blue text-white"
                                                : "text-gray-700 hover:bg-gray-200"
                                        }`}
                                    >
                                        <i className={`${item.icon} w-5 mr-3`}></i>
                                        <span>{item.name}</span>
                                    </a>

                                    {/* Education Structure Tree - always visible */}
                                    <div className="mt-1">
                                        {levels.map(level => {
                                            const levelId = String(level.id);
                                            const levelSublevels = sublevelsByLevel.get(levelId) || [];
                                            const isLevelActive = urlParams.level_id === levelId && !urlParams.sublevel_id && !urlParams.class_id;

                                            return (
                                                <div key={level.id} className="mb-2">
                                                    {/* Level - Always expanded, no toggle */}
                                                    <a
                                                        href={buildFilterUrl({ level_id: levelId })}
                                                        className={`flex items-center pl-4 py-2 rounded text-sm font-semibold transition-colors ${
                                                            isLevelActive
                                                                ? "bg-reb-blue text-white"
                                                                : "text-gray-800 hover:bg-gray-200"
                                                        }`}
                                                    >
                                                        <span>{level.level_name}</span>
                                                    </a>

                                                    {/* Sublevels - always visible under level */}
                                                    <div className="mt-1">
                                                        {levelSublevels.map(sublevel => {
                                                            const sublevelId = String(sublevel.id);
                                                            const sublevelClasses = classesBySublevel.get(sublevelId) || [];
                                                            const isSublevelExpanded = expandedSublevels.has(sublevelId);
                                                            const isSublevelActive = urlParams.sublevel_id === sublevelId && !urlParams.class_id;

                                                            return (
                                                                <div key={sublevel.id}>
                                                                    <CollapsibleMenuItem
                                                                        label={sublevel.sublevel_name}
                                                                        url={buildFilterUrl({ level_id: levelId, sublevel_id: sublevelId })}
                                                                        active={isSublevelActive}
                                                                        isExpanded={isSublevelExpanded}
                                                                        onToggle={() => toggleSublevel(sublevelId)}
                                                                        level={2}
                                                                    >
                                                                        {/* Classes */}
                                                                        {sublevelClasses.map(cls => {
                                                                            const classId = String(cls.id);
                                                                            const isClassActive = urlParams.class_id === classId;

                                                                            return (
                                                                                <CollapsibleMenuItem
                                                                                    key={cls.id}
                                                                                    label={cls.class_name}
                                                                                    url={buildFilterUrl({
                                                                                        level_id: levelId,
                                                                                        sublevel_id: sublevelId,
                                                                                        class_id: classId
                                                                                    })}
                                                                                    active={isClassActive}
                                                                                    level={3}
                                                                                />
                                                                            );
                                                                        })}
                                                                    </CollapsibleMenuItem>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            ) : (
                                // Other library menu items (Browse, Search, etc.)
                                <a
                                    href={item.url}
                                    className={`flex items-center px-3 py-2 rounded text-sm transition-colors ${
                                        item.active
                                            ? "bg-reb-blue text-white"
                                            : "text-gray-700 hover:bg-gray-200"
                                    }`}
                                >
                                    <i className={`${item.icon} w-5 mr-3`}></i>
                                    <span>{item.name}</span>
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            </div>

            {/* Admin Menu Section */}
            {adminMenuItems.length > 0 && (
                <div className="mb-8">
                    <h6 className="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4 pl-3">
                        Admin
                    </h6>
                    <ul className="space-y-1 m-0 p-0">
                        {adminMenuItems.map((item, index) => (
                            <li key={index}>
                                <a
                                    href={item.url}
                                    className={`flex items-center px-3 py-2 rounded text-sm transition-colors ${
                                        item.active
                                            ? "bg-reb-blue text-white"
                                            : "text-gray-700 hover:bg-gray-200"
                                    }`}
                                >
                                    <i className={`${item.icon} w-5 mr-3`}></i>
                                    <span>{item.name}</span>
                                </a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            </aside>
        </>
    );
}
