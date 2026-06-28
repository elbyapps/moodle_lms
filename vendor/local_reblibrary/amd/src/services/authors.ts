/**
 * Author web service client.
 * Provides methods to interact with local_reblibrary author web services.
 *
 * @module local_reblibrary/services/authors
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Declare AMD module types for TypeScript.
declare const require: any;

export interface Author {
    id: number;
    first_name: string;
    last_name: string;
    bio?: string;
}

export interface CreateAuthorData {
    first_name: string;
    last_name: string;
    bio?: string;
}

export interface UpdateAuthorData {
    first_name?: string;
    last_name?: string;
    bio?: string;
}

/**
 * Author service for CRUD operations.
 */
export const AuthorService = {
    /**
     * Get all authors.
     *
     * @returns Promise resolving to array of authors
     */
    getAll: (): Promise<Author[]> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_all_authors',
                    args: {}
                }])[0]
                    .then((data: Author[]) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Get author by ID.
     *
     * @param id Author ID
     * @returns Promise resolving to author data
     */
    getById: (id: number): Promise<Author> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_author_by_id',
                    args: { id }
                }])[0]
                    .then((data: Author) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Create a new author.
     *
     * @param data Author data
     * @returns Promise resolving to created author
     */
    create: (data: CreateAuthorData): Promise<Author> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_create_author',
                    args: {
                        first_name: data.first_name,
                        last_name: data.last_name,
                        bio: data.bio
                    }
                }])[0]
                    .then((result: Author) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Update an author.
     *
     * @param id Author ID
     * @param data Partial author data to update
     * @returns Promise resolving to updated author
     */
    update: (id: number, data: UpdateAuthorData): Promise<Author> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_update_author',
                    args: {
                        id,
                        ...data
                    }
                }])[0]
                    .then((result: Author) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Delete an author.
     *
     * @param id Author ID
     * @returns Promise resolving to success status
     */
    delete: (id: number): Promise<{ success: boolean }> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_delete_author',
                    args: { id }
                }])[0]
                    .then((result: { success: boolean }) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    }
};
