/**
 * Video Player component.
 * HTML5 video player with toolbar matching the PDFReader style.
 *
 * @module local_reblibrary/components/shared/VideoPlayer
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import type { Resource } from '../../services/resources';

interface VideoPlayerProps {
    resource: Resource;
    onClose: () => void;
}

export default function VideoPlayer({ resource, onClose }: VideoPlayerProps) {
    const videoRef = useRef<HTMLVideoElement>(null);

    // Handle Escape key to close
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    const handleDownload = () => {
        if (resource.file_url) {
            const link = document.createElement('a');
            link.href = resource.file_url;
            link.download = resource.title || 'video';
            link.click();
        }
    };

    const handleFullscreen = () => {
        if (videoRef.current) {
            if (videoRef.current.requestFullscreen) {
                videoRef.current.requestFullscreen();
            }
        }
    };

    return (
        <div className="pdf-reader-container">
            {/* Toolbar - matches PDFReader style */}
            <div className="pdf-toolbar">
                <div className="flex items-center justify-between w-full">
                    {/* Left: Close and Title */}
                    <div className="flex items-center gap-4">
                        <button
                            onClick={onClose}
                            className="pdf-control-btn"
                            title="Close (Esc)"
                        >
                            <i className="fa fa-times"></i>
                        </button>
                        <div>
                            <h2 className="text-lg font-semibold text-gray-900">{resource.title}</h2>
                            {resource.author_name && (
                                <p className="text-sm text-gray-600">{resource.author_name}</p>
                            )}
                        </div>
                    </div>

                    {/* Right: Fullscreen and Download */}
                    <div className="flex items-center gap-3">
                        <button
                            onClick={handleFullscreen}
                            className="pdf-control-btn"
                            title="Fullscreen"
                        >
                            <i className="fa fa-expand"></i>
                        </button>
                        <button
                            onClick={handleDownload}
                            className="pdf-control-btn"
                            title="Download"
                        >
                            <i className="fa fa-download"></i>
                        </button>
                    </div>
                </div>
            </div>

            {/* Video Content */}
            <div className="flex-1 flex items-center justify-center bg-black overflow-hidden">
                {resource.file_url ? (
                    <video
                        ref={videoRef}
                        src={resource.file_url}
                        controls
                        autoPlay={false}
                        className="max-w-full max-h-full"
                        style={{ outline: 'none' }}
                    >
                        Your browser does not support video playback.
                    </video>
                ) : (
                    <div className="text-center text-gray-400">
                        <i className="fa fa-video text-6xl mb-4"></i>
                        <p>No video file available for this resource.</p>
                    </div>
                )}
            </div>
        </div>
    );
}
