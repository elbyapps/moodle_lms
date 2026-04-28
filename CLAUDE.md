# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Dockerized Moodle 5.0.1 e-learning platform with git-based plugin management.

## Build Commands

```bash
# Build Moodle locally (requires git and jq)
./build.sh

# Build and run (development with local MariaDB)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build

# Build and run (production: HTTP-only Moodle nginx behind an external SSL-terminating reverse proxy, external DB)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build
```

## Configuration

Copy `.env.example` to `.env`. Key variables:
- `DB_TYPE`: `mariadb` or `pgsql`
- `MOODLE_WWWROOT`: Full URL, or construct from `MOODLE_PROTOCOL`, `MOODLE_HOST`, `MOODLE_PORT`
- `S3_ENDPOINT`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_BUCKET`, `S3_REGION`: S3-compatible storage for reblibrary plugin

## Architecture

### Plugin Management
`moodle-config.json` defines Moodle version and plugins. Each plugin has:
- `repository`: Git URL
- `version`: Branch/tag
- `destination`: Path within Moodle (e.g., `auth/oidc`, `mod/hvp`)

`build.sh` reads this config and clones Moodle core to `moodle_app/` and plugins to `moodle_app/public/<destination>`.

### Docker Build Flow
1. `docker/php/Dockerfile` installs PHP 8.2-FPM with Moodle extensions (mysqli, pdo_pgsql, gd, intl, zip, etc.)
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
- Web root: `/var/www/html/moodle_app/public`

## Docker Services

- **php**: PHP 8.2-FPM with Moodle and plugins
- **nginx**: Web server proxying to PHP-FPM. Speaks HTTP only (port 8080); SSL is terminated by an external reverse proxy in prod.
- **redis**: Session + MUC (cache) backend for scaling across PHP replicas (prod only, via `docker-compose.prod.yml`)
- **cron**: Runs `admin/cli/cron.php` on a 60s loop (prod only, via `docker-compose.prod.yml`)
- **mariadb**: Development only (via `docker-compose.dev.yml`) or standalone (via `docker-compose.db.yml`). In prod the DB is external.
- **garage**: S3-compatible object storage, development only (via `docker-compose.dev.yml`, port 3900). In prod, point the `S3_*` env vars at a dedicated S3 server.

## Prod deployment notes

- Prod assumes an external nginx reverse proxy handles TLS termination and forwards HTTP to this stack on port 8080.
- Set `MOODLE_SSLPROXY=true` and `MOODLE_REVERSEPROXY=false` in prod `.env`. `sslproxy=true` makes Moodle emit `https://` URLs while only seeing HTTP traffic; `reverseproxy=false` avoids `reverseproxyabused` when the upstream proxy forwards the same Host header as `$CFG->wwwroot`.
- External DB is reached via hostname/IP from `.env` (e.g., `DB_HOST=host.docker.internal` in local tests; a real DNS name in prod).
- `scripts/deploy.sh code` does a rolling replacement of PHP-FPM replicas without downtime (nginx is a single instance — expect a ~5s blip when it's recreated at the end).
