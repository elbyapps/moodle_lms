/**
 * Resource web service client.
 * Provides methods to interact with local_reblibrary resource web services.
 *
 * @module local_reblibrary/services/resources
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Declare AMD module types for TypeScript.
declare const require: any;

export interface Resource {
    id: number | string;
    title: string;
    isbn?: string;
    author_id: number;
    author_name?: string;
    description?: string;
    cover_image_url?: string;
    file_url?: string;
    visible?: number;
    media_type?: string;
    created_at: number;
    class_ids?: (number | string)[];
    category_ids?: (number | string)[];
    label_ids?: (number | string)[];
}

export interface CreateResourceData {
    title: string;
    isbn?: string;
    author_id: number;
    description?: string;
    cover_image_url?: string;
    file_url?: string;
    visible?: number;
    media_type?: string;
}

export interface UpdateResourceData {
    title?: string;
    isbn?: string;
    author_id?: number;
    description?: string;
    cover_image_url?: string;
    file_url?: string;
    visible?: number;
    media_type?: string;
}

export interface ResourceFilters {
    searchQuery?: string;
    levelId?: number;
    sublevelId?: number;
    classId?: number;
    categoryId?: number;
    labelId?: number;
    pageContext?: string;
}

/**
 * Resource service for CRUD operations.
 */
export const ResourceService = {
    /**
     * Get all resources with optional filters.
     *
     * @param filters Optional filters for search and filtering
     * @returns Promise resolving to array of resources
     */
    getAll: (filters?: ResourceFilters): Promise<Resource[]> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_all_resources',
                    args: {
                        search_query: filters?.searchQuery || '',
                        level_id: filters?.levelId || 0,
                        sublevel_id: filters?.sublevelId || 0,
                        class_id: filters?.classId || 0,
                        category_id: filters?.categoryId || 0,
                        label_id: filters?.labelId || 0,
                        page_context: filters?.pageContext || 'home',
                    }
                }])[0]
                    .then((data: Resource[]) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Get resource by ID.
     *
     * @param id Resource ID
     * @returns Promise resolving to resource data
     */
    getById: (id: number): Promise<Resource> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_resource_by_id',
                    args: { id }
                }])[0]
                    .then((data: Resource) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Create a new resource.
     *
     * @param data Resource data
     * @returns Promise resolving to created resource
     */
    create: (data: CreateResourceData): Promise<Resource> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_create_resource',
                    args: {
                        title: data.title,
                        isbn: data.isbn,
                        author_id: data.author_id,
                        description: data.description,
                        cover_image_url: data.cover_image_url,
                        file_url: data.file_url,
                        visible: data.visible ?? 1,
                        media_type: data.media_type ?? 'text'
                    }
                }])[0]
                    .then((result: Resource) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Update a resource.
     *
     * @param id Resource ID
     * @param data Partial resource data to update
     * @returns Promise resolving to updated resource
     */
    update: (id: number, data: UpdateResourceData): Promise<Resource> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_update_resource',
                    args: {
                        id,
                        ...data
                    }
                }])[0]
                    .then((result: Resource) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Delete a resource.
     *
     * @param id Resource ID
     * @returns Promise resolving to success status
     */
    delete: (id: number): Promise<{ success: boolean }> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_delete_resource',
                    args: { id }
                }])[0]
                    .then((result: { success: boolean }) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    }
};
