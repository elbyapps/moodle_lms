import { h } from "preact";
import { useState } from "preact/hooks";
import { SublevelService } from "../../services/edu-structure";
import type { EduSublevel, CreateSublevelData } from "../../services/edu-structure";
import { levelsSignal, sublevelsSignal, loadingSignal, errorSignal, successSignal } from "./EdStructure";
import { useDragSort } from "../shared/useDragSort";

export default function SublevelTab() {
    const [showForm, setShowForm] = useState(false);
    const [editingSublevel, setEditingSublevel] = useState<EduSublevel | null>(null);
    const [formData, setFormData] = useState<CreateSublevelData>({
        sublevel_name: '',
        level_id: 0
    });

    const handleAdd = () => {
        setEditingSublevel(null);
        setFormData({ sublevel_name: '', level_id: levelsSignal.value[0]?.id || 0 });
        setShowForm(true);
    };

    const handleEdit = (sublevel: EduSublevel) => {
        setEditingSublevel(sublevel);
        setFormData({
            sublevel_name: sublevel.sublevel_name,
            level_id: sublevel.level_id
        });
        setShowForm(true);
    };

    const handleCancel = () => {
        setShowForm(false);
        setEditingSublevel(null);
        setFormData({ sublevel_name: '', level_id: 0 });
    };

    const handleSubmit = async (e: Event) => {
        e.preventDefault();

        if (!formData.sublevel_name.trim()) {
            errorSignal.value = 'Sublevel name is required';
            return;
        }

        if (!formData.level_id) {
            errorSignal.value = 'Please select a parent level';
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            if (editingSublevel) {
                const updated = await SublevelService.update(editingSublevel.id, formData);
                sublevelsSignal.value = sublevelsSignal.value.map(s =>
                    s.id === updated.id ? updated : s
                );
                successSignal.value = 'Sublevel updated successfully';
            } else {
                // Create — server assigns next sortorder within its parent level.
                const created = await SublevelService.create(formData);
                sublevelsSignal.value = [...sublevelsSignal.value, created];
                successSignal.value = 'Sublevel created successfully';
            }
            handleCancel();
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const handleDelete = async (sublevel: EduSublevel) => {
        if (!confirm(`Are you sure you want to delete "${sublevel.sublevel_name}"?`)) {
            return;
        }

        loadingSignal.value = true;
        errorSignal.value = null;
        successSignal.value = null;

        try {
            await SublevelService.delete(sublevel.id);
            sublevelsSignal.value = sublevelsSignal.value.filter(s => s.id !== sublevel.id);
            successSignal.value = 'Sublevel deleted successfully';
        } catch (error: any) {
            errorSignal.value = error.message || 'An error occurred';
        } finally {
            loadingSignal.value = false;
        }
    };

    const getLevelName = (levelId: number) => {
        return levelsSignal.value.find(l => l.id === levelId)?.level_name || `ID: ${levelId}`;
    };

    // Drag-and-drop reorder — scoped per parent level. Only the items in the
    // dragged row's level get their sortorder re-stamped; cross-level drops are
    // blocked by the hook's `getGroupKey` guard.
    const commitReorder = async (newOrder: EduSublevel[]) => {
        const previous = sublevelsSignal.value;

        // Find the level whose ordering actually changed (the dragged item's level).
        // Re-stamp sortorder 1..n within each level group; other groups are unaffected.
        const groups = new Map<number, EduSublevel[]>();
        for (const s of newOrder) {
            const g = groups.get(s.level_id) ?? [];
            g.push(s);
            groups.set(s.level_id, g);
        }
        const restamped: EduSublevel[] = [];
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

        sublevelsSignal.value = restamped;

        if (dirty.length === 0) return; // No-op drop.

        try {
            await SublevelService.reorder(dirty);
            successSignal.value = 'Order updated';
        } catch (error: any) {
            sublevelsSignal.value = previous;
            errorSignal.value = error.message || 'Failed to save new order';
        }
    };

    const dnd = useDragSort<EduSublevel>(sublevelsSignal.value, {
        getGroupKey: (s) => s.level_id,
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
                        Back to Sublevels
                    </button>

                    {/* Form Only */}
                    <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                        <h3 className="text-lg font-medium text-gray-900 mb-4">
                            {editingSublevel ? 'Edit Sublevel' : 'Add Sublevel'}
                        </h3>
                        <form onSubmit={handleSubmit}>
                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Sublevel Name <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={formData.sublevel_name}
                                    onInput={(e) => setFormData({ ...formData, sublevel_name: (e.target as HTMLInputElement).value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                    placeholder="e.g., Nursery, Lower Primary, A Level"
                                    maxLength={50}
                                    required
                                />
                                <p className="text-xs text-gray-500 mt-1">
                                    e.g., "Nursery", "Lower Primary", "Upper Primary", "O Level", "A Level"
                                </p>
                            </div>

                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Parent Level <span className="text-red-500">*</span>
                                </label>
                                <select
                                    value={formData.level_id}
                                    onChange={(e) => setFormData({ ...formData, level_id: parseInt((e.target as HTMLSelectElement).value) })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-reb-blue"
                                    required
                                >
                                    <option value="">-- Select Level --</option>
                                    {levelsSignal.value.map((level) => (
                                        <option key={level.id} value={level.id}>
                                            {level.level_name}
                                        </option>
                                    ))}
                                </select>
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
                        <h2 className="text-xl font-semibold text-gray-800">Education Sublevels</h2>
                        <button
                            onClick={handleAdd}
                            disabled={levelsSignal.value.length === 0}
                            className="bg-reb-blue text-white px-4 py-2 rounded-lg hover:bg-reb-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <i className="fa fa-plus mr-2"></i>
                            Add Sublevel
                        </button>
                    </div>

                    {levelsSignal.value.length === 0 && (
                        <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                            <p className="text-yellow-800">
                                <i className="fa fa-exclamation-triangle mr-2"></i>
                                Please create at least one Education Level before adding sublevels.
                            </p>
                        </div>
                    )}

                    {/* Table View */}
                    {sublevelsSignal.value.length > 0 ? (
                        <div className="overflow-x-auto">
                            <p className="text-xs text-gray-500 mb-2">
                                <i className="fa fa-info-circle mr-1"></i>
                                Drag rows within the same parent level to reorder.
                            </p>
                            <table className="w-full border-collapse">
                                <thead>
                                    <tr className="bg-gray-50 border-b border-gray-200">
                                        <th className="w-8 py-3 px-2"></th>
                                        <th className="text-left py-3 px-4 font-medium text-gray-700">ID</th>
                                        <th className="text-left py-3 px-4 font-medium text-gray-700">Sublevel Name</th>
                                        <th className="text-left py-3 px-4 font-medium text-gray-700">Parent Level</th>
                                        <th className="text-right py-3 px-4 font-medium text-gray-700">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sublevelsSignal.value.map((sublevel, index) => {
                                        const rowProps = dnd.rowProps(index);
                                        const handleProps = dnd.handleProps(index);
                                        return (
                                        <tr
                                            key={sublevel.id}
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
                                                title="Drag to reorder (within the same parent level)"
                                            >
                                                <i className="fa fa-grip-vertical"></i>
                                            </td>
                                            <td className="py-3 px-4 text-gray-600">{sublevel.id}</td>
                                            <td className="py-3 px-4 text-gray-900 font-medium">{sublevel.sublevel_name}</td>
                                            <td className="py-3 px-4 text-gray-700">{getLevelName(sublevel.level_id)}</td>
                                            <td className="py-3 px-4">
                                                <div className="flex gap-2 justify-end">
                                                    <button
                                                        onClick={() => handleEdit(sublevel)}
                                                        className="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600 transition-colors text-sm"
                                                    >
                                                        <i className="fa fa-edit mr-1"></i>
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(sublevel)}
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
                            <i className="fa fa-layer-group text-4xl mb-4 text-gray-300"></i>
                            <p>No sublevels defined yet. Click "Add Sublevel" to create one.</p>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
