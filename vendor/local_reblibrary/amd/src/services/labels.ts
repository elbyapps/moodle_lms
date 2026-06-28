/**
 * Service for label management API calls.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

export interface Label {
    id: number;
    label_name: string;
    description: string;
}

export interface CreateLabelData {
    label_name: string;
    description?: string;
}

export interface UpdateLabelData {
    label_name: string;
    description?: string;
}

/**
 * Label service for CRUD operations.
 */
export class LabelService {
    /**
     * Get all labels.
     */
    static async getAll(): Promise<Label[]> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_get_all_labels',
            args: {},
        }])[0];

        return response as Label[];
    }

    /**
     * Get a single label by ID.
     */
    static async getById(id: number): Promise<Label> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_get_label_by_id',
            args: { id },
        }])[0];

        return response as Label;
    }

    /**
     * Create a new label.
     */
    static async create(data: CreateLabelData): Promise<Label> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_create_label',
            args: {
                label_name: data.label_name,
                description: data.description ?? '',
            },
        }])[0];

        return response as Label;
    }

    /**
     * Update an existing label.
     */
    static async update(id: number, data: UpdateLabelData): Promise<Label> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_update_label',
            args: {
                id,
                label_name: data.label_name,
                description: data.description ?? '',
            },
        }])[0];

        return response as Label;
    }

    /**
     * Delete a label.
     */
    static async delete(id: number): Promise<{ success: boolean }> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_delete_label',
            args: { id },
        }])[0];

        return response as { success: boolean };
    }
}
