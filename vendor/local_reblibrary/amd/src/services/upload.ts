/**
 * Upload service for S3 storage.
 * Handles file hashing, multipart uploads, and cover extraction (PDF only).
 *
 * @module local_reblibrary/services/upload
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Moodle global config
declare const M: { cfg: { sesskey: string; wwwroot: string } };

/**
 * Upload progress information
 */
export interface UploadProgress {
  stage: 'hashing' | 'requesting_urls' | 'uploading_pdf' | 'uploading_file' | 'extracting_cover' | 'uploading_cover' | 'complete';
  progress: number; // 0-100
  message: string;
  bytesUploaded?: number;
  bytesTotal?: number;
}

/**
 * PDF metadata
 */
export interface PdfMetadata {
  numPages: number;
  title: string;
  author?: string;
  creationDate?: string;
  fileSize: number;
}

/**
 * Options for uploadResourceFiles
 */
export interface UploadOptions {
  mediaType?: string;   // 'text' | 'video' (default: 'text')
  coverFile?: File;     // Manual cover image (used for video)
}

/**
 * Upload result
 */
export interface UploadResult {
  fileUrl: string;
  coverUrl: string;
  // Backward compat aliases
  pdfUrl: string;
}

/**
 * Compute SHA-256 hash of a file.
 *
 * @param file File to hash
 * @returns Promise resolving to SHA-256 hash (64 hex characters)
 */
export async function computeFileHash(file: File): Promise<string> {
  const buffer = await file.arrayBuffer();
  const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Upload file and optional cover via multipart form data.
 * Uses XMLHttpRequest for real upload progress tracking.
 */
async function uploadViaMultipart(
  fileHash: string,
  file: File,
  mediaType: string,
  coverBlob: Blob | null,
  onProgress: (progress: UploadProgress) => void
): Promise<{ fileUrl: string; coverUrl: string }> {
  const formData = new FormData();
  formData.append('file_hash', fileHash);
  formData.append('media_type', mediaType);
  formData.append('sesskey', M.cfg.sesskey);
  formData.append('file', file, file.name);
  if (coverBlob) {
    const coverName = coverBlob instanceof File ? coverBlob.name : 'cover.jpg';
    formData.append('cover', coverBlob, coverName);
  }

  const stage = mediaType === 'video' ? 'uploading_file' : 'uploading_pdf';

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) {
        // Map upload progress to 50-95% range (hashing + cover extraction = 0-50%)
        const uploadPercent = (e.loaded / e.total) * 45 + 50;
        onProgress({
          stage,
          progress: uploadPercent,
          message: `Uploading to backend (${formatFileSize(e.loaded)} / ${formatFileSize(e.total)})...`,
          bytesUploaded: e.loaded,
          bytesTotal: e.total,
        });
      }
    });

    xhr.addEventListener('load', () => {
      try {
        const result = JSON.parse(xhr.responseText);
        if (xhr.status >= 200 && xhr.status < 300 && result.success) {
          resolve({
            fileUrl: result.file_url || result.pdf_url,
            coverUrl: result.cover_url || '',
          });
        } else {
          reject(new Error(result.error || 'Upload failed'));
        }
      } catch {
        reject(new Error('Invalid server response'));
      }
    });

    xhr.addEventListener('error', () => {
      reject(new Error('Network error during upload'));
    });

    xhr.addEventListener('abort', () => {
      reject(new Error('Upload was aborted'));
    });

    xhr.open('POST', M.cfg.wwwroot + '/local/reblibrary/upload_file.php');
    xhr.send(formData);
  });
}

/**
 * Extract first page of PDF as JPEG image.
 *
 * @param pdfFile PDF file to extract from
 * @param scale Rendering scale (default: 2.0 for high quality)
 * @param quality JPEG quality 0-1 (default: 0.85)
 * @returns Promise resolving to JPEG blob
 */
export async function extractPdfCover(
  pdfFile: File,
  scale: number = 2.0,
  quality: number = 0.85
): Promise<Blob> {
  // Dynamically import PDF.js only when needed
  const pdfjsLib = await import('pdfjs-dist');
  pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${pdfjsLib.version}/build/pdf.worker.min.mjs`;

  try {
    // Load PDF
    const arrayBuffer = await pdfFile.arrayBuffer();
    const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer });
    const pdf = await loadingTask.promise;

    // Get first page
    const page = await pdf.getPage(1);

    // Calculate viewport
    const viewport = page.getViewport({ scale });

    // Create canvas
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    if (!context) {
      throw new Error('Failed to get canvas context');
    }

    canvas.width = viewport.width;
    canvas.height = viewport.height;

    // Fill white background first (PDFs might have transparency)
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);

    // Render page to canvas
    const renderContext = {
      canvasContext: context,
      viewport: viewport,
    };
    await page.render(renderContext).promise;

    // Convert to JPEG blob
    return new Promise((resolve, reject) => {
      canvas.toBlob(
        (blob) => {
          if (blob) {
            resolve(blob);
          } else {
            reject(new Error('Failed to create JPEG blob from canvas'));
          }
        },
        'image/jpeg',
        quality
      );
    });
  } catch (error) {
    console.error('Failed to extract PDF cover:', error);
    throw new Error(`Failed to extract cover image: ${error instanceof Error ? error.message : String(error)}`);
  }
}

/**
 * Get PDF metadata.
 *
 * @param pdfFile PDF file to analyze
 * @returns Promise resolving to PDF metadata
 */
export async function getPdfMetadata(pdfFile: File): Promise<PdfMetadata> {
  const pdfjsLib = await import('pdfjs-dist');
  pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${pdfjsLib.version}/build/pdf.worker.min.mjs`;

  try {
    const arrayBuffer = await pdfFile.arrayBuffer();
    const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer });
    const pdf = await loadingTask.promise;
    const metadata = await pdf.getMetadata();

    return {
      numPages: pdf.numPages,
      title: metadata.info?.Title || pdfFile.name.replace(/\.pdf$/i, ''),
      author: metadata.info?.Author,
      creationDate: metadata.info?.CreationDate,
      fileSize: pdfFile.size,
    };
  } catch (error) {
    console.error('Failed to get PDF metadata:', error);
    throw new Error(`Failed to read PDF: ${error instanceof Error ? error.message : String(error)}`);
  }
}

/**
 * Format file size in human-readable format.
 *
 * @param bytes File size in bytes
 * @returns Formatted string (e.g., "12.5 MB")
 */
export function formatFileSize(bytes: number): string {
  const units = ['B', 'KB', 'MB', 'GB'];
  let size = bytes;
  let unitIndex = 0;

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }

  return `${size.toFixed(unitIndex > 0 ? 2 : 0)} ${units[unitIndex]}`;
}

/**
 * Complete upload workflow for resource file.
 * Handles hashing, optional cover extraction (PDF), and multipart upload with progress tracking.
 *
 * @param file File to upload (PDF or video)
 * @param onProgress Progress callback
 * @param options Upload options (mediaType, coverFile)
 * @returns Promise resolving to proxy URLs for file and cover
 */
export async function uploadResourceFiles(
  file: File,
  onProgress: (progress: UploadProgress) => void,
  options?: UploadOptions
): Promise<UploadResult> {
  const mediaType = options?.mediaType || 'text';
  const coverFile = options?.coverFile || null;

  try {
    // Stage 1: Hash file
    onProgress({
      stage: 'hashing',
      progress: 0,
      message: 'Computing file hash...',
    });

    const fileHash = await computeFileHash(file);

    onProgress({
      stage: 'hashing',
      progress: 20,
      message: 'File hash computed',
    });

    // Stage 2: Handle cover image
    let coverBlob: Blob | null = null;

    if (mediaType === 'text') {
      // For PDFs: auto-extract cover from first page
      onProgress({
        stage: 'extracting_cover',
        progress: 25,
        message: 'Extracting cover image from first page...',
      });

      coverBlob = await extractPdfCover(file);

      onProgress({
        stage: 'extracting_cover',
        progress: 40,
        message: `Cover extracted (${formatFileSize(coverBlob.size)})`,
      });
    } else if (coverFile) {
      // For video/audio: use manually provided cover
      coverBlob = coverFile;

      onProgress({
        stage: 'extracting_cover',
        progress: 40,
        message: `Cover image ready (${formatFileSize(coverFile.size)})`,
      });
    } else {
      // No cover - skip to upload
      onProgress({
        stage: 'extracting_cover',
        progress: 40,
        message: 'No cover image (optional for video)',
      });
    }

    // Stage 3: Upload via multipart form data
    const uploadStage = mediaType === 'video' ? 'uploading_file' : 'uploading_pdf';
    const totalSize = file.size + (coverBlob?.size || 0);
    onProgress({
      stage: uploadStage,
      progress: 50,
      message: `Uploading to backend (${formatFileSize(totalSize)})...`,
      bytesTotal: totalSize,
    });

    const result = await uploadViaMultipart(fileHash, file, mediaType, coverBlob, onProgress);

    // Complete
    onProgress({
      stage: 'complete',
      progress: 100,
      message: 'Upload complete!',
    });

    return {
      fileUrl: result.fileUrl,
      coverUrl: result.coverUrl,
      pdfUrl: result.fileUrl, // backward compat
    };

  } catch (error) {
    console.error('Upload workflow error:', error);
    throw new Error(`Upload failed: ${error instanceof Error ? error.message : String(error)}`);
  }
}
