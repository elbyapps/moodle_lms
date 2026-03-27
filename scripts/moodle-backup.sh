#!/bin/bash
# Moodle Backup Script
#
# Backs up the database and moodledata to an S3-compatible bucket (Garage).
# Designed to run as a nightly cron job on the production VPS.
#
# Prerequisites:
#   - aws CLI installed and configured (or use environment variables)
#   - Docker running with the Moodle containers
#
# Cron example (run nightly at 2 AM):
#   0 2 * * * root /opt/scripts/moodle-backup.sh >> /var/log/moodle-backup.log 2>&1
#
# 3-2-1 backup coverage:
#   Copy 1: Live named volume on VPS
#   Copy 2: This script syncs to S3/Garage backup bucket
#   Copy 3: Periodically run 'rclone sync' from the backup bucket to a second site

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration (override via environment or .env file)
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Source .env if available
if [ -f "$PROJECT_DIR/.env" ]; then
    set -a
    # shellcheck source=/dev/null
    source "$PROJECT_DIR/.env"
    set +a
fi

# S3 settings (reuse existing S3 endpoint credentials)
S3_ENDPOINT="${S3_ENDPOINT:?S3_ENDPOINT must be set}"
S3_ACCESS_KEY="${S3_ACCESS_KEY:?S3_ACCESS_KEY must be set}"
S3_SECRET_KEY="${S3_SECRET_KEY:?S3_SECRET_KEY must be set}"
S3_REGION="${S3_REGION:-rw-central-1}"
BACKUP_BUCKET="${BACKUP_S3_BUCKET:-moodle-backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

# Database settings
DB_TYPE="${DB_TYPE:-mariadb}"
DB_NAME="${DB_NAME:-moodle}"
DB_USER="${DB_USER:-moodleuser}"
DB_PASSWORD="${DB_PASSWORD:-moodlepass}"

# Docker settings — adjust container names if your project prefix differs
COMPOSE_PROJECT="${COMPOSE_PROJECT_NAME:-moodle_lms}"
DB_CONTAINER="${COMPOSE_PROJECT}-${DB_TYPE}-1"
PHP_CONTAINER="${COMPOSE_PROJECT}-php-1"

# Local backup directory for DB dumps
DB_BACKUP_DIR="${DB_BACKUP_DIR:-/opt/backups/moodle/db}"

# Moodledata path inside the php container
MOODLEDATA_PATH="/var/www/moodledata"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

# ---------------------------------------------------------------------------
# AWS CLI wrapper (uses project S3 credentials)
# ---------------------------------------------------------------------------
aws_s3() {
    AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY" \
    AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY" \
    AWS_DEFAULT_REGION="$S3_REGION" \
    aws s3 "$@" --endpoint-url "$S3_ENDPOINT"
}

# ---------------------------------------------------------------------------
# 1. Database backup
# ---------------------------------------------------------------------------
echo "[$(date)] Starting database backup..."
mkdir -p "$DB_BACKUP_DIR"

DB_DUMP_FILE="$DB_BACKUP_DIR/moodle-db-${TIMESTAMP}.sql.gz"

if [ "$DB_TYPE" = "mariadb" ] || [ "$DB_TYPE" = "mysqli" ]; then
    docker exec "$DB_CONTAINER" \
        mysqldump -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
        | gzip > "$DB_DUMP_FILE"
elif [ "$DB_TYPE" = "pgsql" ]; then
    docker exec -e PGPASSWORD="$DB_PASSWORD" "$DB_CONTAINER" \
        pg_dump -U "$DB_USER" "$DB_NAME" \
        | gzip > "$DB_DUMP_FILE"
else
    echo "ERROR: Unsupported DB_TYPE=$DB_TYPE" >&2
    exit 1
fi

echo "[$(date)] Uploading database dump to S3..."
aws_s3 cp "$DB_DUMP_FILE" "s3://${BACKUP_BUCKET}/db/moodle-db-${TIMESTAMP}.sql.gz"

# ---------------------------------------------------------------------------
# 2. Sync moodledata to S3 (incremental)
# ---------------------------------------------------------------------------
# We copy moodledata out of the container to a temp dir, then sync to S3.
# This avoids needing to know the host volume path (works with named volumes).
echo "[$(date)] Syncing moodledata to S3..."

MOODLEDATA_TEMP="$(mktemp -d)"
trap 'rm -rf "$MOODLEDATA_TEMP"' EXIT

# Copy from container, excluding ephemeral directories
docker cp "$PHP_CONTAINER:$MOODLEDATA_PATH/filedir" "$MOODLEDATA_TEMP/filedir" 2>/dev/null || true
docker cp "$PHP_CONTAINER:$MOODLEDATA_PATH/secret" "$MOODLEDATA_TEMP/secret" 2>/dev/null || true
docker cp "$PHP_CONTAINER:$MOODLEDATA_PATH/trashdir" "$MOODLEDATA_TEMP/trashdir" 2>/dev/null || true

aws_s3 sync "$MOODLEDATA_TEMP/" "s3://${BACKUP_BUCKET}/moodledata/"

# ---------------------------------------------------------------------------
# 3. Clean up old local DB backups
# ---------------------------------------------------------------------------
echo "[$(date)] Cleaning up local backups older than ${RETENTION_DAYS} days..."
find "$DB_BACKUP_DIR" -name "moodle-db-*.sql.gz" -mtime +"$RETENTION_DAYS" -delete

echo "[$(date)] Backup complete."
