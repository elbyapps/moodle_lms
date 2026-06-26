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
 * CLI script for manual sync operations.
 *
 * @package    local_syncqueue
 * @copyright  2025 REB Rwanda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_syncqueue\queue_manager;
use local_syncqueue\sync_client;

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'upload' => false,
    'download' => false,
    'status' => false,
    'test' => false,
    'force' => false,
], [
    'h' => 'help',
    'u' => 'upload',
    'd' => 'download',
    's' => 'status',
    't' => 'test',
    'f' => 'force',
]);

if ($options['help']) {
    $help = <<<EOF
Sync Queue CLI Tool

Options:
  -h, --help      Show this help message
  -u, --upload    Process and upload pending queue items
  -d, --download  Download updates from central server
  -s, --status    Show queue status
  -t, --test      Test connection to central server
  -f, --force     Force sync even if disabled

Examples:
  php sync.php --status
  php sync.php --test
  php sync.php --upload
  php sync.php --download
  php sync.php --upload --download

EOF;
    echo $help;
    exit(0);
}

$enabled = get_config('local_syncqueue', 'enabled');

if (!$enabled && !$options['force']) {
    cli_error('Sync queue is disabled. Use --force to override.');
}

$queuemanager = new queue_manager();

// Show status.
if ($options['status']) {
    $stats = $queuemanager->get_stats();

    cli_heading('Sync Queue Status');
    cli_writeln("School ID: " . (get_config('local_syncqueue', 'schoolid') ?: 'Not set'));
    cli_writeln("Central Server: " . (get_config('local_syncqueue', 'centralserver') ?: 'Not set'));
    cli_writeln("");
    cli_writeln("Queue Statistics:");
    cli_writeln("  Pending:    {$stats->pending}");
    cli_writeln("  Processing: {$stats->processing}");
    cli_writeln("  Synced:     {$stats->synced}");
    cli_writeln("  Failed:     {$stats->failed}");
    cli_writeln("  Conflicts:  {$stats->conflict}");
    cli_writeln("");
    cli_writeln("Last sync: " . ($stats->lastsync ? userdate($stats->lastsync) : 'Never'));
}

// Test connection.
if ($options['test']) {
    cli_heading('Testing Connection');

    try {
        $client = new sync_client();
        cli_writeln("Configuration OK.");

        if ($client->check_connection()) {
            cli_writeln("Connection successful!");
        } else {
            cli_error("Connection failed: Server returned error.");
        }
    } catch (Exception $e) {
        cli_error("Connection failed: " . $e->getMessage());
    }
}

// Upload.
if ($options['upload']) {
    cli_heading('Uploading Queue');

    try {
        $client = new sync_client();

        if (!$client->check_connection()) {
            cli_error("Cannot connect to central server.");
        }

        $batchsize = get_config('local_syncqueue', 'batchsize') ?: 100;
        $items = $queuemanager->get_pending_items($batchsize);

        if (empty($items)) {
            cli_writeln("No pending items to upload.");
        } else {
            cli_writeln("Uploading " . count($items) . " items...");

            $ids = array_column($items, 'id');
            $queuemanager->mark_processing($ids);

            $results = $client->upload($items);

            $synced = 0;
            $failed = 0;

            foreach ($results as $result) {
                if ($result['status'] === 'success') {
                    $queuemanager->mark_synced($result['id']);
                    $synced++;
                } else {
                    $queuemanager->mark_failed($result['id'], $result['message'] ?? 'Unknown error');
                    $failed++;
                }
            }

            cli_writeln("Complete: {$synced} synced, {$failed} failed.");
        }
    } catch (Exception $e) {
        cli_error("Upload failed: " . $e->getMessage());
    }
}

// Download.
if ($options['download']) {
    cli_heading('Downloading Updates');

    try {
        $client = new sync_client();

        if (!$client->check_connection()) {
            cli_error("Cannot connect to central server.");
        }

        $lastdownload = get_config('local_syncqueue', 'lastdownload') ?: 0;
        cli_writeln("Fetching updates since " . userdate($lastdownload) . "...");

        $updates = $client->download($lastdownload);

        if (empty($updates)) {
            cli_writeln("No updates available.");
        } else {
            cli_writeln("Received " . count($updates) . " updates.");

            $processor = new \local_syncqueue\update_processor();
            $results = $processor->process($updates);

            cli_writeln("Processed: {$results['success']} success, {$results['failed']} failed, {$results['skipped']} skipped.");

            set_config('lastdownload', time(), 'local_syncqueue');
        }
    } catch (Exception $e) {
        cli_error("Download failed: " . $e->getMessage());
    }
}

if (!$options['status'] && !$options['test'] && !$options['upload'] && !$options['download']) {
    cli_writeln("No action specified. Use --help for usage information.");
}

exit(0);
