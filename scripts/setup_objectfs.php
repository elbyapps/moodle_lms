<?php
// scripts/setup_objectfs.php
//
// One-shot bootstrap that seeds tool_objectfs operational settings into the
// Moodle DB from OBJECTFS_* environment variables. Run once after the
// initial install (or after changing the env defaults you want enforced):
//
//   docker exec <php-container> php /tmp/setup_objectfs.php           # idempotent
//   docker exec <php-container> php /tmp/setup_objectfs.php --force   # overwrite DB
//   docker exec <php-container> php /tmp/setup_objectfs.php --dry-run # show, no write
//
// Forced credentials (S3_*) are NOT touched here \u2014 they're already in
// $CFG->forced_plugin_settings via config.php.docker and don't live in DB.

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle_app/public/config.php');

$mapping = [
    'enabletasks'          => ['env' => 'OBJECTFS_ENABLE_TASKS',         'type' => 'int'],
    'sizethreshold'        => ['env' => 'OBJECTFS_SIZE_THRESHOLD',       'type' => 'int'],
    'minimumage'           => ['env' => 'OBJECTFS_MINIMUM_AGE',          'type' => 'int'],
    'deletelocal'          => ['env' => 'OBJECTFS_DELETE_LOCAL',         'type' => 'int'],
    'consistencydelay'     => ['env' => 'OBJECTFS_CONSISTENCY_DELAY',    'type' => 'int'],
    'deleteexternal'       => ['env' => 'OBJECTFS_DELETE_EXTERNAL',      'type' => 'int'],
    'enablepresignedurls'  => ['env' => 'OBJECTFS_PRESIGNED',            'type' => 'int'],
    'expirationtime'       => ['env' => 'OBJECTFS_PRESIGNED_EXPIRATION', 'type' => 'int'],
    'presignedminfilesize' => ['env' => 'OBJECTFS_PRESIGNED_MIN_SIZE',   'type' => 'int'],
    'signingwhitelist'     => ['env' => 'OBJECTFS_PRESIGNED_WHITELIST',  'type' => 'string'],
    'signingmethod'        => ['env' => 'OBJECTFS_SIGNING_METHOD',       'type' => 'string'],
];

$dryrun = in_array('--dry-run', $argv, true);
$force  = in_array('--force',   $argv, true);

$wrote = 0; $skipped = 0; $missing = 0;
foreach ($mapping as $setting => $info) {
    $envval = getenv($info['env']);
    if ($envval === false || $envval === '') {
        printf("  miss %-25s (env %s not set)\n", $setting, $info['env']);
        $missing++;
        continue;
    }
    $newval = $info['type'] === 'int' ? (string)(int)$envval : (string)$envval;
    $current = get_config('tool_objectfs', $setting);
    if (!$force && $current !== false && (string)$current !== '') {
        printf("  skip %-25s (already '%s'; use --force to overwrite)\n", $setting, $current);
        $skipped++;
        continue;
    }
    if ($dryrun) {
        printf("  plan %-25s = %s\n", $setting, $newval);
    } else {
        set_config($setting, $newval, 'tool_objectfs');
        printf("  set  %-25s = %s\n", $setting, $newval);
    }
    $wrote++;
}

printf("\nDone. wrote=%d skipped=%d missing-env=%d%s\n",
    $wrote, $skipped, $missing, $dryrun ? ' (dry-run)' : '');
