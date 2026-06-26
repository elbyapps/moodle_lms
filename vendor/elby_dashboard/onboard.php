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
 * Forced onboarding wizard: choose a user type and (for students/teachers)
 * link the SDMS record. Login-only and self-contained so it works for every
 * user regardless of their capabilities.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

global $USER, $DB, $SESSION, $PAGE, $OUTPUT;

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
if ($returnurl === '' || strpos($returnurl, '/local/elby_dashboard/onboard.php') !== false) {
    $returnurl = '/my/';
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/elby_dashboard/onboard.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('onboard_title', 'local_elby_dashboard'));
$PAGE->set_heading(get_string('onboard_title', 'local_elby_dashboard'));

// Already onboarded — nothing to do.
if (local_elby_dashboard_is_onboarded($USER->id)) {
    $SESSION->elby_onboarded = true;
    redirect(new moodle_url($returnurl));
}

$type = local_elby_dashboard_get_user_type($USER->id);
$valid = ['Student', 'Teacher', 'RTB Staff', 'External'];
$error = '';
$preview = null;

// --- POST: user picked a type ---
$chosen = optional_param('usertype', '', PARAM_TEXT);
if ($chosen !== '' && confirm_sesskey() && in_array($chosen, $valid, true)) {
    local_elby_dashboard_set_user_type($USER->id, $chosen);
    $type = $chosen;
    if ($chosen === 'RTB Staff' || $chosen === 'External') {
        $SESSION->elby_onboarded = true;
        redirect(new moodle_url($returnurl),
            get_string('onboard_done', 'local_elby_dashboard'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Allow switching role before linking.
if (optional_param('choose', 0, PARAM_BOOL) && confirm_sesskey()) {
    $type = '';
}

$isstudenttype = ($type === 'Student' || $type === 'Teacher');
$linktype = ($type === 'Teacher') ? 'staff' : 'student';
$sdmscode = trim(optional_param('sdms_code', '', PARAM_TEXT));
$action = optional_param('action', '', PARAM_ALPHA);

// --- POST: confirm + link (students/teachers) ---
if ($action === 'confirm' && $isstudenttype && $sdmscode !== '' && confirm_sesskey()) {
    $other = $DB->get_record('elby_sdms_users', ['sdms_id' => $sdmscode]);
    if ($other && (int) $other->userid !== (int) $USER->id) {
        $error = get_string('onboard_linked_other', 'local_elby_dashboard');
    } else {
        try {
            $svc = new \local_elby_dashboard\sync_service();
            if ($svc->link_user($USER->id, $sdmscode, $linktype)) {
                $SESSION->elby_onboarded = true;
                redirect(new moodle_url($returnurl),
                    get_string('onboard_done', 'local_elby_dashboard'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
            $error = get_string('sdms_not_found', 'local_elby_dashboard');
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// --- POST: look up a code for preview (students/teachers) ---
if ($action === 'lookup' && $isstudenttype && $sdmscode !== '' && confirm_sesskey() && $error === '') {
    try {
        $client = new \local_elby_dashboard\tdmp_client();
        $data = ($linktype === 'student') ? $client->get_student($sdmscode) : $client->get_teacher($sdmscode);
        if ($data) {
            // Resolve the trade name (full name via /trades, fall back to the short code).
            $program = $data->combinationName ?? '';
            if (!empty($data->combinationCode)) {
                try {
                    $trade = $client->get_trade((string) $data->combinationCode);
                    if ($trade && !empty($trade->tradeName)) {
                        $program = $trade->tradeName;
                    }
                } catch (\Exception $e) {
                    $program = $data->combinationName ?? '';
                }
            }
            $preview = [
                'names' => $data->names ?? '',
                'school' => $data->schoolName ?? '',
                'program' => $program,
                'grade' => $data->gradeName ?? '',
                'position' => $data->positionName ?? '',
            ];
        } else {
            $error = get_string('sdms_not_found', 'local_elby_dashboard');
        }
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
}

$sesskey = sesskey();
$selfurl = new moodle_url('/local/elby_dashboard/onboard.php', ['returnurl' => $returnurl]);

echo $OUTPUT->header();

echo html_writer::start_div('container', ['style' => 'max-width:680px;margin:0 auto;']);

if (!$isstudenttype) {
    // Step 1 — choose a user type.
    echo html_writer::tag('p', get_string('onboard_intro', 'local_elby_dashboard'), ['class' => 'lead']);
    echo html_writer::tag('h4', get_string('onboard_choose', 'local_elby_dashboard'), ['class' => 'mb-3']);

    $choices = [
        'Student' => get_string('usertype_student', 'local_elby_dashboard'),
        'Teacher' => get_string('usertype_teacher', 'local_elby_dashboard'),
        'RTB Staff' => get_string('usertype_rtbstaff', 'local_elby_dashboard'),
        'External' => get_string('usertype_external', 'local_elby_dashboard'),
    ];

    echo html_writer::start_div('row');
    foreach ($choices as $value => $label) {
        $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $selfurl->out(false)]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);
        $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'usertype', 'value' => $value]);
        $form .= html_writer::tag('button', $label,
            ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-block w-100 p-3']);
        $form .= html_writer::end_tag('form');
        echo html_writer::div($form, 'col-6 mb-3');
    }
    echo html_writer::end_div();
} else {
    // Step 2 — student/teacher: look up and link the SDMS record.
    $typelabel = ($type === 'Teacher')
        ? get_string('usertype_teacher', 'local_elby_dashboard')
        : get_string('usertype_student', 'local_elby_dashboard');
    echo html_writer::tag('p', $typelabel . ' — ' . get_string('onboard_sdms_intro', 'local_elby_dashboard'),
        ['class' => 'lead']);

    if ($error !== '') {
        echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $selfurl->out(false), 'class' => 'mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('onboard_sdms_code', 'local_elby_dashboard'),
        ['for' => 'sdms_code', 'class' => 'font-weight-bold']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'id' => 'sdms_code', 'name' => 'sdms_code',
        'value' => s($sdmscode), 'class' => 'form-control', 'required' => 'required',
        'autocomplete' => 'off',
    ]);
    echo html_writer::end_div();

    if ($preview) {
        echo html_writer::start_div('card my-3');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h5', s($preview['names']), ['class' => 'card-title']);
        if (!empty($preview['school'])) {
            echo html_writer::tag('p', s($preview['school']), ['class' => 'mb-1']);
        }
        if (!empty($preview['program'])) {
            echo html_writer::tag('p', s($preview['program']), ['class' => 'mb-1']);
        }
        if (!empty($preview['grade'])) {
            echo html_writer::tag('p', s($preview['grade']), ['class' => 'mb-1 text-muted']);
        }
        if (!empty($preview['position'])) {
            echo html_writer::tag('p', s($preview['position']), ['class' => 'mb-1 text-muted']);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::tag('button', get_string('onboard_confirm', 'local_elby_dashboard'),
            ['type' => 'submit', 'name' => 'action', 'value' => 'confirm', 'class' => 'btn btn-primary']);
    } else {
        echo html_writer::tag('button', get_string('onboard_lookup', 'local_elby_dashboard'),
            ['type' => 'submit', 'name' => 'action', 'value' => 'lookup', 'class' => 'btn btn-primary']);
    }
    echo html_writer::end_tag('form');

    // Link to switch role.
    $changeform = html_writer::start_tag('form', ['method' => 'post', 'action' => $selfurl->out(false)]);
    $changeform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $sesskey]);
    $changeform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'choose', 'value' => '1']);
    $changeform .= html_writer::tag('button', get_string('onboard_change_role', 'local_elby_dashboard'),
        ['type' => 'submit', 'class' => 'btn btn-link p-0']);
    $changeform .= html_writer::end_tag('form');
    echo $changeform;
}

echo html_writer::end_div();

echo $OUTPUT->footer();
