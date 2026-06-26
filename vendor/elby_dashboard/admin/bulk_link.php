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
 * Bulk SDMS link page — upload CSV to mass-link users.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
require_capability('local/elby_dashboard:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/elby_dashboard/admin/bulk_link.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('bulk_link_title', 'local_elby_dashboard'));
$PAGE->set_heading(get_string('bulk_link_title', 'local_elby_dashboard'));

// Handle sample CSV download before any output.
if (optional_param('download_sample', 0, PARAM_INT)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sdms_bulk_link_template.csv"');
    echo "username,sdms_code,role\n";
    echo "john.doe,STU001,student\n";
    echo "jane.smith,STF001,staff\n";
    exit;
}

$form = new \local_elby_dashboard\form\bulk_link_form();

$results = null;

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/elby_dashboard/admin/index.php'));
} else if ($data = $form->get_data()) {
    // Process the CSV upload.
    $content = $form->get_file_content('csvfile');

    $delimitermap = [
        'comma' => 'comma',
        'semicolon' => 'semicolon',
        'tab' => 'tab',
    ];
    $delimiter = $delimitermap[$data->delimiter] ?? 'comma';

    $iid = \csv_import_reader::get_new_iid('local_elby_dashboard_bulk_link');
    $csvreader = new \csv_import_reader($iid, 'local_elby_dashboard_bulk_link');
    $readcount = $csvreader->load_csv_content($content, 'UTF-8', $delimiter);

    if ($readcount === false) {
        $results = [['row' => 0, 'username' => '-', 'sdms_code' => '-', 'status' => 'error',
            'message' => get_string('bulk_link_invalid_csv', 'local_elby_dashboard')]];
    } else {
        // Validate columns.
        $columns = $csvreader->get_columns();
        $columns = array_map('strtolower', array_map('trim', $columns));

        $usernamecol = array_search('username', $columns);
        $sdmscodecol = array_search('sdms_code', $columns);
        $rolecol = array_search('role', $columns);

        if ($usernamecol === false || $sdmscodecol === false || $rolecol === false) {
            $results = [['row' => 0, 'username' => '-', 'sdms_code' => '-', 'status' => 'error',
                'message' => get_string('bulk_link_missing_columns', 'local_elby_dashboard')]];
        } else {
            $results = [];
            $syncservice = new \local_elby_dashboard\sync_service();
            $csvreader->init();
            $rownum = 1;

            while ($row = $csvreader->next()) {
                $rownum++;
                $username = trim($row[$usernamecol] ?? '');
                $sdmscode = trim($row[$sdmscodecol] ?? '');
                $role = strtolower(trim($row[$rolecol] ?? ''));

                // Validate row.
                if (empty($username) || empty($sdmscode) || empty($role)) {
                    $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                        'status' => 'error', 'message' => get_string('bulk_link_empty_fields', 'local_elby_dashboard')];
                    continue;
                }

                if (!in_array($role, ['student', 'staff'])) {
                    $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                        'status' => 'error', 'message' => get_string('bulk_link_invalid_role', 'local_elby_dashboard')];
                    continue;
                }

                // Find Moodle user.
                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
                if (!$user) {
                    $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                        'status' => 'error', 'message' => get_string('bulk_link_user_not_found', 'local_elby_dashboard')];
                    continue;
                }

                // Check if already linked.
                if ($DB->record_exists('elby_sdms_users', ['userid' => $user->id])) {
                    $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                        'status' => 'skipped', 'message' => get_string('bulk_link_already_linked', 'local_elby_dashboard')];
                    continue;
                }

                // Check if SDMS code already used.
                if ($DB->record_exists('elby_sdms_users', ['sdms_id' => $sdmscode])) {
                    $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                        'status' => 'error', 'message' => get_string('sdms_code_taken', 'local_elby_dashboard')];
                    continue;
                }

                // Attempt link.
                try {
                    $linked = $syncservice->link_user($user->id, $sdmscode, $role);
                    if ($linked) {
                        $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                            'status' => 'success', 'message' => get_string('bulk_link_success', 'local_elby_dashboard')];
                    } else {
                        $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                            'status' => 'error', 'message' => get_string('sdmsnotfound', 'local_elby_dashboard')];
                    }
                } catch (\Exception $e) {
                    $results[] = ['row' => $rownum, 'username' => $username, 'sdms_code' => $sdmscode,
                        'status' => 'error', 'message' => $e->getMessage()];
                }
            }

            $csvreader->close();
            $csvreader->cleanup();
        }
    }
}

echo $OUTPUT->header();

// Sample CSV download link.
echo html_writer::tag('p',
    get_string('bulk_link_description', 'local_elby_dashboard'));

$sampleurl = new moodle_url('/local/elby_dashboard/admin/bulk_link.php', ['download_sample' => 1]);

echo html_writer::tag('p',
    html_writer::link($sampleurl, get_string('bulk_link_download_template', 'local_elby_dashboard'),
        ['class' => 'btn btn-sm btn-outline-secondary']));

// Show the form.
$form->display();

// Show results table if processing was done.
if ($results !== null) {
    $successcount = count(array_filter($results, fn($r) => $r['status'] === 'success'));
    $errorcount = count(array_filter($results, fn($r) => $r['status'] === 'error'));
    $skippedcount = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));

    echo html_writer::tag('h3', get_string('bulk_link_results', 'local_elby_dashboard'), ['class' => 'mt-4']);
    echo html_writer::tag('p',
        get_string('bulk_link_results_summary', 'local_elby_dashboard', (object) [
            'success' => $successcount,
            'error' => $errorcount,
            'skipped' => $skippedcount,
        ]));

    $table = new html_table();
    $table->head = [
        get_string('bulk_link_col_row', 'local_elby_dashboard'),
        get_string('bulk_link_col_username', 'local_elby_dashboard'),
        get_string('bulk_link_col_sdms_code', 'local_elby_dashboard'),
        get_string('bulk_link_col_status', 'local_elby_dashboard'),
        get_string('bulk_link_col_message', 'local_elby_dashboard'),
    ];
    $table->attributes['class'] = 'table table-striped table-sm';

    foreach ($results as $row) {
        $statusclass = '';
        if ($row['status'] === 'success') {
            $statusclass = 'badge badge-success bg-success';
        } else if ($row['status'] === 'error') {
            $statusclass = 'badge badge-danger bg-danger';
        } else {
            $statusclass = 'badge badge-warning bg-warning';
        }

        $table->data[] = [
            $row['row'],
            s($row['username']),
            s($row['sdms_code']),
            html_writer::span(ucfirst($row['status']), $statusclass),
            s($row['message']),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
