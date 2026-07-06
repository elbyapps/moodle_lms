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
use local_syncqueue\content_publisher;

/**
 * Weekly content change-scan (ELMS Sync v2 step 7, doc §7, central side).
 *
 * Flags published courses whose live content (max timemodified over the course,
 * its sections and its modules) has drifted past the version last published to
 * the fleet. It deliberately does NOT auto-republish: §7 wants bounded staleness
 * surfaced for an explicit human publish act (content_publisher CLI / dashboard),
 * not an automatic re-backup storm on every edit. The flagged list is persisted
 * in the local_syncqueue/content_stale_courses config for the operator surface.
 *
 * @package    local_syncqueue
 * @copyright  2026 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_change_scan extends scheduled_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_content_change_scan', 'local_syncqueue');
    }

    /**
     * Execute the scan.
     */
    public function execute(): void {
        if (get_config('local_syncqueue', 'mode') !== 'central'
                || !get_config('local_syncqueue', 'enabled')) {
            mtrace('content_change_scan: not an enabled central instance, skipping.');
            return;
        }

        $stale = content_publisher::stale_courses();

        // Persist a bounded summary (course ids only) for the operator surface,
        // and clear it cleanly when nothing is stale.
        $ids = array_map(static fn($s) => (int) $s['courseid'], $stale);
        set_config('content_stale_courses', json_encode(array_values($ids)), 'local_syncqueue');
        set_config('content_scan_lastrun', time(), 'local_syncqueue');

        if (!$stale) {
            mtrace('content_change_scan: all published courses are current.');
            return;
        }

        mtrace('content_change_scan: ' . count($stale) . ' published course(s) have drifted since'
            . ' their last version and need an explicit republish:');
        foreach ($stale as $s) {
            $drift = $s['driftseconds'] < 0 ? 'never versioned' : ($s['driftseconds'] . 's behind');
            mtrace("  course {$s['courseid']} (content v{$s['contentversion']}, {$drift})");
        }
    }
}
