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
 * Settings for local_reblibrary.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Create the settings page for this plugin.
    $settings = new admin_settingpage('local_reblibrary', get_string('pluginname', 'local_reblibrary'));

    // Add this page to the admin menu.
    $ADMIN->add('localplugins', $settings);

    // S3 Storage Settings.
    $settings->add(new admin_setting_heading(
        'local_reblibrary/s3heading',
        get_string('s3settings', 'local_reblibrary'),
        get_string('s3settings_desc', 'local_reblibrary')
    ));

    $settings->add(new admin_setting_configtext(
        'local_reblibrary/s3_endpoint',
        get_string('s3endpoint', 'local_reblibrary'),
        get_string('s3endpoint_desc', 'local_reblibrary'),
        'http://garage:3900',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_reblibrary/s3_public_endpoint',
        get_string('s3publicendpoint', 'local_reblibrary'),
        get_string('s3publicendpoint_desc', 'local_reblibrary'),
        'http://localhost:3900',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_reblibrary/s3_access_key',
        get_string('s3accesskey', 'local_reblibrary'),
        get_string('s3accesskey_desc', 'local_reblibrary'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_reblibrary/s3_secret_key',
        get_string('s3secretkey', 'local_reblibrary'),
        get_string('s3secretkey_desc', 'local_reblibrary'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_reblibrary/s3_bucket',
        get_string('s3bucket', 'local_reblibrary'),
        get_string('s3bucket_desc', 'local_reblibrary'),
        'moodle',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_reblibrary/s3_region',
        get_string('s3region', 'local_reblibrary'),
        get_string('s3region_desc', 'local_reblibrary'),
        'rw-central-1',
        PARAM_TEXT
    ));

    // Home Page Filtering Settings.
    $settings->add(new admin_setting_heading(
        'local_reblibrary/homepagefilteringheading',
        get_string('homepagefiltering', 'local_reblibrary'),
        get_string('homepagefiltering_desc', 'local_reblibrary')
    ));

    // Get all labels dynamically from database.
    global $DB;
    $labels = $DB->get_records_menu('local_reblibrary_labels', null, 'label_name ASC', 'id, label_name');

    $settings->add(new admin_setting_configmultiselect(
        'local_reblibrary/homepage_include_labels',
        get_string('homepageincludelabels', 'local_reblibrary'),
        get_string('homepageincludelabels_desc', 'local_reblibrary'),
        [],
        $labels
    ));

    $settings->add(new admin_setting_configmultiselect(
        'local_reblibrary/homepage_exclude_labels',
        get_string('homepageexcludelabels', 'local_reblibrary'),
        get_string('homepageexcludelabels_desc', 'local_reblibrary'),
        [],
        $labels
    ));

    // Reading Materials Page Filtering Settings.
    $settings->add(new admin_setting_heading(
        'local_reblibrary/readingmaterialsfilteringheading',
        get_string('readingmaterialsfiltering', 'local_reblibrary'),
        get_string('readingmaterialsfiltering_desc', 'local_reblibrary')
    ));

    $settings->add(new admin_setting_configmultiselect(
        'local_reblibrary/readingmaterials_include_labels',
        get_string('readingmaterialsincludelabels', 'local_reblibrary'),
        get_string('readingmaterialsincludelabels_desc', 'local_reblibrary'),
        [],
        $labels
    ));

    $settings->add(new admin_setting_configmultiselect(
        'local_reblibrary/readingmaterials_exclude_labels',
        get_string('readingmaterialsexcludelabels', 'local_reblibrary'),
        get_string('readingmaterialsexcludelabels_desc', 'local_reblibrary'),
        [],
        $labels
    ));
}
