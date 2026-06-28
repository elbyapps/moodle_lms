/**
 * Global state management using Preact signals.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { signal } from '@preact/signals';
import type { UserData, StatsData } from './types';
import type { Resource } from './services/resources';

// User data signal - initialized with default guest data
export const userSignal = signal<UserData>({
    id: 0,
    fullname: 'Guest',
    firstname: 'Guest',
    lastname: '',
    email: '',
    avatar: '',
    roles: ['guest'],
});

// Stats data signal - initialized with zeros
export const statsSignal = signal<StatsData>({
    totalResources: 0,
    totalAuthors: 0,
    totalCategories: 0,
    totalClasses: 0,
});

// Resources data signal - initialized with empty array
export const resourcesSignal = signal<Resource[]>([]);

// Loading state signal - tracks async operations
export const loadingSignal = signal<boolean>(false);

// Error state signal - tracks error messages
export const errorSignal = signal<string | null>(null);
