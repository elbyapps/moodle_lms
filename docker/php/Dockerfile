# syntax=docker/dockerfile:1
FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    jq \
    openssh-client \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Moodle
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    mysqli \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    soap \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure PHP for Moodle
RUN echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/moodle.ini \
    && echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "max_input_vars = 5000" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "zend.exception_ignore_args = On" >> /usr/local/etc/php/conf.d/moodle.ini

# Configure OPcache for better performance
RUN echo "opcache.enable = 1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption = 128" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files = 10000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.revalidate_freq = 60" >> /usr/local/etc/php/conf.d/opcache.ini

# Configure PHP-FPM to pass environment variables and set port
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/listen = 127.0.0.1:9000/listen = 9000/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/listen = 9000/listen = 5174/' /usr/local/etc/php-fpm.d/www.conf

# Set working directory
WORKDIR /var/www/html

# Copy build scripts from project root
COPY build.sh moodle-config.json ./
RUN chmod +x build.sh

# Build arg to skip cloning (for development with bind mounts)
ARG SKIP_CLONE=false

# Configure Git for non-interactive cloning (public repos)
# Disable credential prompt to avoid "No such device or address" errors
ENV GIT_TERMINAL_PROMPT=0
RUN git config --global credential.helper '' && \
    git config --global init.defaultBranch main

# Configure SSH for GitHub (for private repositories)
RUN mkdir -p -m 0700 ~/.ssh && \
    ssh-keyscan github.com >> ~/.ssh/known_hosts

# Run build script to clone Moodle and plugins (skip in dev mode)
# Mount SSH socket to enable cloning private repositories
RUN --mount=type=ssh if [ "$SKIP_CLONE" = "false" ]; then ./build.sh; else echo "SKIP_CLONE=true, skipping build.sh"; fi

# Install Composer dependencies for Moodle (skip in dev mode)
RUN if [ "$SKIP_CLONE" = "false" ]; then cd moodle_app && composer install --no-dev --classmap-authoritative; fi

# Move moodle_app to staging location for volume initialization (skip in dev mode)
# This allows the entrypoint to copy files to a named volume on first run
RUN if [ "$SKIP_CLONE" = "false" ] && [ -d "/var/www/html/moodle_app" ]; then \
        mv /var/www/html/moodle_app /opt/moodle_app && \
        mkdir -p /var/www/html/moodle_app; \
    fi

# Set proper permissions for staging location (skip in dev mode)
RUN if [ "$SKIP_CLONE" = "false" ] && [ -d "/opt/moodle_app" ]; then \
        chown -R www-data:www-data /opt/moodle_app; \
    fi
RUN chown -R www-data:www-data /var/www/html

# Copy entrypoint script
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

# Default command
CMD ["php-fpm"]
