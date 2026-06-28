/**
 * Upload progress indicator component.
 * Displays current upload stage, progress bar, and status message.
 *
 * @module local_reblibrary/components/admin/UploadProgress
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h } from 'preact';
import type { UploadProgress as UploadProgressType } from '../../services/upload';
import { formatFileSize } from '../../services/upload';

interface Props extends UploadProgressType {
  className?: string;
}

/**
 * Upload progress component.
 */
export default function UploadProgress({
  stage,
  progress,
  message,
  bytesUploaded,
  bytesTotal,
  className = ''
}: Props) {

  // Determine color based on stage
  const getStageColor = () => {
    switch (stage) {
      case 'hashing':
      case 'requesting_urls':
        return 'blue';
      case 'uploading_pdf':
      case 'uploading_file':
      case 'uploading_cover':
        return 'indigo';
      case 'extracting_cover':
        return 'purple';
      case 'complete':
        return 'green';
      default:
        return 'gray';
    }
  };

  const color = getStageColor();

  // Stage icons
  const getStageIcon = () => {
    switch (stage) {
      case 'hashing':
        return 'fa-hashtag';
      case 'requesting_urls':
        return 'fa-link';
      case 'uploading_pdf':
        return 'fa-file-pdf';
      case 'uploading_file':
        return 'fa-file-video';
      case 'extracting_cover':
        return 'fa-image';
      case 'uploading_cover':
        return 'fa-upload';
      case 'complete':
        return 'fa-check-circle';
      default:
        return 'fa-spinner fa-spin';
    }
  };

  const isFileStage = stage === 'uploading_pdf' || stage === 'uploading_file';

  return (
    <div className={`bg-${color}-50 border border-${color}-200 rounded-lg p-4 ${className}`}>
      {/* Header */}
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <i className={`fa ${getStageIcon()} text-${color}-600`}></i>
          <span className="text-sm font-medium text-${color}-900">{message}</span>
        </div>
        <span className="text-sm font-bold text-${color}-700">{Math.round(progress)}%</span>
      </div>

      {/* Progress bar */}
      <div className="w-full bg-${color}-200 rounded-full h-2.5 mb-2">
        <div
          className={`bg-${color}-600 h-2.5 rounded-full transition-all duration-300 ease-out`}
          style={{ width: `${progress}%` }}
        />
      </div>

      {/* Bytes info (if available) */}
      {bytesUploaded !== undefined && bytesTotal !== undefined && (
        <div className="text-xs text-${color}-700 mt-2">
          {formatFileSize(bytesUploaded)} / {formatFileSize(bytesTotal)}
        </div>
      )}

      {/* Stage indicator */}
      <div className="flex items-center gap-2 mt-3 text-xs text-${color}-600">
        <div className={`flex items-center gap-1 ${stage === 'hashing' || stage === 'requesting_urls' || isFileStage || stage === 'extracting_cover' || stage === 'uploading_cover' || stage === 'complete' ? 'text-${color}-700 font-medium' : 'text-${color}-400'}`}>
          <i className={`fa fa-circle text-xs ${stage === 'hashing' ? 'fa-spin' : ''}`}></i>
          Hash
        </div>
        <span>→</span>
        <div className={`flex items-center gap-1 ${isFileStage || stage === 'extracting_cover' || stage === 'uploading_cover' || stage === 'complete' ? 'text-${color}-700 font-medium' : 'text-${color}-400'}`}>
          <i className={`fa fa-circle text-xs ${isFileStage ? 'fa-spin' : ''}`}></i>
          File
        </div>
        <span>→</span>
        <div className={`flex items-center gap-1 ${stage === 'extracting_cover' || stage === 'uploading_cover' || stage === 'complete' ? 'text-${color}-700 font-medium' : 'text-${color}-400'}`}>
          <i className={`fa fa-circle text-xs ${stage === 'extracting_cover' ? 'fa-spin' : ''}`}></i>
          Cover
        </div>
        <span>→</span>
        <div className={`flex items-center gap-1 ${stage === 'uploading_cover' || stage === 'complete' ? 'text-${color}-700 font-medium' : 'text-${color}-400'}`}>
          <i className={`fa fa-circle text-xs ${stage === 'uploading_cover' ? 'fa-spin' : ''}`}></i>
          Upload
        </div>
        <span>→</span>
        <div className={`flex items-center gap-1 ${stage === 'complete' ? 'text-green-700 font-medium' : 'text-${color}-400'}`}>
          <i className={`fa ${stage === 'complete' ? 'fa-check-circle' : 'fa-circle'} text-xs`}></i>
          Done
        </div>
      </div>
    </div>
  );
}
