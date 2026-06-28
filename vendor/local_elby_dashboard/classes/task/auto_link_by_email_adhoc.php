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
 * Ad-hoc task wrapper to run auto-link by email in the background.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_elby_dashboard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Ad-hoc task that runs the auto-link by email logic in the background via cron.
 */
class auto_link_by_email_adhoc extends \core\task\adhoc_task {

    /**
     * Execute the task.
     */
    public function execute(): void {
        $task = new auto_link_by_email();
        $task->execute();
    }
}
