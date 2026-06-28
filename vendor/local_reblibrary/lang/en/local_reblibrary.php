<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English language strings for local_reblibrary.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Library';

// Header strings.
$string['header_logo'] = 'library.';
$string['header_search_placeholder'] = 'Search...';
$string['header_subscribe'] = 'Subscribe';

// Sidebar navigation strings.
$string['sidebar_browse'] = 'BROWSE';
$string['sidebar_your_books'] = 'YOUR BOOKS';
$string['sidebar_shelves'] = 'SHELVES';

// Browse section.
$string['nav_topbooks'] = 'Top Books';
$string['nav_discover'] = 'Discover';
$string['nav_categories'] = 'Categories';

// Your Books section.
$string['nav_reading'] = 'Reading';
$string['nav_favorite'] = 'Favorite Reads';
$string['nav_history'] = 'History';

// Shelves section.
$string['nav_your_shelves'] = 'Your Shelves';
$string['btn_create_shelf'] = 'Create a Shelf';

// Main content sections.
$string['section_recently_added'] = 'Recently Added';
$string['section_recommended'] = 'Recommended For You';

// Friends sidebar.
$string['friends_heading'] = 'YOUR FRIENDS';

// Library home page strings.
$string['librarypage_title'] = 'REB Library';
$string['librarypage_heading'] = 'REB Library';
$string['librarypage_welcome'] = 'Welcome to REB Library';
$string['librarypage_description'] = 'Browse and explore educational resources tailored for your learning journey.';

// Browse page strings.
$string['browsepage_title'] = 'Browse Resources';
$string['browsepage_heading'] = 'Browse Library Resources';
$string['browsepage_description'] = 'Explore educational materials by category.';

// Search page strings.
$string['searchpage_title'] = 'Search Library';
$string['searchpage_heading'] = 'Search Library';
$string['searchpage_description'] = 'Find specific content quickly.';

// Collection page strings.
$string['collectionpage_title'] = 'My Collection';
$string['collectionpage_heading'] = 'My Collection';
$string['collectionpage_description'] = 'View your saved resources and bookmarks.';

// Reading Materials page strings.
$string['readingmaterialspage_title'] = 'Reading Materials';
$string['readingmaterialspage_heading'] = 'Reading Materials';
$string['readingmaterialspage_description'] = 'Explore reading materials tailored for your learning journey.';

// Admin page strings.
$string['adminpage_title'] = 'Library Administration';
$string['adminpage_heading'] = 'REB Library Administration';
$string['adminpage_welcome'] = 'Welcome to REB Library Administration';
$string['adminpage_description'] = 'Manage library settings, resources, and user access from this admin panel.';

// Navigation strings.
$string['nav_home'] = 'Home';
$string['nav_browse'] = 'Browse';
$string['nav_search'] = 'Search';
$string['nav_collection'] = 'My Collection';
$string['nav_reading_materials'] = 'Reading Materials';
$string['nav_admin'] = 'Library Admin';

// Admin navigation strings.
$string['admin_menu_heading'] = 'ADMINISTRATION';
$string['admin_nav_dashboard'] = 'Dashboard';
$string['admin_nav_education'] = 'Education Structure';
$string['admin_nav_resources'] = 'Resources & Authors';
$string['admin_nav_categories'] = 'Labels & Categories';
$string['admin_nav_assignments'] = 'Assignments';

// Education levels CRUD.
$string['admin_edu_levels_title'] = 'Manage Education Levels';
$string['admin_edu_levels_heading'] = 'Education Levels Management';
$string['form_level_name'] = 'Level Name';
$string['form_level_name_help'] = 'The name of the education level (e.g., Pre-primary, Primary, Secondary)';
$string['form_add_level'] = 'Add Education Level';
$string['form_edit_level'] = 'Edit Education Level';
$string['error_duplicate_level_name'] = 'An education level with this name already exists';

// General CRUD messages.
$string['success_created'] = 'Record created successfully';
$string['success_updated'] = 'Record updated successfully';
$string['success_deleted'] = 'Record deleted successfully';
$string['confirm_delete'] = 'Confirm Deletion';
$string['confirm_delete_level'] = 'Are you sure you want to delete the education level "{$a}"?';
$string['areyousure'] = 'Are you sure?';

// Education structure page.
$string['ed_structure_page_title'] = 'Education Structure Management';
$string['ed_structure_page_heading'] = 'Education Structure Management';

// Education structure error messages.
$string['error_duplicate_sublevel_name'] = 'A sublevel with this name already exists';
$string['error_duplicate_class_name'] = 'A class with this name already exists';
$string['error_duplicate_class_code'] = 'A class with this code already exists';
$string['error_duplicate_section_code'] = 'A section with this code already exists';

// Categories & Labels page.
$string['categories_page_title'] = 'Labels & Categories Management';
$string['categories_page_heading'] = 'Labels & Categories Management';

// Categories & Labels tabs.
$string['tab_categories'] = 'Categories';
$string['tab_labels'] = 'Labels';

// Categories error messages.
$string['invalidparentcategory'] = 'The parent category does not exist';
$string['cannotbeparentofself'] = 'A category cannot be its own parent';
$string['categoryhaschildren'] = 'Cannot delete category that has subcategories';

// Labels tab.
$string['add_label'] = 'Add Label';
$string['edit_label'] = 'Edit Label';
$string['delete_label'] = 'Delete Label';
$string['label_name'] = 'Label Name';
$string['label_description'] = 'Description';
$string['confirm_delete_label'] = 'Are you sure you want to delete the label "{$a}"?';
$string['no_labels'] = 'No labels found. Click "Add Label" to create one.';
$string['invalidlabelid'] = 'The label does not exist';

// Resources page.
$string['resources_page_title'] = 'Resources & Authors Management';
$string['resources_page_heading'] = 'Resources & Authors Management';

// Resources tab.
$string['tab_resources'] = 'Resources';
$string['tab_authors'] = 'Authors';
$string['add_resource'] = 'Add Resource';
$string['edit_resource'] = 'Edit Resource';
$string['delete_resource'] = 'Delete Resource';
$string['resource_title'] = 'Title';
$string['resource_isbn'] = 'ISBN';
$string['resource_author'] = 'Author';
$string['resource_description'] = 'Description';
$string['resource_cover_image_url'] = 'Cover Image URL';
$string['resource_file_url'] = 'File URL';
$string['resource_created_at'] = 'Created';
$string['confirm_delete_resource'] = 'Are you sure you want to delete the resource "{$a}"?';

// Authors tab.
$string['add_author'] = 'Add Author';
$string['edit_author'] = 'Edit Author';
$string['delete_author'] = 'Delete Author';
$string['author_first_name'] = 'First Name';
$string['author_last_name'] = 'Last Name';
$string['author_bio'] = 'Bio';
$string['author_full_name'] = 'Name';
$string['confirm_delete_author'] = 'Are you sure you want to delete the author "{$a}"?';

// Form buttons.
$string['save'] = 'Save';
$string['cancel'] = 'Cancel';
$string['delete'] = 'Delete';
$string['edit'] = 'Edit';
$string['actions'] = 'Actions';

// Messages.
$string['no_resources'] = 'No resources found. Click "Add Resource" to create one.';
$string['no_authors'] = 'No authors found. Click "Add Author" to create one.';
$string['loading'] = 'Loading...';
$string['search_placeholder'] = 'Search...';

// S3 Storage settings.
$string['s3settings'] = 'S3 Object Storage';
$string['s3settings_desc'] = 'Configure S3-compatible object storage for resource files';
$string['s3endpoint'] = 'S3 Endpoint (Internal)';
$string['s3endpoint_desc'] = 'S3 server endpoint URL for server-side operations (e.g., http://garage:3900)';
$string['s3publicendpoint'] = 'S3 Public Endpoint';
$string['s3publicendpoint_desc'] = 'S3 endpoint URL accessible from browsers (e.g., http://localhost:3900)';
$string['s3accesskey'] = 'Access Key';
$string['s3accesskey_desc'] = 'S3 access key';
$string['s3secretkey'] = 'Secret Key';
$string['s3secretkey_desc'] = 'S3 secret key';
$string['s3bucket'] = 'Bucket Name';
$string['s3bucket_desc'] = 'S3 bucket name for storing resource files';
$string['s3region'] = 'Region';
$string['s3region_desc'] = 'S3 region (default: us-east-1)';

// S3 errors.
$string['s3configmissing'] = 'S3 configuration is missing. Please configure S3 storage settings.';
$string['s3connectionfailed'] = 'Failed to connect to S3 storage';
$string['s3connectionok'] = 'S3 connection successful';
$string['s3presignedfailed'] = 'Failed to generate presigned URL';
$string['s3aclfailed'] = 'Failed to set object ACL';
$string['s3deletefailed'] = 'Failed to delete object from S3 storage';
$string['s3uploaderror'] = 'S3 upload error';

// File download errors.
$string['invalidfilekey'] = 'Invalid file key';
$string['filenotfound'] = 'File not found';
$string['downloaderror'] = 'Error downloading file';
$string['uploadfailed'] = 'File upload failed';

// Home page filtering settings.
$string['homepagefiltering'] = 'Home Page Filtering';
$string['homepagefiltering_desc'] = 'Control which resources appear on the public library home page based on labels';
$string['homepageincludelabels'] = 'Include Labels';
$string['homepageincludelabels_desc'] = 'Show only resources with these labels on the home page. If empty, all resources are shown (subject to exclude filter).';
$string['homepageexcludelabels'] = 'Exclude Labels';
$string['homepageexcludelabels_desc'] = 'Hide resources with these labels from the home page. Exclude takes precedence over include.';

// Reading Materials page filtering settings.
$string['readingmaterialsfiltering'] = 'Reading Materials Page Filtering';
$string['readingmaterialsfiltering_desc'] = 'Control which resources appear on the Reading Materials page based on labels';
$string['readingmaterialsincludelabels'] = 'Include Labels';
$string['readingmaterialsincludelabels_desc'] = 'Show only resources with these labels on the Reading Materials page. If empty, all resources are shown (subject to exclude filter).';
$string['readingmaterialsexcludelabels'] = 'Exclude Labels';
$string['readingmaterialsexcludelabels_desc'] = 'Hide resources with these labels from the Reading Materials page. Exclude takes precedence over include.';
