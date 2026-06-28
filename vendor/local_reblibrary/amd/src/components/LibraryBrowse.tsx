import { h } from "preact";
import { useState, useEffect, useMemo, useRef } from "preact/hooks";
import type { Resource } from "../services/resources";
import type { Category } from "../services/categories";
import Sidebar from "./shared/Sidebar";
import PDFReader from "./shared/PDFReader";
import { getAdminMenuItems } from "../config/admin-menu";
import { getLibraryMenuItems } from "../config/library-menu";

interface EducationLevel {
    id: number | string;
    level_name: string;
}

interface EducationSublevel {
    id: number | string;
    sublevel_name: string;
    level_id: number | string;
}

interface Class {
    id: number | string;
    class_name: string;
    class_code: string;
    sublevel_id: number | string;
    sublevel_name: string;
    level_id: number | string;
    level_name: string;
}

interface LibraryBrowseProps {
    initialResources: Resource[];
    initialLevels: EducationLevel[];
    initialSublevels: EducationSublevel[];
    initialClasses: Class[];
    initialCategories: Category[];
}

interface BookCardProps {
    resource: Resource;
    onViewBook?: (resource: Resource) => void;
}

const BookCard = ({ resource, onViewBook }: BookCardProps) => {
    const placeholderImage = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="140" height="200" viewBox="0 0 140 200"%3E%3Crect fill="%23e5e7eb" width="140" height="200"/%3E%3Ctext x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="14" fill="%239ca3af"%3ENo Cover%3C/text%3E%3C/svg%3E';

    return (
        <div
            className="book-card"
            onClick={() => resource.file_url && onViewBook?.(resource)}
            style={{ cursor: resource.file_url ? 'pointer' : 'default' }}
        >
            <div className="book-cover-container">
                <img
                    src={resource.cover_image_url || placeholderImage}
                    alt={resource.title}
                    className="book-cover"
                    onError={(e) => {
                        (e.target as HTMLImageElement).src = placeholderImage;
                    }}
                />
            </div>
            <h3 className="book-title truncate" title={resource.title}>{resource.title}</h3>
            <p className="book-author truncate" title={resource.author_name}>{resource.author_name}</p>
            {resource.isbn && <p className="book-isbn truncate" title={`ISBN: ${resource.isbn}`}>ISBN: {resource.isbn}</p>}
        </div>
    );
};

interface HorizontalScrollContainerProps {
    children: preact.ComponentChildren;
}

const HorizontalScrollContainer = ({ children }: HorizontalScrollContainerProps) => {
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const [showLeftButton, setShowLeftButton] = useState(false);
    const [showRightButton, setShowRightButton] = useState(false);

    const checkScrollButtons = () => {
        const container = scrollContainerRef.current;
        if (!container) return;

        const { scrollLeft, scrollWidth, clientWidth } = container;
        setShowLeftButton(scrollLeft > 0);
        setShowRightButton(scrollLeft < scrollWidth - clientWidth - 1);
    };

    useEffect(() => {
        checkScrollButtons();
        window.addEventListener('resize', checkScrollButtons);
        return () => window.removeEventListener('resize', checkScrollButtons);
    }, []);

    const scroll = (direction: 'left' | 'right') => {
        const container = scrollContainerRef.current;
        if (!container) return;

        const scrollAmount = container.clientWidth * 0.8;
        const targetScroll = direction === 'left'
            ? container.scrollLeft - scrollAmount
            : container.scrollLeft + scrollAmount;

        container.scrollTo({
            left: targetScroll,
            behavior: 'smooth'
        });
    };

    return (
        <div className="relative">
            {showLeftButton && (
                <button
                    onClick={() => scroll('left')}
                    className="absolute left-0 top-1/2 -translate-y-1/2 z-10 bg-white shadow-lg rounded-full p-3 hover:bg-gray-100 transition-colors"
                    style={{ marginLeft: '-20px' }}
                    aria-label="Scroll left"
                >
                    <i className="fa fa-chevron-left text-gray-700"></i>
                </button>
            )}

            <div
                ref={scrollContainerRef}
                className="overflow-x-auto pb-2"
                onScroll={checkScrollButtons}
                style={{ scrollbarWidth: 'thin' }}
            >
                {children}
            </div>

            {showRightButton && (
                <button
                    onClick={() => scroll('right')}
                    className="absolute right-0 top-1/2 -translate-y-1/2 z-10 bg-white shadow-lg rounded-full p-3 hover:bg-gray-100 transition-colors"
                    style={{ marginRight: '-20px' }}
                    aria-label="Scroll right"
                >
                    <i className="fa fa-chevron-right text-gray-700"></i>
                </button>
            )}
        </div>
    );
};

export default function LibraryBrowse({
    initialResources,
    initialLevels,
    initialSublevels,
    initialClasses,
    initialCategories
}: LibraryBrowseProps) {
    const [resources, setResources] = useState<Resource[]>(initialResources);
    const [searchQuery, setSearchQuery] = useState('');
    const [viewingResource, setViewingResource] = useState<Resource | null>(null);

    // Filter state
    const [selectedLevelId, setSelectedLevelId] = useState<number | null>(null);
    const [selectedSublevelId, setSelectedSublevelId] = useState<number | null>(null);
    const [selectedClassId, setSelectedClassId] = useState<number | null>(null);
    const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(null);

    // Menu items
    const libraryMenuItems = getLibraryMenuItems('browse');
    const adminMenuItems = getAdminMenuItems('');

    // Filter sublevels based on selected level
    const filteredSublevels = useMemo(() => {
        if (!selectedLevelId) return initialSublevels;
        return initialSublevels.filter(sl => parseInt(sl.level_id as any) === selectedLevelId);
    }, [selectedLevelId, initialSublevels]);

    // Filter classes based on selected sublevel
    const filteredClasses = useMemo(() => {
        if (!selectedSublevelId) {
            // If only level is selected, show all classes for that level
            if (selectedLevelId) {
                return initialClasses.filter(c => parseInt(c.level_id as any) === selectedLevelId);
            }
            return initialClasses;
        }
        return initialClasses.filter(c => parseInt(c.sublevel_id as any) === selectedSublevelId);
    }, [selectedLevelId, selectedSublevelId, initialClasses]);

    // Handle level change - reset sublevel and class
    const handleLevelChange = (levelId: number | null) => {
        setSelectedLevelId(levelId);
        setSelectedSublevelId(null);
        setSelectedClassId(null);
    };

    // Handle sublevel change - reset class
    const handleSublevelChange = (sublevelId: number | null) => {
        setSelectedSublevelId(sublevelId);
        setSelectedClassId(null);
    };

    // Filter resources based on all filters
    const filteredResources = useMemo(() => {
        let filtered = resources;

        // Search filter
        if (searchQuery) {
            filtered = filtered.filter(r =>
                r.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
                r.author_name?.toLowerCase().includes(searchQuery.toLowerCase())
            );
        }

        // Class filter
        if (selectedClassId) {
            filtered = filtered.filter(r =>
                r.class_ids && r.class_ids.map(id => parseInt(id as any)).includes(selectedClassId)
            );
        } else if (selectedSublevelId) {
            // If sublevel is selected but not class, show all resources for classes in that sublevel
            const sublevelClassIds = filteredClasses.map(c => parseInt(c.id as any));
            filtered = filtered.filter(r =>
                r.class_ids && r.class_ids.map(id => parseInt(id as any)).some(id => sublevelClassIds.includes(id))
            );
        } else if (selectedLevelId) {
            // If only level is selected, show all resources for classes in that level
            const levelClasses = initialClasses.filter(c => parseInt(c.level_id as any) === selectedLevelId);
            const levelClassIds = levelClasses.map(c => parseInt(c.id as any));
            filtered = filtered.filter(r =>
                r.class_ids && r.class_ids.map(id => parseInt(id as any)).some(id => levelClassIds.includes(id))
            );
        }

        // Category filter
        if (selectedCategoryId) {
            filtered = filtered.filter(r =>
                r.category_ids && r.category_ids.map(id => parseInt(id as any)).includes(selectedCategoryId)
            );
        }

        return filtered;
    }, [resources, searchQuery, selectedLevelId, selectedSublevelId, selectedClassId, selectedCategoryId, filteredClasses, initialClasses]);

    // Group resources by category
    const resourcesByCategory = useMemo(() => {
        const grouped = new Map<number, { category: Category; resources: Resource[] }>();

        // Get all categories
        initialCategories.forEach(cat => {
            const categoryIdNum = parseInt(cat.id as any);
            grouped.set(categoryIdNum, { category: cat, resources: [] });
        });

        // Distribute resources to their categories
        filteredResources.forEach(resource => {
            if (resource.category_ids && resource.category_ids.length > 0) {
                resource.category_ids.forEach(categoryId => {
                    const categoryIdNum = parseInt(categoryId as any);
                    if (grouped.has(categoryIdNum)) {
                        grouped.get(categoryIdNum)!.resources.push(resource);
                    }
                });
            }
        });

        // Filter out categories with no resources and sort alphabetically
        return Array.from(grouped.values())
            .filter(({ resources }) => resources.length > 0)
            .sort((a, b) => a.category.category_name.localeCompare(b.category.category_name));
    }, [filteredResources, initialCategories]);

    // Get uncategorized resources (resources with no category assignments)
    const uncategorizedResources = useMemo(() => {
        return filteredResources.filter(r => !r.category_ids || r.category_ids.length === 0);
    }, [filteredResources]);

    return (
        <div className="flex h-screen bg-white overflow-hidden">
            <Sidebar adminMenuItems={adminMenuItems} libraryMenuItems={libraryMenuItems} />

            <main className="flex-1 flex flex-col bg-gray-50 overflow-hidden min-w-0">
                {viewingResource ? (
                    <PDFReader
                        resource={viewingResource}
                        onClose={() => setViewingResource(null)}
                    />
                ) : (
                <>
                    {/* Fixed Header: Search Bar and Filters */}
                    <div className="flex-shrink-0 p-4 pt-14 pb-3 lg:p-8 lg:pb-4 bg-gray-50">
                        {/* Search and Filter Component */}
                        <div className="max-w-4xl mx-auto">
                            {/* Search Bar */}
                            <div className="mb-3">
                                <div className="library-search">
                                    <i className="fa fa-search search-icon"></i>
                                    <input
                                        type="text"
                                        placeholder="Search books by title or author..."
                                        value={searchQuery}
                                        onInput={(e) => setSearchQuery((e.target as HTMLInputElement).value)}
                                        className="search-input"
                                    />
                                </div>
                            </div>

                            {/* Filter Bar */}
                            <div className="flex flex-wrap gap-3 justify-center">
                            {/* Category Filter */}
                            <div className="inline-block">
                                <select
                                    value={selectedCategoryId || ''}
                                    onChange={(e) => setSelectedCategoryId(e.currentTarget.value ? parseInt(e.currentTarget.value) : null)}
                                    className="px-4 py-2.5 bg-white border-2 border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition-all"
                                >
                                    <option value="">All Categories</option>
                                    {initialCategories.map(category => (
                                        <option key={category.id} value={String(category.id)}>
                                            {category.parent_name ? `${category.parent_name} > ` : ''}{category.category_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Level Filter */}
                            <div className="inline-block">
                                <select
                                    value={selectedLevelId || ''}
                                    onChange={(e) => handleLevelChange(e.currentTarget.value ? parseInt(e.currentTarget.value) : null)}
                                    className="px-4 py-2.5 bg-white border-2 border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition-all"
                                >
                                    <option value="">All Levels</option>
                                    {initialLevels.map(level => (
                                        <option key={level.id} value={String(level.id)}>
                                            {level.level_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Sublevel Filter */}
                            <div className="inline-block">
                                <select
                                    value={selectedSublevelId || ''}
                                    onChange={(e) => handleSublevelChange(e.currentTarget.value ? parseInt(e.currentTarget.value) : null)}
                                    disabled={!selectedLevelId}
                                    className="px-4 py-2.5 bg-white border-2 border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                >
                                    <option value="">All Sublevels</option>
                                    {filteredSublevels.map(sublevel => (
                                        <option key={sublevel.id} value={String(sublevel.id)}>
                                            {sublevel.sublevel_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Class Filter */}
                            <div className="inline-block">
                                <select
                                    value={selectedClassId || ''}
                                    onChange={(e) => setSelectedClassId(e.currentTarget.value ? parseInt(e.currentTarget.value) : null)}
                                    className="px-4 py-2.5 bg-white border-2 border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer transition-all"
                                >
                                    <option value="">All Classes</option>
                                    {filteredClasses.map(cls => (
                                        <option key={cls.id} value={String(cls.id)}>
                                            {cls.class_name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Clear Filters Button */}
                            {(selectedLevelId || selectedSublevelId || selectedClassId || selectedCategoryId || searchQuery) && (
                                <button
                                    onClick={() => {
                                        setSelectedLevelId(null);
                                        setSelectedSublevelId(null);
                                        setSelectedClassId(null);
                                        setSelectedCategoryId(null);
                                        setSearchQuery('');
                                    }}
                                    style={{ borderRadius: '9999px' }}
                                    className="px-4 py-2.5 bg-red-50 border-2 border-red-400 text-sm font-medium text-red-700 hover:bg-red-100 hover:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 transition-all"
                                >
                                    <i className="fa fa-times mr-1"></i>
                                    Clear All
                                </button>
                            )}
                            </div>
                        </div>
                    </div>

                    {/* Scrollable Content: Books List */}
                    <div className="flex-1 overflow-y-auto px-3 pb-6 lg:px-8 lg:pb-8">
                        {/* Show uncategorized resources first */}
                        {uncategorizedResources.length > 0 && (
                            <section className="library-section">
                                <div className="mb-2">
                                    <h2 className="section-title text-2xl font-bold text-gray-900">
                                        Uncategorized Books
                                    </h2>
                                    <p className="text-sm text-gray-600 mt-0.5">
                                        Books without category assignments
                                    </p>
                                </div>
                                <HorizontalScrollContainer>
                                    <div className="flex gap-3" style={{ minWidth: 'min-content' }}>
                                        {uncategorizedResources.map(resource => (
                                            <div key={resource.id} className="flex-shrink-0 w-32 sm:w-40">
                                                <BookCard resource={resource} onViewBook={setViewingResource} />
                                            </div>
                                        ))}
                                    </div>
                                </HorizontalScrollContainer>
                            </section>
                        )}

                        {/* Main Content Area - Category-based Sections */}
                        {resourcesByCategory.length > 0 ? (
                            resourcesByCategory.map(({ category, resources: categoryResources }) => (
                                <section key={category.id} className="library-section">
                                    <div className="mb-2">
                                        <h2 className="section-title text-2xl font-bold text-gray-900">
                                            {category.category_name}
                                        </h2>
                                        {category.description && (
                                            <p className="text-sm text-gray-600 mt-0.5">
                                                {category.description}
                                            </p>
                                        )}
                                    </div>
                                    <HorizontalScrollContainer>
                                        <div className="flex gap-3" style={{ minWidth: 'min-content' }}>
                                            {categoryResources.map(resource => (
                                                <div key={resource.id} className="flex-shrink-0 w-32 sm:w-40">
                                                    <BookCard resource={resource} onViewBook={setViewingResource} />
                                                </div>
                                            ))}
                                        </div>
                                    </HorizontalScrollContainer>
                                </section>
                            ))
                        ) : uncategorizedResources.length === 0 ? (
                            <div className="empty-state">
                                <i className="fa fa-book text-6xl text-gray-300 mb-4"></i>
                                <p>No books found matching your filters.</p>
                                <button
                                    onClick={() => {
                                        setSelectedLevelId(null);
                                        setSelectedSublevelId(null);
                                        setSelectedClassId(null);
                                        setSelectedCategoryId(null);
                                        setSearchQuery('');
                                    }}
                                    style={{ borderRadius: '9999px' }}
                                    className="mt-4 px-4 py-2 bg-blue-600 text-white hover:bg-blue-700"
                                >
                                    Clear Filters
                                </button>
                            </div>
                        ) : null}
                    </div>
                </>
                )}
            </main>
        </div>
    );
}
