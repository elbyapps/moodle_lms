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
 * Admin action: refresh the school's offline roster cache on demand.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
require_sesskey();

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'local_elby_dashboard']);

try {
    $result = (new \local_elby_dashboard\roster_manager())->sync_roster();
    $message = get_string('roster_synced', 'local_elby_dashboard', (object) $result);
    if (!empty($result['warning'])) {
        redirect($returnurl, $message . ' ' . $result['warning'], null,
            \core\output\notification::NOTIFY_WARNING);
    }
    redirect(
        $returnurl,
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
} catch (\Throwable $e) {
    redirect($returnurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}
