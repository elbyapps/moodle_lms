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
