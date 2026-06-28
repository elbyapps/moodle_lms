import { h } from "preact";
import { useState, useEffect } from "preact/hooks";
import { signal } from "@preact/signals";
import type { Resource } from "../../services/resources";
import type { Author } from "../../services/authors";
import { ResourceService, CreateResourceData, UpdateResourceData } from "../../services/resources";
import { AuthorService, CreateAuthorData, UpdateAuthorData } from "../../services/authors";
import { CategoryService } from "../../services/categories";
import type { Category } from "../../services/categories";
import { LabelService } from "../../services/labels";
import type { Label } from "../../services/labels";
import { getClasses, getResourceAssignments, assignToClasses } from "../../services/assignments";
import type { Class } from "../../services/assignments";
import { getResourceCategories, assignCategories } from "../../services/resource-categories";
import { getResourceLabels, assignLabels } from "../../services/resource-labels";
import Sidebar from "../shared/Sidebar";
import Toast from "../shared/Toast";
import { getAdminMenuItems } from "../../config/admin-menu";
import { getLibraryMenuItems } from "../../config/library-menu";
import { uploadResourceFiles, type UploadProgress, type UploadOptions } from "../../services/upload";
import PdfPreview from "./PdfPreview";

// File type config per media type
const FILE_TYPE_CONFIG: Record<string, { accept: string; label: string; maxSize: number; maxSizeLabel: string }> = {
    text: { accept: 'application/pdf', label: 'PDF File', maxSize: 200 * 1024 * 1024, maxSizeLabel: '200MB' },
    video: { accept: 'video/mp4,video/webm', label: 'Video File', maxSize: 500 * 1024 * 1024, maxSizeLabel: '500MB' },
};
import UploadProgressComponent from "./UploadProgress";

// Signals for state management
export const resourcesSignal = signal<Resource[]>([]);
export const authorsSignal = signal<Author[]>([]);
export const loadingSignal = signal<boolean>(false);
export const errorSignal = signal<string | null>(null);
export const successSignal = signal<string | null>(null);

interface ResourcesProps {
    initialResources: Resource[];
    initialAuthors: Author[];
    eduLevels?: import('../../types').EducationLevel[];
    eduSublevels?: import('../../types').EducationSublevel[];
    eduClasses?: import('../../types').EducationClass[];
}

type Tab = 'resources' | 'authors';

export default function Resources({
    initialResources,
    initialAuthors,
    eduLevels = [],
    eduSublevels = [],
    eduClasses = []
}: ResourcesProps) {
    // Initialize signals with data from PHP
    useEffect(() => {
        resourcesSignal.value = initialResources;
        authorsSignal.value = initialAuthors;
    }, [initialResources, initialAuthors]);

    // Load assignment data (classes, categories, labels)
    useEffect(() => {
        const loadAssignmentData = async () => {
            try {
                const [classesData, categoriesData, labelsData] = await Promise.all([
                    getClasses(),
                    CategoryService.getAll(),
                    LabelService.getAll(),
                ]);
                setClasses(classesData);
                setCategories(categoriesData);
                setLabels(labelsData);
            } catch (error) {
                console.error('Failed to load assignment data:', error);
            }
        };
        loadAssignmentData();
    }, []);

    // Menu items
    const libraryMenuItems = getLibraryMenuItems();
    const adminMenuItems = getAdminMenuItems('resources');

    const [activeTab, setActiveTab] = useState<Tab>('resources');
    const [showResourceForm, setShowResourceForm] = useState(false);
    const [showAuthorForm, setShowAuthorForm] = useState(false);
    const [editingResource, setEditingResource] = useState<Resource | null>(null);
    const [editingAuthor, setEditingAuthor] = useState<Author | null>(null);

    const [resourceFormData, setResourceFormData] = useState<CreateResourceData>({
        title: '',
        isbn: '',
        author_id: 0,
        description: '',
        cover_image_url: '',
        file_url: '',
        visible: 1,
        media_type: 'text',
    });

    const [authorFormData, setAuthorFormData] = useState<CreateAuthorData>({
        first_name: '',
        last_name: '',
        bio: '',
    });

    // File upload state
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [selectedCover, setSelectedCover] = useState<File | null>(null);
    const [uploadProgress, setUploadProgress] = useState<UploadProgress | null>(null);
    const [isUploading, setIsUploading] = useState(false);

    // Assignment data
    const [classes, setClasses] = useState<Class[]>([]);
    const [categories, setCategories] = useState<Category[]>([]);
    const [labels, setLabels] = useState<Label[]>([]);
    const [selectedClassIds, setSelectedClassIds] = useState<number[]>([]);
    const [selectedCategoryIds, setSelectedCategoryIds] = useState<number[]>([]);
    const [selectedLabelIds, setSelectedLabelIds] = useState<number[]>([]);

    // Resource handlers
    const handleAddResource = () => {
        setEditingResource(null);
        setResourceFormData({
            title: '',
            isbn: '',
            author_id: authorsSignal.value.length > 0 ? authorsSignal.value[0].id : 0,
            description: '',
            cover_image_url: '',
            file_url: '',
            visible: 1,
            media_type: 'text',
        });
        setSelectedClassIds([]);
        setSelectedCategoryIds([]);
        setSelectedLabelIds([]);
        setShowResourceForm(true);
    };

    const handleEditResource = async (resource: Resource) => {
        setEditingResource(resource);
        setResourceFormData({
            title: resource.title,
            isbn: resource.isbn || '',
            author_id: resource.author_id,
            description: resource.description || '',
            cover_image_url: resource.cover_image_url || '',
            file_url: resource.file_url || '',
            visible: resource.visible ?? 1,
            media_type: resource.media_type || 'text',
        });

        // Load existing assignments
        try {
            const [assignments, categoryAssignments, labelAssignments] = await Promise.all([
                getResourceAssignments(resource.id),
                getResourceCategories(resource.id),
                getResourceLabels(resource.id),
            ]);
            setSelectedClassIds(assignments.class_ids);
            setSelectedCategoryIds(categoryAssignments.category_ids);
            setSelectedLabelIds(labelAssignments.label_ids);
        } catch (error) {
            console.error('Failed to load resource assignments:', error);
            // Reset to empty arrays on error
            setSelectedClassIds([]);
            setSelectedCategoryIds([]);
            setSelectedLabelIds([]);
        }

        setShowResourceForm(true);
    };

    const handleCancelResource = () => {
        setShowResourceForm(false);
        setEditingResource(null);
        setSelectedFile(null);
        setSelectedCover(null);
        setUploadProgress(null);
    };

    const handleFileSelect = (e: Event) => {
        const input = e.target as HTMLInputElement;
        const file = input.files?.[0];

        if (!file) return;

        const config = FILE_TYPE_CONFIG[resourceFormData.media_type || 'text'];
        const allowedTypes = config.accept.split(',');

        // Validate file type
        if (!allowedTypes.includes(file.type)) {
            errorSignal.value = `Please select a valid ${config.label.toLowerCase()} (${config.accept})`;
            return;
        }

        // Validate file size
        if (file.size > config.maxSize) {
            errorSignal.value = `File size must be less than ${config.maxSizeLabel}`;
            return;
        }

        setSelectedFile(file);
        errorSignal.value = null;
    };

    const handleCoverSelect = (e: Event) => {
        const input = e.target as HTMLInputElement;
        const file = input.files?.[0];

        if (!file) return;

        if (!file.type.startsWith('image/')) {
            errorSignal.value = 'Please select an image file for the cover';
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            errorSignal.value = 'Cover image must be less than 10MB';
            return;
        }

        setSelectedCover(file);
        errorSignal.value = null;
    };

    // Clear selected file when media_type changes
    const handleMediaTypeChange = (newMediaType: string) => {
        setResourceFormData({ ...resourceFormData, media_type: newMediaType });
        setSelectedFile(null);
        setSelectedCover(null);
        setUploadProgress(null);
    };

    const handleSubmitResource = async (e: Event) => {
        e.preventDefault();

        if (!resourceFormData.title.trim()) {
            errorSignal.value = 'Resource title is required';
            return;
        }

        if (!resourceFormData.author_id) {
            errorSignal.value = 'Please select an author';
            return;
        }

        setIsUploading(true);
        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            // Upload files if file selected
            let fileUrl = resourceFormData.file_url;
            let coverUrl = resourceFormData.cover_image_url;

            if (selectedFile) {
                const uploadOptions: UploadOptions = {
                    mediaType: resourceFormData.media_type || 'text',
                    coverFile: selectedCover || undefined,
                };
                const result = await uploadResourceFiles(
                    selectedFile,
                    setUploadProgress,
                    uploadOptions
                );
                fileUrl = result.fileUrl;
                if (result.coverUrl) {
                    coverUrl = result.coverUrl;
                }
            }

            // Update form data with file URLs
            const formDataWithUrls = {
                ...resourceFormData,
                file_url: fileUrl,
                cover_image_url: coverUrl,
            };

            // Submit to existing API
            let resourceId: number;
            if (editingResource) {
                const updateData: UpdateResourceData = {
                    title: formDataWithUrls.title,
                    isbn: formDataWithUrls.isbn,
                    author_id: formDataWithUrls.author_id,
                    description: formDataWithUrls.description,
                    cover_image_url: formDataWithUrls.cover_image_url,
                    file_url: formDataWithUrls.file_url,
                    visible: formDataWithUrls.visible,
                    media_type: formDataWithUrls.media_type,
                };
                const updated = await ResourceService.update(editingResource.id, updateData);
                resourcesSignal.value = resourcesSignal.value.map(r =>
                    r.id === updated.id ? updated : r
                );
                resourceId = updated.id;
            } else {
                const created = await ResourceService.create(formDataWithUrls);
                resourcesSignal.value = [created, ...resourcesSignal.value];
                resourceId = created.id;
            }

            // Save assignments
            await Promise.all([
                assignToClasses(resourceId, selectedClassIds),
                assignCategories(resourceId, selectedCategoryIds),
                assignLabels(resourceId, selectedLabelIds),
            ]);

            successSignal.value = editingResource ? 'Resource updated successfully' : 'Resource created successfully';

            // Reset state
            setSelectedFile(null);
            setSelectedCover(null);
            setUploadProgress(null);
            handleCancelResource();
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            setIsUploading(false);
            loadingSignal.value = false;
        }
    };

    const handleDeleteResource = async (resource: Resource) => {
        if (!confirm(`Are you sure you want to delete "${resource.title}"?`)) {
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            await ResourceService.delete(resource.id);
            resourcesSignal.value = resourcesSignal.value.filter(r => r.id !== resource.id);
            successSignal.value = 'Resource deleted successfully';
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    // Author handlers
    const handleAddAuthor = () => {
        setEditingAuthor(null);
        setAuthorFormData({
            first_name: '',
            last_name: '',
            bio: '',
        });
        setShowAuthorForm(true);
    };

    const handleEditAuthor = (author: Author) => {
        setEditingAuthor(author);
        setAuthorFormData({
            first_name: author.first_name,
            last_name: author.last_name,
            bio: author.bio || '',
        });
        setShowAuthorForm(true);
    };

    const handleCancelAuthor = () => {
        setShowAuthorForm(false);
        setEditingAuthor(null);
    };

    const handleSubmitAuthor = async (e: Event) => {
        e.preventDefault();

        if (!authorFormData.first_name.trim() || !authorFormData.last_name.trim()) {
            errorSignal.value = 'First name and last name are required';
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            if (editingAuthor) {
                const updateData: UpdateAuthorData = {
                    first_name: authorFormData.first_name,
                    last_name: authorFormData.last_name,
                    bio: authorFormData.bio,
                };
                const updated = await AuthorService.update(editingAuthor.id, updateData);
                authorsSignal.value = authorsSignal.value.map(a =>
                    a.id === updated.id ? updated : a
                );
                successSignal.value = 'Author updated successfully';
            } else {
                const created = await AuthorService.create(authorFormData);
                authorsSignal.value = [...authorsSignal.value, created].sort((a, b) =>
                    `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`)
                );
                successSignal.value = 'Author created successfully';
            }
            handleCancelAuthor();
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const handleDeleteAuthor = async (author: Author) => {
        if (!confirm(`Are you sure you want to delete "${author.first_name} ${author.last_name}"?`)) {
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            await AuthorService.delete(author.id);
            authorsSignal.value = authorsSignal.value.filter(a => a.id !== author.id);
            successSignal.value = 'Author deleted successfully';
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const getAuthorName = (authorId: number) => {
        const author = authorsSignal.value.find(a => a.id === authorId);
        return author ? `${author.first_name} ${author.last_name}` : `Unknown (ID: ${authorId})`;
    };

    const formatDate = (timestamp: number) => {
        return new Date(timestamp * 1000).toLocaleDateString();
    };

    return (
        <div className="flex min-h-screen bg-white">
            <Sidebar
                adminMenuItems={adminMenuItems}
                libraryMenuItems={libraryMenuItems}
                levels={eduLevels}
                sublevels={eduSublevels}
                classes={eduClasses}
            />
            <main className="flex-1 overflow-y-auto bg-gray-50 min-w-0">
                <div className="p-4 pt-14 lg:p-8">
                    <h1 className="text-2xl lg:text-3xl font-bold text-gray-900 mb-4 lg:mb-6">
                        Resources & Authors Management
                    </h1>

                    {/* Tabs */}
                    <div className="flex border-b border-gray-200 mb-6 overflow-x-auto">
                        <button
                            onClick={() => setActiveTab('resources')}
                            className={`px-4 lg:px-6 py-3 font-medium transition-colors whitespace-nowrap ${
                                activeTab === 'resources'
                                    ? 'border-b-2 border-reb-blue text-reb-blue'
                                    : 'text-gray-600 hover:text-gray-900'
                            }`}
                        >
                            Resources
                        </button>
                        <button
                            onClick={() => setActiveTab('authors')}
                            className={`px-4 lg:px-6 py-3 font-medium transition-colors whitespace-nowrap ${
                                activeTab === 'authors'
                                    ? 'border-b-2 border-reb-blue text-reb-blue'
                                    : 'text-gray-600 hover:text-gray-900'
                            }`}
                        >
                            Authors
                        </button>
                    </div>

                    {/* Resources Tab Content */}
                    {activeTab === 'resources' && (
                        <div className="bg-white rounded-lg shadow p-3 lg:p-6">
                            {showResourceForm ? (
                                <>
                                    {/* Back Button */}
                                    <button
                                        onClick={handleCancelResource}
                                        className="flex items-center text-gray-600 hover:text-gray-900 mb-4 transition-colors"
                                    >
                                        <i className="fa fa-arrow-left mr-2"></i>
                                        Back to Resources
                                    </button>

                                    {/* Form Only */}
                                    <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                                        <h3 className="text-lg font-medium text-gray-900 mb-4">
                                            {editingResource ? 'Edit Resource' : 'Add Resource'}
                                        </h3>
                                        <form onSubmit={handleSubmitResource}>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        Title <span className="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        type="text"
                                                        value={resourceFormData.title}
                                                        onInput={(e) => setResourceFormData({ ...resourceFormData, title: (e.target as HTMLInputElement).value })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                        placeholder="Resource title"
                                                        required
                                                    />
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        ISBN
                                                    </label>
                                                    <input
                                                        type="text"
                                                        value={resourceFormData.isbn}
                                                        onInput={(e) => setResourceFormData({ ...resourceFormData, isbn: (e.target as HTMLInputElement).value })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                        placeholder="ISBN (optional)"
                                                    />
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        Author <span className="text-red-500">*</span>
                                                    </label>
                                                    <select
                                                        value={resourceFormData.author_id}
                                                        onChange={(e) => setResourceFormData({ ...resourceFormData, author_id: parseInt((e.target as HTMLSelectElement).value) })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                        required
                                                    >
                                                        <option value="">-- Select Author --</option>
                                                        {authorsSignal.value.map((author) => (
                                                            <option key={author.id} value={author.id}>
                                                                {author.first_name} {author.last_name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        Visibility
                                                    </label>
                                                    <select
                                                        value={resourceFormData.visible}
                                                        onChange={(e) => setResourceFormData({ ...resourceFormData, visible: parseInt((e.target as HTMLSelectElement).value) })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                    >
                                                        <option value="1">Visible (Public)</option>
                                                        <option value="0">Hidden (Private)</option>
                                                    </select>
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        Media Type
                                                    </label>
                                                    <select
                                                        value={resourceFormData.media_type}
                                                        onChange={(e) => handleMediaTypeChange((e.target as HTMLSelectElement).value)}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                    >
                                                        <option value="text">Text/PDF</option>
                                                        <option value="audio">Audio</option>
                                                        <option value="video">Video</option>
                                                    </select>
                                                </div>

                                                <div className="md:col-span-2">
                                                    {(() => {
                                                        const mediaType = resourceFormData.media_type || 'text';
                                                        const config = FILE_TYPE_CONFIG[mediaType] || FILE_TYPE_CONFIG.text;
                                                        const isVideo = mediaType === 'video';
                                                        const fileIcon = isVideo ? 'fa-file-video' : 'fa-file-pdf';
                                                        const fileIconColor = isVideo ? 'text-purple-500' : 'text-red-500';

                                                        return (
                                                            <>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        {config.label} <span className="text-red-500">*</span>
                                                    </label>

                                                    {!selectedFile && !editingResource?.file_url ? (
                                                        <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-400 transition-colors cursor-pointer">
                                                            <input
                                                                type="file"
                                                                accept={config.accept}
                                                                onChange={handleFileSelect}
                                                                className="hidden"
                                                                id="file-upload"
                                                            />
                                                            <label htmlFor="file-upload" className="cursor-pointer">
                                                                <i className="fa fa-cloud-upload text-4xl text-gray-400 mb-2"></i>
                                                                <p className="text-gray-700 font-medium">Click to upload {config.label.toLowerCase()}</p>
                                                                <p className="text-sm text-gray-500 mt-1">or drag and drop</p>
                                                                <p className="text-xs text-gray-400 mt-2">{config.label} up to {config.maxSizeLabel}</p>
                                                            </label>
                                                        </div>
                                                    ) : selectedFile ? (
                                                        <div>
                                                            {mediaType === 'text' ? (
                                                                <PdfPreview file={selectedFile} />
                                                            ) : (
                                                                <div className="bg-gray-50 rounded-lg p-4 border border-gray-200 flex items-center gap-3">
                                                                    <i className={`fa ${fileIcon} text-3xl ${fileIconColor}`}></i>
                                                                    <div>
                                                                        <p className="font-medium text-gray-900">{selectedFile.name}</p>
                                                                        <p className="text-sm text-gray-500">{(selectedFile.size / (1024 * 1024)).toFixed(1)} MB</p>
                                                                    </div>
                                                                </div>
                                                            )}
                                                            <button
                                                                type="button"
                                                                onClick={() => setSelectedFile(null)}
                                                                className="mt-2 text-sm text-red-600 hover:text-red-800"
                                                            >
                                                                <i className="fa fa-times mr-1"></i>
                                                                Remove file
                                                            </button>
                                                        </div>
                                                    ) : editingResource?.file_url ? (
                                                        <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                                            <div className="mb-4">
                                                                <div className="flex items-start gap-3">
                                                                    <div className="flex-shrink-0">
                                                                        {editingResource.cover_image_url ? (
                                                                            <img
                                                                                src={editingResource.cover_image_url}
                                                                                alt="Cover"
                                                                                className="w-32 h-auto border-2 border-gray-300 rounded shadow-sm"
                                                                            />
                                                                        ) : (
                                                                            <i className={`fa ${fileIcon} text-5xl ${fileIconColor}`}></i>
                                                                        )}
                                                                    </div>
                                                                    <div className="flex-1 min-w-0">
                                                                        <h4 className="font-medium text-gray-900 mb-2">
                                                                            <i className={`fa ${fileIcon} ${fileIconColor} mr-2`}></i>
                                                                            Current {config.label}
                                                                        </h4>
                                                                        <p className="text-sm text-gray-600 mb-2 break-all">
                                                                            <i className="fa fa-link text-xs mr-1"></i>
                                                                            {editingResource.file_url}
                                                                        </p>
                                                                        <a
                                                                            href={editingResource.file_url}
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            className="inline-flex items-center text-sm text-blue-600 hover:text-blue-800"
                                                                        >
                                                                            <i className="fa fa-external-link-alt mr-1"></i>
                                                                            Open in new tab
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div className="border-t border-gray-200 pt-3">
                                                                <p className="text-sm text-gray-700 mb-2">
                                                                    <i className="fa fa-info-circle mr-1"></i>
                                                                    Upload a new file to replace the current one
                                                                </p>
                                                                <div className="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-blue-400 transition-colors cursor-pointer">
                                                                    <input
                                                                        type="file"
                                                                        accept={config.accept}
                                                                        onChange={handleFileSelect}
                                                                        className="hidden"
                                                                        id="file-upload-replace"
                                                                    />
                                                                    <label htmlFor="file-upload-replace" className="cursor-pointer">
                                                                        <i className="fa fa-upload text-2xl text-gray-400 mb-1"></i>
                                                                        <p className="text-sm text-gray-700 font-medium">Click to replace {config.label.toLowerCase()}</p>
                                                                        <p className="text-xs text-gray-400 mt-1">{config.label} up to {config.maxSizeLabel}</p>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    ) : null}
                                                            </>
                                                        );
                                                    })()}
                                                </div>

                                                {/* Cover image upload for video */}
                                                {(resourceFormData.media_type === 'video') && (
                                                    <div className="md:col-span-2">
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                                            Cover Image <span className="text-gray-400">(optional)</span>
                                                        </label>
                                                        {!selectedCover ? (
                                                            <div className="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center hover:border-blue-400 transition-colors cursor-pointer">
                                                                <input
                                                                    type="file"
                                                                    accept="image/jpeg,image/png,image/webp"
                                                                    onChange={handleCoverSelect}
                                                                    className="hidden"
                                                                    id="cover-upload"
                                                                />
                                                                <label htmlFor="cover-upload" className="cursor-pointer">
                                                                    <i className="fa fa-image text-2xl text-gray-400 mb-1"></i>
                                                                    <p className="text-sm text-gray-700 font-medium">Click to upload cover image</p>
                                                                    <p className="text-xs text-gray-400 mt-1">JPEG, PNG, or WebP up to 10MB</p>
                                                                </label>
                                                            </div>
                                                        ) : (
                                                            <div className="flex items-center gap-3 bg-gray-50 rounded-lg p-3 border border-gray-200">
                                                                <img
                                                                    src={URL.createObjectURL(selectedCover)}
                                                                    alt="Cover preview"
                                                                    className="w-16 h-auto rounded border border-gray-300"
                                                                />
                                                                <div className="flex-1">
                                                                    <p className="text-sm font-medium text-gray-900">{selectedCover.name}</p>
                                                                    <p className="text-xs text-gray-500">{(selectedCover.size / 1024).toFixed(0)} KB</p>
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setSelectedCover(null)}
                                                                    className="text-sm text-red-600 hover:text-red-800"
                                                                >
                                                                    <i className="fa fa-times"></i>
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                {uploadProgress && (
                                                    <div className="md:col-span-2">
                                                        <UploadProgressComponent {...uploadProgress} />
                                                    </div>
                                                )}

                                                {selectedFile && uploadProgress?.stage === 'complete' && (
                                                    <div className="md:col-span-2 bg-green-50 border border-green-200 rounded-lg p-3">
                                                        <i className="fa fa-check-circle text-green-600 mr-2"></i>
                                                        <span className="text-green-800 text-sm">
                                                            {resourceFormData.media_type === 'text'
                                                                ? 'Files uploaded successfully! Cover auto-generated from first page.'
                                                                : 'File uploaded successfully!'}
                                                        </span>
                                                    </div>
                                                )}

                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        Description
                                                    </label>
                                                    <textarea
                                                        value={resourceFormData.description}
                                                        onInput={(e) => setResourceFormData({ ...resourceFormData, description: (e.target as HTMLTextAreaElement).value })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                        placeholder="Resource description"
                                                        rows={3}
                                                    />
                                                </div>

                                                {/* Assignments Section */}
                                                <div className="md:col-span-2 mt-4 pt-4 border-t border-gray-200">
                                                    <h4 className="text-base font-medium text-gray-900 mb-3">
                                                        <i className="fa fa-link mr-2 text-gray-600"></i>
                                                        Resource Assignments
                                                    </h4>
                                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                        {/* Classes */}
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                Classes
                                                            </label>
                                                            <select
                                                                multiple
                                                                value={selectedClassIds.map(String)}
                                                                onChange={(e) => {
                                                                    const selected = Array.from((e.target as HTMLSelectElement).selectedOptions).map(opt => parseInt(opt.value));
                                                                    setSelectedClassIds(selected);
                                                                }}
                                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue h-32"
                                                            >
                                                                {classes.map((cls) => (
                                                                    <option key={cls.id} value={cls.id}>
                                                                        {cls.class_name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            <p className="text-xs text-gray-500 mt-1">
                                                                Hold Ctrl (Cmd on Mac) to select multiple
                                                            </p>
                                                        </div>

                                                        {/* Categories */}
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                Categories
                                                            </label>
                                                            <select
                                                                multiple
                                                                value={selectedCategoryIds.map(String)}
                                                                onChange={(e) => {
                                                                    const selected = Array.from((e.target as HTMLSelectElement).selectedOptions).map(opt => parseInt(opt.value));
                                                                    setSelectedCategoryIds(selected);
                                                                }}
                                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue h-32"
                                                            >
                                                                {categories.map((category) => (
                                                                    <option key={category.id} value={category.id}>
                                                                        {category.parent_name ? `${category.parent_name} > ` : ''}{category.category_name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            <p className="text-xs text-gray-500 mt-1">
                                                                Hold Ctrl (Cmd on Mac) to select multiple
                                                            </p>
                                                        </div>

                                                        {/* Labels */}
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                Labels
                                                            </label>
                                                            <select
                                                                multiple
                                                                value={selectedLabelIds.map(String)}
                                                                onChange={(e) => {
                                                                    const selected = Array.from((e.target as HTMLSelectElement).selectedOptions).map(opt => parseInt(opt.value));
                                                                    setSelectedLabelIds(selected);
                                                                }}
                                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue h-32"
                                                            >
                                                                {labels.map((label) => (
                                                                    <option key={label.id} value={label.id}>
                                                                        {label.label_name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            <p className="text-xs text-gray-500 mt-1">
                                                                Hold Ctrl (Cmd on Mac) to select multiple
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="flex gap-2 mt-4">
                                                <button
                                                    type="submit"
                                                    disabled={loadingSignal.value}
                                                    className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors disabled:opacity-50"
                                                >
                                                    {loadingSignal.value ? 'Saving...' : 'Save'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={handleCancelResource}
                                                    className="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400 transition-colors"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </>
                            ) : (
                                <>
                                    {/* Header with Add Button */}
                                    <div className="flex justify-between items-center mb-4">
                                        <h2 className="text-xl font-semibold text-gray-800">Library Resources</h2>
                                        <button
                                            onClick={handleAddResource}
                                            className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors"
                                            disabled={authorsSignal.value.length === 0}
                                        >
                                            <i className="fa fa-plus mr-2"></i>
                                            Add Resource
                                        </button>
                                    </div>

                                    {authorsSignal.value.length === 0 && (
                                        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                                            <p className="text-yellow-700">
                                                <i className="fa fa-exclamation-triangle mr-2"></i>
                                                Please add at least one author before creating resources.
                                                Switch to the "Authors" tab to add authors.
                                            </p>
                                        </div>
                                    )}

                                    {/* Table View */}
                                    {resourcesSignal.value.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse">
                                        <thead>
                                            <tr className="bg-gray-50 border-b border-gray-200">
                                                <th className="text-left py-3 px-4 font-medium text-gray-700">ID</th>
                                                <th className="text-left py-3 px-4 font-medium text-gray-700">Title</th>
                                                <th className="text-left py-3 px-4 font-medium text-gray-700">ISBN</th>
                                                <th className="text-left py-3 px-4 font-medium text-gray-700">Author</th>
                                                <th className="text-left py-3 px-4 font-medium text-gray-700">Created</th>
                                                <th className="text-right py-3 px-4 font-medium text-gray-700">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {resourcesSignal.value.map((resource) => (
                                                <tr key={resource.id} className="border-b border-gray-200 hover:bg-gray-50">
                                                    <td className="py-3 px-4 text-gray-600">{resource.id}</td>
                                                    <td className="py-3 px-4 text-gray-900 font-medium">{resource.title}</td>
                                                    <td className="py-3 px-4 text-gray-600 text-sm">{resource.isbn || '-'}</td>
                                                    <td className="py-3 px-4 text-gray-700">{getAuthorName(resource.author_id)}</td>
                                                    <td className="py-3 px-4 text-gray-600 text-sm">{formatDate(resource.created_at)}</td>
                                                    <td className="py-3 px-4"><div className="flex gap-2 justify-end">
                                                        <button
                                                            onClick={() => handleEditResource(resource)}
                                                            className="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition-colors text-sm"
                                                        >
                                                            <i className="fa fa-edit mr-1"></i>
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDeleteResource(resource)}
                                                            className="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600 transition-colors text-sm"
                                                        >
                                                            <i className="fa fa-trash mr-1"></i>
                                                            Delete
                                                        </button>
                                                    </div></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                    ) : (
                                        <div className="text-center py-8 text-gray-500">
                                            <i className="fa fa-book text-4xl mb-4 text-gray-300"></i>
                                            <p>No resources found. Click "Add Resource" to create one.</p>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    )}

                    {/* Authors Tab Content */}
                    {activeTab === 'authors' && (
                        <div className="bg-white rounded-lg shadow p-3 lg:p-6">
                            {showAuthorForm ? (
                                <>
                                    {/* Back Button */}
                                    <button
                                        onClick={handleCancelAuthor}
                                        className="flex items-center text-gray-600 hover:text-gray-900 mb-4 transition-colors"
                                    >
                                        <i className="fa fa-arrow-left mr-2"></i>
                                        Back to Authors
                                    </button>

                                    {/* Form Only */}
                                    <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                                        <h3 className="text-lg font-medium text-gray-900 mb-4">
                                            {editingAuthor ? 'Edit Author' : 'Add Author'}
                                        </h3>
                                        <form onSubmit={handleSubmitAuthor}>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        First Name <span className="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        type="text"
                                                        value={authorFormData.first_name}
                                                        onInput={(e) => setAuthorFormData({ ...authorFormData, first_name: (e.target as HTMLInputElement).value })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                        placeholder="First name"
                                                        required
                                                    />
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        Last Name <span className="text-red-500">*</span>
                                                    </label>
                                                    <input
                                                        type="text"
                                                        value={authorFormData.last_name}
                                                        onInput={(e) => setAuthorFormData({ ...authorFormData, last_name: (e.target as HTMLInputElement).value })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                        placeholder="Last name"
                                                        required
                                                    />
                                                </div>

                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                                        Bio
                                                    </label>
                                                    <textarea
                                                        value={authorFormData.bio}
                                                        onInput={(e) => setAuthorFormData({ ...authorFormData, bio: (e.target as HTMLTextAreaElement).value })}
                                                        className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                        placeholder="Author biography (optional)"
                                                        rows={3}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex gap-2 mt-4">
                                                <button
                                                    type="submit"
                                                    disabled={loadingSignal.value}
                                                    className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors disabled:opacity-50"
                                                >
                                                    {loadingSignal.value ? 'Saving...' : 'Save'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={handleCancelAuthor}
                                                    className="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400 transition-colors"
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </>
                            ) : (
                                <>
                                    {/* Header with Add Button */}
                                    <div className="flex justify-between items-center mb-4">
                                        <h2 className="text-xl font-semibold text-gray-800">Authors</h2>
                                        <button
                                            onClick={handleAddAuthor}
                                            className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors"
                                        >
                                            <i className="fa fa-plus mr-2"></i>
                                            Add Author
                                        </button>
                                    </div>

                                    {/* Table View */}
                                    {authorsSignal.value.length > 0 ? (
                                        <div className="overflow-x-auto">
                                            <table className="w-full border-collapse">
                                                <thead>
                                                    <tr className="bg-gray-50 border-b border-gray-200">
                                                        <th className="text-left py-3 px-4 font-medium text-gray-700">ID</th>
                                                        <th className="text-left py-3 px-4 font-medium text-gray-700">Name</th>
                                                        <th className="text-left py-3 px-4 font-medium text-gray-700">Bio</th>
                                                        <th className="text-right py-3 px-4 font-medium text-gray-700">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {authorsSignal.value.map((author) => (
                                                        <tr key={author.id} className="border-b border-gray-200 hover:bg-gray-50">
                                                            <td className="py-3 px-4 text-gray-600">{author.id}</td>
                                                            <td className="py-3 px-4 text-gray-900 font-medium">
                                                                {author.first_name} {author.last_name}
                                                            </td>
                                                            <td className="py-3 px-4 text-gray-600 text-sm">
                                                                {author.bio ? (
                                                                    <span className="line-clamp-2">{author.bio}</span>
                                                                ) : '-'}
                                                            </td>
                                                            <td className="py-3 px-4"><div className="flex gap-2 justify-end">
                                                                <button
                                                                    onClick={() => handleEditAuthor(author)}
                                                                    className="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition-colors text-sm"
                                                                >
                                                                    <i className="fa fa-edit mr-1"></i>
                                                                    Edit
                                                                </button>
                                                                <button
                                                                    onClick={() => handleDeleteAuthor(author)}
                                                                    className="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600 transition-colors text-sm"
                                                                >
                                                                    <i className="fa fa-trash mr-1"></i>
                                                                    Delete
                                                                </button>
                                                            </div></td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : (
                                        <div className="text-center py-8 text-gray-500">
                                            <i className="fa fa-user text-4xl mb-4 text-gray-300"></i>
                                            <p>No authors found. Click "Add Author" to create one.</p>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    )}
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
