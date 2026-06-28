/**
 * Resource store actions.
 * Functions to load and manipulate resources, updating Preact signals.
 *
 * @module local_reblibrary/services/resource-store
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { ResourceService, Resource, CreateResourceData, UpdateResourceData } from './resources';
import { resourcesSignal, loadingSignal, errorSignal, statsSignal } from '../store';

/**
 * Load all resources from the server.
 * Updates resourcesSignal and statsSignal on success.
 */
export const loadResources = async (): Promise<void> => {
    loadingSignal.value = true;
    errorSignal.value = null;

    try {
        const data = await ResourceService.getAll();
        resourcesSignal.value = data;

        // Update stats with actual resource count
        statsSignal.value = {
            ...statsSignal.value,
            totalResources: data.length
        };

        console.log('Resources loaded:', data.length);
    } catch (error: any) {
        const errorMessage = error?.message || 'Failed to load resources';
        errorSignal.value = errorMessage;
        console.error('Error loading resources:', error);
    } finally {
        loadingSignal.value = false;
    }
};

/**
 * Load a single resource by ID.
 *
 * @param id Resource ID
 * @returns Promise resolving to resource data
 */
export const loadResourceById = async (id: number): Promise<Resource | null> => {
    loadingSignal.value = true;
    errorSignal.value = null;

    try {
        const data = await ResourceService.getById(id);
        console.log('Resource loaded:', data);
        return data;
    } catch (error: any) {
        const errorMessage = error?.message || `Failed to load resource ${id}`;
        errorSignal.value = errorMessage;
        console.error('Error loading resource:', error);
        return null;
    } finally {
        loadingSignal.value = false;
    }
};

/**
 * Create a new resource.
 * Updates resourcesSignal on success.
 *
 * @param data Resource data
 * @returns Promise resolving to created resource or null on error
 */
export const createResource = async (data: CreateResourceData): Promise<Resource | null> => {
    loadingSignal.value = true;
    errorSignal.value = null;

    try {
        const newResource = await ResourceService.create(data);

        // Add to resources list
        resourcesSignal.value = [...resourcesSignal.value, newResource];

        // Update stats
        statsSignal.value = {
            ...statsSignal.value,
            totalResources: resourcesSignal.value.length
        };

        console.log('Resource created:', newResource);
        return newResource;
    } catch (error: any) {
        const errorMessage = error?.message || 'Failed to create resource';
        errorSignal.value = errorMessage;
        console.error('Error creating resource:', error);
        return null;
    } finally {
        loadingSignal.value = false;
    }
};

/**
 * Update an existing resource.
 * Updates resourcesSignal on success.
 *
 * @param id Resource ID
 * @param data Partial resource data to update
 * @returns Promise resolving to updated resource or null on error
 */
export const updateResource = async (id: number, data: UpdateResourceData): Promise<Resource | null> => {
    loadingSignal.value = true;
    errorSignal.value = null;

    try {
        const updatedResource = await ResourceService.update(id, data);

        // Update in resources list
        resourcesSignal.value = resourcesSignal.value.map(r =>
            r.id === id ? updatedResource : r
        );

        console.log('Resource updated:', updatedResource);
        return updatedResource;
    } catch (error: any) {
        const errorMessage = error?.message || `Failed to update resource ${id}`;
        errorSignal.value = errorMessage;
        console.error('Error updating resource:', error);
        return null;
    } finally {
        loadingSignal.value = false;
    }
};

/**
 * Delete a resource.
 * Updates resourcesSignal on success.
 *
 * @param id Resource ID
 * @returns Promise resolving to success status
 */
export const deleteResource = async (id: number): Promise<boolean> => {
    loadingSignal.value = true;
    errorSignal.value = null;

    try {
        const result = await ResourceService.delete(id);

        if (result.success) {
            // Remove from resources list
            resourcesSignal.value = resourcesSignal.value.filter(r => r.id !== id);

            // Update stats
            statsSignal.value = {
                ...statsSignal.value,
                totalResources: resourcesSignal.value.length
            };

            console.log('Resource deleted:', id);
        }

        return result.success;
    } catch (error: any) {
        const errorMessage = error?.message || `Failed to delete resource ${id}`;
        errorSignal.value = errorMessage;
        console.error('Error deleting resource:', error);
        return false;
    } finally {
        loadingSignal.value = false;
    }
};
