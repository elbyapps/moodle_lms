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
 * Upgrade steps for local_elby_dashboard.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_elby_dashboard_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026021301) {
        // Create all SDMS integration tables from install.xml.
        // Load the XMLDB schema and create any tables that don't exist yet.
        $xmldbfile = new xmldb_file($CFG->dirroot . '/local/elby_dashboard/db/install.xml');
        $xmldbfile->loadXMLStructure();
        $structure = $xmldbfile->getStructure();
        $tables = $structure->getTables();

        foreach ($tables as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }

        upgrade_plugin_savepoint(true, 2026021301, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026021312) {
        // Add all missing SDMS student fields to elby_students.
        $table = new xmldb_table('elby_students');

        $fields = [
            new xmldb_field('gender', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'registration_date'),
            new xmldb_field('date_of_birth', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'gender'),
            new xmldb_field('study_level', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'date_of_birth'),
            new xmldb_field('class_grade', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'study_level'),
            new xmldb_field('grade_code', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'class_grade'),
            new xmldb_field('class_group_name', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'grade_code'),
            new xmldb_field('parent_guardian_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'class_group_name'),
            new xmldb_field('parent_guardian_nid', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'parent_guardian_name'),
            new xmldb_field('address', XMLDB_TYPE_TEXT, null, null, null, null, null, 'parent_guardian_nid'),
            new xmldb_field('emergency_contact_person', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'address'),
            new xmldb_field('emergency_contact_number', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'emergency_contact_person'),
            new xmldb_field('inactive_reason', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'emergency_contact_number'),
            new xmldb_field('sdms_modified_since', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'inactive_reason'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Add indexes.
        $index = new xmldb_index('idx_gender', XMLDB_INDEX_NOTUNIQUE, ['gender']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('idx_grade_code', XMLDB_INDEX_NOTUNIQUE, ['grade_code']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026021312, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026021313) {
        // Add missing SDMS staff fields to elby_teachers.
        $table = new xmldb_table('elby_teachers');

        $fields = [
            new xmldb_field('gender', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'position'),
            new xmldb_field('official_document_id', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'gender'),
            new xmldb_field('mobile_phone', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'official_document_id'),
            new xmldb_field('company_email', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'mobile_phone'),
            new xmldb_field('employment_status', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'company_email'),
            new xmldb_field('employment_start_date', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'employment_status'),
            new xmldb_field('employment_end_date', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'employment_start_date'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $index = new xmldb_index('idx_gender', XMLDB_INDEX_NOTUNIQUE, ['gender']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026021313, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026062100) {
        // TDMP gateway migration: drop the school-hierarchy + staff-subject tables
        // (grade now comes straight from the student record; details fetched live).

        // Drop the classid FK column from elby_students first — it referenced
        // elby_classgroups, which is about to be removed.
        $table = new xmldb_table('elby_students');
        $field = new xmldb_field('classid');
        if ($dbman->field_exists($table, $field)) {
            $key = new xmldb_key('fk_classid', XMLDB_KEY_FOREIGN, ['classid'], 'elby_classgroups', ['id']);
            $dbman->drop_key($table, $key);
            $index = new xmldb_index('idx_classid', XMLDB_INDEX_NOTUNIQUE, ['classid']);
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
            $dbman->drop_field($table, $field);
        }

        // Drop the hierarchy chain (child-first) and the unused staff-subjects table.
        foreach (['elby_staff_subjects', 'elby_classgroups', 'elby_grades', 'elby_combinations', 'elby_levels'] as $tablename) {
            $droptable = new xmldb_table($tablename);
            if ($dbman->table_exists($droptable)) {
                $dbman->drop_table($droptable);
            }
        }

        // Migrate gateway settings from the old sdms_* keys to tdmp_*.
        $oldurl = get_config('local_elby_dashboard', 'sdms_api_url');
        if ($oldurl !== false && get_config('local_elby_dashboard', 'tdmp_api_url') === false) {
            set_config('tdmp_api_url', $oldurl, 'local_elby_dashboard');
        }
        $oldtimeout = get_config('local_elby_dashboard', 'sdms_timeout');
        if ($oldtimeout !== false && get_config('local_elby_dashboard', 'tdmp_timeout') === false) {
            set_config('tdmp_timeout', $oldtimeout, 'local_elby_dashboard');
        }
        unset_config('sdms_api_url', 'local_elby_dashboard');
        unset_config('sdms_timeout', 'local_elby_dashboard');
        unset_config('sdms_cache_ttl', 'local_elby_dashboard');

        upgrade_plugin_savepoint(true, 2026062100, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026062102) {
        // Create the user_type custom profile field (menu) for forced onboarding.
        if (!$DB->record_exists('user_info_field', ['shortname' => 'usertype'])) {
            $categoryid = $DB->get_field_sql('SELECT MIN(id) FROM {user_info_category}');
            if (!$categoryid) {
                $categoryid = $DB->insert_record('user_info_category',
                    (object) ['name' => get_string('pluginname', 'local_elby_dashboard'), 'sortorder' => 1]);
            }
            $sortorder = (int) $DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) FROM {user_info_field}') + 1;
            $DB->insert_record('user_info_field', (object) [
                'shortname' => 'usertype',
                'name' => get_string('usertype_field', 'local_elby_dashboard'),
                'datatype' => 'menu',
                'description' => '',
                'descriptionformat' => FORMAT_HTML,
                'categoryid' => $categoryid,
                'sortorder' => $sortorder,
                'required' => 0,
                'locked' => 0,
                'visible' => 2,
                'forceunique' => 0,
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_HTML,
                'param1' => "Student\nTeacher\nRTB Staff\nExternal",
                'param2' => '',
                'param3' => '',
                'param4' => '',
                'param5' => '',
            ]);
        }

        upgrade_plugin_savepoint(true, 2026062102, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026062200) {
        // Course enrichment: create elby_course_meta and add elby_teachers.specialities.
        $xmldbfile = new xmldb_file($CFG->dirroot . '/local/elby_dashboard/db/install.xml');
        $xmldbfile->loadXMLStructure();
        $structure = $xmldbfile->getStructure();
        $newtable = $structure->getTable('elby_course_meta');
        if ($newtable && !$dbman->table_exists($newtable)) {
            $dbman->create_table($newtable);
        }

        $table = new xmldb_table('elby_teachers');
        $field = new xmldb_field('specialities', XMLDB_TYPE_TEXT, null, null, null, null, null, 'employment_end_date');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062200, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026062202) {
        // Course form module field: cache the chosen module's display label.
        $table = new xmldb_table('elby_course_meta');
        $field = new xmldb_field('module_name', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'module_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062202, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026062603) {
        // Offline roster cache for school-side signup/link.
        $table = new xmldb_table('elby_roster');
        if (!$dbman->table_exists($table)) {
            $xmldbfile = new xmldb_file($CFG->dirroot . '/local/elby_dashboard/db/install.xml');
            $xmldbfile->loadXMLStructure();
            $structure = $xmldbfile->getStructure();
            $newtable = $structure->getTable('elby_roster');
            if ($newtable) {
                $dbman->create_table($newtable);
            }
        }

        upgrade_plugin_savepoint(true, 2026062603, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026070200) {
        // Pin cohort-sync removals to suspend + remove roles: the core default
        // (ENROL_EXT_REMOVED_UNENROL, in force while the setting is unset) fully
        // unenrols and destroys grades when a student is moved out of a cohort
        // (e.g. year rollover or trade change).
        require_once($CFG->libdir . '/enrollib.php');
        set_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES, 'enrol_cohort');

        upgrade_plugin_savepoint(true, 2026070200, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026070201) {
        // Remedial: auto_link_by_email used to permanently quarantine users whose
        // TDMP lookup hit an HTTP 500 (a transient server error) by inserting an
        // unlinked elby_sdms_users marker row (sync_status=0, empty user_type).
        // Drop those markers so the users become retryable; genuine not-found
        // flags ('Not found in TDMP...') are kept.
        $DB->delete_records_select('elby_sdms_users',
            "user_type = '' AND sync_status = 0 AND " . $DB->sql_like('sync_error', ':pat'),
            ['pat' => '%HTTP 500%']);

        upgrade_plugin_savepoint(true, 2026070201, 'local', 'elby_dashboard');
    }

    return true;
}
