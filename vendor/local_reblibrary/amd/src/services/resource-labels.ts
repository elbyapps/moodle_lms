/**
 * Resource labels service for label assignment management.
 * Handles fetching labels and managing resource-label assignments.
 *
 * @module local_reblibrary/services/resource-labels
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Declare AMD module types for TypeScript
declare const require: any;

/**
 * Resource label assignments
 */
export interface ResourceLabelAssignments {
  label_ids: number[];
}

/**
 * Get labels assigned to a resource.
 *
 * @param resourceId Resource ID
 * @returns Promise resolving to resource label assignments
 */
export async function getResourceLabels(resourceId: number): Promise<ResourceLabelAssignments> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_get_resource_labels',
        args: { resource_id: resourceId }
      }])[0]
        .then((data: ResourceLabelAssignments) => resolve(data))
        .catch((error: any) => {
          console.error('Failed to fetch resource labels:', error);
          reject(new Error(error.message || 'Failed to fetch resource labels'));
        });
    });
  });
}

/**
 * Assign labels to a resource.
 *
 * @param resourceId Resource ID
 * @param labelIds Array of label IDs
 * @returns Promise resolving when assignment completes
 */
export async function assignLabels(resourceId: number, labelIds: number[]): Promise<void> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_assign_labels',
        args: { resource_id: resourceId, label_ids: labelIds }
      }])[0]
        .then(() => resolve())
        .catch((error: any) => {
          console.error('Failed to assign labels:', error);
          reject(new Error(error.message || 'Failed to assign labels'));
        });
    });
  });
}
