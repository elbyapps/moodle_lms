# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Dockerized Moodle 5.0.1 e-learning platform with git-based plugin management.

## Build Commands

```bash
# Build Moodle locally (requires git and jq)
./build.sh

# Build and run (development with local MariaDB)
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up --build

# Build and run (production with external database)
docker compose up --build
```

## Configuration

Copy `.env.example` to `.env`. Key variables:
- `DB_TYPE`: `mariadb` or `pgsql`
- `MOODLE_WWWROOT`: Full URL, or construct from `MOODLE_PROTOCOL`, `MOODLE_HOST`, `MOODLE_PORT`

## Architecture

### Plugin Management
`moodle-config.json` defines Moodle version and plugins. Each plugin has:
- `repository`: Git URL
- `version`: Branch/tag
- `destination`: Path within Moodle (e.g., `auth/oidc`, `mod/hvp`)

`build.sh` reads this config and clones Moodle + all plugins to `moodle_app/`.

### Docker Build Flow
1. `Dockerfile` installs PHP 8.2-FPM with Moodle extensions (mysqli, pdo_pgsql, gd, intl, zip, etc.)
2. Runs `build.sh` during image build to clone Moodle and plugins
3. Stages Moodle to `/opt/moodle_app` for volume initialization

### Container Startup (`docker-entrypoint.sh`)
1. Constructs `MOODLE_WWWROOT` from env vars
2. Copies Moodle from `/opt/moodle_app` to volume on first run (`.initialized` marker)
3. Sets www-data permissions (skipped if `.git` detected for dev mounts)
4. Copies `config.php.docker` to `config.php`

### Container Paths
- Moodle install: `/var/www/html/moodle_app`
- Moodle data: `/var/www/moodledata`

## Docker Services

- **php**: PHP 8.2-FPM with Moodle and plugins
- **nginx**: Web server proxying to PHP-FPM (port 8080)
- **mariadb**: Development only (via `docker-compose.dev.yaml`)
