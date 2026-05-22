<?php
// scripts/migrate_auth_externalid.php
//
// One-shot migration that moves every user whose authentication method is the
// custom `auth_externalid` plugin onto Moodle's built-in `manual` auth, so the
// auth_externalid plugin (and its companion local_custom_service) can be safely
// removed.
//
// Why `manual` is the right target:
//   auth_externalid extends auth_email — it stores passwords with Moodle's
//   internal hash (validate_internal_user_password / hash_internal_user_password),
//   so existing user passwords keep working unchanged under `manual`. The only
//   thing the custom plugin really added was a Student/Teacher external-ID
//   check at signup; ongoing login is identical.
//
// Usage:
//   docker exec <php-container> php /tmp/migrate_auth_externalid.php             # apply
//   docker exec <php-container> php /tmp/migrate_auth_externalid.php --dry-run   # preview, no write
//   docker exec <php-container> php /tmp/migrate_auth_externalid.php --target=email  # override default 'manual'
//
// Idempotent: re-running after a successful migration is a no-op (0 users left
// on the externalid auth).

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle_app/public/config.php');

global $DB, $CFG;

$dryrun = in_array('--dry-run', $argv, true);

// Allow overriding the target auth (default: manual).
$target = 'manual';
foreach ($argv as $a) {
    if (strpos($a, '--target=') === 0) {
        $target = substr($a, strlen('--target='));
    }
}

$source = 'externalid';

// Sanity-check the target auth exists in core (manual/email/etc. are always there,
// but if someone passes --target=oidc we want to fail loudly).
$enabled = explode(',', (string)get_config('core', 'auth'));
$allcoreauths = ['manual', 'nologin']; // always available
if (!in_array($target, $allcoreauths, true) && !in_array($target, $enabled, true)) {
    fwrite(STDERR, "ERROR: target auth '$target' is neither core nor enabled.\n");
    fwrite(STDERR, "       Enable it under Site admin > Plugins > Authentication > Manage authentication first.\n");
    exit(1);
}

// Count users on the source auth.
$count = $DB->count_records('user', ['auth' => $source]);
printf("Users on auth='%s' : %d\n", $source, $count);

if ($count === 0) {
    echo "Nothing to do.\n";
    // Still tidy up $CFG->registerauth if it points at the dying plugin.
    handle_registerauth($dryrun);
    exit(0);
}

// Show a small sample so the operator can eyeball it.
$sample = $DB->get_records('user', ['auth' => $source], 'id ASC', 'id, username, firstname, lastname, email', 0, 5);
echo "Sample (first 5):\n";
foreach ($sample as $u) {
    printf("  id=%-6d username=%-20s name='%s %s' email=%s\n",
        $u->id, $u->username, $u->firstname, $u->lastname, $u->email);
}

if ($dryrun) {
    printf("\nDry-run: would set auth='%s' for %d user(s) (UPDATE {user} SET auth='%s' WHERE auth='%s').\n",
        $target, $count, $target, $source);
    handle_registerauth(true);
    exit(0);
}

// Apply the migration in a single SQL update (no per-user side-effects needed,
// password hashes are already in Moodle's internal format).
$DB->set_field('user', 'auth', $target, ['auth' => $source]);
$after = $DB->count_records('user', ['auth' => $source]);

printf("\nDone. migrated=%d remaining_on_%s=%d new_auth=%s\n", $count, $source, $after, $target);

handle_registerauth($dryrun);

// If $CFG->registerauth was pointing at the dying plugin, the self-registration
// page would break the moment the plugin is removed. Reset it to empty so
// signup falls back to disabled (admin can re-enable via UI if desired).
function handle_registerauth(bool $dryrun): void {
    $current = get_config('core', 'registerauth');
    if ($current === 'externalid') {
        if ($dryrun) {
            echo "Would clear \$CFG->registerauth (currently 'externalid').\n";
        } else {
            set_config('registerauth', '');
            echo "Cleared \$CFG->registerauth (was 'externalid'). Self-registration is now disabled.\n";
        }
    }
}
