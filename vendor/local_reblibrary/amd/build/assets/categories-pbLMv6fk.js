define(["exports","core/ajax"],(function(t,a){"use strict";/**
 * Service for category management API calls.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */class c{static async getAll(){return await a.call([{methodname:"local_reblibrary_get_all_categories_with_parent",args:{}}])[0]}static async create(e){return await a.call([{methodname:"local_reblibrary_create_category",args:{category_name:e.category_name,parent_category_id:e.parent_category_id??null,description:e.description??""}}])[0]}static async update(e,r){return await a.call([{methodname:"local_reblibrary_update_category",args:{id:e,category_name:r.category_name,parent_category_id:r.parent_category_id??null,description:r.description??""}}])[0]}static async delete(e){return await a.call([{methodname:"local_reblibrary_delete_category",args:{id:e}}])[0]}}t.CategoryService=c}));
//# sourceMappingURL=categories-pbLMv6fk.js.map
