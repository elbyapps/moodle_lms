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
 * Sync Queue Dashboard.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_syncqueue_dashboard');

$action = optional_param('action', '', PARAM_ALPHA);
$sesskey = optional_param('sesskey', '', PARAM_ALPHANUM);

$mode = get_config('local_syncqueue', 'mode');
$enabled = get_config('local_syncqueue', 'enabled');

$PAGE->set_url(new moodle_url('/local/syncqueue/dashboard.php'));
$PAGE->set_title(get_string('dashboard', 'local_syncqueue'));
$PAGE->set_heading(get_string('dashboard', 'local_syncqueue'));

// Handle actions.
if ($action && confirm_sesskey()) {
    if ($action === 'testconnection' && $mode === 'school') {
        try {
            $client = new \local_syncqueue\sync_client();
            $result = $client->check_connection_detailed();
            if ($result['success']) {
                \core\notification::success(get_string('connectionok', 'local_syncqueue') . ' - ' . $result['message']);
            } else {
                \core\notification::error(get_string('connectionfailed', 'local_syncqueue') . ': ' . $result['message']);
            }
        } catch (\Exception $e) {
            \core\notification::error(get_string('connectionfailed', 'local_syncqueue') . ': ' . $e->getMessage());
        }
    } else if ($action === 'syncnow' && $mode === 'school') {
        // Run the upload in the background so the page returns immediately.
        $task = new \local_syncqueue\task\upload_now();
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);
        \core\notification::success(get_string('syncqueued', 'local_syncqueue'));
    } else if ($action === 'download' && $mode === 'school') {
        // Download + restore is heavy; run it as a tracked async job.
        $jobid = (new \local_syncqueue\job_manager())->create_pull_job($USER->id);
        redirect(new moodle_url('/local/syncqueue/job.php', ['id' => $jobid]),
            get_string('pullqueued', 'local_syncqueue'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($PAGE->url);
}

echo $OUTPUT->header();
echo local_syncqueue_get_navigation('dashboard');
echo $OUTPUT->heading(get_string('dashboard', 'local_syncqueue'));

// Show warning if plugin is disabled.
if (!$enabled) {
    echo $OUTPUT->notification(get_string('plugindisabled', 'local_syncqueue'), 'warning');
}

// Show mode indicator.
$modestring = ($mode === 'school') ? get_string('mode_school', 'local_syncqueue') : get_string('mode_central', 'local_syncqueue');
echo html_writer::tag('p', get_string('currentmode', 'local_syncqueue', $modestring), ['class' => 'lead']);

if ($mode === 'school') {
    // School mode dashboard.
    echo render_school_dashboard();
} else {
    // Central mode dashboard.
    echo render_central_dashboard();
}

echo $OUTPUT->footer();

/**
 * Render the school mode dashboard.
 *
 * @return string HTML output.
 */
function render_school_dashboard(): string {
    global $OUTPUT, $DB, $PAGE;

    $html = '';

    // Connection status card.
    $html .= html_writer::start_div('card mb-4');
    $html .= html_writer::start_div('card-header');
    $html .= html_writer::tag('h4', get_string('connectionstatus', 'local_syncqueue'), ['class' => 'mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('card-body');

    $centralserver = get_config('local_syncqueue', 'centralserver');
    $apikey = get_config('local_syncqueue', 'apikey');
    $schoolid = get_config('local_syncqueue', 'schoolid');

    if (empty($centralserver) || empty($apikey) || empty($schoolid)) {
        $html .= html_writer::tag('p', get_string('notconfigured', 'local_syncqueue'), ['class' => 'text-warning']);
        $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_syncqueue']);
        $html .= html_writer::link($settingsurl, get_string('configuresettings', 'local_syncqueue'), ['class' => 'btn btn-primary']);
    } else {
        $html .= html_writer::tag('p', get_string('centralserver', 'local_syncqueue') . ': ' . $centralserver);
        $html .= html_writer::tag('p', get_string('schoolid', 'local_syncqueue') . ': ' . $schoolid);

        // Test connection button.
        $testurl = new moodle_url('/local/syncqueue/dashboard.php', ['action' => 'testconnection', 'sesskey' => sesskey()]);
        $html .= html_writer::link($testurl, get_string('testconnection', 'local_syncqueue'), ['class' => 'btn btn-secondary mr-2']);
    }

    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Queue statistics card.
    $qm = new \local_syncqueue\queue_manager();
    $stats = $qm->get_stats();

    $html .= html_writer::start_div('card mb-4');
    $html .= html_writer::start_div('card-header');
    $html .= html_writer::tag('h4', get_string('queuestats', 'local_syncqueue'), ['class' => 'mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('card-body');

    $html .= html_writer::start_div('row');

    // Stat boxes.
    $statboxes = [
        ['label' => 'status_pending', 'value' => $stats->pending, 'class' => 'bg-warning'],
        ['label' => 'status_processing', 'value' => $stats->processing, 'class' => 'bg-info'],
        ['label' => 'status_synced', 'value' => $stats->synced, 'class' => 'bg-success'],
        ['label' => 'status_failed', 'value' => $stats->failed, 'class' => 'bg-danger'],
        ['label' => 'status_conflict', 'value' => $stats->conflict, 'class' => 'bg-secondary'],
    ];

    foreach ($statboxes as $box) {
        $html .= html_writer::start_div('col-md-2 text-center mb-3');
        $html .= html_writer::start_div('p-3 rounded ' . $box['class'] . ' text-white');
        $html .= html_writer::tag('h3', $box['value'], ['class' => 'mb-0']);
        $html .= html_writer::tag('small', get_string($box['label'], 'local_syncqueue'));
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
    }

    $html .= html_writer::end_div(); // End row.

    // Last sync time.
    if ($stats->lastsync) {
        $html .= html_writer::tag('p', get_string('lastsync', 'local_syncqueue') . ': ' . userdate($stats->lastsync));
    } else {
        $html .= html_writer::tag('p', get_string('neversync', 'local_syncqueue'), ['class' => 'text-muted']);
    }

    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Actions card.
    $html .= html_writer::start_div('card mb-4');
    $html .= html_writer::start_div('card-header');
    $html .= html_writer::tag('h4', get_string('actions', 'local_syncqueue'), ['class' => 'mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('card-body');

    $downloadurl = new moodle_url('/local/syncqueue/dashboard.php', ['action' => 'download', 'sesskey' => sesskey()]);
    $html .= html_writer::link($downloadurl, get_string('downloadupdates', 'local_syncqueue'), ['class' => 'btn btn-primary mr-2']);

    $syncurl = new moodle_url('/local/syncqueue/dashboard.php', ['action' => 'syncnow', 'sesskey' => sesskey()]);
    $html .= html_writer::link($syncurl, get_string('syncnow', 'local_syncqueue'), ['class' => 'btn btn-secondary mr-2']);

    $queueurl = new moodle_url('/local/syncqueue/queue.php');
    $html .= html_writer::link($queueurl, get_string('viewqueue', 'local_syncqueue'), ['class' => 'btn btn-outline-secondary']);

    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    return $html;
}

/**
 * Render the central mode dashboard.
 *
 * @return string HTML output.
 */
function render_central_dashboard(): string {
    global $OUTPUT, $DB;

    $html = '';
    $schoolmanager = new \local_syncqueue\school_manager();

    // School statistics card.
    $allschools = $schoolmanager->get_all_schools();
    $activeschools = $schoolmanager->get_all_schools('active');
    $suspendedschools = $schoolmanager->get_all_schools('suspended');
    $overdueschools = $schoolmanager->get_overdue_schools();

    $html .= html_writer::start_div('card mb-4');
    $html .= html_writer::start_div('card-header');
    $html .= html_writer::tag('h4', get_string('schoolstats', 'local_syncqueue'), ['class' => 'mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('card-body');

    $html .= html_writer::start_div('row');

    $statboxes = [
        ['label' => 'totalschools', 'value' => count($allschools), 'class' => 'bg-primary'],
        ['label' => 'activeschools', 'value' => count($activeschools), 'class' => 'bg-success'],
        ['label' => 'suspendedschools', 'value' => count($suspendedschools), 'class' => 'bg-warning'],
        ['label' => 'overdueschools', 'value' => count($overdueschools), 'class' => 'bg-danger'],
    ];

    foreach ($statboxes as $box) {
        $html .= html_writer::start_div('col-md-3 text-center mb-3');
        $html .= html_writer::start_div('p-3 rounded ' . $box['class'] . ' text-white');
        $html .= html_writer::tag('h3', $box['value'], ['class' => 'mb-0']);
        $html .= html_writer::tag('small', get_string($box['label'], 'local_syncqueue'));
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
    }

    $html .= html_writer::end_div(); // End row.
    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Recent sync activity card.
    $html .= html_writer::start_div('card mb-4');
    $html .= html_writer::start_div('card-header');
    $html .= html_writer::tag('h4', get_string('recentsyncactivity', 'local_syncqueue'), ['class' => 'mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('card-body');

    // Count items received in last 24 hours (from sync log).
    $onedayago = time() - DAYSECS;
    $recentcount = $DB->get_field_sql(
        'SELECT COALESCE(SUM(successcount), 0) FROM {local_syncqueue_log}
         WHERE direction = :direction AND timecreated > :time',
        ['direction' => 'upload', 'time' => $onedayago]
    );
    $html .= html_writer::tag('p', get_string('itemslast24h', 'local_syncqueue', (int)$recentcount));

    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    // Overdue schools warning (paginated).
    if (!empty($overdueschools)) {
        $page = optional_param('page', 0, PARAM_INT);
        $perpage = 20;
        $totaloverdue = count($overdueschools);
        $pageoverdue = array_slice($overdueschools, $page * $perpage, $perpage, true);

        $html .= html_writer::start_div('card mb-4 border-danger');
        $html .= html_writer::start_div('card-header bg-danger text-white');
        $html .= html_writer::tag('h4', get_string('overdueschoolswarning', 'local_syncqueue'), ['class' => 'mb-0']);
        $html .= html_writer::end_div();
        $html .= html_writer::start_div('card-body');

        $html .= html_writer::start_tag('ul', ['class' => 'list-group']);
        foreach ($pageoverdue as $school) {
            $lastsynced = $school->lastsynced ? userdate($school->lastsynced) : get_string('never', 'local_syncqueue');
            $html .= html_writer::tag('li',
                $school->name . ' (' . $school->schoolid . ') - ' . get_string('lastsync', 'local_syncqueue') . ': ' . $lastsynced,
                ['class' => 'list-group-item list-group-item-warning']
            );
        }
        $html .= html_writer::end_tag('ul');
        $html .= $OUTPUT->paging_bar($totaloverdue, $page, $perpage, new moodle_url('/local/syncqueue/dashboard.php'));

        $html .= html_writer::end_div();
        $html .= html_writer::end_div();
    }

    // Actions card.
    $html .= html_writer::start_div('card mb-4');
    $html .= html_writer::start_div('card-header');
    $html .= html_writer::tag('h4', get_string('actions', 'local_syncqueue'), ['class' => 'mb-0']);
    $html .= html_writer::end_div();
    $html .= html_writer::start_div('card-body');

    $schoolsurl = new moodle_url('/local/syncqueue/schools.php');
    $html .= html_writer::link($schoolsurl, get_string('manageschools', 'local_syncqueue'), ['class' => 'btn btn-primary mr-2']);

    $coursesurl = new moodle_url('/local/syncqueue/courses.php');
    $html .= html_writer::link($coursesurl, get_string('pushcourses', 'local_syncqueue'), ['class' => 'btn btn-secondary']);

    $html .= html_writer::end_div();
    $html .= html_writer::end_div();

    return $html;
}
