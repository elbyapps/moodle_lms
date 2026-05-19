<?php
define("CLI_SCRIPT", true);
require "/var/www/html/moodle_app/config.php";

use core_cache\config_writer;
use core_cache\store;

$config = config_writer::instance();
$stores = $config->get_all_stores();
echo "BEFORE — stores: " . implode(", ", array_keys($stores)) . "\n";

if (!isset($stores["redis"])) {
    $config->add_store_instance("redis", "redis", [
        "server" => "redis:6379",
        "prefix" => "moodle_muc_",
        "compressor" => 1,
    ]);
    echo "  -> ADDED redis store\n";
}

$mappings = [
    store::MODE_APPLICATION => ["redis"],
    store::MODE_SESSION     => ["redis"],
    store::MODE_REQUEST     => ["default_request"],
];
$config->set_mode_mappings($mappings);
echo "  -> set_mode_mappings done\n";

// CRITICAL: reload from disk to verify persistence
$config = config_writer::instance();
echo "\nAFTER — stores: " . implode(", ", array_keys($config->get_all_stores())) . "\n";
echo "AFTER — mode mappings:\n";
foreach ($config->get_mode_mappings() as $m) {
    printf("  mode=%s store=%s sort=%s\n", $m["mode"], $m["store"], $m["sort"]);
}
