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
 * English language strings for local_elby_dashboard.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Elby Dashboard';

// Page strings.
$string['page_title'] = 'Elby Dashboard';
$string['page_heading'] = 'Elby Dashboard';
$string['page_welcome'] = 'Welcome to Elby Dashboard';
$string['page_description'] = 'View analytics and statistics for your e-learning platform.';

// Admin page strings.
$string['admin_page_title'] = 'Dashboard Administration';
$string['admin_page_heading'] = 'Elby Dashboard Administration';
$string['admin_page_welcome'] = 'Welcome to Dashboard Administration';
$string['admin_page_description'] = 'Manage dashboard settings and view detailed analytics.';

// Navigation strings.
$string['nav_admin'] = 'Dashboard Admin';
$string['nav_overview'] = 'Overview';
$string['nav_reports'] = 'Reports';
$string['nav_settings'] = 'Settings';

// Stats labels.
$string['stats_total_courses'] = 'Total Courses';
$string['stats_total_users'] = 'Total Users';
$string['stats_total_enrollments'] = 'Total Enrollments';
$string['stats_total_activities'] = 'Total Activities';
$string['stats_active_users'] = 'Active Users (30 days)';
$string['stats_total_teachers'] = 'Total Teachers';
$string['stats_total_students'] = 'Total Students';

// Capability strings.
$string['elby_dashboard:view'] = 'View Elby Dashboard';
$string['elby_dashboard:viewreports'] = 'View detailed reports';
$string['elby_dashboard:manage'] = 'Manage dashboard settings';

// =============================================
// SIDEBAR SETTINGS
// =============================================
$string['sidenavheading'] = 'Sidebar Settings';
$string['sidenavheading_desc'] = 'Configure the appearance of the sidebar navigation.';
$string['sidenavtitle'] = 'Sidebar Title';
$string['sidenavtitle_desc'] = 'The title displayed at the top of the sidebar navigation. Default: "Dashboard"';
$string['sidenavlogo'] = 'Sidebar Logo';
$string['sidenavlogo_desc'] = 'Upload a logo image to display in the sidebar. Recommended size: 32x32 pixels. Supported formats: JPG, PNG, SVG, GIF.';

// =============================================
// COLOR SETTINGS
// =============================================
$string['colorsheading'] = 'Color Settings';
$string['colorsheading_desc'] = 'Customize the colors used throughout the dashboard.';
$string['sidenavaccentcolor'] = 'Sidebar Accent Color';
$string['sidenavaccentcolor_desc'] = 'The background color for the active menu item in the sidebar.';
$string['statcard1color'] = 'Students Card Color';
$string['statcard1color_desc'] = 'Background color for the Students statistics card.';
$string['statcard2color'] = 'Teachers Card Color';
$string['statcard2color_desc'] = 'Background color for the Teachers statistics card.';
$string['statcard3color'] = 'Users Card Color';
$string['statcard3color_desc'] = 'Background color for the Total Users statistics card.';
$string['statcard4color'] = 'Courses Card Color';
$string['statcard4color_desc'] = 'Background color for the Courses statistics card.';
$string['chartprimarycolor'] = 'Chart Primary Color';
$string['chartprimarycolor_desc'] = 'Primary color used in charts and graphs (e.g., enrollments, attendance).';
$string['chartsecondarycolor'] = 'Chart Secondary Color';
$string['chartsecondarycolor_desc'] = 'Secondary color used in charts and graphs (e.g., completions).';

// =============================================
// HEADER OPTIONS
// =============================================
$string['headerheading'] = 'Header Options';
$string['headerheading_desc'] = 'Configure which elements appear in the dashboard header.';
$string['showsearchbar'] = 'Show Search Bar';
$string['showsearchbar_desc'] = 'Display the search bar in the header.';
$string['shownotifications'] = 'Show Notifications';
$string['shownotifications_desc'] = 'Display the notification bell icon in the header.';
$string['showuserprofile'] = 'Show User Profile';
$string['showuserprofile_desc'] = 'Display the user profile section (avatar and name) in the header.';

// =============================================
// MENU CUSTOMIZATION
// =============================================
$string['menuheading'] = 'Menu Customization';
$string['menuheading_desc'] = 'Choose which menu items to display in the sidebar navigation.';
$string['showmenu_courses'] = 'Show Courses';
$string['showmenu_courses_desc'] = 'Display the Courses menu item in the sidebar.';
$string['showmenu_presence'] = 'Show Presence';
$string['showmenu_presence_desc'] = 'Display the Presence menu item in the sidebar.';
$string['showmenu_communication'] = 'Show Communication';
$string['showmenu_communication_desc'] = 'Display the Communication menu item in the sidebar.';
$string['showmenu_event'] = 'Show Event';
$string['showmenu_event_desc'] = 'Display the Event menu item in the sidebar.';
$string['showmenu_pedagogy'] = 'Show Pedagogy';
$string['showmenu_pedagogy_desc'] = 'Display the Pedagogy menu item in the sidebar.';
$string['showmenu_message'] = 'Show Message';
$string['showmenu_message_desc'] = 'Display the Message menu item in the sidebar.';
$string['showmenu_completion'] = 'Show Completion';
$string['showmenu_completion_desc'] = 'Display the Completion menu item in the sidebar.';
$string['showmenu_settings'] = 'Show Settings';
$string['showmenu_settings_desc'] = 'Display the Settings menu item in the sidebar.';

// Courses report strings.
$string['courses_report'] = 'Courses Report';
$string['courses_report_title'] = 'Course Report by School';
$string['select_course'] = 'Select a course';
$string['school_code'] = 'School Code';
$string['school_name'] = 'School Name';
$string['student_count'] = 'Students';
$string['completion_rate'] = 'Completion Rate';
$string['average_grade'] = 'Average Grade';
$string['overview'] = 'Overview';
$string['enrolled_students'] = 'Enrolled Students';
$string['unit'] = 'Unit';

// =============================================
// COURSE REPORT SETTINGS
// =============================================
$string['reportheading'] = 'Course Report Settings';
$string['reportheading_desc'] = 'Configure settings for course reports.';
$string['enrollment_cutoff_month'] = 'Enrollment Cutoff Month';
$string['enrollment_cutoff_month_desc'] = 'Only include students enrolled on or after this month in the current academic year.';
$string['enrollment_cutoff_day'] = 'Enrollment Cutoff Day';
$string['enrollment_cutoff_day_desc'] = 'The day of the month for the enrollment cutoff date.';

// =============================================
// SDMS INTEGRATION SETTINGS
// =============================================
$string['sdmsheading'] = 'SDMS Integration';
$string['sdmsheading_desc'] = 'Configure connection to the Student Data Management System API. SDMS uses IP whitelist authentication.';
$string['sdms_api_url'] = 'SDMS API URL';
$string['sdms_api_url_desc'] = 'Base URL for the SDMS API (e.g., http://sdms.internal/api). No trailing slash.';
$string['sdms_timeout'] = 'Request Timeout';
$string['sdms_timeout_desc'] = 'HTTP request timeout in seconds. Default: 30';
$string['sdms_cache_ttl'] = 'Cache TTL';
$string['sdms_cache_ttl_desc'] = 'Time-to-live for cached SDMS data in seconds. Default: 604800 (7 days)';

// SDMS error messages.
$string['sdmsapierror'] = '{$a}';
$string['nosdmsid'] = 'User does not have an SDMS ID configured';
$string['sdmsnotfound'] = 'Record not found in SDMS';
$string['sdmssyncfailed'] = 'Failed to sync from SDMS: {$a}';

// =============================================
// SDMS SELF-REGISTRATION
// =============================================
$string['sdms_signup_title'] = 'Sign Up with SDMS';
$string['sdms_signup_heading'] = 'Create Your Account';
$string['sdms_signup_subtext'] = 'Sign up using your SDMS code';
$string['sdms_lookup_heading'] = 'Find Your Account';
$string['sdms_preview_heading'] = 'Your Information';
$string['sdms_register_heading'] = 'Set Your Password';
$string['sdms_code_label'] = 'SDMS Code';
$string['sdms_code_placeholder'] = 'Enter your SDMS code';
$string['sdms_usertype_label'] = 'I am a';
$string['sdms_student'] = 'Student';
$string['sdms_staff'] = 'Staff';
$string['sdms_lookup_btn'] = 'Look Up';
$string['sdms_continue_btn'] = 'Continue to Register';
$string['sdms_register_btn'] = 'Create Account';
$string['sdms_back'] = 'Back';
$string['sdms_back_to_login'] = 'Back to login';
$string['sdms_confirm_password'] = 'Confirm Password';
$string['sdms_already_registered'] = 'Already Registered';
$string['sdms_already_registered_msg'] = 'An account with this SDMS code already exists. Please log in instead.';
$string['sdms_go_to_login'] = 'Go to Login';
$string['sdms_not_found'] = 'No record found in SDMS for this code. Please check your code and try again.';
$string['sdms_success_title'] = 'Account Created!';
$string['sdms_success_msg'] = 'Your account has been created successfully. You can now log in with your SDMS code as your username.';
$string['sdms_password_mismatch'] = 'Passwords do not match.';
$string['sdms_code_empty'] = 'Please enter your SDMS code.';
$string['sdms_rate_limited'] = 'Too many attempts. Please try again in a few minutes.';
$string['sdms_signup_email_domain'] = 'Signup Email Domain';
$string['sdms_signup_email_domain_desc'] = 'Domain used to generate email addresses for SDMS self-registration (e.g., rtb.ac.rw). Emails will be in the format: sdms_code@domain.';
$string['sdms_signup_link'] = 'Sign up with SDMS';

// Scheduled task names.
$string['task_compute_user_metrics'] = 'Compute user engagement metrics';
$string['task_aggregate_school_metrics'] = 'Aggregate school-level metrics';
$string['task_refresh_sdms_cache'] = 'Refresh stale SDMS cache records';
$string['task_auto_link_by_email'] = 'Auto-link users to SDMS by email';
$string['task_cleanup_old_metrics'] = 'Clean up old metrics data';

// Metrics API strings.
$string['no_metrics_data'] = 'No metrics data available for this period';

// Schools directory strings.
$string['schools_directory'] = 'Schools Directory';
$string['school_detail'] = 'School Detail';
$string['student_list'] = 'Student List';
$string['admin_panel'] = 'Admin Panel';
$string['filter_province'] = 'Province';
$string['filter_district'] = 'District';
$string['filter_all'] = 'All';
$string['filter_course'] = 'Course';
$string['filter_school'] = 'School';
$string['filter_engagement'] = 'Engagement Level';
$string['export_csv'] = 'Export CSV';
$string['engagement_high'] = 'High Engagement';
$string['engagement_medium'] = 'Medium Engagement';
$string['engagement_low'] = 'Low Engagement';
$string['at_risk'] = 'Inactive';
$string['total_enrolled'] = 'Total Enrolled';
$string['total_active'] = 'Active This Week';
$string['avg_quiz_score'] = 'Avg Quiz Score';
$string['last_synced'] = 'Last Synced';
$string['sync_school'] = 'Sync School';
$string['sync_status'] = 'Sync Status';
$string['linked_users'] = 'Linked Users';
$string['stale_records'] = 'Stale Records';
$string['error_count'] = 'Error Count';
$string['recent_sync_logs'] = 'Recent Sync Logs';
$string['manual_sync'] = 'Manual Sync';
$string['task_schedule'] = 'Task Schedule';
$string['last_run'] = 'Last Run';
$string['next_scheduled'] = 'Next Scheduled';
$string['search_students'] = 'Search students...';
$string['search_schools'] = 'Search schools...';
$string['no_schools_found'] = 'No schools found';
$string['no_students_found'] = 'No students found';

// Teacher list strings.
$string['teacher_list'] = 'Teacher List';

// Traffic report strings.
$string['traffic_report'] = 'Platform Traffic';
$string['traffic_heatmap'] = 'Peak Hours';
$string['traffic_top_users'] = 'Top Active Users';
$string['traffic_by_school'] = 'Traffic by School';
$string['traffic_action_breakdown'] = 'Action Type Breakdown';

// Access log strings.
$string['access_log'] = 'Access Log';

// Trades report strings.
$string['trades_report'] = 'Trades Report';

// =============================================
// PROFILE SDMS INFORMATION
// =============================================
$string['profile_sdms_category'] = 'SDMS Information';
$string['profile_sdms_id'] = 'SDMS ID';
$string['profile_user_type'] = 'User Type';
$string['profile_school'] = 'School';
$string['profile_program'] = 'Program';
$string['profile_position'] = 'Position';
$string['profile_gender'] = 'Gender';
$string['profile_status'] = 'Status';
$string['profile_academic_year'] = 'Academic Year';
$string['profile_link_own'] = 'Link your SDMS account';
$string['profile_link_admin'] = 'Link this user to SDMS';

// =============================================
// SELF-LINK SDMS (for existing users)
// =============================================
$string['self_link_title'] = 'Link SDMS Account';
$string['self_link_description'] = 'Link your Moodle account to your SDMS record to access the full dashboard features.';
$string['self_link_step1_title'] = 'Enter Your SDMS Code';
$string['self_link_step2_title'] = 'Confirm Your Information';
$string['self_link_confirm'] = 'Confirm & Link Account';
$string['self_link_success_title'] = 'Account Linked!';
$string['self_link_success_msg'] = 'Your Moodle account has been successfully linked to your SDMS record.';
$string['self_link_go_dashboard'] = 'Go to Dashboard';
$string['self_link_prompt'] = 'Your account is not linked to SDMS. <a href="{$a}">Link your SDMS account</a> to access full features.';
$string['sdms_already_linked'] = 'Your account is already linked to SDMS.';
$string['sdms_code_taken'] = 'This SDMS code is already linked to another account.';
$string['sdms_code_taken_title'] = 'SDMS Code Already Linked';

// =============================================
// ADMIN BULK LINK
// =============================================
$string['bulk_link_title'] = 'Bulk SDMS Link';
$string['bulk_link_description'] = 'Upload a CSV file to link multiple Moodle users to their SDMS records at once.';
$string['bulk_link_upload_header'] = 'Upload CSV File';
$string['bulk_link_csvfile'] = 'CSV File';
$string['bulk_link_delimiter'] = 'Delimiter';
$string['bulk_link_delimiter_comma'] = 'Comma (,)';
$string['bulk_link_delimiter_semicolon'] = 'Semicolon (;)';
$string['bulk_link_delimiter_tab'] = 'Tab';
$string['bulk_link_upload_btn'] = 'Upload & Process';
$string['bulk_link_csv_help'] = 'The CSV file must contain three columns: <strong>username</strong>, <strong>sdms_code</strong>, and <strong>role</strong> (student or staff).';
$string['bulk_link_download_template'] = 'Download sample CSV template';
$string['bulk_link_invalid_csv'] = 'Failed to parse the CSV file. Please check the format.';
$string['bulk_link_missing_columns'] = 'CSV must contain columns: username, sdms_code, role';
$string['bulk_link_empty_fields'] = 'Row has empty required fields.';
$string['bulk_link_invalid_role'] = 'Role must be "student" or "staff".';
$string['bulk_link_user_not_found'] = 'Moodle user not found.';
$string['bulk_link_already_linked'] = 'User already linked to SDMS.';
$string['bulk_link_success'] = 'Successfully linked.';
$string['bulk_link_results'] = 'Processing Results';
$string['bulk_link_results_summary'] = '{$a->success} linked successfully, {$a->error} errors, {$a->skipped} skipped.';
$string['bulk_link_col_row'] = 'Row';
$string['bulk_link_col_username'] = 'Username';
$string['bulk_link_col_sdms_code'] = 'SDMS Code';
$string['bulk_link_col_status'] = 'Status';
$string['bulk_link_col_message'] = 'Message';

// School override strings.
$string['change_school'] = 'Change School';
$string['school_updated'] = 'School updated successfully';
$string['school_code_not_found'] = 'School code not found';
$string['select_school'] = 'Select school';

// School detail demographics strings.
$string['people_overview'] = 'People Overview';
$string['age_distribution'] = 'Student Age Distribution';
$string['school_structure'] = 'School Structure';

// =============================================
// AUTO-ENROLLMENT
// =============================================
$string['auto_enroll_enabled'] = 'Auto-enroll students by trade & level';
$string['auto_enroll_enabled_desc'] = 'When enabled, students are automatically enrolled into Moodle courses whose category idnumber matches their trade code and level (e.g., category idnumber "527:3" matches a student with combinationCode 527 and classGrade "Level 3").';
$string['auto_enroll_success'] = 'Auto-enrolled in {$a} course(s)';
$string['auto_enroll_no_match'] = 'No matching course category found for trade:level "{$a}"';

// =============================================
// REPORTS FROM CATEGORY TAGGING
// =============================================
$string['courses_by_trade'] = 'Courses by Trade';
$string['no_course_category_mappings'] = 'No course category mappings found';
$string['enrollment_coverage'] = 'Enrollment Coverage';
$string['enrollment_coverage_desc'] = 'Platform-wide view of trade:level combinations and their mapping status to Moodle categories.';
$string['total_combos'] = 'Total Combos';
$string['mapped_combos'] = 'Mapped';
$string['unmapped_combos'] = 'Unmapped';
$string['sdms_students'] = 'SDMS Students';
$string['enrolled_students_count'] = 'Enrolled';
$string['coverage_status'] = 'Coverage Status';
$string['coverage_mapped'] = 'Mapped';
$string['coverage_unmapped'] = 'Unmapped';
$string['coverage_partial'] = 'Partial';
$string['enrollment_logs'] = 'Auto-enrollment Logs';
$string['enrollment_logs_desc'] = 'Monitoring panel for enrollment sync activity.';
$string['auto_enrollments'] = 'Auto-enrollments';
$string['total_skipped'] = 'Skipped';
$string['last_enrollment'] = 'Last Enrollment';
$string['no_enrollment_logs'] = 'No enrollment logs found';
$string['filter_all'] = 'All';
$string['filter_enrolled'] = 'Enrolled';
$string['filter_skipped'] = 'Skipped';

// =============================================
// BLENDED LEARNING
// =============================================
$string['blended_learning_report'] = 'Blended Learning';
$string['blendedlearningheading'] = 'Blended Learning Settings';
$string['blendedlearningheading_desc'] = 'Configure the blended learning report.';
$string['blended_learning_category'] = 'Blended Learning Parent Category';
$string['blended_learning_category_desc'] = 'Select the parent course category. All courses under this category (including subcategories) will be included in blended learning metrics.';
$string['showmenu_blended_learning'] = 'Show Blended Learning';
$string['showmenu_blended_learning_desc'] = 'Show or hide the Blended Learning menu item in the sidebar.';
$string['blended_learning_category_not_set'] = 'Blended Learning parent category has not been configured. Please set it in the plugin settings.';
$string['blended_learning_no_courses'] = 'No courses found under the configured category.';

// =============================================
// RISE
// =============================================
$string['rise'] = 'RISE';
$string['rise_page_subtitle'] = 'Resilience In Secondary Education — recruitment campaigns and applicants.';
$string['riseheading'] = 'RISE Integration';
$string['riseheading_desc'] = 'Configure access to the RISE (Resilience In Secondary Education) recruitment API.';
$string['rise_api_url'] = 'RISE API URL';
$string['rise_api_url_desc'] = 'Base URL of the RISE API, e.g. https://rise.elearning.reb.rw/api';
$string['rise_api_key'] = 'RISE API key';
$string['rise_api_key_desc'] = 'API key sent in the X-API-KEY header. Stored on the server and never exposed to the browser.';
$string['rise_timeout'] = 'RISE request timeout';
$string['rise_timeout_desc'] = 'HTTP request timeout in seconds for RISE API calls.';
$string['riseapinotconfigured'] = 'The RISE API is not configured. Set the RISE API URL and key in the plugin settings.';
$string['riseapierror'] = 'Could not reach the RISE API. Please try again later.';

// RISE learner provisioning.
$string['elby_dashboard:manageriseusers'] = 'Provision Moodle accounts for RISE learners';
$string['messageprovider:riseaction'] = 'RISE identity action needed';
$string['riseprovisionfetchfailed'] = 'Could not fetch the applicant from RISE — the account was not created. Detail: {$a}';
$string['riseusernamelock'] = 'Could not acquire the username-generation lock. Please try again.';
$string['riseprovisionlocktimeout'] = 'This learner is already being provisioned by another process. Please try again in a moment.';
$string['rise_provisioning_heading'] = 'RISE learner provisioning';
$string['rise_provisioning_heading_desc'] = 'Account creation, SMS notifications and self-service correction links for approved RISE learners.';
$string['rise_signup_email_domain'] = 'RISE learner email domain';
$string['rise_signup_email_domain_desc'] = 'Domain for the synthetic learner login email ({username}@domain). SMS is the real contact channel.';
$string['rise_autoprovision'] = 'Auto-provision on approval';
$string['rise_autoprovision_desc'] = 'Automatically create/link the Moodle account when a NESA review is approved (by a reviewer holding the manage-RISE-users capability).';
$string['rise_action_link_base'] = 'Public link base URL';
$string['rise_action_link_base_desc'] = 'Base URL used in SMS deep links (set-password and correction forms). Leave empty to use the site URL.';
$string['smsheading'] = 'SMS gateway (InTouch)';
$string['smsheading_desc'] = 'Server-side InTouch SMS credentials for learner notifications. Credentials never reach the browser.';
$string['sms_enabled'] = 'Enable SMS sending';
$string['sms_enabled_desc'] = 'Turn off to disable all outgoing SMS (e.g. in dev/staging). Skipped messages are still logged.';
$string['sms_api_url'] = 'SMS API URL';
$string['sms_api_url_desc'] = 'InTouch send endpoint.';
$string['sms_sender'] = 'SMS sender id';
$string['sms_sender_desc'] = 'Sender name shown on the learner\'s phone.';
$string['sms_username'] = 'SMS username';
$string['sms_username_desc'] = 'InTouch account username (Basic auth). Stored on the server.';
$string['sms_password'] = 'SMS password';
$string['sms_password_desc'] = 'InTouch account password (Basic auth). Stored on the server and never exposed to the browser.';
$string['sms_timeout'] = 'SMS request timeout';
$string['sms_timeout_desc'] = 'HTTP request timeout in seconds for SMS gateway calls.';
$string['rise_badge_title'] = 'RISE';
$string['rise_badge_label'] = 'RISE Learner';
$string['rise_badge_verified'] = 'RISE Learner — Verified';
$string['rise_badge_action'] = 'RISE Learner — Action needed';
$string['rise_create_account'] = 'Create account';
$string['rise_account_exists'] = 'Account';
$string['rise_account_created'] = 'Account created';
$string['rise_action_subject'] = 'Action needed on your RISE application';
$string['rise_action_fixdetails'] = 'Fix your RISE details';
$string['rise_action_review'] = 'Your RISE application needs your attention.';
$string['rise_action_nid_missing'] = 'Please add your National ID to your RISE application.';
$string['rise_action_nid_invalid'] = 'Please fix your National ID number on your RISE application.';
$string['rise_action_details_mismatch'] = 'Your details do not match NIDA records. Please correct your names or National ID.';
$string['rise_action_duplicate_nid'] = 'Another account already uses this National ID. The reviewer must resolve this before an account can be created.';
$string['rise_create_requires_approval'] = 'This learner must have an approved NESA review before an account can be created.';
$string['rise_reset_sent'] = 'A set-password link was sent by SMS.';
$string['rise_reset_skipped'] = 'No SMS was sent — the phone is invalid/missing or the SMS gateway is off.';
$string['rise_reset_failed'] = 'Could not send the SMS. Please try again.';
$string['rise_reset_noaccount'] = 'This learner does not have a Moodle account yet.';
$string['rise_reset_suspended'] = 'This account is suspended — reactivate it before sending a set-password link.';
$string['rise_reset_conflict'] = 'Resolve the RISE sync conflict for this learner before sending a set-password link.';
$string['rise_action_prompt'] = 'Action needed: fix your RISE identity details.';
$string['rise_sms_welcome'] = 'Welcome to REB e-Learning. Your username is {$a->username}. Set your password: {$a->url}';
$string['rise_sms_setpassword'] = 'REB e-Learning: set or reset your password. Username: {$a->username}. Link: {$a->url}';
$string['rise_sms_reviewercomment'] = 'Reviewer: {$a}';
$string['rise_sms_fixlink'] = 'Update your details here: {$a}';
$string['rise_setpassword_title'] = 'Set your password';
$string['rise_setpassword_intro'] = 'Choose a password for your REB e-Learning account ({$a}).';
$string['rise_setpassword_submit'] = 'Set password';
$string['rise_setpassword_done_title'] = 'Password set';
$string['rise_setpassword_done'] = 'Your password has been set. You can now log in with your username {$a}.';
$string['rise_setpassword_done_generic'] = 'Your password has been set. You can now log in with the username sent to you by SMS.';
$string['rise_action_nothing_needed'] = 'Your RISE details are all in order — no action is needed right now.';
$string['rise_password_confirm'] = 'Confirm password';
$string['rise_password_nomatch'] = 'The passwords do not match.';
$string['rise_token_invalid'] = 'This link is not valid. Please use the most recent link sent to you by SMS, or contact your reviewer for a new one.';
$string['rise_token_expired'] = 'This link has expired. Please contact your reviewer to receive a new link by SMS.';
$string['rise_token_used'] = 'This link has already been used. Please contact your reviewer if you need to make further changes.';
$string['rise_action_title'] = 'Fix your RISE details';
$string['rise_action_intro'] = 'Correct your names and National ID, and upload your ID card and NESA result confirmation. Your reviewer will re-check your application.';
$string['rise_action_reviewersaid'] = 'Reviewer comment: ';
$string['rise_action_nid_label'] = 'National ID (16 digits)';
$string['rise_action_note_label'] = 'Note to the reviewer (optional)';
$string['rise_action_submit'] = 'Submit corrections';
$string['rise_action_done_title'] = 'Corrections submitted';
$string['rise_action_done'] = 'Thank you. Your corrections were submitted and your reviewer will re-check your application.';
$string['rise_action_names_required'] = 'First name and last name are required.';
$string['rise_action_nid_format'] = 'The National ID must be exactly 16 digits.';
$string['rise_action_file_idcard'] = 'National ID document (image or PDF)';
$string['rise_action_file_nesaresult'] = 'NESA result confirmation (image or PDF)';
$string['rise_action_upload_failed'] = 'The upload of "{$a}" failed. Please try again.';
$string['rise_action_upload_toobig'] = '"{$a}" is too large. The maximum file size is 8 MB.';
$string['rise_action_upload_badtype'] = '"{$a}" must be an image (JPG/PNG/WebP) or a PDF.';
$string['rise_action_upload_infected'] = '"{$a}" failed the virus scan and was rejected.';
$string['task_ensure_rise_users'] = 'Provision RISE learner accounts (backfill + RISE sync retry)';

// Privacy API.
$string['privacy:metadata:reviews'] = 'NESA eligibility reviews of RISE learners, linked to the provisioned Moodle account.';
$string['privacy:metadata:reviews:userid'] = 'The Moodle user provisioned/linked for this RISE learner.';
$string['privacy:metadata:reviews:campaignid'] = 'The RISE campaign the applicant belongs to (retained after erasure for aggregate statistics only).';
$string['privacy:metadata:reviews:applicantid'] = 'The external RISE applicant identifier (replaced with an opaque value on erasure).';
$string['privacy:metadata:reviews:fullname'] = 'The applicant\'s full name (snapshot from RISE).';
$string['privacy:metadata:reviews:nid'] = 'The applicant\'s National ID.';
$string['privacy:metadata:reviews:phone'] = 'The applicant\'s phone number.';
$string['privacy:metadata:reviews:provincecode'] = 'The applicant\'s province code (location).';
$string['privacy:metadata:reviews:nesaindexnumber'] = 'The NESA Senior 3 confirmation index number.';
$string['privacy:metadata:reviews:applicantdata'] = 'Full JSON snapshot of the RISE applicant record.';
$string['privacy:metadata:reviews:nesastatus'] = 'The NESA eligibility decision.';
$string['privacy:metadata:reviews:comment'] = 'The reviewer\'s comment.';
$string['privacy:metadata:reviews:reviewedby'] = 'The Moodle user who reviewed the applicant.';
$string['privacy:metadata:tokens'] = 'Single-use tokens for set-password and correction deep links (only a hash of the token is stored).';
$string['privacy:metadata:tokens:userid'] = 'The user the link was issued for.';
$string['privacy:metadata:tokens:purpose'] = 'Whether the link sets a password or opens the correction form.';
$string['privacy:metadata:tokens:expires'] = 'When the link expires.';
$string['privacy:metadata:tokens:usedat'] = 'When the link was used.';
$string['privacy:metadata:corrections'] = 'Identity corrections submitted by RISE learners.';
$string['privacy:metadata:corrections:firstname'] = 'The corrected first name.';
$string['privacy:metadata:corrections:lastname'] = 'The corrected last name.';
$string['privacy:metadata:corrections:nid'] = 'The corrected National ID.';
$string['privacy:metadata:corrections:note'] = 'The learner\'s note to the reviewer.';
$string['privacy:metadata:corrections:reviewedby'] = 'The Moodle user who cleared the resubmission.';
$string['privacy:metadata:smslog'] = 'Audit log of SMS notifications sent to RISE learners.';
$string['privacy:metadata:smslog:userid'] = 'The user the SMS was sent to (when an account exists).';
$string['privacy:metadata:smslog:phone'] = 'The recipient phone number.';
$string['privacy:metadata:smslog:message'] = 'The message body as sent.';
$string['privacy:metadata:smslog:status'] = 'Whether the message was sent, failed or skipped.';
$string['privacy:metadata:smslog:error'] = 'Failure detail, which may include the recipient phone number.';
$string['privacy:metadata:files'] = 'Uploaded ID-card and NESA-result documents are stored with the Moodle file API.';
$string['privacy:metadata:rise'] = 'The RISE recruitment platform: the Moodle user id is written back to the applicant record, and corrected names/NIDs are pushed upstream.';
$string['privacy:metadata:rise:linkeduserid'] = 'The Moodle user id linked to the applicant.';
$string['privacy:metadata:rise:fullname'] = 'The corrected full name.';
$string['privacy:metadata:rise:nid'] = 'The corrected National ID.';
$string['privacy:metadata:intouchsms'] = 'The InTouch SMS gateway used to deliver learner notifications.';
$string['privacy:metadata:intouchsms:phone'] = 'The recipient phone number.';
$string['privacy:metadata:intouchsms:message'] = 'The SMS message text.';

// TMIS / NIDA ID validation.
$string['tmisheading'] = 'TMIS / NIDA ID validation';
$string['tmisheading_desc'] = 'Configure access to TMIS for validating a RISE learner\'s National ID against NIDA records.';
$string['tmis_api_url'] = 'TMIS API URL';
$string['tmis_api_url_desc'] = 'Base URL of the TMIS API, e.g. https://tmis.reb.rw/api';
$string['tmis_username'] = 'TMIS username';
$string['tmis_username_desc'] = 'Login identifier (phone/email) used to authenticate to TMIS. Stored on the server.';
$string['tmis_password'] = 'TMIS password';
$string['tmis_password_desc'] = 'Password used to obtain a TMIS session. Stored on the server and never exposed to the browser.';
$string['tmis_timeout'] = 'TMIS request timeout';
$string['tmis_timeout_desc'] = 'HTTP request timeout in seconds for TMIS API calls.';
$string['tmisnotconfigured'] = 'TMIS is not configured. Set the TMIS URL, username and password in the plugin settings.';
$string['tmisauthfailed'] = 'Could not authenticate to TMIS. Check the configured TMIS username and password.';
$string['tmisnotfound'] = 'No NIDA record was found for this National ID.';
$string['tmiserror'] = 'Could not reach TMIS/NIDA. Please try again.';

// General strings.
$string['loading'] = 'Loading...';
$string['error'] = 'An error occurred';
$string['no_data'] = 'No data available';
