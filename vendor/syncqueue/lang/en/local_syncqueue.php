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
 * Language strings for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Sync Queue';
$string['privacy:metadata'] = 'The Sync Queue plugin stores event data for synchronization between central and school servers.';

// Settings - General.
$string['settings'] = 'Sync Queue Settings';
$string['enabled'] = 'Enable sync queue';
$string['enabled_desc'] = 'When enabled, the sync system will be active based on the selected mode.';
$string['mode'] = 'Operation mode';
$string['mode_desc'] = 'Select whether this instance operates as the central server or a school instance.';
$string['mode_school'] = 'School (syncs to central)';
$string['mode_central'] = 'Central (receives from schools)';

// Settings - School mode.
$string['schoolsettings'] = 'School Mode Settings';
$string['schoolsettings_desc'] = 'Configure these settings when running in school mode.';
$string['schoolid'] = 'School identifier';
$string['schoolid_desc'] = 'Unique identifier for this school instance (e.g., school_kigali_001).';
$string['centralserver'] = 'Central server URL';
$string['centralserver_desc'] = 'The URL of the central Moodle server to sync with.';
$string['wstoken'] = 'Web service token';
$string['wstoken_desc'] = 'The Moodle web service token from the central server. Create a token for the "Sync Queue Service" on the central server.';
$string['apikey'] = 'School API key';
$string['apikey_desc'] = 'The API key generated when this school was registered on the central server.';
$string['syncinterval'] = 'Sync interval';
$string['syncinterval_desc'] = 'How often to attempt synchronization.';

// Settings - Central mode.
$string['centralsettings'] = 'Central Mode Settings';
$string['centralsettings_desc'] = 'Configure these settings when running in central mode.';
$string['allowregistration'] = 'Allow school registration';
$string['allowregistration_desc'] = 'When enabled, new schools can register using the registration secret.';
$string['registrationsecret'] = 'Registration secret';
$string['registrationsecret_desc'] = 'Secret key that schools must provide to register. Share this securely with school administrators.';

// Settings - Common.
$string['commonsettings'] = 'Common Settings';
$string['commonsettings_desc'] = 'Settings that apply to both central and school modes.';
$string['batchsize'] = 'Batch size';
$string['batchsize_desc'] = 'Maximum number of items to sync in a single batch.';
$string['maxretries'] = 'Maximum retries';
$string['maxretries_desc'] = 'Maximum number of retry attempts for failed sync items.';
$string['maxfilesize'] = 'Maximum file size';
$string['maxfilesize_desc'] = 'Maximum file size (in bytes) to include inline with sync data. Larger files are synced separately.';
$string['debug'] = 'Debug mode';
$string['debug_desc'] = 'Enable verbose logging for troubleshooting sync issues.';

// Queue statuses.
$string['status_pending'] = 'Pending';
$string['status_processing'] = 'Processing';
$string['status_synced'] = 'Synced';
$string['status_failed'] = 'Failed';
$string['status_conflict'] = 'Conflict';

// Event types.
$string['eventtype_grade'] = 'Grade change';
$string['eventtype_submission'] = 'Assignment submission';
$string['eventtype_quiz'] = 'Quiz attempt';
$string['eventtype_forum'] = 'Forum post';
$string['eventtype_user'] = 'User update';
$string['eventtype_enrol'] = 'Enrollment change';
$string['eventtype_completion'] = 'Activity completion';

// Tasks.
$string['task_processqueue'] = 'Process sync queue';
$string['task_downloadupdates'] = 'Download updates from central server';
$string['task_cleanup'] = 'Clean up old sync records';

// Admin page.
$string['queuestatus'] = 'Queue status';
$string['pendingitems'] = 'Pending items';
$string['faileditem'] = 'Failed items';
$string['lastsync'] = 'Last successful sync';
$string['syncnow'] = 'Sync now';
$string['viewqueue'] = 'View queue';
$string['clearfailed'] = 'Clear failed items';

// Errors.
$string['error_noconnection'] = 'Cannot connect to central server';
$string['error_authfailed'] = 'Authentication failed';
$string['error_syncfailed'] = 'Synchronization failed: {$a}';
$string['error_invalidresponse'] = 'Invalid response from central server: {$a}';
$string['error_conflict'] = 'Data conflict detected for {$a}';
$string['error_notconfigured'] = 'Sync queue is not properly configured';

// Dashboard.
$string['dashboard'] = 'Dashboard';
$string['currentmode'] = 'Current mode: {$a}';
$string['plugindisabled'] = 'The sync queue plugin is currently disabled. Enable it in settings to start synchronization.';
$string['connectionstatus'] = 'Connection Status';
$string['notconfigured'] = 'Connection settings are not configured.';
$string['configuresettings'] = 'Configure settings';
$string['testconnection'] = 'Test Connection';
$string['connectionok'] = 'Connection to central server successful!';
$string['connectionfailed'] = 'Connection to central server failed';
$string['queuestats'] = 'Queue Statistics';
$string['neversync'] = 'Never synced';
$string['syncstarted'] = 'Sync process started';
$string['syncfailed'] = 'Sync process failed';
$string['actions'] = 'Actions';

// Central mode dashboard.
$string['schoolstats'] = 'School Statistics';
$string['totalschools'] = 'Total Schools';
$string['activeschools'] = 'Active';
$string['suspendedschools'] = 'Suspended';
$string['overdueschools'] = 'Overdue';
$string['recentsyncactivity'] = 'Recent Sync Activity';
$string['itemslast24h'] = 'Items received in last 24 hours: {$a}';
$string['overdueschoolswarning'] = 'Schools Overdue for Sync';
$string['never'] = 'Never';
$string['manageschools'] = 'Manage Schools';

// Queue viewer.
$string['queueviewer'] = 'Queue Viewer';
$string['id'] = 'ID';
$string['eventtype'] = 'Event Type';
$string['eventname'] = 'Event Name';
$string['attempts'] = 'Attempts';
$string['timecreated'] = 'Created';
$string['timesynced'] = 'Synced';
$string['lasterror'] = 'Last Error';
$string['viewpayload'] = 'View Payload';
$string['payload'] = 'Payload';
$string['retry'] = 'Retry';
$string['itemretried'] = 'Item has been queued for retry';
$string['itemdeleted'] = 'Item has been deleted';
$string['deleteitem'] = 'Delete Queue Item';
$string['deleteitemconfirm'] = 'Are you sure you want to delete queue item #{$a}?';
$string['itemnotfound'] = 'Queue item not found';
$string['allstatuses'] = 'All statuses';
$string['alleventtypes'] = 'All event types';
$string['filter'] = 'Filter';
$string['reset'] = 'Reset';
$string['bulkactions'] = 'Bulk actions';
$string['retryallfailed'] = 'Retry All Failed';
$string['allitemsretried'] = 'All failed items have been queued for retry';
$string['faileditems_cleared'] = 'All failed items have been cleared';
$string['clearfailedconfirm'] = 'Are you sure you want to delete {$a} failed/conflict items? This action cannot be undone.';

// School management.
$string['schoolmanagement'] = 'School Management';
$string['centralmodeonlypage'] = 'This page is only available in central mode';
$string['registernewschool'] = 'Register New School';
$string['schooldetails'] = 'School Details';
$string['schoolname'] = 'School Name';
$string['contactemail'] = 'Contact Email';
$string['registerschool'] = 'Register School';
$string['schoolidexists'] = 'A school with this ID already exists';
$string['schoolregistered'] = 'School Registered Successfully';
$string['schoolregisteredmsg'] = 'The school "{$a}" has been registered successfully.';
$string['apikeyonetime'] = 'This API key will only be shown once. Make sure to copy it now and share it securely with the school administrator.';
$string['noschools'] = 'No schools have been registered yet.';
$string['totalsynced'] = 'Total Synced';
$string['suspend'] = 'Suspend';
$string['activate'] = 'Activate';
$string['regeneratekey'] = 'Regenerate Key';
$string['deleteschool'] = 'Delete School';
$string['deleteschoolconfirm'] = 'Are you sure you want to delete the school "{$a}"? This will remove all registration data for this school.';
$string['schooldeleted'] = 'School has been deleted';
$string['schoolnotfound'] = 'School not found';
$string['schoolsuspended'] = 'School has been suspended';
$string['schoolactivated'] = 'School has been activated';
$string['regenerateapikey'] = 'Regenerate API Key';
$string['regenerateapikeyconfirm'] = 'Are you sure you want to regenerate the API key for "{$a}"? The old key will stop working immediately.';
$string['newapikey'] = 'New API Key Generated';
$string['newapikeymsg'] = 'A new API key has been generated for "{$a}".';
$string['newapikeywarn'] = 'This key will only be shown once. Make sure to copy it and update the school\'s configuration.';
$string['status_active'] = 'Active';
$string['status_suspended'] = 'Suspended';
$string['status_pending'] = 'Pending';
$string['schoolid_help'] = 'A unique identifier for this school (e.g., school_kigali_001). This ID must be unique across all registered schools.';

// Course sync.
$string['syncedcourses'] = 'Synced Courses';
$string['syncedcoursesdesc'] = 'Courses synchronized from the central server.';
$string['pushcourses'] = 'Push Courses';
$string['selectcourses'] = 'Select courses to push to schools';
$string['pushtoschools'] = 'Push to Schools';
$string['coursespushed'] = '{$a} course(s) queued for sync to schools';
$string['coursespushednobackup'] = '{$a} course(s) pushed without backup (metadata only)';
$string['enrolmentspushed'] = 'Queued {$a} student enrolment(s) for syncing.';
$string['userspushed'] = 'Queued {$a} student account(s) for syncing.';
$string['nocourses'] = 'No courses available to push';
$string['searchcourses'] = 'Search by name, shortname or category...';
$string['nocoursesmatchsearch'] = 'No courses matching your search';
$string['downloadupdates'] = 'Download Updates';
$string['updatessuccess'] = 'Downloaded and processed {$a->success} updates ({$a->failed} failed, {$a->skipped} skipped)';
$string['noupdates'] = 'No updates available from central server';

// Async push jobs, category push, and per-school priority (v0.4.0).
$string['coursesqueued'] = '{$a} course(s) queued for push. Backups run in the background — progress below.';
$string['coursesskipped'] = '{$a} already in progress and were skipped.';
$string['nocoursesqueued'] = 'Nothing to queue — the selected courses are already being pushed.';
$string['pushcategory'] = 'Push a whole category';
$string['pushcategory_help'] = 'Queue every course in a category (optionally including its subcategories) in one action.';
$string['pushcategorybtn'] = 'Push category';
$string['includesubcategories'] = 'Include subcategories';
$string['categoryempty'] = 'That category has no courses to push.';
$string['pushstate'] = 'Push state';
$string['pushstate_never'] = 'Never pushed';
$string['pushstate_pushed'] = 'Pushed';
$string['pushstate_failed'] = 'Failed';
$string['pushstate_skipped'] = 'Skipped';
$string['pushstate_inprogress'] = 'In progress';
$string['jobprogress'] = 'Course push progress';
$string['jobnotfound'] = 'Push job not found.';
$string['jobstatus'] = 'Status';
$string['jobstatus_queued'] = 'Queued';
$string['jobstatus_running'] = 'Running';
$string['jobstatus_completed'] = 'Completed';
$string['jobstatus_partial'] = 'Completed with errors';
$string['jobstatus_failed'] = 'Failed';
$string['jobsummary'] = '{$a->done} of {$a->total} courses done, {$a->failed} failed. Queued {$a->users} user record(s) and {$a->enrolments} enrolment(s).';
$string['jobrunninghint'] = 'Backups are running in the background (via scheduled tasks). This page refreshes automatically.';
$string['backtocourses'] = 'Back to courses';
$string['users'] = 'Users';
$string['enrolments'] = 'Enrolments';
// Per-school trade/level priority.
$string['task_deriveschooltrades'] = 'Derive per-school trade/level priorities';
$string['schooltrades'] = 'Course priorities (trades/levels)';
$string['schooltrades_help'] = 'Courses matching these trades/levels are delivered to this school first when it pulls updates.';
$string['schooltrades_auto'] = 'Auto-detected from enrolled students';
$string['schooltrades_none'] = 'No trade/level priorities set — this school pulls in default order.';
$string['autodetecttrades'] = 'Auto-detect from enrolled students';
$string['tradecode'] = 'Trade';
$string['level'] = 'Level';
// School-side async pull (v0.4.1).
$string['pullqueued'] = 'Pulling updates in the background — progress below.';
$string['syncqueued'] = 'Upload queued — running in the background.';
$string['pullprogress'] = 'Pull updates progress';
$string['pullsummary'] = '{$a->done} of {$a->total} updates applied, {$a->failed} failed.';
$string['backtodashboard'] = 'Back to dashboard';
// F3: school-side catalogue browse + pull priorities (v0.4.2).
$string['pullpriorities'] = 'Course pull priorities';
$string['pullpriorities_help'] = 'Browse the courses available from the central server and choose which ones to prioritise pulling. Selected courses are delivered first; optionally pull only your selection.';
$string['schoolmodeonlypage'] = 'This page is only available in school mode.';
$string['catalogempty'] = 'No courses are available from the central server yet.';
$string['uncategorized'] = 'Uncategorised';
$string['onlypullselected'] = 'Only pull the courses I select (skip everything else)';
$string['priorityweight'] = 'Priority (lower first)';
$string['savepriorities'] = 'Save priorities';
$string['prioritiessaved'] = 'Saved {$a} course priority preference(s).';
