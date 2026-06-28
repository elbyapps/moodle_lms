/**
 * Assignments service for resource assignment management.
 * Handles fetching classes/sections and managing resource assignments.
 *
 * @module local_reblibrary/services/assignments
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Declare AMD module types for TypeScript
declare const require: any;

/**
 * Class with sublevel info
 */
export interface Class {
  id: number;
  class_name: string;
  class_code: string;
  sublevel_id: number;
  sublevel_name: string;
  level_name: string;
}

/**
 * Section with sublevel info
 */
export interface Section {
  id: number;
  section_name: string;
  section_code: string;
  sublevel_id: number;
  sublevel_name: string;
  level_name: string;
}

/**
 * Resource assignments
 */
export interface ResourceAssignments {
  class_ids: number[];
  section_ids: number[];
}

/**
 * Get all classes with sublevel information.
 *
 * @returns Promise resolving to array of classes
 */
export async function getClasses(): Promise<Class[]> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_get_classes_for_assignment',
        args: {}
      }])[0]
        .then((data: Class[]) => resolve(data))
        .catch((error: any) => {
          console.error('Failed to fetch classes:', error);
          reject(new Error(error.message || 'Failed to fetch classes'));
        });
    });
  });
}

/**
 * Get all sections with sublevel information.
 *
 * @returns Promise resolving to array of sections
 */
export async function getSections(): Promise<Section[]> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_get_sections_for_assignment',
        args: {}
      }])[0]
        .then((data: Section[]) => resolve(data))
        .catch((error: any) => {
          console.error('Failed to fetch sections:', error);
          reject(new Error(error.message || 'Failed to fetch sections'));
        });
    });
  });
}

/**
 * Get current assignments for a resource.
 *
 * @param resourceId Resource ID
 * @returns Promise resolving to resource assignments
 */
export async function getResourceAssignments(resourceId: number): Promise<ResourceAssignments> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_get_resource_assignments',
        args: { resource_id: resourceId }
      }])[0]
        .then((data: ResourceAssignments) => resolve(data))
        .catch((error: any) => {
          console.error('Failed to fetch resource assignments:', error);
          reject(new Error(error.message || 'Failed to fetch resource assignments'));
        });
    });
  });
}

/**
 * Assign resource to classes.
 *
 * @param resourceId Resource ID
 * @param classIds Array of class IDs
 * @returns Promise resolving when assignment completes
 */
export async function assignToClasses(resourceId: number, classIds: number[]): Promise<void> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_assign_to_classes',
        args: { resource_id: resourceId, class_ids: classIds }
      }])[0]
        .then(() => resolve())
        .catch((error: any) => {
          console.error('Failed to assign to classes:', error);
          reject(new Error(error.message || 'Failed to assign to classes'));
        });
    });
  });
}

/**
 * Assign resource to sections.
 *
 * @param resourceId Resource ID
 * @param sectionIds Array of section IDs
 * @returns Promise resolving when assignment completes
 */
export async function assignToSections(resourceId: number, sectionIds: number[]): Promise<void> {
  return new Promise((resolve, reject) => {
    require(['core/ajax'], (ajax: any) => {
      ajax.call([{
        methodname: 'local_reblibrary_assign_to_sections',
        args: { resource_id: resourceId, section_ids: sectionIds }
      }])[0]
        .then(() => resolve())
        .catch((error: any) => {
          console.error('Failed to assign to sections:', error);
          reject(new Error(error.message || 'Failed to assign to sections'));
        });
    });
  });
}
