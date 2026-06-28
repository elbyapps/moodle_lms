import { h } from "preact";
import { useState, useEffect } from "preact/hooks";
import { signal } from "@preact/signals";
import type { Category } from "../../services/categories";
import { CategoryService, CreateCategoryData } from "../../services/categories";
import type { Label } from "../../services/labels";
import { LabelService, CreateLabelData } from "../../services/labels";
import Sidebar from "../shared/Sidebar";
import Toast from "../shared/Toast";
import { getAdminMenuItems } from "../../config/admin-menu";
import { getLibraryMenuItems } from "../../config/library-menu";

// Signals for state management
export const categoriesSignal = signal<Category[]>([]);
export const labelsSignal = signal<Label[]>([]);
export const loadingSignal = signal<boolean>(false);
export const errorSignal = signal<string | null>(null);
export const successSignal = signal<string | null>(null);

interface CategoriesProps {
    initialCategories: Category[];
    initialLabels: Label[];
    eduLevels?: import('../../types').EducationLevel[];
    eduSublevels?: import('../../types').EducationSublevel[];
    eduClasses?: import('../../types').EducationClass[];
}

type Tab = 'categories' | 'labels';

export default function Categories({
    initialCategories,
    initialLabels,
    eduLevels = [],
    eduSublevels = [],
    eduClasses = []
}: CategoriesProps) {
    // Initialize signals with data from PHP
    useEffect(() => {
        categoriesSignal.value = initialCategories;
        labelsSignal.value = initialLabels;
    }, [initialCategories, initialLabels]);

    // Menu items
    const libraryMenuItems = getLibraryMenuItems();
    const adminMenuItems = getAdminMenuItems('categories');

    // Tab state
    const [activeTab, setActiveTab] = useState<Tab>('categories');

    // Category state
    const [showForm, setShowForm] = useState(false);
    const [editingCategory, setEditingCategory] = useState<Category | null>(null);
    const [formData, setFormData] = useState<CreateCategoryData>({
        category_name: '',
        parent_category_id: null,
        description: '',
    });

    // Label state
    const [showLabelForm, setShowLabelForm] = useState(false);
    const [editingLabel, setEditingLabel] = useState<Label | null>(null);
    const [labelFormData, setLabelFormData] = useState<CreateLabelData>({
        label_name: '',
        description: '',
    });

    const handleAdd = () => {
        setEditingCategory(null);
        setFormData({ category_name: '', parent_category_id: null, description: '' });
        setShowForm(true);
    };

    const handleEdit = (category: Category) => {
        setEditingCategory(category);
        setFormData({
            category_name: category.category_name,
            parent_category_id: category.parent_category_id,
            description: category.description,
        });
        setShowForm(true);
    };

    const handleCancel = () => {
        setShowForm(false);
        setEditingCategory(null);
        setFormData({ category_name: '', parent_category_id: null, description: '' });
    };

    const handleSubmit = async (e: Event) => {
        e.preventDefault();

        if (!formData.category_name.trim()) {
            errorSignal.value = 'Category name is required';
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            if (editingCategory) {
                const updated = await CategoryService.update(editingCategory.id, formData);
                categoriesSignal.value = categoriesSignal.value.map(c =>
                    c.id === updated.id ? updated : c
                );
                successSignal.value = 'Category updated successfully';
            } else {
                const created = await CategoryService.create(formData);
                categoriesSignal.value = [...categoriesSignal.value, created].sort((a, b) =>
                    a.category_name.localeCompare(b.category_name)
                );
                successSignal.value = 'Category created successfully';
            }
            handleCancel();
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const handleDelete = async (category: Category) => {
        if (!confirm(`Are you sure you want to delete "${category.category_name}"?`)) {
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            await CategoryService.delete(category.id);
            categoriesSignal.value = categoriesSignal.value.filter(c => c.id !== category.id);
            successSignal.value = 'Category deleted successfully';
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const getCategoryName = (categoryId: number | null) => {
        if (!categoryId) return 'None (Top-level)';
        return categoriesSignal.value.find(c => c.id === categoryId)?.category_name || `ID: ${categoryId}`;
    };

    // Label handlers
    const handleAddLabel = () => {
        setEditingLabel(null);
        setLabelFormData({ label_name: '', description: '' });
        setShowLabelForm(true);
    };

    const handleEditLabel = (label: Label) => {
        setEditingLabel(label);
        setLabelFormData({
            label_name: label.label_name,
            description: label.description,
        });
        setShowLabelForm(true);
    };

    const handleCancelLabel = () => {
        setShowLabelForm(false);
        setEditingLabel(null);
        setLabelFormData({ label_name: '', description: '' });
    };

    const handleSubmitLabel = async (e: Event) => {
        e.preventDefault();

        if (!labelFormData.label_name.trim()) {
            errorSignal.value = 'Label name is required';
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            if (editingLabel) {
                const updated = await LabelService.update(editingLabel.id, labelFormData);
                labelsSignal.value = labelsSignal.value.map(l =>
                    l.id === updated.id ? updated : l
                );
                successSignal.value = 'Label updated successfully';
            } else {
                const created = await LabelService.create(labelFormData);
                labelsSignal.value = [...labelsSignal.value, created].sort((a, b) =>
                    a.label_name.localeCompare(b.label_name)
                );
                successSignal.value = 'Label created successfully';
            }
            handleCancelLabel();
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const handleDeleteLabel = async (label: Label) => {
        if (!confirm(`Are you sure you want to delete "${label.label_name}"?`)) {
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            await LabelService.delete(label.id);
            labelsSignal.value = labelsSignal.value.filter(l => l.id !== label.id);
            successSignal.value = 'Label deleted successfully';
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
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
                        Labels & Categories Management
                    </h1>

                    {/* Tab Navigation */}
                    <div className="flex border-b border-gray-200 mb-6 overflow-x-auto">
                        <button
                            onClick={() => setActiveTab('categories')}
                            className={`px-4 lg:px-6 py-3 font-medium transition-colors whitespace-nowrap ${
                                activeTab === 'categories'
                                    ? 'border-b-2 border-reb-blue text-reb-blue'
                                    : 'text-gray-600 hover:text-gray-900'
                            }`}
                        >
                            Categories
                        </button>
                        <button
                            onClick={() => setActiveTab('labels')}
                            className={`px-4 lg:px-6 py-3 font-medium transition-colors whitespace-nowrap ${
                                activeTab === 'labels'
                                    ? 'border-b-2 border-reb-blue text-reb-blue'
                                    : 'text-gray-600 hover:text-gray-900'
                            }`}
                        >
                            Labels
                        </button>
                    </div>

                    {/* Categories Tab */}
                    {activeTab === 'categories' && (
                        <div className="bg-white rounded-lg shadow p-3 lg:p-6">
                        {showForm ? (
                            <>
                                {/* Back Button */}
                                <button
                                    onClick={handleCancel}
                                    className="flex items-center text-gray-600 hover:text-gray-900 mb-4 transition-colors"
                                >
                                    <i className="fa fa-arrow-left mr-2"></i>
                                    Back to Categories
                                </button>

                                {/* Form Only */}
                                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                                    <h3 className="text-lg font-medium text-gray-900 mb-4">
                                        {editingCategory ? 'Edit Category' : 'Add Category'}
                                    </h3>
                                    <form onSubmit={handleSubmit}>
                                        <div className="mb-4">
                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                Category Name <span className="text-red-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                value={formData.category_name}
                                                onInput={(e) => setFormData({ ...formData, category_name: (e.target as HTMLInputElement).value })}
                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                placeholder="e.g., Physics, History, Mathematics"
                                                maxLength={100}
                                                required
                                            />
                                        </div>

                                        <div className="mb-4">
                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                Parent Category
                                            </label>
                                            <select
                                                value={formData.parent_category_id ?? ''}
                                                onChange={(e) => setFormData({ ...formData, parent_category_id: (e.target as HTMLSelectElement).value ? parseInt((e.target as HTMLSelectElement).value) : null })}
                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                            >
                                                <option value="">-- None (Top-level) --</option>
                                                {categoriesSignal.value.filter(c => c.id !== editingCategory?.id).map((category) => (
                                                    <option key={category.id} value={category.id}>
                                                        {category.category_name}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="text-xs text-gray-500 mt-1">
                                                Leave empty for top-level category
                                            </p>
                                        </div>

                                        <div className="mb-4">
                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                Description
                                            </label>
                                            <textarea
                                                value={formData.description}
                                                onInput={(e) => setFormData({ ...formData, description: (e.target as HTMLTextAreaElement).value })}
                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                placeholder="Optional description of the category"
                                                rows={3}
                                            />
                                        </div>

                                        <div className="flex gap-2">
                                            <button
                                                type="submit"
                                                disabled={loadingSignal.value}
                                                className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors disabled:opacity-50"
                                            >
                                                {loadingSignal.value ? 'Saving...' : 'Save'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={handleCancel}
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
                                    <h2 className="text-xl font-semibold text-gray-800">Resource Categories</h2>
                                    <button
                                        onClick={handleAdd}
                                        className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors"
                                    >
                                        <i className="fa fa-plus mr-2"></i>
                                        Add Category
                                    </button>
                                </div>

                                {/* Table View */}
                                {categoriesSignal.value.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="w-full border-collapse">
                                            <thead>
                                                <tr className="bg-gray-50 border-b border-gray-200">
                                                    <th className="text-left py-3 px-4 font-medium text-gray-700">ID</th>
                                                    <th className="text-left py-3 px-4 font-medium text-gray-700">Category Name</th>
                                                    <th className="text-left py-3 px-4 font-medium text-gray-700">Parent Category</th>
                                                    <th className="text-left py-3 px-4 font-medium text-gray-700">Description</th>
                                                    <th className="text-right py-3 px-4 font-medium text-gray-700">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {categoriesSignal.value.map((category) => (
                                                    <tr key={category.id} className="border-b border-gray-200 hover:bg-gray-50">
                                                        <td className="py-3 px-4 text-gray-600">{category.id}</td>
                                                        <td className="py-3 px-4 text-gray-900 font-medium">{category.category_name}</td>
                                                        <td className="py-3 px-4 text-gray-700">{getCategoryName(category.parent_category_id)}</td>
                                                        <td className="py-3 px-4 text-gray-600 text-sm">{category.description || '-'}</td>
                                                        <td className="py-3 px-4">
                                                            <div className="flex gap-2 justify-end">
                                                                <button
                                                                    onClick={() => handleEdit(category)}
                                                                    className="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition-colors text-sm"
                                                                >
                                                                    <i className="fa fa-edit mr-1"></i>
                                                                    Edit
                                                                </button>
                                                                <button
                                                                    onClick={() => handleDelete(category)}
                                                                    className="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600 transition-colors text-sm"
                                                                >
                                                                    <i className="fa fa-trash mr-1"></i>
                                                                    Delete
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="text-center py-8 text-gray-500">
                                        <i className="fa fa-tags text-4xl mb-4 text-gray-300"></i>
                                        <p>No categories defined yet. Click "Add Category" to create one.</p>
                                    </div>
                                )}
                            </>
                        )}
                        </div>
                    )}

                    {/* Labels Tab */}
                    {activeTab === 'labels' && (
                        <div className="bg-white rounded-lg shadow p-3 lg:p-6">
                        {showLabelForm ? (
                            <>
                                {/* Back Button */}
                                <button
                                    onClick={handleCancelLabel}
                                    className="flex items-center text-gray-600 hover:text-gray-900 mb-4 transition-colors"
                                >
                                    <i className="fa fa-arrow-left mr-2"></i>
                                    Back to Labels
                                </button>

                                {/* Form Only */}
                                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                                    <h3 className="text-lg font-medium text-gray-900 mb-4">
                                        {editingLabel ? 'Edit Label' : 'Add Label'}
                                    </h3>
                                    <form onSubmit={handleSubmitLabel}>
                                        <div className="mb-4">
                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                Label Name <span className="text-red-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                value={labelFormData.label_name}
                                                onInput={(e) => setLabelFormData({ ...labelFormData, label_name: (e.target as HTMLInputElement).value })}
                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                placeholder="e.g., Recommended, Popular, Featured"
                                                maxLength={100}
                                                required
                                            />
                                        </div>

                                        <div className="mb-4">
                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                Description
                                            </label>
                                            <textarea
                                                value={labelFormData.description}
                                                onInput={(e) => setLabelFormData({ ...labelFormData, description: (e.target as HTMLTextAreaElement).value })}
                                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                                placeholder="Optional description of the label"
                                                rows={3}
                                            />
                                        </div>

                                        <div className="flex gap-2">
                                            <button
                                                type="submit"
                                                disabled={loadingSignal.value}
                                                className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors disabled:opacity-50"
                                            >
                                                {loadingSignal.value ? 'Saving...' : 'Save'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={handleCancelLabel}
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
                                    <h2 className="text-xl font-semibold text-gray-800">Resource Labels</h2>
                                    <button
                                        onClick={handleAddLabel}
                                        className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors"
                                    >
                                        <i className="fa fa-plus mr-2"></i>
                                        Add Label
                                    </button>
                                </div>

                                {/* Table View */}
                                {labelsSignal.value.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="w-full border-collapse">
                                            <thead>
                                                <tr className="bg-gray-50 border-b border-gray-200">
                                                    <th className="text-left py-3 px-4 font-medium text-gray-700">ID</th>
                                                    <th className="text-left py-3 px-4 font-medium text-gray-700">Label Name</th>
                                                    <th className="text-left py-3 px-4 font-medium text-gray-700">Description</th>
                                                    <th className="text-right py-3 px-4 font-medium text-gray-700">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {labelsSignal.value.map((label) => (
                                                    <tr key={label.id} className="border-b border-gray-200 hover:bg-gray-50">
                                                        <td className="py-3 px-4 text-gray-600">{label.id}</td>
                                                        <td className="py-3 px-4 text-gray-900 font-medium">{label.label_name}</td>
                                                        <td className="py-3 px-4 text-gray-600 text-sm">{label.description || '-'}</td>
                                                        <td className="py-3 px-4">
                                                            <div className="flex gap-2 justify-end">
                                                                <button
                                                                    onClick={() => handleEditLabel(label)}
                                                                    className="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition-colors text-sm"
                                                                >
                                                                    <i className="fa fa-edit mr-1"></i>
                                                                    Edit
                                                                </button>
                                                                <button
                                                                    onClick={() => handleDeleteLabel(label)}
                                                                    className="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600 transition-colors text-sm"
                                                                >
                                                                    <i className="fa fa-trash mr-1"></i>
                                                                    Delete
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="text-center py-8 text-gray-500">
                                        <i className="fa fa-tag text-4xl mb-4 text-gray-300"></i>
                                        <p>No labels defined yet. Click "Add Label" to create one.</p>
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
