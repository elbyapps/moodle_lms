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

    if ($oldversion < 2026062701) {
        // Create the NESA RISE learner review table.
        $table = new xmldb_table('elby_rise_reviews');
        if (!$dbman->table_exists($table)) {
            $xmldbfile = new xmldb_file($CFG->dirroot . '/local/elby_dashboard/db/install.xml');
            $xmldbfile->loadXMLStructure();
            $structure = $xmldbfile->getStructure();
            $dbman->create_table($structure->getTable('elby_rise_reviews'));
        }

        upgrade_plugin_savepoint(true, 2026062701, 'local', 'elby_dashboard');
    }

    return true;
}
