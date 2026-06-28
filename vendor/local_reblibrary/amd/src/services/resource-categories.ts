/**
 * Resource categories service for category assignment management.
 * Handles fetching categories and managing resource-category assignments.
 *
 * @module local_reblibrary/services/resource-categories
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Declare AMD module types for TypeScript
declare const require: any;

/**
 * Category with parent information
 */
export interface CategoryWithParent {
  id: number;
  category_name: string;
  parent_category_id?: number;
  parent_name?: string;
  description?: string;
}

/**
 * Resource category assignments
 */
export interface ResourceCategoryAssignments {
  category_ids: number[];
}

/**
 * Get all categories with parent information.
 *
 * @returns Promise resolving to array of categories
 */
export async function getAllCategories(): Promise<CategoryWithParent[]> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_get_all_categories_with_parent',
        args: {}
      }])[0]
        .then((data: CategoryWithParent[]) => resolve(data))
        .catch((error: any) => {
          console.error('Failed to fetch categories:', error);
          reject(new Error(error.message || 'Failed to fetch categories'));
        });
    });
  });
}

/**
 * Get categories assigned to a resource.
 *
 * @param resourceId Resource ID
 * @returns Promise resolving to resource category assignments
 */
export async function getResourceCategories(resourceId: number): Promise<ResourceCategoryAssignments> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_get_resource_categories',
        args: { resource_id: resourceId }
      }])[0]
        .then((data: ResourceCategoryAssignments) => resolve(data))
        .catch((error: any) => {
          console.error('Failed to fetch resource categories:', error);
          reject(new Error(error.message || 'Failed to fetch resource categories'));
        });
    });
  });
}

/**
 * Assign categories to a resource.
 *
 * @param resourceId Resource ID
 * @param categoryIds Array of category IDs
 * @returns Promise resolving when assignment completes
 */
export async function assignCategories(resourceId: number, categoryIds: number[]): Promise<void> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_assign_categories',
        args: { resource_id: resourceId, category_ids: categoryIds }
      }])[0]
        .then(() => resolve())
        .catch((error: any) => {
          console.error('Failed to assign categories:', error);
          reject(new Error(error.message || 'Failed to assign categories'));
        });
    });
  });
}
