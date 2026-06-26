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
 * Elby Dashboard schools page.
 *
 * Displays either the school directory or a single school detail view.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/elby_dashboard/lib.php');

// Get optional parameters.
$schoolcode = optional_param('school_code', '', PARAM_TEXT);
$view = optional_param('view', '', PARAM_TEXT);

// Require login.
require_login();

// Set up the page context.
$context = context_system::instance();
$PAGE->set_context($context);

// Check capability.
require_capability('local/elby_dashboard:viewreports', $context);

$urlparams = [];
if (!empty($schoolcode)) {
    $urlparams['school_code'] = $schoolcode;
}
if (!empty($view)) {
    $urlparams['view'] = $view;
}

$PAGE->set_url(new moodle_url('/local/elby_dashboard/schools.php', $urlparams));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('page_title', 'local_elby_dashboard') . ' - ' .
    get_string('schools_directory', 'local_elby_dashboard'));
$PAGE->set_heading(get_string('page_heading', 'local_elby_dashboard'));

// Add body classes.
$PAGE->add_body_class('local-elby-dashboard-plugin');
$PAGE->add_body_class('local-elby-dashboard-page');
$PAGE->add_body_class('local-elby-dashboard-schools');

// Load CSS and JS.
$PAGE->requires->css('/local/elby_dashboard/styles.css');
$PAGE->requires->js_call_amd('local_elby_dashboard/dashboard', 'init');

// Breadcrumbs.
$PAGE->navbar->add(get_string('pluginname', 'local_elby_dashboard'), new moodle_url('/local/elby_dashboard/index.php'));
$PAGE->navbar->add(get_string('schools_directory', 'local_elby_dashboard'));

// Get sidenav configuration.
$sidenavtitle = get_config('local_elby_dashboard', 'sidenavtitle') ?: 'Dashboard';
$sidenavlogourl = '';

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'local_elby_dashboard', 'logo', 0, 'sortorder', false);
if ($files) {
    $file = reset($files);
    $sidenavlogourl = moodle_url::make_pluginfile_url(
        $context->id, 'local_elby_dashboard', 'logo', 0, '/', $file->get_filename()
    )->out();
}

$sidenavconfig = [
    'title' => $sidenavtitle,
    'logoUrl' => $sidenavlogourl ?: null,
];

// Get theme configuration with capability-based menu items.
$isadmin = has_capability('moodle/site:config', $context);
$canviewreports = has_capability('local/elby_dashboard:viewreports', $context);

$themeconfig = [
    'sidenavAccentColor' => get_config('local_elby_dashboard', 'sidenavaccentcolor') ?: '#005198',
    'statCard1Color' => get_config('local_elby_dashboard', 'statcard1color') ?: '#cffafe',
    'statCard2Color' => get_config('local_elby_dashboard', 'statcard2color') ?: '#fef3c7',
    'statCard3Color' => get_config('local_elby_dashboard', 'statcard3color') ?: '#f3e8ff',
    'statCard4Color' => get_config('local_elby_dashboard', 'statcard4color') ?: '#dcfce7',
    'chartPrimaryColor' => get_config('local_elby_dashboard', 'chartprimarycolor') ?: '#22d3ee',
    'chartSecondaryColor' => get_config('local_elby_dashboard', 'chartsecondarycolor') ?: '#a78bfa',
    'showSearchBar' => (bool) (get_config('local_elby_dashboard', 'showsearchbar') ?? 1),
    'showNotifications' => (bool) (get_config('local_elby_dashboard', 'shownotifications') ?? 1),
    'showUserProfile' => (bool) (get_config('local_elby_dashboard', 'showuserprofile') ?? 1),
    'menuVisibility' => [
        'courses' => (bool) (get_config('local_elby_dashboard', 'showmenu_courses') ?? 1),
        'presence' => (bool) (get_config('local_elby_dashboard', 'showmenu_presence') ?? 1),
        'communication' => (bool) (get_config('local_elby_dashboard', 'showmenu_communication') ?? 1),
        'event' => (bool) (get_config('local_elby_dashboard', 'showmenu_event') ?? 1),
        'pedagogy' => (bool) (get_config('local_elby_dashboard', 'showmenu_pedagogy') ?? 1),
        'message' => (bool) (get_config('local_elby_dashboard', 'showmenu_message') ?? 1),
        'completion' => (bool) (get_config('local_elby_dashboard', 'showmenu_completion') ?? 1),
        'settings' => (bool) (get_config('local_elby_dashboard', 'showmenu_settings') ?? 1),
        'blended_learning' => $canviewreports && (bool) (get_config('local_elby_dashboard', 'showmenu_blended_learning') ?? 1),
        'schools' => $canviewreports,
        'students' => $canviewreports,
        'teachers' => $canviewreports,
        'traffic' => $canviewreports,
        'accesslog' => $canviewreports,
        'admin' => $isadmin,
    ],
];

// Prepare user data.
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
    'isAdmin' => $isadmin,
];

// Determine active page.
$activepage = 'schools';
if ($view === 'detail' && !empty($schoolcode)) {
    $activepage = 'school_detail';
}

// Prepare stats data (minimal for non-home pages).
$statsdata = [
    'totalCourses' => 0,
    'totalUsers' => 0,
    'totalEnrollments' => 0,
    'totalActivities' => 0,
    'totalTeachers' => 0,
    'totalStudents' => 0,
    'teachers' => [],
];

// Pre-load school directory data for the grid.
$schoolsdata = [];
if ($activepage === 'schools') {
    $schools = $DB->get_records('elby_schools', null, 'school_name ASC');

    // Get live user counts per school from elby_sdms_users (always available).
    $usercounts = $DB->get_records_sql(
        "SELECT schoolid,
                SUM(CASE WHEN user_type = 'student' THEN 1 ELSE 0 END) AS student_count,
                SUM(CASE WHEN user_type = 'teacher' THEN 1 ELSE 0 END) AS teacher_count,
                COUNT(*) AS total_users
         FROM {elby_sdms_users}
         GROUP BY schoolid"
    );

    // Get active user count per school (accessed within last 7 days).
    $activethreshold = time() - (7 * 86400);
    $activecounts = $DB->get_records_sql(
        "SELECT su.schoolid, COUNT(DISTINCT su.userid) AS active_count
         FROM {elby_sdms_users} su
         JOIN {user} u ON u.id = su.userid
         WHERE u.lastaccess >= :threshold AND u.deleted = 0
         GROUP BY su.schoolid",
        ['threshold' => $activethreshold]
    );

    // Get at-risk count per school (no access in 7+ days or never).
    $atriskcounts = $DB->get_records_sql(
        "SELECT su.schoolid, COUNT(DISTINCT su.userid) AS atrisk_count
         FROM {elby_sdms_users} su
         JOIN {user} u ON u.id = su.userid
         WHERE (u.lastaccess < :threshold OR u.lastaccess IS NULL) AND u.deleted = 0
         GROUP BY su.schoolid",
        ['threshold' => $activethreshold]
    );

    foreach ($schools as $school) {
        // Get pre-computed metrics (may be empty if tasks haven't run).
        $metrics = $DB->get_records_sql(
            "SELECT * FROM {elby_school_metrics}
             WHERE schoolid = :schoolid AND courseid = 0 AND period_type = 'weekly'
             ORDER BY period_start DESC LIMIT 1",
            ['schoolid' => $school->id]
        );
        $m = reset($metrics);

        // Use live counts from elby_sdms_users, falling back to metrics if available.
        $uc = $usercounts[$school->id] ?? null;
        $ac = $activecounts[$school->id] ?? null;
        $arc = $atriskcounts[$school->id] ?? null;

        $enrolled = $uc ? (int) $uc->student_count : ($m ? (int) $m->total_enrolled : 0);
        $active = $ac ? (int) $ac->active_count : ($m ? (int) $m->total_active : 0);
        $atrisk = $arc ? (int) $arc->atrisk_count : ($m ? (int) $m->at_risk_count : 0);

        $parsed = local_elby_dashboard_parse_region_code($school->region_code ?? '');
        $schoolsdata[] = [
            'school_code' => $school->school_code,
            'school_name' => $school->school_name,
            'region_code' => $school->region_code ?? '',
            'total_enrolled' => $enrolled,
            'total_active' => $active,
            'at_risk_count' => $atrisk,
            'avg_quiz_score' => $m && $m->avg_quiz_score !== null ? round((float) $m->avg_quiz_score, 1) : null,
            'avg_actions_per_student' => $m && $m->avg_actions_per_student !== null ? round((float) $m->avg_actions_per_student, 1) : 0,
            'province' => $parsed['province'],
            'district' => $parsed['district'],
        ];
    }
}

// Prepare data for template.
$templatecontext = [
    'user_data_json' => json_encode($userdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'stats_data_json' => json_encode($statsdata, JSON_HEX_QUOT | JSON_HEX_APOS),
    'sidenav_config_json' => json_encode($sidenavconfig, JSON_HEX_QUOT | JSON_HEX_APOS),
    'theme_config_json' => json_encode($themeconfig, JSON_HEX_QUOT | JSON_HEX_APOS),
    'active_page' => $activepage,
    'school_code' => $schoolcode,
    'schools_data_json' => !empty($schoolsdata) ? json_encode($schoolsdata, JSON_HEX_QUOT | JSON_HEX_APOS) : '',
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elby_dashboard/root', $templatecontext);
echo $OUTPUT->footer();
