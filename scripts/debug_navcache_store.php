<?php
define("CLI_SCRIPT", true);
require "/var/www/html/moodle_app/config.php";

echo "=== State BEFORE cache::make() ===\n";
echo "isset(\$_SESSION['SESSION']->cachestore_session): "
    . (isset($_SESSION['SESSION']->cachestore_session) ? "YES" : "no") . "\n";

$cache = cache::make('core', 'navigation_cache');
echo "\n=== State AFTER cache::make('core','navigation_cache') ===\n";
echo "Cache wrapper class: " . get_class($cache) . "\n";
echo "isset(\$_SESSION['SESSION']->cachestore_session): "
    . (isset($_SESSION['SESSION']->cachestore_session) ? "YES (PROBLEM)" : "no") . "\n";
if (isset($_SESSION['SESSION']->cachestore_session)) {
    echo "  keys: " . implode(', ', array_keys($_SESSION['SESSION']->cachestore_session)) . "\n";
}

echo "\n=== Underlying stores in the wrapper ===\n";
$ref = new ReflectionObject($cache);
foreach (['storeobject', 'store', 'stores'] as $prop) {
    if ($ref->hasProperty($prop)) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $val = $p->getValue($cache);
        if (is_array($val)) {
            foreach ($val as $i => $s) echo "  $prop\[$i] => " . get_class($s) . "\n";
        } else if (is_object($val)) {
            echo "  $prop => " . get_class($val) . "\n";
        }
    }
}

// Walk up to parent classes for store
$parent = get_parent_class($cache);
echo "Parent class: $parent\n";
if ($parent) {
    $pref = new ReflectionClass($parent);
    foreach ($pref->getProperties() as $p) {
        $p->setAccessible(true);
        try { $v = $p->getValue($cache); } catch (Throwable $e) { continue; }
        if (is_object($v)) echo "  parent\\${$p->getName()} => " . get_class($v) . "\n";
    }
}

echo "\n=== Trigger a write ===\n";
$cache->set('test_key', 'test_value');
echo "After set: isset(cachestore_session) = "
    . (isset($_SESSION['SESSION']->cachestore_session) ? "YES (PROBLEM)" : "no") . "\n";
if (isset($_SESSION['SESSION']->cachestore_session)) {
    echo "  keys: " . implode(', ', array_keys($_SESSION['SESSION']->cachestore_session)) . "\n";
}

echo "\n=== Redis state ===\n";
$r = new Redis();
$r->connect('redis', 6379, 2);
echo "moodle_muc_* count: " . count($r->keys('moodle_muc_*')) . "\n";
foreach (array_slice($r->keys('moodle_muc_*'), 0, 10) as $k) echo "  $k\n";
