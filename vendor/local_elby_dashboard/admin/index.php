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
 * Elby Dashboard admin page.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/local/elby_dashboard/lib.php');

// Require login and admin capability.
require_login();

// Set up the page context.
$context = context_system::instance();
$PAGE->set_context($context);

// Check for admin capability - only site admins can access.
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/elby_dashboard/admin/index.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('admin_page_title', 'local_elby_dashboard'));
$PAGE->set_heading(get_string('admin_page_heading', 'local_elby_dashboard'));

// Add body classes for plugin-specific page styling.
$PAGE->add_body_class('local-elby-dashboard-plugin');
$PAGE->add_body_class('local-elby-dashboard-page');
$PAGE->add_body_class('local-elby-dashboard-admin-page');

// Load custom CSS.
$PAGE->requires->css('/local/elby_dashboard/styles.css');

// Load custom JavaScript module.
$PAGE->requires->js_call_amd('local_elby_dashboard/dashboard', 'init');

// Add breadcrumb navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_elby_dashboard'));
$PAGE->navbar->add(get_string('nav_admin', 'local_elby_dashboard'));

// Get sidenav configuration.
$sidenavtitle = get_config('local_elby_dashboard', 'sidenavtitle') ?: 'Dashboard';
$sidenavlogourl = '';

// Get logo file URL if it exists.
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'local_elby_dashboard', 'logo', 0, 'sortorder', false);
if ($files) {
    $file = reset($files);
    $sidenavlogourl = moodle_url::make_pluginfile_url(
        $context->id,
        'local_elby_dashboard',
        'logo',
        0,
        '/',
        $file->get_filename()
    )->out();
}

$sidenavconfig = [
    'title' => $sidenavtitle,
    'logoUrl' => $sidenavlogourl ?: null,
];

// Get theme configuration.
$themeconfig = [
    // Colors.
    'sidenavAccentColor' => get_config('local_elby_dashboard', 'sidenavaccentcolor') ?: '#005198',
    'statCard1Color' => get_config('local_elby_dashboard', 'statcard1color') ?: '#cffafe',
    'statCard2Color' => get_config('local_elby_dashboard', 'statcard2color') ?: '#fef3c7',
    'statCard3Color' => get_config('local_elby_dashboard', 'statcard3color') ?: '#f3e8ff',
    'statCard4Color' => get_config('local_elby_dashboard', 'statcard4color') ?: '#dcfce7',
    'chartPrimaryColor' => get_config('local_elby_dashboard', 'chartprimarycolor') ?: '#22d3ee',
    'chartSecondaryColor' => get_config('local_elby_dashboard', 'chartsecondarycolor') ?: '#a78bfa',
    // Header options.
    'showSearchBar' => (bool) (get_config('local_elby_dashboard', 'showsearchbar') ?? 1),
    'showNotifications' => (bool) (get_config('local_elby_dashboard', 'shownotifications') ?? 1),
    'showUserProfile' => (bool) (get_config('local_elby_dashboard', 'showuserprofile') ?? 1),
    // Menu visibility.
    'menuVisibility' => [
        'courses' => (bool) (get_config('local_elby_dashboard', 'showmenu_courses') ?? 1),
        'presence' => (bool) (get_config('local_elby_dashboard', 'showmenu_presence') ?? 1),
        'communication' => (bool) (get_config('local_elby_dashboard', 'showmenu_communication') ?? 1),
        'event' => (bool) (get_config('local_elby_dashboard', 'showmenu_event') ?? 1),
        'pedagogy' => (bool) (get_config('local_elby_dashboard', 'showmenu_pedagogy') ?? 1),
        'message' => (bool) (get_config('local_elby_dashboard', 'showmenu_message') ?? 1),
        'completion' => (bool) (get_config('local_elby_dashboard', 'showmenu_completion') ?? 1),
        'settings' => (bool) (get_config('local_elby_dashboard', 'showmenu_settings') ?? 1),
    ],
];

// Prepare user data for Preact (via data attributes).
$userroles = [];
foreach (get_user_roles($context, $USER->id) as $role) {
    $userroles[] = $role->shortname;
}

$userdata = [
    'id' => $USER->id,
    'fullname' => fullname($USER),
    'firstname' => $USER->firstname,
    'lastname' => $USER->lastname,
    'email' => $USER->email,
    'avatar' => $OUTPUT->get_generated_image_for_id($USER->id),
    'roles' => $userroles,
    'isAdmin' => true,
];

// Prepare comprehensive stats data for admin dashboard.
$statsdata = [
    'totalCourses' => $DB->count_records('course') - 1,
    'totalUsers' => $DB->count_records('user', ['deleted' => 0]) - 1,
    'totalEnrollments' => $DB->count_records('user_enrolments'),
    'totalActivities' => $DB->count_records('course_modules'),
    'activeUsers' => $DB->count_records_select('user', 'deleted = 0 AND lastaccess > ?', [time() - 86400 * 30]),
    'totalTeachers' => $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ra.userid)
         FROM {role_assignments} ra
         JOIN {role} r ON r.id = ra.roleid
         WHERE r.shortname IN ('teacher', 'editingteacher')"
    ),
    'totalStudents' => $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ra.userid)
         FROM {role_assignments} ra
         JOIN {role} r ON r.id = ra.roleid
         WHERE r.shortname = 'student'"
    ),
];

// Pre-load admin panel data.
$ttl = (int) (get_config('local_elby_dashboard', 'sdms_cache_ttl') ?: 604800);
$stalethreshold = time() - $ttl;

$adminstats = [
    'linked_users' => $DB->count_records('elby_sdms_users'),
    'stale_users' => $DB->count_records_select('elby_sdms_users', 'last_synced < :threshold',
        ['threshold' => $stalethreshold]),
    'error_users' => $DB->count_records('elby_sdms_users', ['sync_status' => 0]),
    'total_schools' => $DB->count_records('elby_schools'),
    'user_metrics_count' => $DB->count_records('elby_user_metrics'),
    'school_metrics_count' => $DB->count_records('elby_school_metrics'),
];

// Get recent sync logs.
$synclogs = $DB->get_records('elby_sync_log', null, 'timecreated DESC', '*', 0, 20);
$formattedlogs = [];
foreach ($synclogs as $log) {
    $formattedlogs[] = [
        'id' => (int) $log->id,
        'sync_type' => $log->sync_type,
        'entity_id' => $log->entity_id ?? '',
        'operation' => $log->operation,
        'error_message' => $log->error_message,
        'triggered_by' => $log->triggered_by ?? '',
        'timecreated' => (int) $log->timecreated,
    ];
}

$admindata = [
    'stats' => $adminstats,
    'logs' => $formattedlogs,
];

// Add capability-based menu visibility.
$themeconfig['menuVisibility']['blended_learning'] = (bool) (get_config('local_elby_dashboard', 'showmenu_blended_learning') ?? 1);
$themeconfig['menuVisibility']['schools'] = true;
$themeconfig['menuVisibility']['students'] = true;
$themeconfig['menuVisibility']['teachers'] = true;
$themeconfig['menuVisibility']['traffic'] = true;
$themeconfig['menuVisibility']['accesslog'] = true;
$themeconfig['menuVisibility']['admin'] = true;

// Prepare data for template.
$templatecontext = [
    'user_data_json' => json_encode($userdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'stats_data_json' => json_encode($statsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'sidenav_config_json' => json_encode($sidenavconfig, JSON_HEX_QUOT | JSON_HEX_APOS),
    'theme_config_json' => json_encode($themeconfig, JSON_HEX_QUOT | JSON_HEX_APOS),
    'admin_data_json' => json_encode($admindata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'active_page' => 'admin',
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elby_dashboard/root', $templatecontext);
echo $OUTPUT->footer();
