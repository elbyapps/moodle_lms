define(["exports"],(function(o){"use strict";/**
 * Resource web service client.
 * Provides methods to interact with local_reblibrary resource web services.
 *
 * @module local_reblibrary/services/resources
 * @copyright 2025 Rwanda Education Board
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */const i={getAll:()=>new Promise((e,a)=>{require(["core/ajax"],l=>{l.call([{methodname:"local_reblibrary_get_all_resources",args:{}}])[0].then(c=>e(c)).catch(c=>a(c))})}),getById:e=>new Promise((a,l)=>{require(["core/ajax"],c=>{c.call([{methodname:"local_reblibrary_get_resource_by_id",args:{id:e}}])[0].then(r=>a(r)).catch(r=>l(r))})}),create:e=>new Promise((a,l)=>{require(["core/ajax"],c=>{c.call([{methodname:"local_reblibrary_create_resource",args:{title:e.title,isbn:e.isbn,author_id:e.author_id,description:e.description,cover_image_url:e.cover_image_url,file_url:e.file_url}}])[0].then(r=>a(r)).catch(r=>l(r))})}),update:(e,a)=>new Promise((l,c)=>{require(["core/ajax"],r=>{r.call([{methodname:"local_reblibrary_update_resource",args:{id:e,...a}}])[0].then(t=>l(t)).catch(t=>c(t))})}),delete:e=>new Promise((a,l)=>{require(["core/ajax"],c=>{c.call([{methodname:"local_reblibrary_delete_resource",args:{id:e}}])[0].then(r=>a(r)).catch(r=>l(r))})})};o.ResourceService=i}));
//# sourceMappingURL=resources-DcBbVI18.js.map
