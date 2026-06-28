define(["exports"],(function(a){"use strict";/**
 * Admin menu configuration for REB Library.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */function r(e=""){return[{name:"Dashboard",url:"/local/reblibrary/admin/index.php",icon:"fa fa-tachometer-alt",active:e==="dashboard"},{name:"Education Structure",url:"/local/reblibrary/admin/ed_structure.php",icon:"fa fa-graduation-cap",active:e==="education"},{name:"Resources & Authors",url:"/local/reblibrary/admin/resources.php",icon:"fa fa-book",active:e==="resources"},{name:"Labels & Categories",url:"/local/reblibrary/admin/categories.php",icon:"fa fa-tags",active:e==="categories"}]}/**
 * Library menu configuration for REB Library.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */function i(e=""){return[{name:"Resources",url:"/local/reblibrary/index.php",icon:"fa fa-book-open",active:e==="home",children:[]},{name:"Reading Materials",url:"/local/reblibrary/reading-materials.php",icon:"fa fa-book-reader",active:e==="reading-materials",children:[]}]}a.getAdminMenuItems=r,a.getLibraryMenuItems=i}));
//# sourceMappingURL=library-menu-BSGNgkIk.js.map
