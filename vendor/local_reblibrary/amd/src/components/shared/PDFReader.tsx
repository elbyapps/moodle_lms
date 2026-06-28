/**
 * PDF Reader component.
 * Full-featured PDF viewer with zoom, scroll, download, and navigation controls.
 *
 * @module local_reblibrary/components/shared/PDFReader
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h } from 'preact';
import { useState, useEffect, useRef } from 'preact/hooks';
import * as pdfjsLib from 'pdfjs-dist';
import type { Resource } from '../../services/resources';

// Configure PDF.js worker using CDN for reliability
pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${pdfjsLib.version}/build/pdf.worker.min.mjs`;

interface PDFReaderProps {
    resource: Resource;
    onClose: () => void;
}

type ZoomMode = 'custom' | 'fit-width' | 'fit-page';

const ZOOM_LEVELS = [50, 75, 100, 125, 150, 200];
const PAGES_PER_BATCH = 5;

export default function PDFReader({ resource, onClose }: PDFReaderProps) {
    const [pdfDoc, setPdfDoc] = useState<any>(null);
    const [numPages, setNumPages] = useState(0);
    const [currentPage, setCurrentPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [zoom, setZoom] = useState(100);
    const [zoomMode, setZoomMode] = useState<ZoomMode>('custom');
    const [pagesToLoad, setPagesToLoad] = useState(PAGES_PER_BATCH);
    const [renderedPages, setRenderedPages] = useState<Set<number>>(new Set());

    const contentRef = useRef<HTMLDivElement>(null);
    const pageRefs = useRef<Map<number, HTMLDivElement>>(new Map());
    const renderingTasks = useRef<Map<number, any>>(new Map());

    // Load PDF document
    useEffect(() => {
        if (!resource.file_url) {
            setError('No PDF file available for this resource');
            setLoading(false);
            return;
        }

        loadPdf();
    }, [resource.file_url]);

    async function loadPdf() {
        setLoading(true);
        setError(null);

        try {
            const loadingTask = pdfjsLib.getDocument(resource.file_url!);
            const pdf = await loadingTask.promise;

            setPdfDoc(pdf);
            setNumPages(pdf.numPages);
            setLoading(false);
        } catch (err) {
            console.error('Failed to load PDF:', err);
            setError(err instanceof Error ? err.message : 'Failed to load PDF');
            setLoading(false);
        }
    }

    // Render a specific page
    async function renderPage(pageNum: number, zoomLevel: number) {
        if (!pdfDoc || renderedPages.has(pageNum)) return;

        try {
            // Cancel any existing render task for this page
            const existingTask = renderingTasks.current.get(pageNum);
            if (existingTask) {
                existingTask.cancel();
            }

            const page = await pdfDoc.getPage(pageNum);
            const viewport = page.getViewport({ scale: zoomLevel / 100 });

            const pageElement = pageRefs.current.get(pageNum);
            if (!pageElement) {
                return;
            }

            const canvas = pageElement.querySelector('canvas') as HTMLCanvasElement;
            if (!canvas) {
                return;
            }

            const context = canvas.getContext('2d', { alpha: false });
            if (!context) return;

            // Set canvas size to match viewport
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            // Fill white background
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);

            // Render PDF page
            const renderTask = page.render({
                canvasContext: context,
                viewport: viewport,
            });

            renderingTasks.current.set(pageNum, renderTask);

            await renderTask.promise;
            renderingTasks.current.delete(pageNum);

            // Mark as rendered
            setRenderedPages(prev => new Set(prev).add(pageNum));
        } catch (err: any) {
            if (err?.name !== 'RenderingCancelledException') {
                console.error(`Failed to render page ${pageNum}:`, err);
            }
        }
    }

    // Render pages when PDF loads or when new pages should be loaded
    useEffect(() => {
        if (!pdfDoc || numPages === 0) return;

        // Use requestAnimationFrame to ensure DOM is ready
        requestAnimationFrame(() => {
            const pagesToRender = Math.min(pagesToLoad, numPages);

            for (let i = 1; i <= pagesToRender; i++) {
                renderPage(i, zoom);
            }
        });
    }, [pdfDoc, numPages, pagesToLoad]);

    // Re-render all pages when zoom changes
    useEffect(() => {
        if (!pdfDoc || numPages === 0) return;

        // Clear rendered pages set
        setRenderedPages(new Set());

        // Cancel all rendering tasks
        renderingTasks.current.forEach(task => {
            try {
                task.cancel();
            } catch (e) {
                // Ignore errors
            }
        });
        renderingTasks.current.clear();

        // Re-render visible pages
        requestAnimationFrame(() => {
            const pagesToRender = Math.min(pagesToLoad, numPages);

            for (let i = 1; i <= pagesToRender; i++) {
                renderPage(i, zoom);
            }
        });
    }, [zoom]);

    // Progressive loading and current page tracking on scroll
    useEffect(() => {
        const container = contentRef.current;
        if (!container) return;

        const handleScroll = () => {
            const scrollTop = container.scrollTop;
            const scrollHeight = container.scrollHeight;
            const clientHeight = container.clientHeight;

            // Check if scrolled near bottom (within 1000px)
            if (scrollHeight - scrollTop - clientHeight < 1000) {
                // Load more pages if available
                if (pagesToLoad < numPages) {
                    const newPagesToLoad = Math.min(pagesToLoad + PAGES_PER_BATCH, numPages);
                    setPagesToLoad(newPagesToLoad);
                }
            }

            // Track current page based on scroll position
            const scrollCenter = scrollTop + clientHeight / 2;

            for (let i = 1; i <= numPages; i++) {
                const pageElement = pageRefs.current.get(i);
                if (!pageElement) continue;

                const rect = pageElement.getBoundingClientRect();
                const containerRect = container.getBoundingClientRect();
                const pageTop = rect.top - containerRect.top + scrollTop;
                const pageBottom = pageTop + rect.height;

                if (scrollCenter >= pageTop && scrollCenter <= pageBottom) {
                    setCurrentPage(i);
                    break;
                }
            }
        };

        container.addEventListener('scroll', handleScroll);
        return () => container.removeEventListener('scroll', handleScroll);
    }, [numPages, pagesToLoad]);

    // Keyboard shortcuts
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                goToPreviousPage();
            } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                goToNextPage();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [currentPage, numPages]);

    function goToPreviousPage() {
        if (currentPage > 1) {
            scrollToPage(currentPage - 1);
        }
    }

    function goToNextPage() {
        if (currentPage < numPages) {
            scrollToPage(currentPage + 1);
        }
    }

    function scrollToPage(pageNum: number) {
        const pageElement = pageRefs.current.get(pageNum);
        if (pageElement && contentRef.current) {
            pageElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function handleZoomIn() {
        setZoomMode('custom');

        // Find next zoom level higher than current zoom
        const nextLevel = ZOOM_LEVELS.find(level => level > zoom);

        if (nextLevel) {
            setZoom(nextLevel);
        } else {
            // Already at or above max level
            setZoom(ZOOM_LEVELS[ZOOM_LEVELS.length - 1]);
        }
    }

    function handleZoomOut() {
        setZoomMode('custom');

        // Find next zoom level lower than current zoom
        const prevLevel = [...ZOOM_LEVELS].reverse().find(level => level < zoom);

        if (prevLevel) {
            setZoom(prevLevel);
        } else {
            // Already at or below min level
            setZoom(ZOOM_LEVELS[0]);
        }
    }

    function handleFitWidth() {
        setZoomMode('fit-width');
        // Calculate zoom to fit width
        const container = contentRef.current;
        if (container && pdfDoc) {
            const containerWidth = container.clientWidth - 48; // Account for padding
            pdfDoc.getPage(1).then((page: any) => {
                const viewport = page.getViewport({ scale: 1 });
                const scale = (containerWidth / viewport.width) * 100;
                setZoom(Math.round(scale));
            });
        }
    }

    function handleFitPage() {
        setZoomMode('fit-page');
        // Calculate zoom to fit entire page
        const container = contentRef.current;
        if (container && pdfDoc) {
            const containerWidth = container.clientWidth - 48;
            const containerHeight = container.clientHeight - 48;
            pdfDoc.getPage(1).then((page: any) => {
                const viewport = page.getViewport({ scale: 1 });
                const scaleWidth = containerWidth / viewport.width;
                const scaleHeight = containerHeight / viewport.height;
                const scale = Math.min(scaleWidth, scaleHeight) * 100;
                setZoom(Math.round(scale));
            });
        }
    }

    async function handleDownload() {
        if (!resource.file_url) return;

        try {
            // Fetch the PDF file
            const response = await fetch(resource.file_url);
            if (!response.ok) throw new Error('Failed to download PDF');

            // Get the blob
            const blob = await response.blob();

            // Create a blob URL
            const blobUrl = URL.createObjectURL(blob);

            // Create a temporary link and trigger download
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = `${resource.title}.pdf`;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Clean up the blob URL
            setTimeout(() => URL.revokeObjectURL(blobUrl), 100);
        } catch (error) {
            console.error('Failed to download PDF:', error);
            alert('Failed to download PDF. Please try again.');
        }
    }

    if (loading) {
        return (
            <div className="pdf-reader-container">
                <div className="flex items-center justify-center h-full">
                    <div className="text-center">
                        <i className="fa fa-spinner fa-spin text-5xl text-gray-400 mb-4"></i>
                        <p className="text-lg text-gray-600">Loading PDF...</p>
                    </div>
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <div className="pdf-reader-container">
                <div className="flex items-center justify-center h-full">
                    <div className="text-center max-w-md">
                        <i className="fa fa-exclamation-triangle text-5xl text-red-500 mb-4"></i>
                        <h3 className="text-xl font-semibold text-gray-900 mb-2">Failed to Load PDF</h3>
                        <p className="text-gray-600 mb-4">{error}</p>
                        <button
                            onClick={onClose}
                            className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                        >
                            Return to Library
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="pdf-reader-container">
            {/* Toolbar.
                Layout strategy:
                  - md+ (≥768px): three-column flex with title on the left,
                    page nav in the center, full zoom+download controls right.
                  - <md: compact single row — close button, compact page
                    indicator ("1 / 7"), zoom out / zoom in, download. Title,
                    fit-width/fit-page, zoom percentage, and section dividers
                    are hidden so the controls fit a phone width without
                    horizontal scrolling. */}
            <div className="pdf-toolbar">
                <div className="flex items-center justify-between w-full gap-2">
                    {/* Left: Close and Title */}
                    <div className="flex items-center gap-2 md:gap-4 min-w-0 flex-shrink">
                        <button
                            onClick={onClose}
                            className="pdf-control-btn flex-shrink-0"
                            title="Close (Esc)"
                            aria-label="Close PDF"
                        >
                            <i className="fa fa-times"></i>
                        </button>
                        <div className="hidden md:block min-w-0">
                            <h2 className="text-base lg:text-lg font-semibold text-gray-900 truncate">{resource.title}</h2>
                            {resource.author_name && (
                                <p className="text-sm text-gray-600 truncate">{resource.author_name}</p>
                            )}
                        </div>
                    </div>

                    {/* Center: Page Navigation */}
                    <div className="flex items-center gap-1 md:gap-3 flex-shrink-0">
                        <button
                            onClick={goToPreviousPage}
                            disabled={currentPage === 1}
                            className="pdf-control-btn"
                            title="Previous Page"
                            aria-label="Previous page"
                        >
                            <i className="fa fa-chevron-left"></i>
                        </button>
                        {/* Compact label on mobile, verbose on md+ */}
                        <span className="pdf-page-indicator md:hidden">
                            {currentPage} / {numPages}
                        </span>
                        <span className="pdf-page-indicator hidden md:inline">
                            Page {currentPage} of {numPages}
                        </span>
                        <button
                            onClick={goToNextPage}
                            disabled={currentPage === numPages}
                            className="pdf-control-btn"
                            title="Next Page"
                            aria-label="Next page"
                        >
                            <i className="fa fa-chevron-right"></i>
                        </button>
                    </div>

                    {/* Right: Zoom and Download */}
                    <div className="flex items-center gap-1 md:gap-3 flex-shrink-0">
                        <button
                            onClick={handleZoomOut}
                            disabled={zoom <= ZOOM_LEVELS[0]}
                            className="pdf-control-btn"
                            title="Zoom Out"
                            aria-label="Zoom out"
                        >
                            <i className="fa fa-search-minus"></i>
                        </button>
                        {/* Hide the % readout on mobile to save space. */}
                        <span className="pdf-zoom-display hidden md:inline">{zoom}%</span>
                        <button
                            onClick={handleZoomIn}
                            disabled={zoom >= ZOOM_LEVELS[ZOOM_LEVELS.length - 1]}
                            className="pdf-control-btn"
                            title="Zoom In"
                            aria-label="Zoom in"
                        >
                            <i className="fa fa-search-plus"></i>
                        </button>
                        {/* Fit-width / fit-page and their divider hidden on
                            mobile — less commonly used and the page already
                            renders at fit-width by default on first load. */}
                        <div className="hidden md:block border-l border-gray-300 h-8 mx-2"></div>
                        <button
                            onClick={handleFitWidth}
                            className={`pdf-control-btn hidden md:flex ${zoomMode === 'fit-width' ? 'bg-blue-100' : ''}`}
                            title="Fit to Width"
                            aria-label="Fit to width"
                        >
                            <i className="fa fa-arrows-alt-h"></i>
                        </button>
                        <button
                            onClick={handleFitPage}
                            className={`pdf-control-btn hidden md:flex ${zoomMode === 'fit-page' ? 'bg-blue-100' : ''}`}
                            title="Fit to Page"
                            aria-label="Fit to page"
                        >
                            <i className="fa fa-expand"></i>
                        </button>
                        <div className="hidden md:block border-l border-gray-300 h-8 mx-2"></div>
                        <button
                            onClick={handleDownload}
                            className="pdf-control-btn"
                            title="Download PDF"
                            aria-label="Download PDF"
                        >
                            <i className="fa fa-download"></i>
                        </button>
                    </div>
                </div>
            </div>

            {/* PDF Content */}
            <div ref={contentRef} className="pdf-content">
                <div className="pdf-pages-container">
                    {Array.from({ length: numPages }, (_, i) => i + 1).map(pageNum => (
                        <div
                            key={`page-${pageNum}`}
                            ref={(el) => {
                                if (el) {
                                    pageRefs.current.set(pageNum, el);
                                }
                            }}
                            className="pdf-page"
                            style={{ display: pageNum <= pagesToLoad ? 'block' : 'none' }}
                        >
                            <canvas />
                            <div className="pdf-page-number">Page {pageNum}</div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
