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
 * School-mode page: browse the central course catalogue and set pull priorities (F3).
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_syncqueue_pullpriorities');

$PAGE->set_url(new moodle_url('/local/syncqueue/pull_priorities.php'));
$PAGE->set_title(get_string('pullpriorities', 'local_syncqueue'));
$PAGE->set_heading(get_string('pullpriorities', 'local_syncqueue'));

$mode = get_config('local_syncqueue', 'mode');
if ($mode !== 'school') {
    redirect(new moodle_url('/local/syncqueue/dashboard.php'),
        get_string('schoolmodeonlypage', 'local_syncqueue'), null,
        \core\output\notification::NOTIFY_WARNING);
}

$action = optional_param('action', '', PARAM_ALPHA);

// Save selections back to central.
if ($action === 'save' && confirm_sesskey()) {
    $selected = optional_param_array('sel', [], PARAM_INT);       // courseids checked.
    $weights = optional_param_array('weight', [], PARAM_INT);     // courseid => weight.
    $onlyselected = optional_param('onlyselected', 0, PARAM_BOOL);

    $prefs = [];
    foreach ($selected as $courseid) {
        $prefs[] = [
            'courseid' => (int) $courseid,
            'selected' => true,
            'weight' => isset($weights[$courseid]) ? (int) $weights[$courseid] : 0,
        ];
    }

    try {
        $client = new \local_syncqueue\sync_client();
        $result = $client->upload_priorities((bool) $onlyselected, $prefs);
        \core\notification::success(get_string('prioritiessaved', 'local_syncqueue', $result['stored'] ?? count($prefs)));
    } catch (\Exception $e) {
        \core\notification::error(get_string('connectionfailed', 'local_syncqueue') . ': ' . $e->getMessage());
    }
    redirect($PAGE->url);
}

echo $OUTPUT->header();
echo local_syncqueue_get_navigation('pullpriorities');
echo $OUTPUT->heading(get_string('pullpriorities', 'local_syncqueue'));
echo html_writer::tag('p', get_string('pullpriorities_help', 'local_syncqueue'), ['class' => 'text-muted']);

// Fetch the catalogue from central.
try {
    $client = new \local_syncqueue\sync_client();
    $catalog = $client->get_catalog();
} catch (\Exception $e) {
    echo $OUTPUT->notification(get_string('connectionfailed', 'local_syncqueue') . ': ' . $e->getMessage(), 'error');
    echo $OUTPUT->footer();
    exit;
}

$courses = $catalog['courses'];
if (empty($courses)) {
    echo $OUTPUT->notification(get_string('catalogempty', 'local_syncqueue'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Group by category path for a tree-like display.
$groups = [];
foreach ($courses as $c) {
    $key = $c['categorypath'] !== '' ? $c['categorypath'] : get_string('uncategorized', 'local_syncqueue');
    $groups[$key][] = $c;
}
ksort($groups);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out_omit_querystring()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Only-pull-selected toggle.
echo html_writer::start_div('form-check mb-3');
echo html_writer::checkbox('onlyselected', 1, !empty($catalog['onlyselected']),
    get_string('onlypullselected', 'local_syncqueue'),
    ['class' => 'form-check-input', 'id' => 'onlyselected']);
echo html_writer::end_div();

foreach ($groups as $categorypath => $groupcourses) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-header d-flex align-items-center');
    echo html_writer::checkbox('', '', false, '', ['class' => 'cat-toggle mr-2', 'title' => get_string('selectall')]);
    echo html_writer::tag('strong', s($categorypath));
    echo html_writer::end_div();

    $table = new html_table();
    $table->head = [
        '',
        get_string('course'),
        get_string('tradecode', 'local_syncqueue') . ' / ' . get_string('level', 'local_syncqueue'),
        get_string('priorityweight', 'local_syncqueue'),
    ];
    $table->attributes['class'] = 'generaltable mb-0';
    foreach ($groupcourses as $c) {
        $cid = (int) $c['courseid'];
        $check = html_writer::checkbox('sel[]', $cid, !empty($c['selected']), '',
            ['class' => 'course-pref-checkbox']);
        $tradelevel = trim(($c['tradecode'] ?? '') . ($c['level'] !== '' ? ' / ' . $c['level'] : '')) ?: '-';
        $weightinput = html_writer::empty_tag('input', [
            'type' => 'number',
            'name' => 'weight[' . $cid . ']',
            'value' => (int) $c['weight'],
            'class' => 'form-control',
            'style' => 'width: 90px;',
            'min' => 0,
        ]);
        $table->data[] = [$check, s($c['fullname']), s($tradelevel), $weightinput];
    }
    echo html_writer::table($table);
    echo html_writer::end_div();
}

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('savepriorities', 'local_syncqueue'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

// Category select-all (inline page JS).
$js = <<<JS
document.querySelectorAll('.cat-toggle').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        var card = this.closest('.card');
        card.querySelectorAll('.course-pref-checkbox').forEach(function(cb) {
            cb.checked = toggle.checked;
        });
    });
});
JS;
echo html_writer::script($js);

echo $OUTPUT->footer();
