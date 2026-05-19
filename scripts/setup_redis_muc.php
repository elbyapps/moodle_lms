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

// -----------------------------------------------------------------------------
// Invalidate opcache copies of moodledata/muc/config.php.
//
// Why this matters: docker/php/docker-entrypoint.sh renders opcache with
// validate_timestamps=0 (prod-tuned), so workers never re-stat source files.
// We just rewrote /var/www/moodledata/muc/config.php on disk — without an
// explicit invalidation, PHP-FPM workers keep serving the pre-remap version
// and HTTP requests instantiate cachestore_session for session-mode caches,
// triggering the "session caches can not be in the session store when
// enable_read_only_sessions is enabled" error on the next request.
//
// opcache_invalidate() only affects the calling process's opcache, so:
//   1. Invalidate in this CLI process (cheap, harmless).
//   2. Invalidate in the PHP-FPM pool by making a FastCGI call into it.
//      cgi-fcgi is already installed in the image (used by the FPM
//      healthcheck), so this works in every environment.
// -----------------------------------------------------------------------------
$muc_config = "/var/www/moodledata/muc/config.php";

if (function_exists("opcache_invalidate")) {
    @opcache_invalidate($muc_config, true);
    echo "\n  -> CLI opcache invalidated for $muc_config\n";
}

// Best-effort FPM opcache reset. Skipped (with a clear message) if php-fpm
// isn't listening — e.g. when this script runs from the entrypoint before
// FPM has started, which is fine because workers will load the fresh config
// from disk on their very first request anyway.
$fpm_host = getenv("FPM_HOST") ?: "127.0.0.1";
$fpm_port = (int)(getenv("FPM_PORT") ?: 9000);
$reset_php = tempnam(sys_get_temp_dir(), "opcreset_") . ".php";
file_put_contents($reset_php, "<?php\nif (function_exists('opcache_reset')) {\n    opcache_reset();\n    echo 'opcache_reset() OK';\n} else {\n    echo 'opcache_reset unavailable';\n}\n");
chmod($reset_php, 0644);  // www-data needs to read it

$cmd = sprintf(
    "SCRIPT_NAME=%s SCRIPT_FILENAME=%s REQUEST_METHOD=GET cgi-fcgi -bind -connect %s:%d 2>&1",
    escapeshellarg("/" . basename($reset_php)),
    escapeshellarg($reset_php),
    escapeshellarg($fpm_host),
    $fpm_port
);
$out = trim((string)shell_exec($cmd));
@unlink($reset_php);

if (stripos($out, "opcache_reset() OK") !== false) {
    echo "  -> FPM opcache reset via FastCGI ({$fpm_host}:{$fpm_port}): OK\n";
} else {
    echo "  -> FPM opcache reset SKIPPED (php-fpm not reachable at {$fpm_host}:{$fpm_port}).\n";
    echo "     This is expected if FPM hasn't started yet. If FPM is running,\n";
    echo "     workers may still hold the pre-remap muc/config.php — restart\n";
    echo "     the php container to force a clean opcache:\n";
    echo "       docker restart \$(docker ps -qf name=php)\n";
    if ($out !== "") {
        echo "     cgi-fcgi output: $out\n";
    }
}
