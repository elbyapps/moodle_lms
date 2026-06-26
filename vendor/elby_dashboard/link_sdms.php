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
 * Self-service SDMS account linking page for logged-in users.
 *
 * @package    local_elby_dashboard
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/elby_dashboard:view', context_system::instance());

// If already linked, redirect to dashboard.
global $DB, $USER;
if ($DB->record_exists('elby_sdms_users', ['userid' => $USER->id])) {
    redirect(
        new moodle_url('/local/elby_dashboard/index.php'),
        get_string('sdms_already_linked', 'local_elby_dashboard'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$PAGE->set_url(new moodle_url('/local/elby_dashboard/link_sdms.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('self_link_title', 'local_elby_dashboard'));
$PAGE->set_heading(get_string('self_link_title', 'local_elby_dashboard'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elby_dashboard/link_sdms', [
    'dashboardurl' => (new moodle_url('/local/elby_dashboard/index.php'))->out(false),
    'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
