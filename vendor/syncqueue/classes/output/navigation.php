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
 * Navigation renderer for syncqueue admin pages.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_syncqueue\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Navigation class for rendering tab-based navigation across syncqueue admin pages.
 */
class navigation {

    /** @var string The current page identifier. */
    protected $currentpage;

    /** @var string The current operation mode (school or central). */
    protected $mode;

    /**
     * Constructor.
     *
     * @param string $currentpage The current page identifier (dashboard, settings, queue, schools, courses).
     */
    public function __construct(string $currentpage) {
        $this->currentpage = $currentpage;
        $this->mode = get_config('local_syncqueue', 'mode');
    }

    /**
     * Get all navigation tabs.
     *
     * @return array Array of tab definitions.
     */
    protected function get_tabs(): array {
        $tabs = [];

        // Dashboard - always visible.
        $tabs['dashboard'] = [
            'label' => get_string('dashboard', 'local_syncqueue'),
            'url' => new \moodle_url('/local/syncqueue/dashboard.php'),
            'visible' => true,
        ];

        // Queue Viewer - school mode only.
        $tabs['queue'] = [
            'label' => get_string('queueviewer', 'local_syncqueue'),
            'url' => new \moodle_url('/local/syncqueue/queue.php'),
            'visible' => ($this->mode === 'school'),
        ];

        // Pull priorities - school mode only.
        $tabs['pullpriorities'] = [
            'label' => get_string('pullpriorities', 'local_syncqueue'),
            'url' => new \moodle_url('/local/syncqueue/pull_priorities.php'),
            'visible' => ($this->mode === 'school'),
        ];

        // School Management - central mode only.
        $tabs['schools'] = [
            'label' => get_string('schoolmanagement', 'local_syncqueue'),
            'url' => new \moodle_url('/local/syncqueue/schools.php'),
            'visible' => ($this->mode === 'central'),
        ];

        // Push Courses - central mode only.
        $tabs['courses'] = [
            'label' => get_string('pushcourses', 'local_syncqueue'),
            'url' => new \moodle_url('/local/syncqueue/courses.php'),
            'visible' => ($this->mode === 'central'),
        ];

        return $tabs;
    }

    /**
     * Render the navigation tabs as HTML using Moodle's tabtree.
     *
     * @return string The HTML output for navigation tabs.
     */
    public function render(): string {
        global $OUTPUT;

        $tabs = $this->get_tabs();
        $tabobjects = [];

        foreach ($tabs as $key => $tab) {
            if (!$tab['visible']) {
                continue;
            }

            $tabobjects[] = new \tabobject(
                $key,
                $tab['url'],
                $tab['label']
            );
        }

        return $OUTPUT->tabtree($tabobjects, $this->currentpage);
    }
}
