/**
 * Education structure web service client.
 * Provides methods to interact with local_reblibrary education structure web services.
 *
 * @module local_reblibrary/services/edu-structure
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Declare AMD module types for TypeScript.
declare const require: any;

// ============================================
// TypeScript Interfaces
// ============================================

export interface EduLevel {
    id: number;
    level_name: string;
    sortorder: number;
}

export interface EduSublevel {
    id: number;
    sublevel_name: string;
    level_id: number;
    sortorder: number;
}

export interface EduClass {
    id: number;
    class_name: string;
    class_code: string;
    sublevel_id: number;
    sortorder: number;
}

export interface EduSection {
    id: number;
    section_name: string;
    section_code: string;
    sublevel_id: number;
    sortorder: number;
}

export interface ReorderItem {
    id: number;
    sortorder: number;
}

export interface ReorderResult {
    success: boolean;
    updated: number;
}

// Shared helper that wraps an AJAX call to one of the reorder web services.
function callReorder(methodname: string, items: ReorderItem[]): Promise<ReorderResult> {
    return new Promise((resolve, reject) => {
        require(['core/ajax'], (ajax: any) => {
            ajax.call([{
                methodname,
                args: { items }
            }])[0]
                .then((result: ReorderResult) => resolve(result))
                .catch((error: any) => reject(error));
        });
    });
}

export interface CreateLevelData {
    level_name: string;
}

export interface CreateSublevelData {
    sublevel_name: string;
    level_id: number;
}

export interface CreateClassData {
    class_name: string;
    class_code: string;
    sublevel_id: number;
}

export interface CreateSectionData {
    section_name: string;
    section_code: string;
    sublevel_id: number;
}

// ============================================
// Education Levels Service
// ============================================

export const LevelService = {
    /**
     * Get all education levels.
     */
    getAll: (): Promise<EduLevel[]> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_all_levels',
                    args: {}
                }])[0]
                    .then((data: EduLevel[]) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Create a new education level.
     */
    create: (data: CreateLevelData): Promise<EduLevel> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_create_level',
                    args: {
                        level_name: data.level_name
                    }
                }])[0]
                    .then((result: EduLevel) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Update an education level.
     */
    update: (id: number, data: CreateLevelData): Promise<EduLevel> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_update_level',
                    args: {
                        id,
                        level_name: data.level_name
                    }
                }])[0]
                    .then((result: EduLevel) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Delete an education level.
     */
    delete: (id: number): Promise<{ success: boolean }> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_delete_level',
                    args: { id }
                }])[0]
                    .then((result: { success: boolean }) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Persist a new sort order for education levels.
     */
    reorder: (items: ReorderItem[]): Promise<ReorderResult> => {
        return callReorder('local_reblibrary_reorder_levels', items);
    }
};

// ============================================
// Education Sublevels Service
// ============================================

export const SublevelService = {
    /**
     * Get all education sublevels.
     */
    getAll: (): Promise<EduSublevel[]> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_all_sublevels',
                    args: {}
                }])[0]
                    .then((data: EduSublevel[]) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Create a new education sublevel.
     */
    create: (data: CreateSublevelData): Promise<EduSublevel> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_create_sublevel',
                    args: {
                        sublevel_name: data.sublevel_name,
                        level_id: data.level_id
                    }
                }])[0]
                    .then((result: EduSublevel) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Update an education sublevel.
     */
    update: (id: number, data: CreateSublevelData): Promise<EduSublevel> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_update_sublevel',
                    args: {
                        id,
                        sublevel_name: data.sublevel_name,
                        level_id: data.level_id
                    }
                }])[0]
                    .then((result: EduSublevel) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Delete an education sublevel.
     */
    delete: (id: number): Promise<{ success: boolean }> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_delete_sublevel',
                    args: { id }
                }])[0]
                    .then((result: { success: boolean }) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Persist a new sort order for sublevels. Sortorder is scoped per parent level on the server.
     */
    reorder: (items: ReorderItem[]): Promise<ReorderResult> => {
        return callReorder('local_reblibrary_reorder_sublevels', items);
    }
};

// ============================================
// Classes Service
// ============================================

export const ClassService = {
    /**
     * Get all classes.
     */
    getAll: (): Promise<EduClass[]> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_all_classes',
                    args: {}
                }])[0]
                    .then((data: EduClass[]) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Create a new class.
     */
    create: (data: CreateClassData): Promise<EduClass> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_create_class',
                    args: {
                        class_name: data.class_name,
                        class_code: data.class_code,
                        sublevel_id: data.sublevel_id
                    }
                }])[0]
                    .then((result: EduClass) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Update a class.
     */
    update: (id: number, data: CreateClassData): Promise<EduClass> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_update_class',
                    args: {
                        id,
                        class_name: data.class_name,
                        class_code: data.class_code,
                        sublevel_id: data.sublevel_id
                    }
                }])[0]
                    .then((result: EduClass) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Delete a class.
     */
    delete: (id: number): Promise<{ success: boolean }> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_delete_class',
                    args: { id }
                }])[0]
                    .then((result: { success: boolean }) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Persist a new sort order for classes. Sortorder is scoped per parent sublevel on the server.
     */
    reorder: (items: ReorderItem[]): Promise<ReorderResult> => {
        return callReorder('local_reblibrary_reorder_classes', items);
    }
};

// ============================================
// Sections Service
// ============================================

export const SectionService = {
    /**
     * Get all sections.
     */
    getAll: (): Promise<EduSection[]> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_get_all_sections',
                    args: {}
                }])[0]
                    .then((data: EduSection[]) => resolve(data))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Create a new section.
     */
    create: (data: CreateSectionData): Promise<EduSection> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_create_section',
                    args: {
                        section_name: data.section_name,
                        section_code: data.section_code,
                        sublevel_id: data.sublevel_id
                    }
                }])[0]
                    .then((result: EduSection) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Update a section.
     */
    update: (id: number, data: CreateSectionData): Promise<EduSection> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_update_section',
                    args: {
                        id,
                        section_name: data.section_name,
                        section_code: data.section_code,
                        sublevel_id: data.sublevel_id
                    }
                }])[0]
                    .then((result: EduSection) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Delete a section.
     */
    delete: (id: number): Promise<{ success: boolean }> => {
        return new Promise((resolve, reject) => {
            require(['core/ajax'], (ajax: any) => {
                ajax.call([{
                    methodname: 'local_reblibrary_delete_section',
                    args: { id }
                }])[0]
                    .then((result: { success: boolean }) => resolve(result))
                    .catch((error: any) => reject(error));
            });
        });
    },

    /**
     * Persist a new sort order for sections. Sortorder is scoped per parent sublevel on the server.
     */
    reorder: (items: ReorderItem[]): Promise<ReorderResult> => {
        return callReorder('local_reblibrary_reorder_sections', items);
    }
};
