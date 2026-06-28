define(["exports"],(function(i){"use strict";/**
 * Resource web service client.
 * Provides methods to interact with local_reblibrary resource web services.
 *
 * @module local_reblibrary/services/resources
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */const t={getAll:e=>new Promise((c,l)=>{require(["core/ajax"],a=>{a.call([{methodname:"local_reblibrary_get_all_resources",args:{search_query:e?.searchQuery||"",level_id:e?.levelId||0,sublevel_id:e?.sublevelId||0,class_id:e?.classId||0,category_id:e?.categoryId||0,label_id:e?.labelId||0,page_context:e?.pageContext||"home"}}])[0].then(r=>c(r)).catch(r=>l(r))})}),getById:e=>new Promise((c,l)=>{require(["core/ajax"],a=>{a.call([{methodname:"local_reblibrary_get_resource_by_id",args:{id:e}}])[0].then(r=>c(r)).catch(r=>l(r))})}),create:e=>new Promise((c,l)=>{require(["core/ajax"],a=>{a.call([{methodname:"local_reblibrary_create_resource",args:{title:e.title,isbn:e.isbn,author_id:e.author_id,description:e.description,cover_image_url:e.cover_image_url,file_url:e.file_url,visible:e.visible??1,media_type:e.media_type??"text"}}])[0].then(r=>c(r)).catch(r=>l(r))})}),update:(e,c)=>new Promise((l,a)=>{require(["core/ajax"],r=>{r.call([{methodname:"local_reblibrary_update_resource",args:{id:e,...c}}])[0].then(o=>l(o)).catch(o=>a(o))})}),delete:e=>new Promise((c,l)=>{require(["core/ajax"],a=>{a.call([{methodname:"local_reblibrary_delete_resource",args:{id:e}}])[0].then(r=>c(r)).catch(r=>l(r))})})};i.ResourceService=t}));
//# sourceMappingURL=resources-CaLdCk3e.js.map
