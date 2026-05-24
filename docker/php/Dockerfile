# syntax=docker/dockerfile:1
FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    jq \
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
    ghostscript \
    poppler-utils \
    aspell \
    aspell-en \
    python3 \
    libfcgi-bin \
    gettext-base \
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
    opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure PHP for Moodle
RUN echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/moodle.ini \
    && echo "upload_max_filesize = 512M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "post_max_size = 600M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "max_input_vars = 5000" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "zend.exception_ignore_args = On" >> /usr/local/etc/php/conf.d/moodle.ini

# OPcache and PHP-FPM pool are configured at runtime by docker-entrypoint.sh
# via calculate-resources.sh and www.conf.template (env-var-driven)

# Set working directory
WORKDIR /var/www/html

# Copy build scripts and vendored plugin trees from project root.
# build.sh reads moodle-config.json and either clones (source=git) or copies
# (source=vendor, reading from ./vendor/) each plugin into moodle_app/public/.
COPY build.sh moodle-config.json ./
COPY vendor ./vendor
COPY scripts ./scripts
RUN chmod +x build.sh

# Build arg to skip cloning (for development with bind mounts)
ARG SKIP_CLONE=false

# Configure Git for non-interactive cloning. All plugin repos in
# moodle-config.json are public HTTPS URLs (see build.sh) — no SSH agent,
# known_hosts seeding, or BuildKit ssh mount needed. GIT_TERMINAL_PROMPT=0
# turns any unexpected auth prompt (e.g. a repo that went private) into an
# immediate hard failure instead of a hung build.
ENV GIT_TERMINAL_PROMPT=0
RUN git config --global credential.helper '' && \
    git config --global init.defaultBranch main

# Run build script to clone Moodle and plugins (skip in dev mode).
RUN if [ "$SKIP_CLONE" = "false" ]; then ./build.sh; else echo "SKIP_CLONE=true, skipping build.sh"; fi

# Install Composer dependencies for Moodle (skip in dev mode)
RUN if [ "$SKIP_CLONE" = "false" ]; then cd moodle_app && composer install --no-dev --classmap-authoritative; fi

# Code lives at /var/www/html/moodle_app directly — no named volume, no copy-on-boot.
# Vendored plugins are installed by build.sh from ./vendor/ — see vendor/README.md.
RUN chown -R www-data:www-data /var/www/html

# Copy entrypoint script and resource tuning files
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY docker/php/calculate-resources.sh /usr/local/bin/calculate-resources.sh
COPY docker/php/www.conf.template /usr/local/etc/php-fpm.d/www.conf.template
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/calculate-resources.sh

# Set entrypoint
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

# Default command
CMD ["php-fpm"]
