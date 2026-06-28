/**
 * TypeScript type definitions for REB Library.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export interface UserData {
    id: number;
    fullname: string;
    firstname: string;
    lastname: string;
    email: string;
    avatar: string;
    roles: string[];
}

export interface StatsData {
    totalResources: number;
    totalAuthors: number;
    totalCategories: number;
    totalClasses: number;
}

// Education Structure Types
export interface EducationLevel {
    id: number | string;
    level_name: string;
}

export interface EducationSublevel {
    id: number | string;
    sublevel_name: string;
    level_id: number | string;
}

export interface EducationClass {
    id: number | string;
    class_name: string;
    class_code: string;
    sublevel_id: number | string;
    sublevel_name: string;
    level_id: number | string;
    level_name: string;
}

// URL Filter Parameters
export interface URLFilterParams {
    level_id?: string;
    sublevel_id?: string;
    class_id?: string;
    category_id?: string;
    q?: string; // search query
}

// Menu Types
export interface MenuItem {
    name: string;
    url?: string;
    icon: string;
    active?: boolean;
    children?: MenuItem[];
}
