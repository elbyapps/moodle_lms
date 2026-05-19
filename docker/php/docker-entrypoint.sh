#!/bin/bash
set -e

# =============================================================================
# 1. Calculate PHP-FPM resource settings from env vars
# =============================================================================
source /usr/local/bin/calculate-resources.sh
echo "=== PHP-FPM Resource Settings ==="
echo "  Replicas: ${PHP_REPLICAS} | PM mode: ${PHP_PM_MODE}"
echo "  max_children: ${PM_MAX_CHILDREN} | start: ${PM_START_SERVERS} | min_spare: ${PM_MIN_SPARE_SERVERS} | max_spare: ${PM_MAX_SPARE_SERVERS}"
echo "  OPcache: ${OPCACHE_MEMORY_MB}MB | JIT buffer: ${OPCACHE_JIT_BUFFER_MB}MB"

# =============================================================================
# 2. Render PHP-FPM pool config and OPcache config from env vars
# =============================================================================
envsubst < /usr/local/etc/php-fpm.d/www.conf.template > /usr/local/etc/php-fpm.d/www.conf
echo "Rendered PHP-FPM pool config."

cat > /usr/local/etc/php/conf.d/opcache.ini <<OPCACHE
opcache.enable = 1
opcache.memory_consumption = ${OPCACHE_MEMORY_MB}
opcache.max_accelerated_files = 20000
opcache.revalidate_freq = 60
opcache.validate_timestamps = 0
opcache.save_comments = 1
opcache.interned_strings_buffer = ${OPCACHE_INTERNED_STRINGS_MB}
opcache.jit = 1255
opcache.jit_buffer_size = ${OPCACHE_JIT_BUFFER_MB}M
OPCACHE
echo "Rendered OPcache + JIT config."

# =============================================================================
# 3. Construct MOODLE_WWWROOT if not explicitly set
# =============================================================================
if [ -z "$MOODLE_WWWROOT" ]; then
    MOODLE_PROTOCOL=${MOODLE_PROTOCOL:-http}
    MOODLE_HOST=${MOODLE_HOST:-localhost}
    MOODLE_PORT=${MOODLE_PORT:-8080}

    if [[ "$MOODLE_PORT" == "80" && "$MOODLE_PROTOCOL" == "http" ]] || \
       [[ "$MOODLE_PORT" == "443" && "$MOODLE_PROTOCOL" == "https" ]]; then
        export MOODLE_WWWROOT="${MOODLE_PROTOCOL}://${MOODLE_HOST}"
    else
        export MOODLE_WWWROOT="${MOODLE_PROTOCOL}://${MOODLE_HOST}:${MOODLE_PORT}"
    fi

    echo "Constructed MOODLE_WWWROOT: $MOODLE_WWWROOT"
else
    echo "Using explicitly set MOODLE_WWWROOT: $MOODLE_WWWROOT"
fi

# =============================================================================
# 4. Create moodledata directories and set permissions
#    (moodle_app code comes baked into the image, nothing to initialize here.)
# =============================================================================
echo "Creating moodledata directories..."
mkdir -p /var/www/moodledata/sessions
mkdir -p /var/www/moodledata/temp
mkdir -p /var/www/moodledata/cache
mkdir -p /var/www/moodledata/localcache

# Create container-local localcache dir (not shared across replicas)
mkdir -p /tmp/moodle_localcache

echo "Setting permissions for moodledata..."
chown -R www-data:www-data /var/www/moodledata
chmod -R 0777 /var/www/moodledata
chown -R www-data:www-data /tmp/moodle_localcache

# =============================================================================
# 5. Copy Moodle config if needed
# =============================================================================
if [ ! -f "/var/www/html/moodle_app/config.php" ] && [ -f "/var/www/html/config.php.docker" ]; then
    echo "Copying config.php.docker to moodle_app/config.php..."
    cp /var/www/html/config.php.docker /var/www/html/moodle_app/config.php
    chown www-data:www-data /var/www/html/moodle_app/config.php
    echo "Config file created successfully."
fi

# =============================================================================
# 6. Database auto-initialization (flock-guarded for replicas)
# =============================================================================
wait_for_db() {
    echo "Waiting for database to be ready..."
    local db_type="${DB_TYPE:-mariadb}"

    for i in $(seq 1 30); do
        if [ "$db_type" = "pgsql" ]; then
            if php -r "new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432}', '${DB_USER}', '${DB_PASSWORD}');" 2>/dev/null; then
                echo "Database is ready."
                return 0
            fi
        else
            if php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306}', '${DB_USER}', '${DB_PASSWORD}');" 2>/dev/null; then
                echo "Database is ready."
                return 0
            fi
        fi
        echo "Waiting for database... ($i/30)"
        sleep 2
    done
    echo "Database not ready after 60 seconds."
    return 1
}

check_moodle_installed() {
    local db_type="${DB_TYPE:-mariadb}"
    local db_prefix="${DB_PREFIX:-mdl_}"

    if [ "$db_type" = "pgsql" ]; then
        php -r "
            \$pdo = new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASSWORD}');
            \$result = \$pdo->query(\"SELECT tablename FROM pg_tables WHERE tablename = '${db_prefix}config'\");
            exit(\$result->rowCount() > 0 ? 0 : 1);
        " 2>/dev/null
    else
        php -r "
            \$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASSWORD}');
            \$result = \$pdo->query(\"SHOW TABLES LIKE '${db_prefix}config'\");
            exit(\$result->rowCount() > 0 ? 0 : 1);
        " 2>/dev/null
    fi
}

if wait_for_db; then
    (
        flock -x 200
        if ! check_moodle_installed; then
            echo "Moodle database not initialized. Running installer..."
            php /var/www/html/moodle_app/admin/cli/install_database.php \
                --adminuser="${MOODLE_ADMIN_USER:-admin}" \
                --adminpass="${MOODLE_ADMIN_PASS:-Admin123!}" \
                --adminemail="${MOODLE_ADMIN_EMAIL:-admin@example.com}" \
                --fullname="${MOODLE_SITE_FULLNAME:-Moodle Dev}" \
                --shortname="${MOODLE_SITE_SHORTNAME:-moodle}" \
                --agree-license
            echo "Moodle database installation complete."
        else
            echo "Moodle database already initialized."
        fi
    ) 200>/var/www/moodledata/.db_install_lock
fi

# =============================================================================
# 7. Start PHP-FPM or cron
# =============================================================================
if [ "$1" = "cron" ]; then
    echo "Starting Moodle cron service..."
    # `set -e` is on at the top of this script. cron.php legitimately exits 1
    # during maintenance mode or while an upgrade is pending; without `|| true`
    # the entrypoint would die on the first such exit and the container would
    # stop instead of retrying on the next sleep.
    while true; do
        php /var/www/html/moodle_app/admin/cli/cron.php || true
        sleep 60
    done
else
    echo "Starting PHP-FPM..."
    exec "$@"
fi
