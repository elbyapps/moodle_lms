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
 * Library functions for local_syncqueue.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get the navigation tabs HTML for syncqueue admin pages.
 *
 * @param string $currentpage The current page identifier (dashboard, settings, queue, schools, courses).
 * @return string The rendered HTML for navigation tabs.
 */
function local_syncqueue_get_navigation(string $currentpage): string {
    $navigation = new \local_syncqueue\output\navigation($currentpage);
    return $navigation->render();
}

/**
 * Render a small badge describing a course's latest push state.
 *
 * @param \stdClass|null $state Latest job-item row (status, timecompleted) or null.
 * @return string Badge HTML.
 */
function local_syncqueue_push_state_badge(?\stdClass $state): string {
    if (!$state) {
        return \html_writer::tag('span', get_string('pushstate_never', 'local_syncqueue'),
            ['class' => 'badge bg-secondary']);
    }
    switch ($state->status) {
        case 'done':
            $label = get_string('pushstate_pushed', 'local_syncqueue');
            if (!empty($state->timecompleted)) {
                $label .= ' · ' . userdate($state->timecompleted, get_string('strftimedateshort', 'langconfig'));
            }
            return \html_writer::tag('span', $label, ['class' => 'badge bg-success text-white']);
        case 'failed':
            return \html_writer::tag('span', get_string('pushstate_failed', 'local_syncqueue'),
                ['class' => 'badge bg-danger text-white']);
        case 'skipped':
            return \html_writer::tag('span', get_string('pushstate_skipped', 'local_syncqueue'),
                ['class' => 'badge bg-secondary']);
        case 'queued':
        case 'backing_up':
        case 'queuing_enrolments':
        default:
            return \html_writer::tag('span', get_string('pushstate_inprogress', 'local_syncqueue'),
                ['class' => 'badge bg-info text-white']);
    }
}

/**
 * Build a category tree from catalogue courses, keyed by category-path segments.
 *
 * @param array $courses Catalogue rows (each with a 'categorypath' string).
 * @return array Root node ['children' => [name => node, ...], 'courses' => [...]].
 */
function local_syncqueue_build_catalog_tree(array $courses): array {
    $root = ['children' => [], 'courses' => []];
    foreach ($courses as $c) {
        $pathstr = trim((string) ($c['categorypath'] ?? ''));
        $segments = $pathstr !== '' ? array_map('trim', explode(' / ', $pathstr)) : [];
        $node = &$root;
        foreach ($segments as $seg) {
            if ($seg === '') {
                continue;
            }
            if (!isset($node['children'][$seg])) {
                $node['children'][$seg] = ['children' => [], 'courses' => []];
            }
            $node = &$node['children'][$seg];
        }
        $node['courses'][] = $c;
        unset($node);
    }
    local_syncqueue_sort_catalog_tree($root);
    return $root;
}

/**
 * Sort a catalogue tree's children recursively by name (natural, case-insensitive).
 *
 * @param array $node Node passed by reference.
 */
function local_syncqueue_sort_catalog_tree(array &$node): void {
    if (!empty($node['children'])) {
        ksort($node['children'], SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($node['children'] as &$child) {
            local_syncqueue_sort_catalog_tree($child);
        }
        unset($child);
    }
}

/**
 * Count courses (and currently-selected courses) in a node's whole subtree.
 *
 * @param array $node Tree node.
 * @return array{0:int,1:int} [total, selected].
 */
function local_syncqueue_count_catalog_node(array $node): array {
    $total = 0;
    $selected = 0;
    foreach ($node['courses'] as $c) {
        $total++;
        if (!empty($c['selected'])) {
            $selected++;
        }
    }
    foreach ($node['children'] as $child) {
        [$t, $s] = local_syncqueue_count_catalog_node($child);
        $total += $t;
        $selected += $s;
    }
    return [$total, $selected];
}

/**
 * Render the catalogue category tree for the school pull-priorities picker.
 *
 * @param array $root Root node from local_syncqueue_build_catalog_tree().
 * @return string HTML.
 */
function local_syncqueue_render_catalog_tree(array $root): string {
    $seq = 0;
    $html = \html_writer::start_div('sq-tree');
    foreach ($root['children'] as $name => $child) {
        $html .= local_syncqueue_render_catalog_node((string) $name, $child, $seq);
    }
    foreach ($root['courses'] as $c) {
        $html .= local_syncqueue_render_catalog_course($c);
    }
    $html .= \html_writer::end_div();
    return $html;
}

/**
 * Render one category node (collapsible) with cascade controls.
 *
 * @param string $name Category name.
 * @param array $node Tree node.
 * @param int $seq Running id sequence (by reference, for unique ids).
 * @return string HTML.
 */
function local_syncqueue_render_catalog_node(string $name, array $node, int &$seq): string {
    $seq++;
    $nid = 'sqn' . $seq;
    $bodyid = $nid . '-body';
    [$total, $selected] = local_syncqueue_count_catalog_node($node);
    $allselected = ($total > 0 && $selected === $total);

    $html = \html_writer::start_div('sq-node', ['data-node' => $nid]);

    // Header row.
    $html .= \html_writer::start_div('sq-cat-row');
    $html .= \html_writer::tag('button', '+', [
        'type' => 'button',
        'class' => 'sq-toggle btn btn-sm btn-link p-0 mr-1',
        'style' => 'width: 1.5rem; font-weight: bold;',
        'data-target' => $bodyid,
        'aria-expanded' => 'false',
    ]);
    $catattrs = [
        'type' => 'checkbox',
        'class' => 'sq-cat-cb mr-2',
        'data-target' => $bodyid,
    ];
    if ($allselected) {
        $catattrs['checked'] = 'checked';
    }
    $html .= \html_writer::empty_tag('input', $catattrs);
    $html .= \html_writer::tag('span', s($name), ['class' => 'sq-cat-name font-weight-bold']);
    $html .= \html_writer::tag('span', get_string('categorycourses', 'local_syncqueue', $total),
        ['class' => 'sq-cat-count text-muted ml-2']);
    $html .= \html_writer::empty_tag('input', [
        'type' => 'number',
        'class' => 'sq-cat-weight form-control form-control-sm ml-auto',
        'data-target' => $bodyid,
        'min' => 0,
        'placeholder' => get_string('priorityweight', 'local_syncqueue'),
        'style' => 'width: 110px;',
    ]);
    $html .= \html_writer::end_div();

    // Collapsible body: child categories then courses.
    $html .= \html_writer::start_div('sq-node-body sq-collapsed', ['id' => $bodyid]);
    foreach ($node['children'] as $cname => $child) {
        $html .= local_syncqueue_render_catalog_node((string) $cname, $child, $seq);
    }
    foreach ($node['courses'] as $c) {
        $html .= local_syncqueue_render_catalog_course($c);
    }
    $html .= \html_writer::end_div();

    $html .= \html_writer::end_div();
    return $html;
}

/**
 * Render a single course row inside the catalogue tree.
 *
 * @param array $c Catalogue course row.
 * @return string HTML.
 */
function local_syncqueue_render_catalog_course(array $c): string {
    $cid = (int) $c['courseid'];
    $level = (string) ($c['level'] ?? '');
    $tradelevel = trim((string) ($c['tradecode'] ?? '') . ($level !== '' ? ' / ' . $level : ''));
    if ($tradelevel === '') {
        $tradelevel = '-';
    }
    $cbattrs = [
        'type' => 'checkbox',
        'class' => 'sq-course-cb mr-2',
        'name' => 'sel[]',
        'value' => $cid,
    ];
    if (!empty($c['selected'])) {
        $cbattrs['checked'] = 'checked';
    }
    $html = \html_writer::start_div('sq-course-row');
    $html .= \html_writer::empty_tag('input', $cbattrs);
    $html .= \html_writer::tag('span', s($c['fullname']), ['class' => 'sq-course-name']);
    $html .= \html_writer::tag('span', s($tradelevel), ['class' => 'sq-course-meta text-muted ml-2']);
    $html .= \html_writer::empty_tag('input', [
        'type' => 'number',
        'class' => 'sq-course-weight form-control form-control-sm ml-auto',
        'name' => 'weight[' . $cid . ']',
        'value' => (int) $c['weight'],
        'min' => 0,
        'style' => 'width: 90px;',
    ]);
    $html .= \html_writer::end_div();
    return $html;
}

/**
 * Render the site category hierarchy as a collapsible tree of push checkboxes
 * (central mode "push a category"). Tick a (sub)category to push it.
 *
 * @return string HTML.
 */
function local_syncqueue_render_category_push_tree(): string {
    global $DB;
    $cats = $DB->get_records('course_categories', null, 'sortorder ASC',
        'id, name, parent, coursecount');
    $byparent = [];
    foreach ($cats as $cat) {
        $byparent[(int) $cat->parent][] = $cat;
    }
    $html = \html_writer::start_div('sq-tree sq-cat-push-tree');
    $html .= local_syncqueue_render_category_push_nodes($byparent, 0);
    $html .= \html_writer::end_div();
    return $html;
}

/**
 * Recursively render category push-tree nodes for one parent.
 *
 * @param array $byparent Map of parentid => child category records.
 * @param int $parentid Parent category id (0 for top level).
 * @return string HTML.
 */
function local_syncqueue_render_category_push_nodes(array $byparent, int $parentid): string {
    if (empty($byparent[$parentid])) {
        return '';
    }
    $html = '';
    foreach ($byparent[$parentid] as $cat) {
        $haschildren = !empty($byparent[(int) $cat->id]);
        $bodyid = 'sqpc' . (int) $cat->id . '-body';

        $html .= \html_writer::start_div('sq-node', ['data-node' => 'sqpc' . (int) $cat->id]);
        $html .= \html_writer::start_div('sq-cat-row');
        if ($haschildren) {
            $html .= \html_writer::tag('button', '+', [
                'type' => 'button',
                'class' => 'sq-toggle btn btn-sm btn-link p-0 mr-1',
                'data-target' => $bodyid,
                'aria-expanded' => 'false',
                'style' => 'width: 1.5rem; font-weight: bold;',
            ]);
        } else {
            $html .= \html_writer::tag('span', '', ['style' => 'display:inline-block; width:1.5rem;']);
        }
        $html .= \html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'class' => 'sq-catpush-cb mr-2',
            'name' => 'catsel[]',
            'value' => (int) $cat->id,
        ]);
        $html .= \html_writer::tag('span', s($cat->name), ['class' => 'sq-cat-name font-weight-bold']);
        $html .= \html_writer::tag('span',
            get_string('categorycourses', 'local_syncqueue', (int) $cat->coursecount),
            ['class' => 'text-muted ml-2']);
        $html .= \html_writer::end_div();

        if ($haschildren) {
            $html .= \html_writer::start_div('sq-node-body sq-collapsed', ['id' => $bodyid]);
            $html .= local_syncqueue_render_category_push_nodes($byparent, (int) $cat->id);
            $html .= \html_writer::end_div();
        }
        $html .= \html_writer::end_div();
    }
    return $html;
}
