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

// Build a real category tree from the catalogue's category paths.
$root = local_syncqueue_build_catalog_tree($courses);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out_omit_querystring()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Only-pull-selected toggle.
echo html_writer::start_div('form-check mb-3');
echo html_writer::checkbox('onlyselected', 1, !empty($catalog['onlyselected']),
    get_string('onlypullselected', 'local_syncqueue'),
    ['class' => 'form-check-input', 'id' => 'onlyselected']);
echo html_writer::end_div();

// Expand / collapse all.
echo html_writer::div(
    html_writer::link('#', get_string('expandall', 'local_syncqueue'),
        ['id' => 'sq-expand-all', 'class' => 'btn btn-sm btn-outline-secondary mr-2']) .
    html_writer::link('#', get_string('collapseall', 'local_syncqueue'),
        ['id' => 'sq-collapse-all', 'class' => 'btn btn-sm btn-outline-secondary']),
    'mb-2'
);

// Category tree picker: tick a (sub)category to prioritise all its courses;
// expand to fine-tune individual courses.
echo local_syncqueue_render_catalog_tree($root);

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('savepriorities', 'local_syncqueue'),
    'class' => 'btn btn-primary mt-3',
]);
echo html_writer::end_tag('form');

// Tree behaviour: collapse/expand + cascade select & weight + indeterminate roll-up.
// (Tree styling lives in the plugin's styles.css.)
$js = <<<JS
(function() {
    function toggle(btn) {
        var body = document.getElementById(btn.getAttribute('data-target'));
        if (!body) { return; }
        var collapsed = body.classList.toggle('sq-collapsed');
        btn.textContent = collapsed ? '+' : '-';
        btn.setAttribute('aria-expanded', (!collapsed).toString());
    }
    document.querySelectorAll('.sq-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() { toggle(btn); });
    });
    document.querySelectorAll('.sq-cat-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var body = document.getElementById(cb.getAttribute('data-target'));
            if (!body) { return; }
            body.querySelectorAll('.sq-course-cb').forEach(function(x) { x.checked = cb.checked; });
            body.querySelectorAll('.sq-cat-cb').forEach(function(x) { x.checked = cb.checked; x.indeterminate = false; });
            cb.indeterminate = false;
            rollup();
        });
    });
    document.querySelectorAll('.sq-cat-weight').forEach(function(w) {
        w.addEventListener('input', function() {
            var body = document.getElementById(w.getAttribute('data-target'));
            if (!body) { return; }
            body.querySelectorAll('.sq-course-weight').forEach(function(x) { x.value = w.value; });
            body.querySelectorAll('.sq-cat-weight').forEach(function(x) { x.value = w.value; });
        });
    });
    document.querySelectorAll('.sq-course-cb').forEach(function(cb) {
        cb.addEventListener('change', rollup);
    });
    function rollup() {
        document.querySelectorAll('.sq-node').forEach(function(node) {
            var cb = node.querySelector(':scope > .sq-cat-row > .sq-cat-cb');
            var body = node.querySelector(':scope > .sq-node-body');
            if (!cb || !body) { return; }
            var boxes = body.querySelectorAll('.sq-course-cb');
            var total = boxes.length, sel = 0;
            boxes.forEach(function(b) { if (b.checked) { sel++; } });
            if (total === 0 || sel === 0) { cb.checked = false; cb.indeterminate = false; }
            else if (sel === total) { cb.checked = true; cb.indeterminate = false; }
            else { cb.checked = false; cb.indeterminate = true; }
        });
    }
    var ea = document.getElementById('sq-expand-all');
    var ca = document.getElementById('sq-collapse-all');
    if (ea) { ea.addEventListener('click', function(e) { e.preventDefault();
        document.querySelectorAll('.sq-node-body').forEach(function(b) { b.classList.remove('sq-collapsed'); });
        document.querySelectorAll('.sq-toggle').forEach(function(t) { t.textContent = '-'; t.setAttribute('aria-expanded', 'true'); });
    }); }
    if (ca) { ca.addEventListener('click', function(e) { e.preventDefault();
        document.querySelectorAll('.sq-node-body').forEach(function(b) { b.classList.add('sq-collapsed'); });
        document.querySelectorAll('.sq-toggle').forEach(function(t) { t.textContent = '+'; t.setAttribute('aria-expanded', 'false'); });
    }); }
    rollup();
})();
JS;
echo html_writer::script($js);

echo $OUTPUT->footer();
