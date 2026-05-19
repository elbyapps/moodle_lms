<?php
// Placeholder vendored Moodle local plugin. Safe to delete.
//
// Why this exists: vendor/ would otherwise be empty (apart from README.md),
// which makes it unclear what "a vendored plugin tree" should look like.
// This stub is a minimal-but-valid local_ plugin — copy it to start a real
// in-house plugin, or just delete the directory.
//
// It is *not* installed into Moodle automatically. Nothing in vendor/ is
// touched unless an entry in moodle-config.json points at it with
// source: "vendor". See ../README.md.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_hello';
$plugin->version   = 2026010100;
$plugin->requires  = 2025101000; // Moodle 5.1
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
