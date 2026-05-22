<?php
// scripts/reconcile_plugins.php
//
// Reconcile the installed Moodle plugin set against moodle-config.json:
// any plugin present in Moodle (DB + disk) but no longer listed in the
// config is treated as an "orphan" and uninstalled cleanly — DB tables and
// settings via Moodle's uninstall API, then the source directory via
// remove_plugin_folder().
//
// Core/standard plugins are NEVER touched — only third-party plugins that
// were originally provisioned from moodle-config.json.
//
// Usage (run inside the php container, with both files in /tmp):
//   php /tmp/reconcile_plugins.php --dry-run    # list orphans, write nothing
//   php /tmp/reconcile_plugins.php              # uninstall + delete source dir
//
// Options:
//   --config=PATH   Path to moodle-config.json (default /tmp/moodle-config.json)
//   --keep-source   Uninstall from DB but leave the source tree on disk
//                   (use when you want to inspect what was there before
//                    `make build-fresh` wipes it).
//
// Intentionally NOT wired into the container entrypoint: see the README for
// why this stays a manual operation.

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle_app/public/config.php');
require_once($CFG->libdir . '/clilib.php');
// core_plugin_manager::uninstall_plugin() calls the global uninstall_plugin()
// function defined in lib/adminlib.php. Without this require, the call fails
// with "Call to undefined function core\uninstall_plugin()" because PHP
// resolves the unqualified name against the namespaced caller.
require_once($CFG->libdir . '/adminlib.php');

global $CFG;

// ---- args ------------------------------------------------------------------

$dryrun     = in_array('--dry-run', $argv, true);
$keepsource = in_array('--keep-source', $argv, true);
$configpath = '/tmp/moodle-config.json';
foreach ($argv as $a) {
    if (strpos($a, '--config=') === 0) {
        $configpath = substr($a, strlen('--config='));
    }
}

if (!is_readable($configpath)) {
    fwrite(STDERR, "ERROR: cannot read $configpath\n");
    exit(1);
}

$config = json_decode(file_get_contents($configpath), true);
if (!is_array($config) || empty($config['plugins'])) {
    fwrite(STDERR, "ERROR: $configpath has no .plugins array\n");
    exit(1);
}

// ---- build the "configured" set --------------------------------------------
// Match by DESTINATION PATH, not by the JSON `name` field. The JSON name is
// just a human label — for some entries it doesn't match the real Moodle
// frankenstyle component (e.g. JSON name `admin_tool_objectfs` but the actual
// component is `tool_objectfs`). Destination paths are unambiguous: they map
// 1:1 to where the plugin lives under public/ and therefore to a single
// installed plugin's rootdir.

$configured = [];          // relative-path-under-public => true
foreach ($config['plugins'] as $p) {
    if (!empty($p['destination'])) {
        $configured[$p['destination']] = true;
    }
}

// ---- find orphans ----------------------------------------------------------

$pluginman = core_plugin_manager::instance();
$orphans   = [];           // [component => plugininfo]

// Anchor for converting absolute rootdir back to a relative-under-public path.
// Moodle 5.x serves from $CFG->dirroot which IS the public/ directory itself.
$rootprefix = rtrim($CFG->dirroot, '/') . '/';

foreach ($pluginman->get_plugins() as $type => $plugins) {
    foreach ($plugins as $name => $plugininfo) {
        $component = $plugininfo->component;

        // Never touch standard/core plugins shipped with Moodle itself.
        if ($plugininfo->source === core_plugin_manager::PLUGIN_SOURCE_STANDARD) {
            continue;
        }

        // Compute the plugin's path relative to public/, e.g. "auth/oidc".
        // If rootdir is null (plugin record in DB but source missing on disk),
        // $reldir stays null and the destination match will fail — i.e. it'll
        // be flagged as an orphan, which is the correct behaviour.
        $reldir = null;
        if (!empty($plugininfo->rootdir) && strpos($plugininfo->rootdir, $rootprefix) === 0) {
            $reldir = substr($plugininfo->rootdir, strlen($rootprefix));
        }

        // Plugins that came from moodle-config.json: keep.
        //
        // Match either an EXACT destination (the plugin itself) OR a
        // PARENT-DIR destination (the plugin is a sub-plugin shipped inside a
        // configured parent, e.g. customcertelement_* under mod/customcert
        // or ultimatebadgecriteria_* under local/ultimate). Sub-plugins
        // belong to their parent and must not be uninstalled separately.
        if ($reldir !== null) {
            if (isset($configured[$reldir])) {
                continue;
            }
            $isSubplugin = false;
            foreach (array_keys($configured) as $configuredDest) {
                if (strpos($reldir, $configuredDest . '/') === 0) {
                    $isSubplugin = true;
                    break;
                }
            }
            if ($isSubplugin) {
                continue;
            }
        }

        // Whatever's left is a third-party plugin that the config no longer
        // declares (or whose source disappeared and the config dropped it)
        // — that's the orphan set.
        $orphans[$component] = $plugininfo;
    }
}

// ---- report ----------------------------------------------------------------

$total = count($orphans);
printf("Configured plugins in moodle-config.json : %d\n", count($configured));
printf("Orphan plugins (installed but not in config) : %d\n\n", $total);

if ($total === 0) {
    echo "Nothing to do — installed set matches config.\n";
    exit(0);
}

foreach ($orphans as $component => $info) {
    $shown = $info->rootdir
        ? str_replace($rootprefix, '', $info->rootdir)
        : '(missing on disk)';
    printf("  %-40s rootdir=%s\n", $component, $shown);
}

if ($dryrun) {
    echo "\nDry-run: no changes written.\n";
    exit(0);
}

// ---- apply -----------------------------------------------------------------

// Use the global null_progress_trace (lib/weblib.php) — there is no
// namespaced equivalent. uninstall_plugin() requires a progress_trace.
$progress = new \null_progress_trace();
$uninstalled = 0;
$removed_dirs = 0;
$failed = [];

foreach ($orphans as $component => $info) {
    printf("\n-> Uninstalling %s ...\n", $component);

    // Some plugins refuse uninstall if other plugins depend on them — surface
    // the reason instead of crashing.
    if (!$pluginman->can_uninstall_plugin($component)) {
        $reqby = $pluginman->other_plugins_that_require($component);
        fwrite(STDERR, "   SKIP: cannot uninstall (required by: " . implode(', ', $reqby) . ")\n");
        $failed[] = $component;
        continue;
    }

    try {
        $pluginman->uninstall_plugin($component, $progress);
        $uninstalled++;
    } catch (Throwable $e) {
        fwrite(STDERR, "   FAIL: " . $e->getMessage() . "\n");
        $failed[] = $component;
        continue;
    }

    if ($keepsource) {
        echo "   kept source tree at " . ($info->rootdir ?? '(no rootdir)') . " (--keep-source)\n";
        continue;
    }

    // Plugin record may exist with the source missing already (rootdir empty
    // or dir gone) — the DB uninstall above is all that's needed in that
    // case. Only try to delete the source tree when it actually exists.
    if (!empty($info->rootdir) && is_dir($info->rootdir)) {
        $pluginman->remove_plugin_folder($info);
        if (is_dir($info->rootdir)) {
            fwrite(STDERR, "   WARN: source dir still present at $info->rootdir\n");
        } else {
            echo "   removed source dir $info->rootdir\n";
            $removed_dirs++;
        }
    } else {
        echo "   no source dir to remove (was already missing on disk)\n";
    }
}

// Bust caches so the admin UI doesn't keep showing ghost plugins.
purge_all_caches();

printf("\nDone. uninstalled=%d source_dirs_removed=%d failed=%d\n",
    $uninstalled, $removed_dirs, count($failed));

if (!empty($failed)) {
    fwrite(STDERR, "Failed components: " . implode(', ', $failed) . "\n");
    exit(2);
}
