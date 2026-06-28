import { h } from "preact";
import { useState, useEffect, useMemo, useRef } from "preact/hooks";
import type { Resource } from "../services/resources";
import { ResourceService } from "../services/resources";
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

interface ReadingMaterialsProps {
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

export default function ReadingMaterials({
    initialResources,
    initialLevels,
    initialSublevels,
    initialClasses,
    initialCategories
}: ReadingMaterialsProps) {
    const [resources, setResources] = useState<Resource[]>(initialResources);
    const [viewingResource, setViewingResource] = useState<Resource | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const searchInputRef = useRef<HTMLInputElement>(null);

    // Read search query from URL params
    const urlParams = new URLSearchParams(window.location.search);
    const [searchQuery, setSearchQuery] = useState(urlParams.get('q') || '');

    // Read category filter from URL params
    const selectedCategoryId = urlParams.get('category_id') ? parseInt(urlParams.get('category_id')!) : null;

    // Page context
    const activePage = 'reading-materials';

    // Debounced search effect
    useEffect(() => {
        const timer = setTimeout(() => {
            const performSearch = async () => {
                setIsSearching(true);
                try {
                    const filteredResources = await ResourceService.getAll({
                        searchQuery: searchQuery,
                        categoryId: selectedCategoryId || undefined,
                        pageContext: activePage,
                    });
                    setResources(filteredResources);

                    // Update URL without reload (for shareable URLs)
                    const url = new URL(window.location.href);
                    if (searchQuery) {
                        url.searchParams.set('q', searchQuery);
                    } else {
                        url.searchParams.delete('q');
                    }
                    window.history.replaceState({}, '', url);
                } catch (error) {
                    console.error('Search failed:', error);
                } finally {
                    setIsSearching(false);
                }
            };

            performSearch();
        }, 500); // 500ms debounce delay

        return () => clearTimeout(timer);
    }, [searchQuery, selectedCategoryId]);

    // Restore focus after search completes
    useEffect(() => {
        if (!isSearching && searchInputRef.current && document.activeElement !== searchInputRef.current) {
            // Only restore if we just finished searching
            searchInputRef.current.focus();
        }
    }, [isSearching]);

    // Menu items
    const libraryMenuItems = getLibraryMenuItems(activePage);

    // Check if user is admin (from PHP)
    const rootElement = document.getElementById('reading-materials-root');
    const isAdmin = rootElement?.dataset.isAdmin === '1';
    const adminMenuItems = isAdmin ? getAdminMenuItems('') : [];

    // Helper function to navigate with URL params
    const navigateWithFilters = (params: {
        category_id?: string | null;
        q?: string | null;
    }) => {
        const url = new URL(window.location.href);
        const searchParams = url.searchParams;

        // Update or remove each param
        Object.entries(params).forEach(([key, value]) => {
            if (value) {
                searchParams.set(key, value);
            } else {
                searchParams.delete(key);
            }
        });

        // Navigate to new URL (this will reload the page with new filters)
        window.location.href = url.pathname + url.search;
    };

    // Handle category change - navigate with new URL params
    const handleCategoryChange = (categoryId: number | null) => {
        navigateWithFilters({
            category_id: categoryId ? String(categoryId) : null,
            q: searchQuery || null
        });
    };

    // Handle search - just update the search query state (debounce will handle the rest)
    const handleSearchInput = (value: string) => {
        setSearchQuery(value);
    };

    // Clear all filters
    const clearAllFilters = () => {
        window.location.href = '/local/reblibrary/reading-materials.php';
    };

    // Group resources by category
    const resourcesByCategory = useMemo(() => {
        const grouped = new Map<number, { category: Category; resources: Resource[] }>();

        // Initialize with all categories
        initialCategories.forEach(category => {
            const categoryIdNum = parseInt(category.id as any);
            grouped.set(categoryIdNum, { category, resources: [] });
        });

        // Distribute resources to their categories
        resources.forEach(resource => {
            if (resource.category_ids && resource.category_ids.length > 0) {
                resource.category_ids.forEach(categoryId => {
                    const categoryIdNum = parseInt(categoryId as any);
                    if (grouped.has(categoryIdNum)) {
                        grouped.get(categoryIdNum)!.resources.push(resource);
                    }
                });
            }
        });

        // Filter out categories with no resources and sort by category name
        return Array.from(grouped.values())
            .filter(({ resources }) => resources.length > 0)
            .sort((a, b) => a.category.category_name.localeCompare(b.category.category_name));
    }, [resources, initialCategories]);

    // Get uncategorized resources (resources with no category assignments)
    const uncategorizedResources = useMemo(() => {
        return resources.filter(r => !r.category_ids || r.category_ids.length === 0);
    }, [resources]);

    return (
        <div className="flex h-screen bg-white overflow-hidden">
            <Sidebar
                adminMenuItems={adminMenuItems}
                libraryMenuItems={libraryMenuItems}
                levels={initialLevels}
                sublevels={initialSublevels}
                classes={initialClasses}
            />

            <main className="flex-1 flex flex-col bg-gray-50 overflow-hidden min-w-0">
                {viewingResource ? (
                    <PDFReader
                        resource={viewingResource}
                        onClose={() => setViewingResource(null)}
                    />
                ) : (
                <>
                    {/* Fixed Header: Search Bar and Category Filter */}
                    <div className="flex-shrink-0 p-4 pt-14 pb-3 lg:p-8 lg:pb-4 bg-gray-50">
                        {/* Search and Filter Component */}
                        <div className="max-w-4xl mx-auto">
                            {/* Search Bar */}
                            <div className="mb-3">
                                <div className="library-search relative">
                                    <i className={`fa ${isSearching ? 'fa-spinner fa-spin' : 'fa-search'} search-icon`}></i>
                                    <input
                                        ref={searchInputRef}
                                        type="text"
                                        placeholder="Search reading materials by title or author..."
                                        value={searchQuery}
                                        onInput={(e) => handleSearchInput((e.target as HTMLInputElement).value)}
                                        className="search-input"
                                    />
                                    {isSearching && (
                                        <span className="absolute right-4 top-1/2 text-sm text-gray-500" style={{ transform: 'translateY(-50%)' }}>
                                            Searching...
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Filter Bar - Only Category Filter */}
                            <div className="flex flex-wrap gap-3 justify-center">
                                {/* Category Filter */}
                                <div className="inline-block">
                                    <select
                                        value={selectedCategoryId || ''}
                                        onChange={(e) => handleCategoryChange(e.currentTarget.value ? parseInt(e.currentTarget.value) : null)}
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

                                {/* Clear Filters Button */}
                                {(selectedCategoryId || searchQuery) && (
                                    <button
                                        onClick={clearAllFilters}
                                        className="px-4 py-2.5 bg-gray-100 border-2 border-gray-300 rounded-full text-sm font-medium text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400 transition-all"
                                    >
                                        <i className="fa fa-times mr-1"></i>
                                        Clear All
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Scrollable Content: Books List Grouped by Category */}
                    <div className="flex-1 overflow-y-auto px-3 pb-6 lg:px-8 lg:pb-8">
                        {/* Main Content Area - Category-based Sections */}
                        {resourcesByCategory.length > 0 ? (
                            resourcesByCategory.map(({ category, resources: categoryResources }) => (
                                <section key={category.id} className="library-section">
                                    <div className="mb-3">
                                        <h2 className="section-title text-2xl font-bold text-gray-900 mb-1">
                                            {category.parent_name
                                                ? `${category.parent_name} > ${category.category_name}`
                                                : category.category_name
                                            }
                                        </h2>
                                        {category.description && (
                                            <p className="text-sm text-gray-600 mb-2">{category.description}</p>
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
                        ) : uncategorizedResources.length > 0 ? (
                            <section className="library-section">
                                <h2 className="section-title text-2xl font-bold text-gray-900 mb-3">Uncategorized</h2>
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
                        ) : (
                            <div className="empty-state">
                                <i className="fa fa-book text-6xl text-gray-300 mb-4"></i>
                                <p>No reading materials found matching your filters.</p>
                                <button
                                    onClick={clearAllFilters}
                                    className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                                >
                                    Clear Filters
                                </button>
                            </div>
                        )}
                    </div>
                </>
                )}
            </main>
        </div>
    );
}
