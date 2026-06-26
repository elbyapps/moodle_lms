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
 * School management page for central mode.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_syncqueue_schools');

$action = optional_param('action', '', PARAM_ALPHA);
$schoolid = optional_param('schoolid', '', PARAM_ALPHANUMEXT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/syncqueue/schools.php'));
$PAGE->set_title(get_string('schoolmanagement', 'local_syncqueue'));
$PAGE->set_heading(get_string('schoolmanagement', 'local_syncqueue'));

$schoolmanager = new \local_syncqueue\school_manager();
$mode = get_config('local_syncqueue', 'mode');

// Redirect if not in central mode.
if ($mode !== 'central') {
    redirect(
        new moodle_url('/local/syncqueue/dashboard.php'),
        get_string('centralmodeonlypage', 'local_syncqueue'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Handle actions.
if ($action && confirm_sesskey()) {
    switch ($action) {
        case 'delete':
            if ($confirm) {
                $schoolmanager->delete_school($schoolid);
                \core\notification::success(get_string('schooldeleted', 'local_syncqueue'));
                redirect($PAGE->url);
            } else {
                // Show confirmation page.
                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('deleteschool', 'local_syncqueue'));

                $school = $schoolmanager->get_school($schoolid);
                if ($school) {
                    $confirmurl = new moodle_url('/local/syncqueue/schools.php', [
                        'action' => 'delete',
                        'schoolid' => $schoolid,
                        'confirm' => 1,
                        'sesskey' => sesskey(),
                    ]);
                    $cancelurl = new moodle_url('/local/syncqueue/schools.php');

                    echo $OUTPUT->confirm(
                        get_string('deleteschoolconfirm', 'local_syncqueue', $school->name),
                        $confirmurl,
                        $cancelurl
                    );
                } else {
                    echo $OUTPUT->notification(get_string('schoolnotfound', 'local_syncqueue'), 'error');
                }
                echo $OUTPUT->footer();
                exit;
            }
            break;

        case 'suspend':
            $schoolmanager->update_status($schoolid, 'suspended');
            \core\notification::success(get_string('schoolsuspended', 'local_syncqueue'));
            redirect($PAGE->url);
            break;

        case 'activate':
            $schoolmanager->update_status($schoolid, 'active');
            \core\notification::success(get_string('schoolactivated', 'local_syncqueue'));
            redirect($PAGE->url);
            break;

        case 'derivetrades':
            $count = $schoolmanager->derive_school_trades($schoolid);
            \core\notification::success(get_string('schooltrades_auto', 'local_syncqueue') . ' (' . $count . ')');
            redirect($PAGE->url);
            break;

        case 'regenerate':
            if ($confirm) {
                $newapikey = $schoolmanager->regenerate_apikey($schoolid);
                $school = $schoolmanager->get_school($schoolid);

                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('newapikey', 'local_syncqueue'));

                echo $OUTPUT->box_start('generalbox');
                echo html_writer::tag('p', get_string('newapikeymsg', 'local_syncqueue', $school->name));
                echo html_writer::tag('pre', $newapikey, ['class' => 'bg-light p-3 border rounded']);
                echo html_writer::tag('p', get_string('newapikeywarn', 'local_syncqueue'), ['class' => 'text-danger']);
                echo $OUTPUT->box_end();

                echo html_writer::link(
                    new moodle_url('/local/syncqueue/schools.php'),
                    get_string('continue'),
                    ['class' => 'btn btn-primary']
                );

                echo $OUTPUT->footer();
                exit;
            } else {
                // Show confirmation page.
                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('regenerateapikey', 'local_syncqueue'));

                $school = $schoolmanager->get_school($schoolid);
                if ($school) {
                    $confirmurl = new moodle_url('/local/syncqueue/schools.php', [
                        'action' => 'regenerate',
                        'schoolid' => $schoolid,
                        'confirm' => 1,
                        'sesskey' => sesskey(),
                    ]);
                    $cancelurl = new moodle_url('/local/syncqueue/schools.php');

                    echo $OUTPUT->confirm(
                        get_string('regenerateapikeyconfirm', 'local_syncqueue', $school->name),
                        $confirmurl,
                        $cancelurl
                    );
                } else {
                    echo $OUTPUT->notification(get_string('schoolnotfound', 'local_syncqueue'), 'error');
                }
                echo $OUTPUT->footer();
                exit;
            }
            break;

        case 'register':
            // Handled by form below.
            break;
    }
}

// Handle registration form.
$registerform = new \local_syncqueue\form\register_school_form();

if ($registerform->is_cancelled()) {
    redirect($PAGE->url);
} else if ($data = $registerform->get_data()) {
    $apikey = $schoolmanager->register_school(
        $data->schoolid,
        $data->name,
        $data->contactemail ?? '',
        $data->description ?? ''
    );

    // Show the API key to the user.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('schoolregistered', 'local_syncqueue'));

    echo $OUTPUT->box_start('generalbox');
    echo html_writer::tag('p', get_string('schoolregisteredmsg', 'local_syncqueue', $data->name));
    echo html_writer::tag('p', html_writer::tag('strong', get_string('schoolid', 'local_syncqueue') . ': ') . $data->schoolid);
    echo html_writer::tag('p', html_writer::tag('strong', get_string('apikey', 'local_syncqueue') . ':'));
    echo html_writer::tag('pre', $apikey, ['class' => 'bg-light p-3 border rounded']);
    echo html_writer::tag('p', get_string('apikeyonetime', 'local_syncqueue'), ['class' => 'text-danger']);
    echo $OUTPUT->box_end();

    echo html_writer::link(
        new moodle_url('/local/syncqueue/schools.php'),
        get_string('continue'),
        ['class' => 'btn btn-primary']
    );

    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo local_syncqueue_get_navigation('schools');
echo $OUTPUT->heading(get_string('schoolmanagement', 'local_syncqueue'));

// Show registration form toggle.
$showform = optional_param('showform', 0, PARAM_BOOL);
if ($showform) {
    echo $OUTPUT->box_start('generalbox');
    echo $OUTPUT->heading(get_string('registernewschool', 'local_syncqueue'), 3);
    $registerform->display();
    echo $OUTPUT->box_end();
} else {
    $registerurl = new moodle_url('/local/syncqueue/schools.php', ['showform' => 1]);
    echo html_writer::tag('p',
        html_writer::link($registerurl, get_string('registernewschool', 'local_syncqueue'), ['class' => 'btn btn-primary'])
    );
}

// Display schools table (paginated).
$schools = $schoolmanager->get_all_schools();
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;
$totalschools = count($schools);

if (empty($schools)) {
    echo $OUTPUT->notification(get_string('noschools', 'local_syncqueue'), 'info');
} else {
    $schools = array_slice($schools, $page * $perpage, $perpage, true);
    $table = new html_table();
    $table->head = [
        get_string('schoolid', 'local_syncqueue'),
        get_string('schoolname', 'local_syncqueue'),
        get_string('status'),
        get_string('contactemail', 'local_syncqueue'),
        get_string('lastsync', 'local_syncqueue'),
        get_string('totalsynced', 'local_syncqueue'),
        get_string('schooltrades', 'local_syncqueue'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($schools as $school) {
        // Status badge.
        $statusclass = [
            'active' => 'badge-success',
            'suspended' => 'badge-warning',
            'pending' => 'badge-secondary',
        ];
        $statusbadge = html_writer::tag('span',
            get_string('status_' . $school->status, 'local_syncqueue'),
            ['class' => 'badge ' . ($statusclass[$school->status] ?? 'badge-secondary')]
        );

        // Last sync time.
        $lastsync = $school->lastsynced ? userdate($school->lastsynced) : get_string('never', 'local_syncqueue');

        // Build actions.
        $actions = [];

        // Suspend/Activate toggle.
        if ($school->status === 'active') {
            $suspendurl = new moodle_url('/local/syncqueue/schools.php', [
                'action' => 'suspend',
                'schoolid' => $school->schoolid,
                'sesskey' => sesskey(),
            ]);
            $actions[] = html_writer::link($suspendurl, get_string('suspend', 'local_syncqueue'), ['class' => 'btn btn-sm btn-warning']);
        } else {
            $activateurl = new moodle_url('/local/syncqueue/schools.php', [
                'action' => 'activate',
                'schoolid' => $school->schoolid,
                'sesskey' => sesskey(),
            ]);
            $actions[] = html_writer::link($activateurl, get_string('activate', 'local_syncqueue'), ['class' => 'btn btn-sm btn-success']);
        }

        // Regenerate API key.
        $regenerateurl = new moodle_url('/local/syncqueue/schools.php', [
            'action' => 'regenerate',
            'schoolid' => $school->schoolid,
            'sesskey' => sesskey(),
        ]);
        $actions[] = html_writer::link($regenerateurl, get_string('regeneratekey', 'local_syncqueue'), ['class' => 'btn btn-sm btn-secondary']);

        // Delete.
        $deleteurl = new moodle_url('/local/syncqueue/schools.php', [
            'action' => 'delete',
            'schoolid' => $school->schoolid,
            'sesskey' => sesskey(),
        ]);
        $actions[] = html_writer::link($deleteurl, get_string('delete'), ['class' => 'btn btn-sm btn-danger']);

        // Course priorities (trades/levels) cell.
        $trades = $schoolmanager->get_school_trades($school->schoolid);
        if ($trades) {
            $tradehtml = '';
            foreach ($trades as $t) {
                $lbl = $t->tradecode . ($t->level ? ('/' . $t->level) : '');
                $tradehtml .= html_writer::tag('span', s($lbl), ['class' => 'badge bg-info text-white mr-1']) . ' ';
            }
        } else {
            $tradehtml = html_writer::tag('span', get_string('schooltrades_none', 'local_syncqueue'), ['class' => 'text-muted']);
        }
        $detecturl = new moodle_url('/local/syncqueue/schools.php', [
            'action' => 'derivetrades',
            'schoolid' => $school->schoolid,
            'sesskey' => sesskey(),
        ]);
        $tradehtml .= html_writer::div(
            html_writer::link($detecturl, get_string('autodetecttrades', 'local_syncqueue'),
                ['class' => 'btn btn-sm btn-outline-secondary mt-1']),
            'mt-1'
        );

        $table->data[] = [
            s($school->schoolid),
            s($school->name),
            $statusbadge,
            s($school->contactemail),
            $lastsync,
            $school->totalsynced,
            $tradehtml,
            implode(' ', $actions),
        ];
    }

    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalschools, $page, $perpage, new moodle_url('/local/syncqueue/schools.php'));
}

echo $OUTPUT->footer();
