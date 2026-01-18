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
if [ ! -f "/var/www/html/moodle_app/.initialized" ]; then
    echo "Initializing moodle_app volume from Docker image..."

    # Check if moodle_app exists in the image but not in the volume
    if [ -d "/opt/moodle_app" ] && [ ! -f "/var/www/html/moodle_app/version.php" ]; then
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

# Create and fix permissions for moodledata directory
echo "Creating moodledata directory if it doesn't exist..."
mkdir -p /var/www/moodledata

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

echo "Starting PHP-FPM..."

# Execute PHP-FPM (it will run worker processes as www-data based on php-fpm.conf)
exec "$@"
