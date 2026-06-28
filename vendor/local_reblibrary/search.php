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
 * REB Library search page.
 *
 * This page provides search functionality for the library.
 *
 * @package    local_reblibrary
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/reblibrary/lib.php');

// Allow both authenticated users and guests to access this page.
require_login(null, true);

// Set up the page context.
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/reblibrary/search.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('searchpage_title', 'local_reblibrary'));
$PAGE->set_heading(get_string('searchpage_heading', 'local_reblibrary'));

// Add breadcrumb navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_reblibrary'), new moodle_url('/local/reblibrary/index.php'));
$PAGE->navbar->add(get_string('nav_search', 'local_reblibrary'));

// Load JavaScript module.
$PAGE->requires->js_call_amd('local_reblibrary/library', 'init');

// Prepare data for template.
$templatecontext = [
    'description' => get_string('searchpage_description', 'local_reblibrary'),
];

// Output the page.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_reblibrary/navigation', local_reblibrary_get_navigation('search'));
echo $OUTPUT->render_from_template('local_reblibrary/library_home', $templatecontext);
echo $OUTPUT->footer();
