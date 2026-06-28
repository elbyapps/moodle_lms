/**
 * Service for category management API calls.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

export interface Category {
    id: number;
    category_name: string;
    parent_category_id: number | null;
    parent_name?: string;
    description: string;
}

export interface CreateCategoryData {
    category_name: string;
    parent_category_id?: number | null;
    description?: string;
}

export interface UpdateCategoryData {
    category_name: string;
    parent_category_id?: number | null;
    description?: string;
}

/**
 * Category service for CRUD operations.
 */
export class CategoryService {
    /**
     * Get all categories with parent information.
     */
    static async getAll(): Promise<Category[]> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_get_all_categories_with_parent',
            args: {},
        }])[0];

        return response as Category[];
    }

    /**
     * Create a new category.
     */
    static async create(data: CreateCategoryData): Promise<Category> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_create_category',
            args: {
                category_name: data.category_name,
                parent_category_id: data.parent_category_id ?? null,
                description: data.description ?? '',
            },
        }])[0];

        return response as Category;
    }

    /**
     * Update an existing category.
     */
    static async update(id: number, data: UpdateCategoryData): Promise<Category> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_update_category',
            args: {
                id,
                category_name: data.category_name,
                parent_category_id: data.parent_category_id ?? null,
                description: data.description ?? '',
            },
        }])[0];

        return response as Category;
    }

    /**
     * Delete a category.
     */
    static async delete(id: number): Promise<{ success: boolean }> {
        const response = await Ajax.call([{
            methodname: 'local_reblibrary_delete_category',
            args: { id },
        }])[0];

        return response as { success: boolean };
    }
}
