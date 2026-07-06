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
 * Upgrade steps for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin schema.
 *
 * @param int $oldversion Previous version.
 * @return bool
 */
function xmldb_local_syncqueue_upgrade($oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026062600) {

        // 1. trade/level columns + index on the updates table (per-school priority).
        $table = new xmldb_table('local_syncqueue_updates');

        $field = new xmldb_field('tradecode', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'priority');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('level', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'tradecode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('idx_tradelevel', XMLDB_INDEX_NOTUNIQUE, ['tradecode', 'level']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // 2. Push jobs table.
        $table = new xmldb_table('local_syncqueue_jobs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'push_courses');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'queued');
            $table->add_field('totalitems', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('doneitems', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('faileditems', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usercount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enrolcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_status_time', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
            $table->add_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }

        // 3. Job items table.
        $table = new xmldb_table('local_syncqueue_job_items');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'queued');
            $table->add_field('backupfile', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('usercount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enrolcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_jobid', XMLDB_KEY_FOREIGN, ['jobid'], 'local_syncqueue_jobs', ['id']);
            $table->add_index('idx_jobid_status', XMLDB_INDEX_NOTUNIQUE, ['jobid', 'status']);
            $table->add_index('idx_courseid_status', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'status']);
            $dbman->create_table($table);
        }

        // 4. Per-school trade/level priorities.
        $table = new xmldb_table('local_syncqueue_school_trades');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('tradecode', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('level', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('weight', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('source', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'auto');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_school_trade', XMLDB_INDEX_UNIQUE, ['schoolid', 'tradecode', 'level']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026062600, 'local', 'syncqueue');
    }

    if ($oldversion < 2026062602) {
        // itemtype/label on job items so pull jobs can describe their items.
        $table = new xmldb_table('local_syncqueue_job_items');
        $field = new xmldb_field('itemtype', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('label', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'itemtype');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062602, 'local', 'syncqueue');
    }

    if ($oldversion < 2026062603) {
        // F3: onlyselected flag on schools + per-school course preferences table.
        $table = new xmldb_table('local_syncqueue_schools');
        $field = new xmldb_field('onlyselected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'totalsynced');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_syncqueue_course_prefs');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('selected', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('weight', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_school_course', XMLDB_INDEX_UNIQUE, ['schoolid', 'courseid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026062603, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070200) {
        // ELMS Sync v2 step 1: sequenced outbox foundation tables.

        // 1. Outbox (append-only; seq NULL until assigned post-commit).
        $table = new xmldb_table('local_syncqueue_outbox');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('seq', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('entitytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entitykey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entityversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('action', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, 'big', null, null, null, null);
            $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('contentversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('partitionkey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lineageuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('factversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('factuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('rostergen', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_seq', XMLDB_INDEX_UNIQUE, ['seq']);
            $table->add_index('idx_partition_seq', XMLDB_INDEX_NOTUNIQUE, ['partitionkey', 'seq']);
            $table->add_index('idx_entity', XMLDB_INDEX_NOTUNIQUE, ['entitytype', 'entitykey']);
            $table->add_index('idx_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($table);
        }

        // 2. Sequencer counters.
        $table = new xmldb_table('local_syncqueue_seq');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('value', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_name', XMLDB_INDEX_UNIQUE, ['name']);
            $dbman->create_table($table);
        }

        // 3. Per-peer per-direction stream cursors.
        $table = new xmldb_table('local_syncqueue_cursor');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('peer', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('direction', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('epoch', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('lastappliedseq', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_peer_direction', XMLDB_INDEX_UNIQUE, ['peer', 'direction']);
            $dbman->create_table($table);
        }

        // 4. Applied-state digest / entity version registry.
        $table = new xmldb_table('local_syncqueue_applied');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('entitytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entitykey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entityversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('localid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_entity', XMLDB_INDEX_UNIQUE, ['entitytype', 'entitykey']);
            $dbman->create_table($table);
        }

        // 5. Dead-letter queue.
        $table = new xmldb_table('local_syncqueue_deadletter');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('peer', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('direction', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('seq', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('entitytype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entitykey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entityversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, 'big', null, null, null, null);
            $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'retry');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('idx_entity', XMLDB_INDEX_NOTUNIQUE, ['entitytype', 'entitykey']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026070200, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070202) {

        // ELMS Sync v2 step 2: upstream fact ledger, central ingest buffer,
        // epoch registry. All guarded by table_exists so a partially-migrated
        // site re-runs cleanly.

        // 1. Fact ledger (school side): deterministic identity + tenure, no payloads.
        $table = new xmldb_table('local_syncqueue_ledger');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('origin', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('facttype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lineageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('factversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('factuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('naturalkey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcetable', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourceversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('rostergen', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('homeschool', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $table->add_field('capturedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lastexportedseq', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'captured');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_factuuid', XMLDB_INDEX_UNIQUE, ['factuuid']);
            $table->add_index('idx_lineage_version', XMLDB_INDEX_UNIQUE, ['lineageuuid', 'factversion']);
            $table->add_index('idx_source', XMLDB_INDEX_NOTUNIQUE, ['sourcetable', 'sourceid']);
            $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('idx_origin', XMLDB_INDEX_NOTUNIQUE, ['origin']);
            $dbman->create_table($table);
        }

        // 2. Central ingest buffer.
        $table = new xmldb_table('local_syncqueue_ingest');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('epoch', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('schoolseq', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('factuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lineageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('factversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('facttype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('entitykey', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('payload', XMLDB_TYPE_TEXT, 'big', null, null, null, null);
            $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rostergen', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'buffered');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_factuuid', XMLDB_INDEX_UNIQUE, ['factuuid']);
            $table->add_index('idx_lineage_version', XMLDB_INDEX_UNIQUE, ['lineageuuid', 'factversion']);
            $table->add_index('idx_school_seq', XMLDB_INDEX_UNIQUE, ['schoolid', 'epoch', 'schoolseq']);
            $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('idx_schoolid', XMLDB_INDEX_NOTUNIQUE, ['schoolid']);
            $dbman->create_table($table);
        }

        // 3. Epoch registry.
        $table = new xmldb_table('local_syncqueue_epoch');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('scope', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
            $table->add_field('epoch', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('headseq', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('bootcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_scope_school', XMLDB_INDEX_UNIQUE, ['scope', 'schoolid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026070202, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070203) {

        // ELMS Sync v2 step 2 (capture): link an upstream fact outbox row to the
        // ledger row it was captured from, so the serialized sequencer can
        // finalize the fact's version/uuid. NULL for downstream content rows.
        $table = new xmldb_table('local_syncqueue_outbox');
        $field = new xmldb_field('ledgerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'rostergen');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070203, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070300) {

        // ELMS Sync v2 step 4 preflight: school-side durable store of received
        // cm/grade-item identity maps, so an operator can dry-run review a map
        // (apply_identity_map.php) before it back-stamps local idnumbers.
        $table = new xmldb_table('local_syncqueue_identity_map');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('centralcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('localcourseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('entityversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('payloadhash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('map', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('report', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_centralcourseid', XMLDB_INDEX_UNIQUE, ['centralcourseid']);
            $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026070300, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070301) {

        // ELMS Sync v2 step 4 (tenure): central home-tenure intervals, the AGS
        // per-item origin-seq high-water, and the true-contradiction table. All
        // guarded by table_exists so a partially-migrated site re-runs cleanly.

        // 1. Home-tenure intervals.
        $table = new xmldb_table('local_syncqueue_tenure');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('sdms', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('fromrostergen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('torostergen', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_sdms_school', XMLDB_INDEX_NOTUNIQUE, ['sdms', 'schoolid']);
            $table->add_index('idx_sdms_open', XMLDB_INDEX_NOTUNIQUE, ['sdms', 'torostergen']);
            $dbman->create_table($table);
        }

        // 2. AGS per-item origin-seq high-water.
        $table = new xmldb_table('local_syncqueue_ags');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('origin', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('epoch', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('agskey', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('schoolseq', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_group', XMLDB_INDEX_UNIQUE, ['origin', 'epoch', 'agskey']);
            $dbman->create_table($table);
        }

        // 3. True-contradiction records.
        $table = new xmldb_table('local_syncqueue_conflicts');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('facttype', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lineageuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('factuuid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
            $table->add_field('origin', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sdms', XMLDB_TYPE_CHAR, '64', null, null, null, null);
            $table->add_field('entitykey', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('rostergen', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('reason', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $table->add_field('detail', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'open');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_lineage', XMLDB_INDEX_NOTUNIQUE, ['lineageuuid']);
            $table->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('idx_origin', XMLDB_INDEX_NOTUNIQUE, ['origin']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026070301, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070302) {
        // Option B changes what local_syncqueue/rostergen MEANS: it stops being a
        // per-instance counter this box bumped and becomes the central roster
        // generation this box ADOPTS. A value left over from the old counter is in
        // an incommensurable numbering space, and rostergen::adopt() is monotonic so
        // it would never be overwritten by central's (freshly-restarted) generation
        // — stranding the box stamping a meaningless value. Clear it so the next
        // clean roster refresh adopts central's clock from scratch; until then the
        // box stamps NULL and central's tenure gate stays dormant for it, which is
        // safe. Central ignores this key (it uses central_rostergen).
        unset_config('rostergen', 'local_syncqueue');

        upgrade_plugin_savepoint(true, 2026070302, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070400) {
        // ELMS Sync v2 step 5 (history down-sync): the coalescing reseed-job queue
        // (central) and the seed provenance table (school) for deterministic handover.
        $seedjob = new xmldb_table('local_syncqueue_seedjob');
        $seedjob->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $seedjob->add_field('sdms', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $seedjob->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $seedjob->add_field('rostergen', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $seedjob->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $seedjob->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $seedjob->add_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $seedjob->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $seedjob->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $seedjob->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $seedjob->add_index('idx_learner_school', XMLDB_INDEX_UNIQUE, ['sdms', 'schoolid']);
        $seedjob->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($seedjob)) {
            $dbman->create_table($seedjob);
        }

        $seed = new xmldb_table('local_syncqueue_seed');
        $seed->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $seed->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $seed->add_field('sdms', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $seed->add_field('itemuuid', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
        $seed->add_field('itemtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $seed->add_field('seededvalue', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $seed->add_field('localitemid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $seed->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'seeded');
        $seed->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $seed->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $seed->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $seed->add_index('idx_seed_item', XMLDB_INDEX_UNIQUE, ['schoolid', 'sdms', 'itemuuid']);
        $seed->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($seed)) {
            $dbman->create_table($seed);
        }

        upgrade_plugin_savepoint(true, 2026070400, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070404) {
        // ELMS Sync v2 step 6 (snapshot bootstrap): per-school chunked manifests (central).
        $snapshot = new xmldb_table('local_syncqueue_snapshot');
        $snapshot->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $snapshot->add_field('manifestid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $snapshot->add_field('schoolid', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $snapshot->add_field('headseq', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $snapshot->add_field('chunkindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $snapshot->add_field('numchunks', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $snapshot->add_field('entries', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $snapshot->add_field('pinneduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $snapshot->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $snapshot->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $snapshot->add_index('idx_manifest_chunk', XMLDB_INDEX_UNIQUE, ['manifestid', 'chunkindex']);
        $snapshot->add_index('idx_school', XMLDB_INDEX_NOTUNIQUE, ['schoolid']);
        if (!$dbman->table_exists($snapshot)) {
            $dbman->create_table($snapshot);
        }

        upgrade_plugin_savepoint(true, 2026070404, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070501) {
        // ELMS Sync v2 step 7 (content versioning apply): track the applied .mbz
        // content version, and a crash-safe re-restore log (school side).
        $applied = new xmldb_table('local_syncqueue_applied');
        $contentversion = new xmldb_field('contentversion', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'localid');
        if (!$dbman->field_exists($applied, $contentversion)) {
            $dbman->add_field($applied, $contentversion);
        }

        $restorelog = new xmldb_table('local_syncqueue_restorelog');
        $restorelog->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $restorelog->add_field('entitykey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $restorelog->add_field('centralcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $restorelog->add_field('contentversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $restorelog->add_field('oldlocalid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $restorelog->add_field('newlocalid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $restorelog->add_field('marker', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $restorelog->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'restoring');
        $restorelog->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $restorelog->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $restorelog->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $restorelog->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $restorelog->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $restorelog->add_index('idx_entity_version', XMLDB_INDEX_UNIQUE, ['entitykey', 'contentversion']);
        $restorelog->add_index('idx_status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        if (!$dbman->table_exists($restorelog)) {
            $dbman->create_table($restorelog);
        }

        upgrade_plugin_savepoint(true, 2026070501, 'local', 'syncqueue');
    }

    if ($oldversion < 2026070503) {
        // ELMS Sync v2 step 7 (key rotation): dual-validity previous-key columns so a
        // rotation cannot brick an offline school (the old key stays valid in a grace window).
        $schools = new xmldb_table('local_syncqueue_schools');
        $prev = new xmldb_field('apikey_prev', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'apikey');
        if (!$dbman->field_exists($schools, $prev)) {
            $dbman->add_field($schools, $prev);
        }
        $expires = new xmldb_field('apikey_prev_expires', XMLDB_TYPE_INTEGER, '10', null, null, null, null,
            'apikey_prev');
        if (!$dbman->field_exists($schools, $expires)) {
            $dbman->add_field($schools, $expires);
        }

        upgrade_plugin_savepoint(true, 2026070503, 'local', 'syncqueue');
    }

    return true;
}
