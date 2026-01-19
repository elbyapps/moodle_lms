#!/bin/bash
set -e

# Construct MOODLE_WWWROOT if not explicitly set
if [ -z "$MOODLE_WWWROOT" ]; then
    MOODLE_PROTOCOL=${MOODLE_PROTOCOL:-http}
    MOODLE_HOST=${MOODLE_HOST:-localhost}
    MOODLE_PORT=${MOODLE_PORT:-8080}

    # Construct the URL
    # For standard ports (80, 443), we can omit the port
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

# Initialize moodle_app volume with files from image on first run
# This allows the Docker image to contain Moodle, but share it via named volume
# Skip entirely for bind mounts (detected by .git directory)
if [ -d "/var/www/html/moodle_app/.git" ]; then
    echo "Host bind mount detected, skipping volume initialization..."
elif [ ! -f "/var/www/html/moodle_app/.initialized" ]; then
    echo "Initializing moodle_app volume from Docker image..."

    # Check if moodle_app exists in the image but not in the volume
    # Note: version.php is at public/version.php in this project structure
    if [ -d "/opt/moodle_app" ] && [ ! -f "/var/www/html/moodle_app/public/version.php" ]; then
        echo "Copying Moodle files from image to volume..."
        cp -a /opt/moodle_app/. /var/www/html/moodle_app/

        # Mark as initialized
        touch /var/www/html/moodle_app/.initialized
        echo "Moodle files copied successfully."
    else
        echo "Moodle files already exist in volume."
        touch /var/www/html/moodle_app/.initialized
    fi
else
    echo "moodle_app volume already initialized."
fi

# Create and fix permissions for moodledata directory and subdirectories
echo "Creating moodledata directories..."
mkdir -p /var/www/moodledata/sessions
mkdir -p /var/www/moodledata/temp
mkdir -p /var/www/moodledata/cache
mkdir -p /var/www/moodledata/localcache

echo "Setting permissions for moodledata..."
chown -R www-data:www-data /var/www/moodledata
chmod -R 0777 /var/www/moodledata

# Set proper permissions for moodle_app
# Only change ownership if moodle_app is from a volume (not a host mount)
# Host mounts will maintain host permissions
if [ -d "/var/www/html/moodle_app" ] && [ ! -d "/var/www/html/moodle_app/.git" ]; then
    echo "Setting permissions for moodle_app (volume-based)..."
    chown -R www-data:www-data /var/www/html/moodle_app
else
    echo "Skipping permission changes for moodle_app (host mount detected)..."
fi

# Copy Moodle config if it doesn't exist
if [ ! -f "/var/www/html/moodle_app/config.php" ] && [ -f "/var/www/html/config.php.docker" ]; then
    echo "Copying config.php.docker to moodle_app/config.php..."
    cp /var/www/html/config.php.docker /var/www/html/moodle_app/config.php
    chown www-data:www-data /var/www/html/moodle_app/config.php
    echo "Config file created successfully."
fi

# Database auto-initialization functions
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

# Auto-install Moodle database if needed
if wait_for_db; then
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
fi

# Check if we're running cron or PHP-FPM
if [ "$1" = "cron" ]; then
    echo "Starting Moodle cron service..."
    while true; do
        php /var/www/html/moodle_app/public/admin/cli/cron.php
        sleep 60
    done
else
    echo "Starting PHP-FPM..."
    # Execute PHP-FPM (it will run worker processes as www-data based on php-fpm.conf)
    exec "$@"
fi
