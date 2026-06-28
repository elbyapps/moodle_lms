import { h } from "preact";
import { useState } from "preact/hooks";
import { SectionService } from "../../services/edu-structure";
import type { EduSection, CreateSectionData } from "../../services/edu-structure";
import { sublevelsSignal, sectionsSignal, loadingSignal, errorSignal, successSignal } from "./EdStructure";
import { useDragSort } from "../shared/useDragSort";

export default function SectionTab() {
    const [showForm, setShowForm] = useState(false);
    const [editingSection, setEditingSection] = useState<EduSection | null>(null);
    const [formData, setFormData] = useState<CreateSectionData>({
        section_name: '',
        section_code: '',
        sublevel_id: 0
    });

    const handleAdd = () => {
        setEditingSection(null);
        // Try to find "A Level" sublevel or default to first
        const aLevelSublevel = sublevelsSignal.value.find(s => s.sublevel_name.toLowerCase().includes('a level'));
        setFormData({
            section_name: '',
            section_code: '',
            sublevel_id: aLevelSublevel?.id || sublevelsSignal.value[0]?.id || 0
        });
        setShowForm(true);
    };

    const handleEdit = (section: EduSection) => {
        setEditingSection(section);
        setFormData({
            section_name: section.section_name,
            section_code: section.section_code,
            sublevel_id: section.sublevel_id
        });
        setShowForm(true);
    };

    const handleCancel = () => {
        setShowForm(false);
        setEditingSection(null);
        setFormData({ section_name: '', section_code: '', sublevel_id: 0 });
    };

    const handleSubmit = async (e: Event) => {
        e.preventDefault();

        if (!formData.section_name.trim() || !formData.section_code.trim()) {
            errorSignal.value = 'Section name and code are required';
            return;
        }

        if (!formData.sublevel_id) {
            errorSignal.value = 'Please select a parent sublevel';
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            if (editingSection) {
                const updated = await SectionService.update(editingSection.id, formData);
                sectionsSignal.value = sectionsSignal.value.map(s =>
                    s.id === updated.id ? updated : s
                );
                successSignal.value = 'Section updated successfully';
            } else {
                // Create — server assigns next sortorder within its parent sublevel.
                const created = await SectionService.create(formData);
                sectionsSignal.value = [...sectionsSignal.value, created];
                successSignal.value = 'Section created successfully';
            }
            handleCancel();
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const handleDelete = async (section: EduSection) => {
        if (!confirm(`Are you sure you want to delete "${section.section_name}"?`)) {
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            await SectionService.delete(section.id);
            sectionsSignal.value = sectionsSignal.value.filter(s => s.id !== section.id);
            successSignal.value = 'Section deleted successfully';
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const getSublevelName = (sublevelId: number) => {
        return sublevelsSignal.value.find(s => s.id === sublevelId)?.sublevel_name || `ID: ${sublevelId}`;
    };

    // Drag-and-drop reorder — scoped per parent sublevel.
    const commitReorder = async (newOrder: EduSection[]) => {
        const previous = sectionsSignal.value;

        const groups = new Map<number, EduSection[]>();
        for (const s of newOrder) {
            const g = groups.get(s.sublevel_id) ?? [];
            g.push(s);
            groups.set(s.sublevel_id, g);
        }
        const restamped: EduSection[] = [];
        const dirty: { id: number; sortorder: number }[] = [];
        for (const [, group] of groups) {
            group.forEach((item, i) => {
                const so = i + 1;
                if (item.sortorder !== so) {
                    dirty.push({ id: item.id, sortorder: so });
                }
                restamped.push({ ...item, sortorder: so });
            });
        }

        sectionsSignal.value = restamped;
        if (dirty.length === 0) return;

        try {
            await SectionService.reorder(dirty);
            successSignal.value = 'Order updated';
        } catch (error: any) {
            sectionsSignal.value = previous;
            errorSignal.value = error.message || 'Failed to save new order';
        }
    };

    const dnd = useDragSort<EduSection>(sectionsSignal.value, {
        getGroupKey: (s) => s.sublevel_id,
        onCommit: commitReorder,
    });

    return (
        <div className="p-6">
            {showForm ? (
                <>
                    {/* Back Button */}
                    <button
                        onClick={handleCancel}
                        className="flex items-center text-gray-600 hover:text-gray-900 mb-4 transition-colors"
                    >
                        <i className="fa fa-arrow-left mr-2"></i>
                        Back to Sections
                    </button>

                    {/* Form Only */}
                    <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                        <h3 className="text-lg font-medium text-gray-900 mb-4">
                            {editingSection ? 'Edit Section' : 'Add Section'}
                        </h3>
                        <form onSubmit={handleSubmit}>
                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Section Name <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={formData.section_name}
                                    onInput={(e) => setFormData({ ...formData, section_name: (e.target as HTMLInputElement).value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                    placeholder="e.g., Physics-Chemistry-Maths"
                                    maxLength={100}
                                    required
                                />
                                <p className="text-xs text-gray-500 mt-1">
                                    e.g., "Physics-Chemistry-Maths", "History-Economics-Geography"
                                </p>
                            </div>

                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Section Code <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={formData.section_code}
                                    onInput={(e) => setFormData({ ...formData, section_code: (e.target as HTMLInputElement).value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                    placeholder="e.g., PCM, HEG, BCM"
                                    maxLength={10}
                                    required
                                />
                                <p className="text-xs text-gray-500 mt-1">
                                    A short unique code, e.g., "PCM", "HEG", "BCM", "MEG"
                                </p>
                            </div>

                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Parent Sublevel <span className="text-red-500">*</span>
                                </label>
                                <select
                                    value={formData.sublevel_id}
                                    onChange={(e) => setFormData({ ...formData, sublevel_id: parseInt((e.target as HTMLSelectElement).value) })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                    required
                                >
                                    <option value="">-- Select Sublevel --</option>
                                    {sublevelsSignal.value.map((sublevel) => (
                                        <option key={sublevel.id} value={sublevel.id}>
                                            {sublevel.sublevel_name}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-gray-500 mt-1">
                                    Sections are typically linked to "A Level" sublevel
                                </p>
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
                        <div>
                            <h2 className="text-xl font-semibold text-gray-800">Sections (A-Level Combinations)</h2>
                            <p className="text-sm text-gray-600 mt-1">
                                Subject combinations for A-Level students (e.g., PCM, HEG, BCM)
                            </p>
                        </div>
                        <button
                            onClick={handleAdd}
                            disabled={sublevelsSignal.value.length === 0}
                            className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <i className="fa fa-plus mr-2"></i>
                            Add Section
                        </button>
                    </div>

                    {/* Warning Message */}
                    {sublevelsSignal.value.length === 0 && (
                        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                            <p className="text-yellow-800">
                                <i className="fa fa-exclamation-triangle mr-2"></i>
                                Please create at least one Sublevel (ideally "A Level") before adding sections.
                            </p>
                        </div>
                    )}

                    {/* Table View */}
                    {sectionsSignal.value.length > 0 ? (
                        <div className="overflow-x-auto">
                            <p className="text-xs text-gray-500 mb-2">
                                <i className="fa fa-info-circle mr-1"></i>
                                Drag rows within the same parent sublevel to reorder.
                            </p>
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="bg-gray-50 border-b border-gray-200">
                                        <th className="w-8 py-3 px-2"></th>
                                        <th className="text-left py-3 px-4 font-medium text-gray-700">ID</th>
                                        <th className="text-left py-3 px-4 font-medium text-gray-700">Section Name</th>
                                        <th className="text-left py-3 px-4 font-medium text-gray-700">Section Code</th>
                                        <th className="text-left py-3 px-4 font-medium text-gray-700">Parent Sublevel</th>
                                        <th className="text-right py-3 px-4 font-medium text-gray-700">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sectionsSignal.value.map((section, index) => {
                                        const rowProps = dnd.rowProps(index);
                                        const handleProps = dnd.handleProps(index);
                                        return (
                                        <tr
                                            key={section.id}
                                            draggable={rowProps.draggable}
                                            onDragOver={rowProps.onDragOver}
                                            onDragLeave={rowProps.onDragLeave}
                                            onDrop={rowProps.onDrop}
                                            onDragEnd={rowProps.onDragEnd}
                                            className={`border-b border-gray-200 hover:bg-gray-50 ${rowProps.className}`}
                                        >
                                            <td
                                                draggable
                                                onDragStart={handleProps.onDragStart}
                                                onMouseDown={handleProps.onMouseDown}
                                                onMouseUp={handleProps.onMouseUp}
                                                className={`py-3 px-2 text-center ${handleProps.className}`}
                                                title="Drag to reorder (within the same parent sublevel)"
                                            >
                                                <i className="fa fa-grip-vertical"></i>
                                            </td>
                                            <td className="py-3 px-4 text-gray-600">{section.id}</td>
                                            <td className="py-3 px-4 text-gray-900 font-medium">{section.section_name}</td>
                                            <td className="py-3 px-4">
                                                <span className="bg-green-100 text-green-800 px-2 py-1 rounded text-sm font-medium">
                                                    {section.section_code}
                                                </span>
                                            </td>
                                            <td className="py-3 px-4 text-gray-700">{getSublevelName(section.sublevel_id)}</td>
                                            <td className="py-3 px-4">
                                                <div className="flex gap-2 justify-end">
                                                    <button
                                                        onClick={() => handleEdit(section)}
                                                        className="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition-colors text-sm"
                                                    >
                                                        <i className="fa fa-edit mr-1"></i>
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(section)}
                                                        className="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600 transition-colors text-sm"
                                                    >
                                                        <i className="fa fa-trash mr-1"></i>
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="text-center py-8 text-gray-500">
                            <i className="fa fa-list text-4xl mb-4 text-gray-300"></i>
                            <p>No sections defined yet. Click "Add Section" to create one.</p>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
