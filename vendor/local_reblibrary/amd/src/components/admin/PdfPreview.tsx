/**
 * PDF preview component.
 * Displays PDF metadata and thumbnail previews of first few pages.
 *
 * @module local_reblibrary/components/admin/PdfPreview
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h } from 'preact';
import { useState, useEffect } from 'preact/hooks';
import { getPdfMetadata, formatFileSize } from '../../services/upload';
import * as pdfjsLib from 'pdfjs-dist';

interface Props {
  file: File;
  onMetadata?: (metadata: { numPages: number; title: string; fileSize: number }) => void;
}

/**
 * PDF preview component.
 */
export default function PdfPreview({ file, onMetadata }: Props) {
  const [thumbnails, setThumbnails] = useState<string[]>([]);
  const [metadata, setMetadata] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    loadPdfPreview();
  }, [file]);

  async function loadPdfPreview() {
    setLoading(true);
    setError(null);

    try {
      // Get metadata
      const meta = await getPdfMetadata(file);
      setMetadata(meta);
      onMetadata?.(meta);

      // Load PDF for thumbnails
      const arrayBuffer = await file.arrayBuffer();
      const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer });
      const pdf = await loadingTask.promise;

      console.log('PDF loaded successfully, pages:', pdf.numPages);

      // Generate thumbnails for first 3 pages
      const thumbs: string[] = [];
      const maxPages = Math.min(3, pdf.numPages);

      for (let pageNum = 1; pageNum <= maxPages; pageNum++) {
        console.log(`Rendering page ${pageNum}...`);
        const page = await pdf.getPage(pageNum);
        const viewport = page.getViewport({ scale: 1.5 });
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { alpha: false });

        if (!context) {
          throw new Error('Failed to get canvas context');
        }

        canvas.width = viewport.width;
        canvas.height = viewport.height;

        console.log(`Canvas size: ${canvas.width}x${canvas.height}`);

        // Fill white background
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);

        // Render PDF page with better quality
        const renderTask = page.render({
          canvasContext: context,
          viewport: viewport,
          background: 'white',
        });

        await renderTask.promise;
        console.log(`Page ${pageNum} rendered successfully`);

        const dataUrl = canvas.toDataURL('image/png');
        console.log(`Data URL length: ${dataUrl.length}`);
        console.log(`Data URL preview: ${dataUrl.substring(0, 100)}...`);
        thumbs.push(dataUrl);
      }

      console.log(`Generated ${thumbs.length} thumbnails`);
      setThumbnails(thumbs);
      setLoading(false);
    } catch (err) {
      console.error('Failed to load PDF preview:', err);
      setError(err instanceof Error ? err.message : 'Failed to load PDF preview');
      setLoading(false);
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center p-8 bg-gray-50 rounded-lg border border-gray-200">
        <div className="text-center">
          <i className="fa fa-spinner fa-spin text-3xl text-gray-400 mb-2"></i>
          <p className="text-sm text-gray-600">Loading PDF preview...</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <div className="flex items-center gap-2 text-red-800">
          <i className="fa fa-exclamation-triangle"></i>
          <span className="font-medium">Failed to load PDF</span>
        </div>
        <p className="text-sm text-red-700 mt-1">{error}</p>
      </div>
    );
  }

  return (
    <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
      {/* Metadata */}
      {metadata && (
        <div className="mb-4">
          <div className="flex items-start gap-3">
            <div className="flex-shrink-0">
              <i className="fa fa-file-pdf text-3xl text-red-500"></i>
            </div>
            <div className="flex-1 min-w-0">
              <h4 className="font-medium text-gray-900 truncate">{metadata.title}</h4>
              <div className="flex flex-wrap gap-3 mt-1 text-sm text-gray-600">
                <span>
                  <i className="fa fa-file text-xs mr-1"></i>
                  {metadata.numPages} {metadata.numPages === 1 ? 'page' : 'pages'}
                </span>
                <span>
                  <i className="fa fa-hdd text-xs mr-1"></i>
                  {formatFileSize(metadata.fileSize)}
                </span>
                {metadata.author && (
                  <span>
                    <i className="fa fa-user text-xs mr-1"></i>
                    {metadata.author}
                  </span>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Thumbnails */}
      {thumbnails.length > 0 && (
        <div>
          <p className="text-sm font-medium text-gray-700 mb-2">
            <i className="fa fa-images mr-1"></i>
            Preview:
          </p>
          <div className="flex gap-2 overflow-x-auto pb-2">
            {thumbnails.map((thumb, idx) => (
              <div key={idx} className="flex-shrink-0">
                <img
                  src={thumb}
                  alt={`Page ${idx + 1}`}
                  style={{ width: '150px', height: 'auto', display: 'block', backgroundColor: 'white' }}
                  className="border-2 border-gray-300 rounded shadow-sm"
                  onLoad={() => console.log(`Image ${idx + 1} loaded successfully`)}
                  onError={(e) => console.error(`Image ${idx + 1} failed to load:`, e)}
                />
                <p className="text-xs text-center text-gray-500 mt-1">Page {idx + 1}</p>
              </div>
            ))}
            {metadata && metadata.numPages > 3 && (
              <div className="flex items-center justify-center w-24 border-2 border-dashed border-gray-300 rounded bg-gray-100">
                <div className="text-center text-gray-500">
                  <i className="fa fa-ellipsis-h text-xl mb-1"></i>
                  <p className="text-xs">+{metadata.numPages - 3} more</p>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
