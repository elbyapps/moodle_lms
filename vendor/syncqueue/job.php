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
 * Progress view for an async course-push job (central mode).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$jobid = required_param('id', PARAM_INT);

admin_externalpage_setup('local_syncqueue_dashboard');

$PAGE->set_url(new moodle_url('/local/syncqueue/job.php', ['id' => $jobid]));
$PAGE->set_title(get_string('jobprogress', 'local_syncqueue'));
$PAGE->set_heading(get_string('jobprogress', 'local_syncqueue'));

$jobmgr = new \local_syncqueue\job_manager();
$status = $jobmgr->get_status($jobid);
$jobrec = $jobmgr->get_job($jobid);
$ispull = ($jobrec && $jobrec->type === 'pull_updates');
$navtab = $ispull ? 'dashboard' : 'courses';
$backurl = $ispull
    ? new moodle_url('/local/syncqueue/dashboard.php')
    : new moodle_url('/local/syncqueue/courses.php');

if ($status === null) {
    redirect(new moodle_url('/local/syncqueue/courses.php'),
        get_string('jobnotfound', 'local_syncqueue'), null,
        \core\output\notification::NOTIFY_ERROR);
}

// Auto-refresh while the job is still running (transparent without JS).
if (!$status['finished']) {
    $PAGE->set_periodic_refresh_delay(5);
}

echo $OUTPUT->header();
echo local_syncqueue_get_navigation($navtab);
echo $OUTPUT->heading(get_string($ispull ? 'pullprogress' : 'jobprogress', 'local_syncqueue'));

// Overall status line.
$statuslabels = [
    'queued' => 'badge bg-secondary',
    'running' => 'badge bg-info text-white',
    'completed' => 'badge bg-success text-white',
    'partial' => 'badge bg-warning text-dark',
    'failed' => 'badge bg-danger text-white',
];
$badgeclass = $statuslabels[$status['status']] ?? 'badge bg-secondary';
echo html_writer::tag('p',
    get_string('jobstatus', 'local_syncqueue') . ': ' .
    html_writer::tag('span', get_string('jobstatus_' . $status['status'], 'local_syncqueue'), ['class' => $badgeclass]));

// Progress bar.
$total = max(1, $status['totalitems']);
$processed = $status['doneitems'] + $status['faileditems'];
// Skipped items are terminal too; count them as processed.
foreach ($status['items'] as $it) {
    if ($it['status'] === 'skipped') {
        $processed++;
    }
}
$percent = (int) round(($processed / $total) * 100);

echo html_writer::start_div('progress mb-3', ['style' => 'height: 24px;']);
echo html_writer::div(
    $percent . '%',
    'progress-bar' . ($status['finished'] ? ' bg-success' : ' progress-bar-striped progress-bar-animated'),
    ['role' => 'progressbar', 'style' => 'width: ' . $percent . '%;',
     'aria-valuenow' => $percent, 'aria-valuemin' => '0', 'aria-valuemax' => '100']
);
echo html_writer::end_div();

// Roll-up counters.
if ($ispull) {
    $summary = get_string('pullsummary', 'local_syncqueue', (object) [
        'total' => $status['totalitems'],
        'done' => $status['doneitems'],
        'failed' => $status['faileditems'],
    ]);
} else {
    $summary = get_string('jobsummary', 'local_syncqueue', (object) [
        'total' => $status['totalitems'],
        'done' => $status['doneitems'],
        'failed' => $status['faileditems'],
        'users' => $status['usercount'],
        'enrolments' => $status['enrolcount'],
    ]);
}
echo html_writer::tag('p', $summary, ['class' => 'lead']);

if (!$status['finished']) {
    echo $OUTPUT->notification(get_string('jobrunninghint', 'local_syncqueue'), 'info');
}

// Per-course items.
$table = new html_table();
$table->head = [
    get_string('course'),
    get_string('pushstate', 'local_syncqueue'),
    get_string('users', 'local_syncqueue'),
    get_string('enrolments', 'local_syncqueue'),
    get_string('error'),
];
$table->attributes['class'] = 'generaltable';

foreach ($status['items'] as $item) {
    $state = (object) ['status' => $item['status'], 'timecompleted' => null];
    $table->data[] = [
        $item['coursename'], // Already passed through format_string() in get_status().
        local_syncqueue_push_state_badge($state),
        $item['usercount'],
        $item['enrolcount'],
        $item['error'] !== '' ? s($item['error']) : '-',
    ];
}
echo html_writer::table($table);

echo html_writer::link($backurl,
    get_string($ispull ? 'backtodashboard' : 'backtocourses', 'local_syncqueue'),
    ['class' => 'btn btn-secondary']);

echo $OUTPUT->footer();
