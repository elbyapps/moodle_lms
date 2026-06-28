define(["exports","core/ajax"],(function(t,r){"use strict";/**
 * Service for category management API calls.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */class s{static async getAll(){return await r.call([{methodname:"local_reblibrary_get_all_categories_with_parent",args:{}}])[0]}static async create(e){return await r.call([{methodname:"local_reblibrary_create_category",args:{category_name:e.category_name,parent_category_id:e.parent_category_id??null,description:e.description??""}}])[0]}static async update(e,a){return await r.call([{methodname:"local_reblibrary_update_category",args:{id:e,category_name:a.category_name,parent_category_id:a.parent_category_id??null,description:a.description??""}}])[0]}static async delete(e){return await r.call([{methodname:"local_reblibrary_delete_category",args:{id:e}}])[0]}}/**
 * Service for label management API calls.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */class l{static async getAll(){return await r.call([{methodname:"local_reblibrary_get_all_labels",args:{}}])[0]}static async getById(e){return await r.call([{methodname:"local_reblibrary_get_label_by_id",args:{id:e}}])[0]}static async create(e){return await r.call([{methodname:"local_reblibrary_create_label",args:{label_name:e.label_name,description:e.description??""}}])[0]}static async update(e,a){return await r.call([{methodname:"local_reblibrary_update_label",args:{id:e,label_name:a.label_name,description:a.description??""}}])[0]}static async delete(e){return await r.call([{methodname:"local_reblibrary_delete_label",args:{id:e}}])[0]}}t.CategoryService=s,t.LabelService=l}));
//# sourceMappingURL=labels-Dh-Khnw9.js.map
