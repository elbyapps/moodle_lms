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
 * Push courses to schools (Central mode).
 *
 * Selecting courses (or a whole category) creates an async push job: the heavy
 * backup + enrolment fan-out runs in adhoc tasks, so the request returns
 * immediately and progress is tracked on job.php.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

admin_externalpage_setup('local_syncqueue_courses');

$action = optional_param('action', '', PARAM_ALPHA);
$courseids = optional_param_array('courses', [], PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$includesub = optional_param('includesub', 0, PARAM_BOOL);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

$pageurl = new moodle_url('/local/syncqueue/courses.php');
if ($search !== '') {
    $pageurl->param('search', $search);
}
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('pushcourses', 'local_syncqueue'));
$PAGE->set_heading(get_string('pushcourses', 'local_syncqueue'));

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

// Build category list once; reused for the picker and the row labels.
$categorylist = \core_course_category::make_categories_list('', 0, ' / ');

// Handle push of an explicit selection.
if ($action === 'push' && confirm_sesskey() && !empty($courseids)) {
    $jobmgr = new \local_syncqueue\job_manager();
    $job = $jobmgr->create_push_job($USER->id, $courseids);
    local_syncqueue_redirect_to_job($job);
}

// Handle push of one or more whole categories (optionally recursive).
if ($action === 'pushcategory' && confirm_sesskey()) {
    $catids = optional_param_array('catsel', [], PARAM_INT);
    if (empty($catids) && $categoryid) {
        $catids = [$categoryid];
    }
    if (!empty($catids)) {
        $jobmgr = new \local_syncqueue\job_manager();
        $expanded = [];
        foreach ($catids as $catid) {
            $expanded = array_merge($expanded, $jobmgr->expand_category((int) $catid, (bool) $includesub));
        }
        $expanded = array_values(array_unique(array_map('intval', $expanded)));
        if (empty($expanded)) {
            redirect($PAGE->url, get_string('categoryempty', 'local_syncqueue'), null,
                \core\output\notification::NOTIFY_WARNING);
        }
        $job = $jobmgr->create_push_job($USER->id, $expanded,
            count($catids) === 1 ? (int) $catids[0] : null);
        local_syncqueue_redirect_to_job($job);
    }
}

/**
 * Redirect to the job progress page with a non-blocking summary.
 *
 * @param \stdClass $job Job returned by job_manager::create_push_job().
 */
function local_syncqueue_redirect_to_job(\stdClass $job): void {
    $queued = count($job->itemcourseids);
    $skipped = count($job->skipped);
    if ($queued > 0) {
        $msg = get_string('coursesqueued', 'local_syncqueue', $queued);
        if ($skipped > 0) {
            $msg .= ' ' . get_string('coursesskipped', 'local_syncqueue', $skipped);
        }
        redirect(new moodle_url('/local/syncqueue/job.php', ['id' => $job->id]), $msg, null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect(new moodle_url('/local/syncqueue/courses.php'),
        get_string('nocoursesqueued', 'local_syncqueue'), null,
        \core\output\notification::NOTIFY_INFO);
}

echo $OUTPUT->header();
echo local_syncqueue_get_navigation('courses');
echo $OUTPUT->heading(get_string('pushcourses', 'local_syncqueue'));

// --- Push a whole category (F1) ---
echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h5', get_string('pushcategory', 'local_syncqueue'), ['class' => 'card-title']);
echo html_writer::tag('p', get_string('pushcategory_help', 'local_syncqueue'), ['class' => 'text-muted']);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/syncqueue/courses.php'))->out_omit_querystring(),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'pushcategory']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-check mb-2');
echo html_writer::checkbox('includesub', 1, true, get_string('includesubcategories', 'local_syncqueue'),
    ['class' => 'form-check-input', 'id' => 'includesub']);
echo html_writer::end_div();

echo html_writer::div(
    html_writer::link('#', get_string('expandall', 'local_syncqueue'),
        ['id' => 'sq-expand-all', 'class' => 'btn btn-sm btn-outline-secondary mr-2']) .
    html_writer::link('#', get_string('collapseall', 'local_syncqueue'),
        ['id' => 'sq-collapse-all', 'class' => 'btn btn-sm btn-outline-secondary']),
    'mb-2'
);

echo local_syncqueue_render_category_push_tree();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('pushcategorybtn', 'local_syncqueue'),
    'class' => 'btn btn-primary mt-3',
]);
echo html_writer::end_tag('form');

// Collapse/expand behaviour for the category push tree (styling in styles.css).
$cattreejs = <<<JS
(function() {
    document.querySelectorAll('.sq-cat-push-tree .sq-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var body = document.getElementById(btn.getAttribute('data-target'));
            if (!body) { return; }
            var collapsed = body.classList.toggle('sq-collapsed');
            btn.textContent = collapsed ? '+' : '-';
            btn.setAttribute('aria-expanded', (!collapsed).toString());
        });
    });
    var ea = document.getElementById('sq-expand-all');
    var ca = document.getElementById('sq-collapse-all');
    if (ea) { ea.addEventListener('click', function(e) { e.preventDefault();
        document.querySelectorAll('.sq-cat-push-tree .sq-node-body').forEach(function(b) { b.classList.remove('sq-collapsed'); });
        document.querySelectorAll('.sq-cat-push-tree .sq-toggle').forEach(function(t) { t.textContent = '-'; t.setAttribute('aria-expanded', 'true'); });
    }); }
    if (ca) { ca.addEventListener('click', function(e) { e.preventDefault();
        document.querySelectorAll('.sq-cat-push-tree .sq-node-body').forEach(function(b) { b.classList.add('sq-collapsed'); });
        document.querySelectorAll('.sq-cat-push-tree .sq-toggle').forEach(function(t) { t.textContent = '+'; t.setAttribute('aria-expanded', 'false'); });
    }); }
})();
JS;
echo html_writer::script($cattreejs);
echo html_writer::end_div();
echo html_writer::end_div();

// Build search query.
$params = ['siteid' => SITEID];
$searchsql = '';
if ($search !== '') {
    $searchsql = ' AND (' . $DB->sql_like('c.fullname', ':search1', false) .
                 ' OR ' . $DB->sql_like('c.shortname', ':search2', false) .
                 ' OR ' . $DB->sql_like('cc.name', ':search3', false) . ')';
    $params['search1'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['search3'] = '%' . $DB->sql_like_escape($search) . '%';
}

$countsql = "SELECT COUNT(*)
               FROM {course} c
          LEFT JOIN {course_categories} cc ON cc.id = c.category
              WHERE c.id != :siteid" . $searchsql;
$totalcount = $DB->count_records_sql($countsql, $params);

$sql = "SELECT c.id, c.shortname, c.fullname, c.visible, c.category, cc.name AS categoryname
          FROM {course} c
     LEFT JOIN {course_categories} cc ON cc.id = c.category
         WHERE c.id != :siteid" . $searchsql . "
      ORDER BY c.fullname ASC";
$courses = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// Search form.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/syncqueue/courses.php'))->out_omit_querystring(),
    'class' => 'mb-3',
]);
echo html_writer::start_div('input-group', ['style' => 'max-width: 400px;']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'search',
    'value' => $search,
    'placeholder' => get_string('searchcourses', 'local_syncqueue'),
    'class' => 'form-control',
]);
echo html_writer::start_div('input-group-append');
echo html_writer::tag('button', get_string('search'), [
    'type' => 'submit',
    'class' => 'btn btn-outline-secondary',
]);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_tag('form');

if ($totalcount === 0) {
    if ($search !== '') {
        echo $OUTPUT->notification(get_string('nocoursesmatchsearch', 'local_syncqueue'), 'info');
    } else {
        echo $OUTPUT->notification(get_string('nocourses', 'local_syncqueue'), 'info');
    }
} else {
    echo html_writer::tag('p', get_string('selectcourses', 'local_syncqueue'));

    // Latest push state per visible course (for the badge column).
    $jobmgr = new \local_syncqueue\job_manager();
    $states = $jobmgr->get_latest_states(array_keys($courses));

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $PAGE->url->out_omit_querystring(),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'push']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    $table = new html_table();
    $table->head = [
        html_writer::checkbox('selectall', 1, false, '', ['id' => 'selectall']),
        get_string('course'),
        get_string('shortname'),
        get_string('category'),
        get_string('visible'),
        get_string('pushstate', 'local_syncqueue'),
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($courses as $course) {
        $categoryname = $categorylist[$course->category] ?? ($course->categoryname ?: '-');

        $checkbox = html_writer::checkbox('courses[]', $course->id, false, '', ['class' => 'course-checkbox']);
        $visible = $course->visible ? get_string('yes') : get_string('no');

        $table->data[] = [
            $checkbox,
            s($course->fullname),
            s($course->shortname),
            s($categoryname),
            $visible,
            local_syncqueue_push_state_badge($states[$course->id] ?? null),
        ];
    }

    echo html_writer::table($table);

    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, $PAGE->url);

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('pushtoschools', 'local_syncqueue'),
        'class' => 'btn btn-primary',
    ]);

    echo html_writer::end_tag('form');

    // Select-all for the current page (inline page JS, not an AMD module).
    $js = <<<JS
document.getElementById('selectall').addEventListener('change', function() {
    var checkboxes = document.querySelectorAll('.course-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = this.checked;
    }
});
JS;
    echo html_writer::script($js);
}

echo $OUTPUT->footer();
