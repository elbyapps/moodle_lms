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

namespace local_syncqueue\task;

use core\task\scheduled_task;
use local_syncqueue\outbox\sequencer;

/**
 * Scheduled task assigning dense sequence numbers to committed outbox rows.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sequencer_task extends scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sequencer', 'local_syncqueue');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        if (!$DB->record_exists_select('local_syncqueue_outbox', 'seq IS NULL')) {
            return;
        }

        $count = sequencer::assign();
        mtrace("Sequenced {$count} outbox row(s).");
    }
}
