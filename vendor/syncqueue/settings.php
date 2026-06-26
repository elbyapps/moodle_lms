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
 * Admin settings for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Create a settings category for the plugin.
    $ADMIN->add('localplugins', new admin_category(
        'local_syncqueue_category',
        get_string('pluginname', 'local_syncqueue')
    ));

    // Main settings page.
    $settings = new admin_settingpage('local_syncqueue', get_string('settings', 'local_syncqueue'));

    // Add JS for dynamic show/hide of mode-specific settings.
    $settings->add(new \local_syncqueue\admin_setting_jsinclude(
        'local_syncqueue/settingsjs',
        'local_syncqueue/settings'
    ));

    // Enable/disable sync queue.
    $settings->add(new admin_setting_configcheckbox(
        'local_syncqueue/enabled',
        get_string('enabled', 'local_syncqueue'),
        get_string('enabled_desc', 'local_syncqueue'),
        0
    ));

    // Operation mode.
    $settings->add(new admin_setting_configselect(
        'local_syncqueue/mode',
        get_string('mode', 'local_syncqueue'),
        get_string('mode_desc', 'local_syncqueue'),
        'school',
        [
            'school' => get_string('mode_school', 'local_syncqueue'),
            'central' => get_string('mode_central', 'local_syncqueue'),
        ]
    ));

    // ===========================================
    // SCHOOL MODE SETTINGS
    // ===========================================
    $settings->add(new admin_setting_heading(
        'local_syncqueue/schoolheading',
        get_string('schoolsettings', 'local_syncqueue'),
        get_string('schoolsettings_desc', 'local_syncqueue')
    ));

    // School identifier.
    $settings->add(new admin_setting_configtext(
        'local_syncqueue/schoolid',
        get_string('schoolid', 'local_syncqueue'),
        get_string('schoolid_desc', 'local_syncqueue'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // Central server URL.
    $settings->add(new admin_setting_configtext(
        'local_syncqueue/centralserver',
        get_string('centralserver', 'local_syncqueue'),
        get_string('centralserver_desc', 'local_syncqueue'),
        '',
        PARAM_URL
    ));

    // Web service token (for Moodle web service authentication).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_syncqueue/wstoken',
        get_string('wstoken', 'local_syncqueue'),
        get_string('wstoken_desc', 'local_syncqueue'),
        ''
    ));

    // School API key (for school-level authentication).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_syncqueue/apikey',
        get_string('apikey', 'local_syncqueue'),
        get_string('apikey_desc', 'local_syncqueue'),
        ''
    ));

    // Sync interval.
    $settings->add(new admin_setting_configduration(
        'local_syncqueue/syncinterval',
        get_string('syncinterval', 'local_syncqueue'),
        get_string('syncinterval_desc', 'local_syncqueue'),
        300 // 5 minutes default.
    ));

    // ===========================================
    // CENTRAL MODE SETTINGS
    // ===========================================
    $settings->add(new admin_setting_heading(
        'local_syncqueue/centralheading',
        get_string('centralsettings', 'local_syncqueue'),
        get_string('centralsettings_desc', 'local_syncqueue')
    ));

    // Allow school registration.
    $settings->add(new admin_setting_configcheckbox(
        'local_syncqueue/allowregistration',
        get_string('allowregistration', 'local_syncqueue'),
        get_string('allowregistration_desc', 'local_syncqueue'),
        0
    ));

    // Registration secret (schools need this to register).
    $settings->add(new admin_setting_configpasswordunmask(
        'local_syncqueue/registrationsecret',
        get_string('registrationsecret', 'local_syncqueue'),
        get_string('registrationsecret_desc', 'local_syncqueue'),
        ''
    ));

    // ===========================================
    // COMMON SETTINGS
    // ===========================================
    $settings->add(new admin_setting_heading(
        'local_syncqueue/commonheading',
        get_string('commonsettings', 'local_syncqueue'),
        get_string('commonsettings_desc', 'local_syncqueue')
    ));

    // Batch size.
    $settings->add(new admin_setting_configtext(
        'local_syncqueue/batchsize',
        get_string('batchsize', 'local_syncqueue'),
        get_string('batchsize_desc', 'local_syncqueue'),
        100,
        PARAM_INT
    ));

    // Max retries.
    $settings->add(new admin_setting_configtext(
        'local_syncqueue/maxretries',
        get_string('maxretries', 'local_syncqueue'),
        get_string('maxretries_desc', 'local_syncqueue'),
        5,
        PARAM_INT
    ));

    // Max file size for inline sync (larger files use separate transfer).
    $settings->add(new admin_setting_configtext(
        'local_syncqueue/maxfilesize',
        get_string('maxfilesize', 'local_syncqueue'),
        get_string('maxfilesize_desc', 'local_syncqueue'),
        10485760, // 10MB default
        PARAM_INT
    ));

    // Debug mode.
    $settings->add(new admin_setting_configcheckbox(
        'local_syncqueue/debug',
        get_string('debug', 'local_syncqueue'),
        get_string('debug_desc', 'local_syncqueue'),
        0
    ));

    // Add settings page to category.
    $ADMIN->add('local_syncqueue_category', $settings);

    // Dashboard page.
    $ADMIN->add('local_syncqueue_category', new admin_externalpage(
        'local_syncqueue_dashboard',
        get_string('dashboard', 'local_syncqueue'),
        new moodle_url('/local/syncqueue/dashboard.php')
    ));

    // Queue viewer page (school mode).
    $ADMIN->add('local_syncqueue_category', new admin_externalpage(
        'local_syncqueue_queue',
        get_string('queueviewer', 'local_syncqueue'),
        new moodle_url('/local/syncqueue/queue.php')
    ));

    // School management page (central mode).
    $ADMIN->add('local_syncqueue_category', new admin_externalpage(
        'local_syncqueue_schools',
        get_string('schoolmanagement', 'local_syncqueue'),
        new moodle_url('/local/syncqueue/schools.php')
    ));

    // Push courses page (central mode).
    $ADMIN->add('local_syncqueue_category', new admin_externalpage(
        'local_syncqueue_courses',
        get_string('pushcourses', 'local_syncqueue'),
        new moodle_url('/local/syncqueue/courses.php')
    ));

    // Pull priorities page (school mode).
    $ADMIN->add('local_syncqueue_category', new admin_externalpage(
        'local_syncqueue_pullpriorities',
        get_string('pullpriorities', 'local_syncqueue'),
        new moodle_url('/local/syncqueue/pull_priorities.php')
    ));
}
