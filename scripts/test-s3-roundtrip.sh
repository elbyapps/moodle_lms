#!/usr/bin/env bash
# scripts/test-s3-roundtrip.sh
#
# End-to-end smoke test for the tool_objectfs integration on the dev stack.
# Pass criterion is exactly the one in the design doc: write a file via
# Moodle's file API, force migration to S3, delete the local copy, and
# read the file back — the SHA1 must match. If that passes, the integration
# works; everything else is defense in depth.
#
# Prereqs:
#   - `make build` has been run with the updated moodle-config.json
#   - `make dev` is up and the stack is healthy
#   - FILESTORAGE_BACKEND=s3 in .env
#   - tool_objectfs has been installed via the admin upgrade flow once
#
# Usage:
#   ./scripts/test-s3-roundtrip.sh

set -euo pipefail
cd "$(dirname "$0")/.."

# ---------- knobs ----------------------------------------------------------
PHP_CTR="${PHP_CTR:-moodle_lms-php-1}"
CRON_CTR="${CRON_CTR:-moodle_lms-cron-1}"
GARAGE_CTR="${GARAGE_CTR:-moodle_garage_dev}"
S3_BUCKET_NAME="${S3_BUCKET_NAME:-moodle}"
PAYLOAD_KB="${PAYLOAD_KB:-1500}"   # 1.5 MB by default

# ---------- output helpers -------------------------------------------------
if [ -t 1 ]; then
  R=$'\033[31m'; G=$'\033[32m'; Y=$'\033[33m'; B=$'\033[1;34m'; N=$'\033[0m'
else
  R=''; G=''; Y=''; B=''; N=''
fi
step() { printf "\n%s\u25b8 %s%s\n" "$B" "$*" "$N"; }
ok()   { printf "  %s\u2713%s %s\n" "$G" "$N" "$*"; }
warn() { printf "  %s!%s %s\n"     "$Y" "$N" "$*"; }
die()  { printf "  %s\u2717 FAIL: %s%s\n" "$R" "$*" "$N" >&2; exit 1; }

# ---------- cleanup hook ---------------------------------------------------
saved_settings_file=""
pnhash=""
cleanup() {
  local rc=$?
  step "Cleanup"
  if [ -n "$pnhash" ]; then
    docker exec "$PHP_CTR" php /tmp/objectfs_test.php cleanup "$pnhash" \
      2>/dev/null >/dev/null && ok "Moodle file deleted" \
      || warn "could not delete Moodle file ($pnhash); manual cleanup may be needed"
  fi
  if [ -n "$saved_settings_file" ] && [ -f "$saved_settings_file" ]; then
    docker cp "$saved_settings_file" "$PHP_CTR:/tmp/objectfs_test_restore.php" 2>/dev/null || true
    docker exec "$PHP_CTR" php /tmp/objectfs_test_restore.php 2>/dev/null \
      && ok "tool_objectfs settings restored" \
      || warn "could not restore tool_objectfs settings; rerun scripts/setup_objectfs.php --force"
    rm -f "$saved_settings_file"
  fi
  docker exec "$PHP_CTR" rm -f /tmp/objectfs_test.php /tmp/objectfs_test_payload.bin \
    /tmp/objectfs_test_restore.php 2>/dev/null || true
  if [ "$rc" -eq 0 ]; then
    printf "\n%s================================================================%s\n" "$G" "$N"
    printf   "%s  PASS \u2014 tool_objectfs round-trip works against bucket '%s'%s\n" "$G" "$S3_BUCKET_NAME" "$N"
    printf   "%s================================================================%s\n" "$G" "$N"
  else
    printf "\n%s================================================================%s\n" "$R" "$N"
    printf   "%s  FAIL \u2014 see error above%s\n" "$R" "$N"
    printf   "%s================================================================%s\n" "$R" "$N"
  fi
}
trap cleanup EXIT

# ===========================================================================
# 1. Pre-flight
# ===========================================================================
step "Pre-flight"

command -v jq >/dev/null 2>&1 || die "jq not installed on host"
command -v shasum >/dev/null 2>&1 || command -v sha1sum >/dev/null 2>&1 \
  || die "neither shasum nor sha1sum available on host"

for ctr in "$PHP_CTR" "$CRON_CTR" "$GARAGE_CTR"; do
  running=$(docker inspect -f '{{.State.Running}}' "$ctr" 2>/dev/null || echo missing)
  [ "$running" = "true" ] || die "container '$ctr' is not running (make dev?)"
done
ok "containers up: $PHP_CTR, $CRON_CTR, $GARAGE_CTR"

backend=$(docker exec "$PHP_CTR" printenv FILESTORAGE_BACKEND 2>/dev/null || true)
[ "$backend" = "s3" ] || die "FILESTORAGE_BACKEND in container is '$backend' (need 's3'). Edit .env, then 'make dev' to restart."
ok "FILESTORAGE_BACKEND=s3"

docker exec "$PHP_CTR" test -d /var/www/html/moodle_app/public/admin/tool/objectfs \
  || die "tool_objectfs plugin missing inside container. Run 'make build' to clone it."
docker exec "$PHP_CTR" test -d /var/www/html/moodle_app/public/local/aws \
  || die "local_aws plugin missing inside container. Run 'make build' to clone it."
ok "plugins present on disk"

# Confirm Moodle has actually completed the install of the plugin.
installed_version=$(docker exec "$PHP_CTR" php -r '
  define("CLI_SCRIPT", true);
  require_once("/var/www/html/moodle_app/public/config.php");
  echo get_config("tool_objectfs", "version") ?: "";
' 2>/dev/null)
[ -n "$installed_version" ] \
  || die "tool_objectfs is on disk but not installed in DB. Visit /admin/index.php once to complete the plugin install."
ok "tool_objectfs installed in DB (version=$installed_version)"

# ===========================================================================
# 2. Snapshot current tool_objectfs settings, then force test-friendly values
# ===========================================================================
step "Snapshot current tool_objectfs settings + apply test overrides"

saved_settings_file=$(mktemp -t objectfs_restore_XXXXXX.php)
docker exec "$PHP_CTR" php -r '
  define("CLI_SCRIPT", true);
  require_once("/var/www/html/moodle_app/public/config.php");
  $keys = ["sizethreshold","minimumage","enabletasks"];
  $out = [];
  foreach ($keys as $k) { $out[$k] = get_config("tool_objectfs", $k); }
  echo "<?php\n";
  echo "define(\"CLI_SCRIPT\", true);\n";
  echo "require_once(\"/var/www/html/moodle_app/public/config.php\");\n";
  foreach ($out as $k => $v) {
      if ($v === false) {
          echo "unset_config(\"$k\", \"tool_objectfs\");\n";
      } else {
          $esc = addslashes((string)$v);
          echo "set_config(\"$k\", \"$esc\", \"tool_objectfs\");\n";
      }
  }
' > "$saved_settings_file"
ok "settings snapshot saved (auto-restored on exit)"

docker exec "$PHP_CTR" php -r '
  define("CLI_SCRIPT", true);
  require_once("/var/www/html/moodle_app/public/config.php");
  set_config("sizethreshold", 0, "tool_objectfs");
  set_config("minimumage",    0, "tool_objectfs");
  set_config("enabletasks",   1, "tool_objectfs");
'
ok "test overrides applied (sizethreshold=0, minimumage=0, enabletasks=1)"

# ===========================================================================
# 3. Seed a known file into Moodle
# ===========================================================================
step "Seed a $PAYLOAD_KB KB random file into Moodle's file storage"

tmpfile=$(mktemp -t objectfs_payload_XXXXXX.bin)
trap 'rm -f "$tmpfile"' RETURN 2>/dev/null || true
dd if=/dev/urandom of="$tmpfile" bs=1024 count="$PAYLOAD_KB" status=none

if command -v shasum >/dev/null 2>&1; then
  expected_sha1=$(shasum -a 1 "$tmpfile" | awk '{print $1}')
else
  expected_sha1=$(sha1sum "$tmpfile" | awk '{print $1}')
fi
ok "generated payload, sha1=$expected_sha1"

docker cp scripts/objectfs_test.php "$PHP_CTR:/tmp/objectfs_test.php" >/dev/null
docker cp "$tmpfile" "$PHP_CTR:/tmp/objectfs_test_payload.bin"   >/dev/null
rm -f "$tmpfile"

seed_json=$(docker exec "$PHP_CTR" php /tmp/objectfs_test.php seed /tmp/objectfs_test_payload.bin)
contenthash=$(echo "$seed_json" | jq -r .contenthash)
pnhash=$(echo "$seed_json" | jq -r .pathnamehash)
localpath=$(echo "$seed_json" | jq -r .localpath)
filesize=$(echo "$seed_json" | jq -r .filesize)

[ "$contenthash" = "$expected_sha1" ] \
  || die "contenthash mismatch (expected $expected_sha1, got $contenthash). Are you using SHA1-keyed storage?"
ok "Moodle accepted file (contenthash=$contenthash, $filesize bytes)"

docker exec "$PHP_CTR" test -f "$localpath" \
  || die "expected local copy at $localpath does not exist"
ok "local copy at $localpath"

# ===========================================================================
# 4. Force migration to S3
# ===========================================================================
step "Run tool_objectfs push task in cron container"

# Capture bucket size before for a sanity-check delta.
bucket_objects_before=$(docker exec "$GARAGE_CTR" /garage bucket info "$S3_BUCKET_NAME" 2>/dev/null \
  | awk -F'[ \t]+' '/^Objects:/ {print $2; exit}')
bucket_objects_before=${bucket_objects_before:-0}

if ! docker exec "$CRON_CTR" php /var/www/html/moodle_app/admin/cli/scheduled_task.php \
  --execute='\tool_objectfs\task\push_objects_to_storage' >/tmp/objectfs_push.log 2>&1; then
  cat /tmp/objectfs_push.log >&2
  die "push_objects_to_storage failed (log above)"
fi
ok "push task ran"

bucket_objects_after=$(docker exec "$GARAGE_CTR" /garage bucket info "$S3_BUCKET_NAME" 2>/dev/null \
  | awk -F'[ \t]+' '/^Objects:/ {print $2; exit}')
bucket_objects_after=${bucket_objects_after:-0}
ok "Garage bucket '$S3_BUCKET_NAME': objects before=$bucket_objects_before, after=$bucket_objects_after"

if [ "$bucket_objects_after" -le "$bucket_objects_before" ]; then
  warn "bucket object count did not increase \u2014 the push task may have classified the file as ineligible. Continuing to readback test, which is the real proof."
fi

# ===========================================================================
# 5. Delete local copy and round-trip via Moodle file API
# ===========================================================================
step "Delete local copy and read file back through Moodle's file API"

docker exec "$PHP_CTR" rm -f "$localpath"
docker exec "$PHP_CTR" test ! -f "$localpath" \
  || die "could not delete local copy at $localpath"
ok "local copy removed"

# Disable -e for the capture so a non-zero exit from docker exec doesn't kill
# the script before we can print the captured error.
set +e
readback_out=$(docker exec "$PHP_CTR" php /tmp/objectfs_test.php readback "$pnhash" 2>&1)
readback_rc=$?
set -e
if [ "$readback_rc" -ne 0 ]; then
  echo "  readback exited $readback_rc with output:" >&2
  printf '    %s\n' "$readback_out" >&2
  die "readback failed: file was deleted locally and objectfs could not serve it from S3"
fi
readback_sha1=$(printf '%s' "$readback_out" | tr -d '[:space:]')
if [ "$readback_sha1" != "$expected_sha1" ]; then
  echo "  readback output was: $readback_out" >&2
  die "round-trip SHA1 mismatch \u2014 objectfs did not serve the file back from S3"
fi
ok "round-trip SHA1 matches: $readback_sha1"

# All assertions passed. cleanup() runs via trap.
