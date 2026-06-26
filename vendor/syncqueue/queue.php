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
 * Queue viewer page for school mode.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_syncqueue_queue');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$eventtype = optional_param('eventtype', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/syncqueue/queue.php', ['status' => $status, 'eventtype' => $eventtype]));
$PAGE->set_title(get_string('queueviewer', 'local_syncqueue'));
$PAGE->set_heading(get_string('queueviewer', 'local_syncqueue'));

$mode = get_config('local_syncqueue', 'mode');

// Handle actions.
if ($action && confirm_sesskey()) {
    switch ($action) {
        case 'retry':
            if ($id) {
                global $DB;
                $DB->set_field('local_syncqueue_items', 'status', 'pending', ['id' => $id]);
                $DB->set_field('local_syncqueue_items', 'attempts', 0, ['id' => $id]);
                $DB->set_field('local_syncqueue_items', 'timemodified', time(), ['id' => $id]);
                \core\notification::success(get_string('itemretried', 'local_syncqueue'));
            }
            redirect($PAGE->url);
            break;

        case 'delete':
            if ($id) {
                if ($confirm) {
                    global $DB;
                    $DB->delete_records('local_syncqueue_items', ['id' => $id]);
                    \core\notification::success(get_string('itemdeleted', 'local_syncqueue'));
                    redirect($PAGE->url);
                } else {
                    echo $OUTPUT->header();
                    echo $OUTPUT->heading(get_string('deleteitem', 'local_syncqueue'));

                    $confirmurl = new moodle_url('/local/syncqueue/queue.php', [
                        'action' => 'delete',
                        'id' => $id,
                        'confirm' => 1,
                        'sesskey' => sesskey(),
                        'status' => $status,
                        'eventtype' => $eventtype,
                    ]);
                    $cancelurl = new moodle_url('/local/syncqueue/queue.php', [
                        'status' => $status,
                        'eventtype' => $eventtype,
                    ]);

                    echo $OUTPUT->confirm(
                        get_string('deleteitemconfirm', 'local_syncqueue', $id),
                        $confirmurl,
                        $cancelurl
                    );
                    echo $OUTPUT->footer();
                    exit;
                }
            }
            break;

        case 'viewpayload':
            if ($id) {
                global $DB;
                $item = $DB->get_record('local_syncqueue_items', ['id' => $id]);

                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('viewpayload', 'local_syncqueue') . ' - #' . $id);

                if ($item) {
                    $payload = json_decode($item->payload, true);
                    $formattedpayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                    echo $OUTPUT->box_start('generalbox');
                    echo html_writer::tag('p', html_writer::tag('strong', get_string('eventtype', 'local_syncqueue') . ': ') . s($item->eventtype));
                    echo html_writer::tag('p', html_writer::tag('strong', get_string('eventname', 'local_syncqueue') . ': ') . s($item->eventname));
                    echo html_writer::tag('p', html_writer::tag('strong', get_string('status') . ': ') . get_string('status_' . $item->status, 'local_syncqueue'));
                    echo html_writer::tag('p', html_writer::tag('strong', get_string('timecreated', 'local_syncqueue') . ': ') . userdate($item->timecreated));

                    if (!empty($item->lasterror)) {
                        echo html_writer::tag('p', html_writer::tag('strong', get_string('lasterror', 'local_syncqueue') . ': '));
                        echo html_writer::tag('pre', s($item->lasterror), ['class' => 'bg-danger text-white p-2 rounded']);
                    }

                    echo html_writer::tag('p', html_writer::tag('strong', get_string('payload', 'local_syncqueue') . ':'));
                    echo html_writer::tag('pre', s($formattedpayload), ['class' => 'bg-light p-3 border rounded', 'style' => 'max-height: 400px; overflow: auto;']);
                    echo $OUTPUT->box_end();
                } else {
                    echo $OUTPUT->notification(get_string('itemnotfound', 'local_syncqueue'), 'error');
                }

                echo html_writer::link(
                    new moodle_url('/local/syncqueue/queue.php', ['status' => $status, 'eventtype' => $eventtype]),
                    get_string('back'),
                    ['class' => 'btn btn-secondary']
                );

                echo $OUTPUT->footer();
                exit;
            }
            break;

        case 'clearfailed':
            if ($confirm) {
                global $DB;
                $deleted = $DB->delete_records('local_syncqueue_items', ['status' => 'failed']);
                $deleted += $DB->delete_records('local_syncqueue_items', ['status' => 'conflict']);
                \core\notification::success(get_string('faileditems_cleared', 'local_syncqueue'));
                redirect($PAGE->url);
            } else {
                global $DB;
                $failedcount = $DB->count_records('local_syncqueue_items', ['status' => 'failed']);
                $conflictcount = $DB->count_records('local_syncqueue_items', ['status' => 'conflict']);
                $totalcount = $failedcount + $conflictcount;

                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('clearfailed', 'local_syncqueue'));

                $confirmurl = new moodle_url('/local/syncqueue/queue.php', [
                    'action' => 'clearfailed',
                    'confirm' => 1,
                    'sesskey' => sesskey(),
                ]);
                $cancelurl = new moodle_url('/local/syncqueue/queue.php');

                echo $OUTPUT->confirm(
                    get_string('clearfailedconfirm', 'local_syncqueue', $totalcount),
                    $confirmurl,
                    $cancelurl
                );
                echo $OUTPUT->footer();
                exit;
            }
            break;

        case 'retryall':
            global $DB;
            $DB->execute(
                "UPDATE {local_syncqueue_items} SET status = 'pending', attempts = 0, timemodified = ? WHERE status IN ('failed', 'conflict')",
                [time()]
            );
            \core\notification::success(get_string('allitemsretried', 'local_syncqueue'));
            redirect($PAGE->url);
            break;
    }
}

echo $OUTPUT->header();
echo local_syncqueue_get_navigation('queue');
echo $OUTPUT->heading(get_string('queueviewer', 'local_syncqueue'));

// Display queue statistics.
$qm = new \local_syncqueue\queue_manager();
$stats = $qm->get_stats();

echo html_writer::start_div('mb-4');
echo html_writer::start_div('row');

$statboxes = [
    ['label' => 'status_pending', 'value' => $stats->pending, 'class' => 'bg-warning', 'status' => 'pending'],
    ['label' => 'status_processing', 'value' => $stats->processing, 'class' => 'bg-info', 'status' => 'processing'],
    ['label' => 'status_synced', 'value' => $stats->synced, 'class' => 'bg-success', 'status' => 'synced'],
    ['label' => 'status_failed', 'value' => $stats->failed, 'class' => 'bg-danger', 'status' => 'failed'],
    ['label' => 'status_conflict', 'value' => $stats->conflict, 'class' => 'bg-secondary', 'status' => 'conflict'],
];

foreach ($statboxes as $box) {
    $filterurl = new moodle_url('/local/syncqueue/queue.php', ['status' => $box['status']]);
    echo html_writer::start_div('col-md-2 text-center mb-3');
    echo html_writer::start_tag('a', ['href' => $filterurl, 'class' => 'd-block text-decoration-none']);
    echo html_writer::start_div('p-3 rounded ' . $box['class'] . ' text-white');
    echo html_writer::tag('h3', $box['value'], ['class' => 'mb-0']);
    echo html_writer::tag('small', get_string($box['label'], 'local_syncqueue'));
    echo html_writer::end_div();
    echo html_writer::end_tag('a');
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

// Filter form.
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring(), 'class' => 'form-inline mb-4']);

// Status filter.
$statusoptions = [
    '' => get_string('allstatuses', 'local_syncqueue'),
    'pending' => get_string('status_pending', 'local_syncqueue'),
    'processing' => get_string('status_processing', 'local_syncqueue'),
    'synced' => get_string('status_synced', 'local_syncqueue'),
    'failed' => get_string('status_failed', 'local_syncqueue'),
    'conflict' => get_string('status_conflict', 'local_syncqueue'),
];
echo html_writer::label(get_string('status'), 'status', false, ['class' => 'mr-2']);
echo html_writer::select($statusoptions, 'status', $status, false, ['class' => 'form-control mr-3']);

// Event type filter.
$eventtypeoptions = [
    '' => get_string('alleventtypes', 'local_syncqueue'),
    'grade' => get_string('eventtype_grade', 'local_syncqueue'),
    'submission' => get_string('eventtype_submission', 'local_syncqueue'),
    'quiz' => get_string('eventtype_quiz', 'local_syncqueue'),
    'forum' => get_string('eventtype_forum', 'local_syncqueue'),
    'user' => get_string('eventtype_user', 'local_syncqueue'),
    'enrol' => get_string('eventtype_enrol', 'local_syncqueue'),
    'completion' => get_string('eventtype_completion', 'local_syncqueue'),
];
echo html_writer::label(get_string('eventtype', 'local_syncqueue'), 'eventtype', false, ['class' => 'mr-2']);
echo html_writer::select($eventtypeoptions, 'eventtype', $eventtype, false, ['class' => 'form-control mr-3']);

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('filter'), 'class' => 'btn btn-primary']);

// Reset filter link.
echo html_writer::link(
    new moodle_url('/local/syncqueue/queue.php'),
    get_string('reset'),
    ['class' => 'btn btn-secondary ml-2']
);

echo html_writer::end_tag('form');

// Bulk actions.
if ($stats->failed > 0 || $stats->conflict > 0) {
    echo html_writer::start_div('mb-4');
    echo html_writer::tag('strong', get_string('bulkactions', 'local_syncqueue') . ': ');

    $retryallurl = new moodle_url('/local/syncqueue/queue.php', ['action' => 'retryall', 'sesskey' => sesskey()]);
    echo html_writer::link($retryallurl, get_string('retryallfailed', 'local_syncqueue'), ['class' => 'btn btn-primary mr-2']);

    $clearurl = new moodle_url('/local/syncqueue/queue.php', ['action' => 'clearfailed', 'sesskey' => sesskey()]);
    echo html_writer::link($clearurl, get_string('clearfailed', 'local_syncqueue'), ['class' => 'btn btn-danger']);

    echo html_writer::end_div();
}

// Queue table.
$table = new \local_syncqueue\table\queue_table('syncqueue_items');
$table->set_status_filter($status);
$table->set_eventtype_filter($eventtype);

$baseurl = new moodle_url('/local/syncqueue/queue.php', ['status' => $status, 'eventtype' => $eventtype]);
$table->define_baseurl($baseurl);

$table->out(50, true);

echo $OUTPUT->footer();
