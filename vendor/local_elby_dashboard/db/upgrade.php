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

    if ($oldversion < 2026062801) {
        // Store the NESA Senior 3 confirmation index number for approved learners.
        $table = new xmldb_table('elby_rise_reviews');
        $field = new xmldb_field('nesaindexnumber', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'nesastatus');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062801, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026062802) {
        // Persist actual NIDA validation status for applicant-list badges and metrics.
        $table = new xmldb_table('elby_rise_reviews');
        $field = new xmldb_field('nidstatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending', 'nesaindexnumber');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062802, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026062803) {
        // NESA index numbers are unique when present. Store empty strings as NULL so multiple
        // pending/non-approved reviews can coexist while the unique index protects real values.
        $table = new xmldb_table('elby_rise_reviews');
        if ($dbman->table_exists($table)) {
            $DB->execute("UPDATE {elby_rise_reviews} SET nesaindexnumber = NULL WHERE nesaindexnumber = ''");
            $index = new xmldb_index('uq_nesaindexnumber', XMLDB_INDEX_UNIQUE, ['nesaindexnumber']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        upgrade_plugin_savepoint(true, 2026062803, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026070700) {
        // RISE learner -> Moodle user provisioning: link/state fields on the review row,
        // plus tokens, username sequence, corrections and SMS log tables.
        $table = new xmldb_table('elby_rise_reviews');

        $fields = [
            new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'reviewedby'),
            new xmldb_field('provisioningaction', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'userid'),
            new xmldb_field('correctionstatus', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'provisioningaction'),
            new xmldb_field('lastnotifiedhash', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'correctionstatus'),
            new xmldb_field('lastnotifiedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lastnotifiedhash'),
            new xmldb_field('userprovisionedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lastnotifiedat'),
            new xmldb_field('risesyncstatus', XMLDB_TYPE_CHAR, '20', null, null, null, 'ok', 'userprovisionedat'),
            new xmldb_field('risesyncerror', XMLDB_TYPE_TEXT, null, null, null, null, null, 'risesyncstatus'),
            new xmldb_field('risesyncedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'risesyncerror'),
            new xmldb_field('riselinkeduserid', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'risesyncedat'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Foreign key (also creates the lookup index) for the provisioned user.
        // Guarded: when elby_rise_reviews was created from the current install.xml
        // earlier in this same upgrade run, the key already exists. Moodle's DDL
        // manager has no key_exists() (and find_key_name() computes the name
        // without consulting the DB), so probe the key's backing index — Moodle
        // implements foreign keys as plain non-unique indexes.
        $keyindex = new xmldb_index('fk_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $keyindex)) {
            $key = new xmldb_key('fk_userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $dbman->add_key($table, $key);
        }

        // Create the new tables from install.xml.
        $xmldbfile = new xmldb_file($CFG->dirroot . '/local/elby_dashboard/db/install.xml');
        $xmldbfile->loadXMLStructure();
        $structure = $xmldbfile->getStructure();
        foreach (['elby_rise_tokens', 'elby_rise_username_seq', 'elby_rise_corrections', 'elby_rise_sms_log'] as $name) {
            if (!$dbman->table_exists(new xmldb_table($name))) {
                $dbman->create_table($structure->getTable($name));
            }
        }

        // One-time, portable seed of the username sequence: parse the numeric suffix of
        // existing {type}{yy}NNNNN usernames in PHP (no DB-specific casts). The generator
        // also skips taken numbers, so an incomplete seed is safe.
        $year = substr(date('Y'), 2, 2);
        foreach (['1', '2'] as $type) {
            $prefix = $type . $year;
            if ($DB->record_exists('elby_rise_username_seq', ['seqkey' => $prefix])) {
                continue;
            }
            $max = 0;
            $rs = $DB->get_recordset_select('user', $DB->sql_like('username', ':pat'),
                ['pat' => $DB->sql_like_escape($prefix) . '%'], '', 'id, username');
            foreach ($rs as $u) {
                if (preg_match('/^' . $prefix . '(\d{5})$/', $u->username, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
            $rs->close();
            $DB->insert_record('elby_rise_username_seq', (object) ['seqkey' => $prefix, 'nextval' => $max + 1]);
        }

        upgrade_plugin_savepoint(true, 2026070700, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026070701) {
        // Track whether a learner correction was accepted by the RISE PATCH or is
        // stored locally only (graceful fallback the reviewer must know about).
        $table = new xmldb_table('elby_rise_corrections');
        $field = new xmldb_field('risesynced', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'note');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026070701, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026070703) {
        // Store the RISE province code on the review snapshot so the DB-backed
        // applicant-list path can honour the province filter (previously only the
        // remote RISE path filtered by province). Backfill from applicantdata in
        // PHP (no DB JSON functions needed). This savepoint also realigns the
        // stored plugin version with version.php (2026070703).
        $table = new xmldb_table('elby_rise_reviews');
        $field = new xmldb_field('provincecode', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'district');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $rs = $DB->get_recordset_select('elby_rise_reviews',
            "applicantdata IS NOT NULL AND (provincecode IS NULL OR provincecode = '')",
            [], '', 'id, applicantdata');
        foreach ($rs as $row) {
            $snapshot = json_decode($row->applicantdata, true);
            $code = is_array($snapshot) && isset($snapshot['location']['provinceCode'])
                ? (string) $snapshot['location']['provinceCode'] : '';
            if ($code !== '') {
                $DB->set_field('elby_rise_reviews', 'provincecode', $code, ['id' => $row->id]);
            }
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2026070703, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026070704) {
        // No schema change — this savepoint exists so the version bump runs the
        // upgrade path, which re-reads db/services.php and registers the new
        // RISE web-service functions (rise_get_sms_log, rise_queue_backlog).
        // Keeps the last savepoint aligned with $plugin->version.
        upgrade_plugin_savepoint(true, 2026070704, 'local', 'elby_dashboard');
    }

    if ($oldversion < 2026070705) {
        // No schema change — re-register services so rise_get_sms_log picks up its
        // tightened capability (viewreports -> manageriseusers).
        upgrade_plugin_savepoint(true, 2026070705, 'local', 'elby_dashboard');
    }

    return true;
}
