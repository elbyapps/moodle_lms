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
 * Database upgrade script for local_reblibrary.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_reblibrary plugin.
 *
 * @param int $oldversion The version number of the plugin that was installed.
 * @return bool Always returns true.
 */
function xmldb_local_reblibrary_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Add visibility and media_type fields to resources, visibility to categories, and create labels tables.
    if ($oldversion < 2025102503) {

        // Define field visible to be added to local_reblibrary_resources.
        $table = new xmldb_table('local_reblibrary_resources');
        $field = new xmldb_field('visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'author_id');

        // Conditionally launch add field visible.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define index visible (not unique) to be added to local_reblibrary_resources.
        $index = new xmldb_index('visible', XMLDB_INDEX_NOTUNIQUE, ['visible']);

        // Conditionally launch add index visible.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Define field media_type to be added to local_reblibrary_resources.
        $field = new xmldb_field('media_type', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'text', 'visible');

        // Conditionally launch add field media_type.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define index media_type (not unique) to be added to local_reblibrary_resources.
        $index = new xmldb_index('media_type', XMLDB_INDEX_NOTUNIQUE, ['media_type']);

        // Conditionally launch add index media_type.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Define field visible to be added to local_reblibrary_categories.
        $table = new xmldb_table('local_reblibrary_categories');
        $field = new xmldb_field('visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'description');

        // Conditionally launch add field visible.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define index visible (not unique) to be added to local_reblibrary_categories.
        $index = new xmldb_index('visible', XMLDB_INDEX_NOTUNIQUE, ['visible']);

        // Conditionally launch add index visible.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Define table local_reblibrary_labels to be created.
        $table = new xmldb_table('local_reblibrary_labels');

        // Adding fields to table local_reblibrary_labels.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('label_name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);

        // Adding keys to table local_reblibrary_labels.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Adding indexes to table local_reblibrary_labels.
        $table->add_index('label_name', XMLDB_INDEX_UNIQUE, ['label_name']);

        // Conditionally launch create table for local_reblibrary_labels.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table local_reblibrary_res_labels to be created.
        $table = new xmldb_table('local_reblibrary_res_labels');

        // Adding fields to table local_reblibrary_res_labels.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('resource_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('label_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_reblibrary_res_labels.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('resource_id', XMLDB_KEY_FOREIGN, ['resource_id'], 'local_reblibrary_resources', ['id']);
        $table->add_key('label_id', XMLDB_KEY_FOREIGN, ['label_id'], 'local_reblibrary_labels', ['id']);
        $table->add_key('resource_label', XMLDB_KEY_UNIQUE, ['resource_id', 'label_id']);

        // Conditionally launch create table for local_reblibrary_res_labels.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Reblibrary savepoint reached.
        upgrade_plugin_savepoint(true, 2025102503, 'local', 'reblibrary');
    }

    if ($oldversion < 2026052200) {
        // Add `sortorder` column to the four education-structure tables and
        // backfill it so the immediate display order is the same as the
        // pre-upgrade alphabetical sort.

        // ----- local_reblibrary_edu_levels -----
        $table = new xmldb_table('local_reblibrary_edu_levels');
        $field = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'level_name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('sortorder', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $rows = $DB->get_records('local_reblibrary_edu_levels', null, 'level_name ASC', 'id');
        $i = 1;
        foreach ($rows as $row) {
            $DB->set_field('local_reblibrary_edu_levels', 'sortorder', $i, ['id' => $row->id]);
            $i++;
        }

        // ----- local_reblibrary_edu_sublevels -----
        $table = new xmldb_table('local_reblibrary_edu_sublevels');
        $field = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'level_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('level_sortorder', XMLDB_INDEX_NOTUNIQUE, ['level_id', 'sortorder']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $levelids = $DB->get_fieldset_select('local_reblibrary_edu_sublevels', 'DISTINCT level_id', '1=1');
        foreach ($levelids as $levelid) {
            $rows = $DB->get_records('local_reblibrary_edu_sublevels', ['level_id' => $levelid], 'sublevel_name ASC', 'id');
            $i = 1;
            foreach ($rows as $row) {
                $DB->set_field('local_reblibrary_edu_sublevels', 'sortorder', $i, ['id' => $row->id]);
                $i++;
            }
        }

        // ----- local_reblibrary_classes -----
        $table = new xmldb_table('local_reblibrary_classes');
        $field = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sublevel_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('sublevel_sortorder', XMLDB_INDEX_NOTUNIQUE, ['sublevel_id', 'sortorder']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $sublevelids = $DB->get_fieldset_select('local_reblibrary_classes', 'DISTINCT sublevel_id', '1=1');
        foreach ($sublevelids as $sublevelid) {
            $rows = $DB->get_records('local_reblibrary_classes', ['sublevel_id' => $sublevelid], 'class_code ASC', 'id');
            $i = 1;
            foreach ($rows as $row) {
                $DB->set_field('local_reblibrary_classes', 'sortorder', $i, ['id' => $row->id]);
                $i++;
            }
        }

        // ----- local_reblibrary_sections -----
        $table = new xmldb_table('local_reblibrary_sections');
        $field = new xmldb_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'sublevel_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('sublevel_sortorder', XMLDB_INDEX_NOTUNIQUE, ['sublevel_id', 'sortorder']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $sublevelids = $DB->get_fieldset_select('local_reblibrary_sections', 'DISTINCT sublevel_id', '1=1');
        foreach ($sublevelids as $sublevelid) {
            $rows = $DB->get_records('local_reblibrary_sections', ['sublevel_id' => $sublevelid], 'section_code ASC', 'id');
            $i = 1;
            foreach ($rows as $row) {
                $DB->set_field('local_reblibrary_sections', 'sortorder', $i, ['id' => $row->id]);
                $i++;
            }
        }

        upgrade_plugin_savepoint(true, 2026052200, 'local', 'reblibrary');
    }

    return true;
}
