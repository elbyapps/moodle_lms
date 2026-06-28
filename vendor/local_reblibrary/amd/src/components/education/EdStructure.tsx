import { h } from "preact";
import { useState, useEffect } from "preact/hooks";
import { signal } from "@preact/signals";
import type { EduLevel, EduSublevel, EduClass, EduSection } from "../../services/edu-structure";
import Sidebar from "../shared/Sidebar";
import Toast from "../shared/Toast";
import LevelTab from "./LevelTab";
import SublevelTab from "./SublevelTab";
import ClassTab from "./ClassTab";
import SectionTab from "./SectionTab";

// Signals for state management
export const levelsSignal = signal<EduLevel[]>([]);
export const sublevelsSignal = signal<EduSublevel[]>([]);
export const classesSignal = signal<EduClass[]>([]);
export const sectionsSignal = signal<EduSection[]>([]);
export const loadingSignal = signal<boolean>(false);
export const errorSignal = signal<string | null>(null);
export const successSignal = signal<string | null>(null);

interface EdStructureProps {
    initialLevels: EduLevel[];
    initialSublevels: EduSublevel[];
    initialClasses: EduClass[];
    initialSections: EduSection[];
}

export default function EdStructure({
    initialLevels,
    initialSublevels,
    initialClasses,
    initialSections
}: EdStructureProps) {
    // Initialize signals with data from PHP
    useEffect(() => {
        levelsSignal.value = initialLevels;
        sublevelsSignal.value = initialSublevels;
        classesSignal.value = initialClasses;
        sectionsSignal.value = initialSections;
    }, [initialLevels, initialSublevels, initialClasses, initialSections]);

    // Menu items for sidebar - use config functions
    const libraryMenuItems = [
        { name: "Library Home", url: "/local/reblibrary/index.php", icon: "fa fa-home", active: false },
        { name: "Browse", url: "/local/reblibrary/browse.php", icon: "fa fa-compass", active: false },
        { name: "Search", url: "/local/reblibrary/search.php", icon: "fa fa-search", active: false },
        { name: "My Collection", url: "/local/reblibrary/collection.php", icon: "fa fa-bookmark", active: false },
    ];

    const adminMenuItems = [
        { name: "Dashboard", url: "/local/reblibrary/admin/index.php", icon: "fa fa-tachometer-alt", active: false },
        { name: "Education Structure", url: "/local/reblibrary/admin/ed_structure.php", icon: "fa fa-graduation-cap", active: true },
        { name: "Resources & Authors", url: "/local/reblibrary/admin/resources.php", icon: "fa fa-book", active: false },
        { name: "Categories", url: "/local/reblibrary/admin/categories.php", icon: "fa fa-tags", active: false },
        { name: "Assignments", url: "/local/reblibrary/admin/assignments.php", icon: "fa fa-link", active: false },
    ];

    // Tab state - use URL hash or default to 'levels'
    const [activeTab, setActiveTab] = useState(() => {
        const hash = window.location.hash.slice(1);
        return ['levels', 'sublevels', 'classes', 'sections'].includes(hash) ? hash : 'levels';
    });

    // Update URL hash when tab changes
    const handleTabChange = (tab: string) => {
        setActiveTab(tab);
        window.location.hash = tab;
    };

    // Listen for hash changes (browser back/forward)
    useEffect(() => {
        const handleHashChange = () => {
            const hash = window.location.hash.slice(1);
            if (['levels', 'sublevels', 'classes', 'sections'].includes(hash)) {
                setActiveTab(hash);
            }
        };
        window.addEventListener('hashchange', handleHashChange);
        return () => window.removeEventListener('hashchange', handleHashChange);
    }, []);

    return (
        <div className="flex min-h-screen bg-white">
            <Sidebar adminMenuItems={adminMenuItems} libraryMenuItems={libraryMenuItems} />
            <main className="flex-1 overflow-y-auto bg-gray-50 min-w-0">
                <div className="p-4 pt-14 lg:p-8">
                    <h1 className="text-2xl lg:text-3xl font-bold text-gray-900 mb-4 lg:mb-6">
                        Education Structure Management
                    </h1>

                    {/* Tab Navigation */}
                <div className="border-b border-gray-200 mb-6 overflow-x-auto">
                    <nav className="flex space-x-4 lg:space-x-8 min-w-max">
                        <button
                            onClick={() => handleTabChange('levels')}
                            className={`py-2 px-1 border-b-2 font-medium text-sm transition-colors whitespace-nowrap ${
                                activeTab === 'levels'
                                    ? 'border-reb-blue text-reb-blue'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            Education Levels
                        </button>
                        <button
                            onClick={() => handleTabChange('sublevels')}
                            className={`py-2 px-1 border-b-2 font-medium text-sm transition-colors whitespace-nowrap ${
                                activeTab === 'sublevels'
                                    ? 'border-reb-blue text-reb-blue'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            Sublevels
                        </button>
                        <button
                            onClick={() => handleTabChange('classes')}
                            className={`py-2 px-1 border-b-2 font-medium text-sm transition-colors whitespace-nowrap ${
                                activeTab === 'classes'
                                    ? 'border-reb-blue text-reb-blue'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            Classes
                        </button>
                        <button
                            onClick={() => handleTabChange('sections')}
                            className={`py-2 px-1 border-b-2 font-medium text-sm transition-colors whitespace-nowrap ${
                                activeTab === 'sections'
                                    ? 'border-reb-blue text-reb-blue'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            Sections (A-Level)
                        </button>
                    </nav>
                </div>

                    {/* Tab Content */}
                    <div className="bg-white rounded-lg shadow">
                        {activeTab === 'levels' && <LevelTab />}
                        {activeTab === 'sublevels' && <SublevelTab />}
                        {activeTab === 'classes' && <ClassTab />}
                        {activeTab === 'sections' && <SectionTab />}
                    </div>
                </div>
            </main>

            {/* Toast Notifications */}
            {successSignal.value && (
                <Toast
                    message={successSignal.value}
                    type="success"
                    onClose={() => successSignal.value = null}
                />
            )}
            {errorSignal.value && (
                <Toast
                    message={errorSignal.value}
                    type="error"
                    onClose={() => errorSignal.value = null}
                />
            )}
        </div>
    );
}
