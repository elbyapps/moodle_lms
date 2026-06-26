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
 * Settings for local_elby_dashboard.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_elby_dashboard', get_string('pluginname', 'local_elby_dashboard'));
    $ADMIN->add('localplugins', $settings);

    // =============================================
    // SIDEBAR SETTINGS
    // =============================================
    $settings->add(new admin_setting_heading(
        'local_elby_dashboard/sidenavheading',
        get_string('sidenavheading', 'local_elby_dashboard'),
        get_string('sidenavheading_desc', 'local_elby_dashboard')
    ));

    // Sidenav Title.
    $settings->add(new admin_setting_configtext(
        'local_elby_dashboard/sidenavtitle',
        get_string('sidenavtitle', 'local_elby_dashboard'),
        get_string('sidenavtitle_desc', 'local_elby_dashboard'),
        'Dashboard',
        PARAM_TEXT
    ));

    // Sidenav Logo.
    $settings->add(new admin_setting_configstoredfile(
        'local_elby_dashboard/sidenavlogo',
        get_string('sidenavlogo', 'local_elby_dashboard'),
        get_string('sidenavlogo_desc', 'local_elby_dashboard'),
        'logo',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.jpg', '.jpeg', '.png', '.svg', '.gif']]
    ));

    // =============================================
    // COLOR SETTINGS
    // =============================================
    $settings->add(new admin_setting_heading(
        'local_elby_dashboard/colorsheading',
        get_string('colorsheading', 'local_elby_dashboard'),
        get_string('colorsheading_desc', 'local_elby_dashboard')
    ));

    // Sidebar Accent Color.
    $settings->add(new admin_setting_configcolourpicker(
        'local_elby_dashboard/sidenavaccentcolor',
        get_string('sidenavaccentcolor', 'local_elby_dashboard'),
        get_string('sidenavaccentcolor_desc', 'local_elby_dashboard'),
        '#005198'
    ));

    // Stat Card 1 Color (Students).
    $settings->add(new admin_setting_configcolourpicker(
        'local_elby_dashboard/statcard1color',
        get_string('statcard1color', 'local_elby_dashboard'),
        get_string('statcard1color_desc', 'local_elby_dashboard'),
        '#cffafe'
    ));

    // Stat Card 2 Color (Teachers).
    $settings->add(new admin_setting_configcolourpicker(
        'local_elby_dashboard/statcard2color',
        get_string('statcard2color', 'local_elby_dashboard'),
        get_string('statcard2color_desc', 'local_elby_dashboard'),
        '#fef3c7'
    ));

    // Stat Card 3 Color (Users).
    $settings->add(new admin_setting_configcolourpicker(
        'local_elby_dashboard/statcard3color',
        get_string('statcard3color', 'local_elby_dashboard'),
        get_string('statcard3color_desc', 'local_elby_dashboard'),
        '#f3e8ff'
    ));

    // Stat Card 4 Color (Courses).
    $settings->add(new admin_setting_configcolourpicker(
        'local_elby_dashboard/statcard4color',
        get_string('statcard4color', 'local_elby_dashboard'),
        get_string('statcard4color_desc', 'local_elby_dashboard'),
        '#dcfce7'
    ));

    // Chart Primary Color.
    $settings->add(new admin_setting_configcolourpicker(
        'local_elby_dashboard/chartprimarycolor',
        get_string('chartprimarycolor', 'local_elby_dashboard'),
        get_string('chartprimarycolor_desc', 'local_elby_dashboard'),
        '#22d3ee'
    ));

    // Chart Secondary Color.
    $settings->add(new admin_setting_configcolourpicker(
        'local_elby_dashboard/chartsecondarycolor',
        get_string('chartsecondarycolor', 'local_elby_dashboard'),
        get_string('chartsecondarycolor_desc', 'local_elby_dashboard'),
        '#a78bfa'
    ));

    // =============================================
    // HEADER OPTIONS
    // =============================================
    $settings->add(new admin_setting_heading(
        'local_elby_dashboard/headerheading',
        get_string('headerheading', 'local_elby_dashboard'),
        get_string('headerheading_desc', 'local_elby_dashboard')
    ));

    // Show Search Bar.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/showsearchbar',
        get_string('showsearchbar', 'local_elby_dashboard'),
        get_string('showsearchbar_desc', 'local_elby_dashboard'),
        1
    ));

    // Show Notifications.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/shownotifications',
        get_string('shownotifications', 'local_elby_dashboard'),
        get_string('shownotifications_desc', 'local_elby_dashboard'),
        1
    ));

    // Show User Profile.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/showuserprofile',
        get_string('showuserprofile', 'local_elby_dashboard'),
        get_string('showuserprofile_desc', 'local_elby_dashboard'),
        1
    ));

    // =============================================
    // COURSE REPORT SETTINGS
    // =============================================
    $settings->add(new admin_setting_heading(
        'local_elby_dashboard/reportheading',
        get_string('reportheading', 'local_elby_dashboard'),
        get_string('reportheading_desc', 'local_elby_dashboard')
    ));

    // Enrollment cutoff date (month and day).
    // Build month choices using userdate() for proper localization.
    $monthchoices = [];
    for ($i = 1; $i <= 12; $i++) {
        // Create a timestamp for the 15th of each month (to avoid timezone edge cases).
        $timestamp = mktime(12, 0, 0, $i, 15, 2000);
        $monthchoices[(string)$i] = userdate($timestamp, '%B');
    }
    $settings->add(new admin_setting_configselect(
        'local_elby_dashboard/enrollment_cutoff_month',
        get_string('enrollment_cutoff_month', 'local_elby_dashboard'),
        get_string('enrollment_cutoff_month_desc', 'local_elby_dashboard'),
        '9', // Default: September
        $monthchoices
    ));

    // Enrollment cutoff day.
    $days = [];
    for ($i = 1; $i <= 31; $i++) {
        $days[(string)$i] = (string)$i;
    }
    $settings->add(new admin_setting_configselect(
        'local_elby_dashboard/enrollment_cutoff_day',
        get_string('enrollment_cutoff_day', 'local_elby_dashboard'),
        get_string('enrollment_cutoff_day_desc', 'local_elby_dashboard'),
        '1', // Default: 1st
        $days
    ));

    // =============================================
    // BLENDED LEARNING SETTINGS
    // =============================================
    $settings->add(new admin_setting_heading(
        'local_elby_dashboard/blendedlearningheading',
        get_string('blendedlearningheading', 'local_elby_dashboard'),
        get_string('blendedlearningheading_desc', 'local_elby_dashboard')
    ));

    // Blended Learning Parent Category.
    $categories = \core_course_category::make_categories_list();
    $categoryoptions = [0 => get_string('none')] + $categories;
    $settings->add(new admin_setting_configselect(
        'local_elby_dashboard/blended_learning_category',
        get_string('blended_learning_category', 'local_elby_dashboard'),
        get_string('blended_learning_category_desc', 'local_elby_dashboard'),
        0,
        $categoryoptions
    ));

    // =============================================
    // MENU CUSTOMIZATION
    // =============================================
    $settings->add(new admin_setting_heading(
        'local_elby_dashboard/menuheading',
        get_string('menuheading', 'local_elby_dashboard'),
        get_string('menuheading_desc', 'local_elby_dashboard')
    ));

    // Menu items to show/hide.
    $menuitems = ['courses', 'presence', 'communication', 'event', 'pedagogy', 'message', 'completion', 'settings', 'blended_learning'];

    foreach ($menuitems as $item) {
        $settings->add(new admin_setting_configcheckbox(
            'local_elby_dashboard/showmenu_' . $item,
            get_string('showmenu_' . $item, 'local_elby_dashboard'),
            get_string('showmenu_' . $item . '_desc', 'local_elby_dashboard'),
            1
        ));
    }

    // =============================================
    // SDMS INTEGRATION
    // =============================================
    $settings->add(new admin_setting_heading(
        'local_elby_dashboard/sdmsheading',
        get_string('sdmsheading', 'local_elby_dashboard'),
        get_string('sdmsheading_desc', 'local_elby_dashboard')
    ));

    // TDMP Gateway URL.
    $settings->add(new admin_setting_configtext(
        'local_elby_dashboard/tdmp_api_url',
        get_string('tdmp_api_url', 'local_elby_dashboard'),
        get_string('tdmp_api_url_desc', 'local_elby_dashboard'),
        'https://tdmp.rtb.gov.rw/api/v1',
        PARAM_URL
    ));

    // TDMP Gateway API key.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_elby_dashboard/tdmp_api_key',
        get_string('tdmp_api_key', 'local_elby_dashboard'),
        get_string('tdmp_api_key_desc', 'local_elby_dashboard'),
        ''
    ));

    // TDMP Timeout.
    $settings->add(new admin_setting_configtext(
        'local_elby_dashboard/tdmp_timeout',
        get_string('tdmp_timeout', 'local_elby_dashboard'),
        get_string('tdmp_timeout_desc', 'local_elby_dashboard'),
        '30',
        PARAM_INT
    ));

    // Proxy TDMP lookups through local_syncqueue to central (school tier).
    // Enable on school servers, which must not hold the TDMP API key.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/tdmp_proxy_mode',
        get_string('tdmp_proxy_mode', 'local_elby_dashboard'),
        get_string('tdmp_proxy_mode_desc', 'local_elby_dashboard'),
        0
    ));

    // SDMS Signup Email Domain.
    $settings->add(new admin_setting_configtext(
        'local_elby_dashboard/sdms_signup_email_domain',
        get_string('sdms_signup_email_domain', 'local_elby_dashboard'),
        get_string('sdms_signup_email_domain_desc', 'local_elby_dashboard'),
        'rtb.ac.rw',
        PARAM_HOST
    ));

    // Auto-enroll students by trade & level.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/auto_enroll_enabled',
        get_string('auto_enroll_enabled', 'local_elby_dashboard'),
        get_string('auto_enroll_enabled_desc', 'local_elby_dashboard'),
        1
    ));

    // Auto-add students to a trade/level/academic-year cohort.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/auto_cohort_enabled',
        get_string('auto_cohort_enabled', 'local_elby_dashboard'),
        get_string('auto_cohort_enabled_desc', 'local_elby_dashboard'),
        1
    ));

    // Auto cohort-sync enrolment: link courses to matching trade/level/year cohorts.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/auto_cohort_enrol_enabled',
        get_string('auto_cohort_enrol_enabled', 'local_elby_dashboard'),
        get_string('auto_cohort_enrol_enabled_desc', 'local_elby_dashboard'),
        1
    ));

    // Enrich courses with trade/level (and the creating teacher's school).
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/auto_course_enrich_enabled',
        get_string('auto_course_enrich_enabled', 'local_elby_dashboard'),
        get_string('auto_course_enrich_enabled_desc', 'local_elby_dashboard'),
        1
    ));

    // Stamp the creating teacher's school onto their courses.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/course_enrich_set_school',
        get_string('course_enrich_set_school', 'local_elby_dashboard'),
        get_string('course_enrich_set_school_desc', 'local_elby_dashboard'),
        1
    ));

    // Force onboarding (user type + SDMS link) after login. Default OFF — enable only after testing.
    $settings->add(new admin_setting_configcheckbox(
        'local_elby_dashboard/force_onboarding',
        get_string('force_onboarding', 'local_elby_dashboard'),
        get_string('force_onboarding_desc', 'local_elby_dashboard'),
        0
    ));
}
