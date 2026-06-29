#!/bin/bash
# Moodle deployment helper for the Dockerized prod stack.
#
# Two flows:
#   code      Rolling replacement of php replicas (no DB migration). One new
#             replica is brought up and proven healthy before each old one is
#             retired, so PHP traffic never drops. cron + nginx are recreated
#             at the end; nginx is a single instance so expect a brief (~5s)
#             window where users might see a 502.
#   upgrade   Maintenance-mode upgrade for Moodle core / plugin DB migrations.
#             Enables Moodle maintenance mode, refreshes images, recreates all
#             containers, runs admin/cli/upgrade.php, then drops maintenance.
#
# Image refresh mode is profile-specific:
#   prod      builds local images from Dockerfiles (existing behavior)
#   school    pulls pre-baked GHCR images; school boxes must not build locally
#
# Usage:
#   scripts/deploy.sh code [--no-cache] [--profile prod|school]
#   scripts/deploy.sh upgrade [--no-cache] [--profile prod|school]
#
# Flags:
#   --no-cache   Prod profile only: pass --no-cache to `docker compose build`.
#                Useful when you want to force fresh `git clone`s of plugins
#                whose ref in moodle-config.json hasn't changed (so the build.sh
#                layer would otherwise be served from the Docker build cache).
#                Ignored for the school profile, which pulls GHCR images.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_DIR"

# Deploy target profile (default prod). Selected with --profile {prod|school}:
#   prod   — external-DB overlay (docker-compose.prod.yml), default 3 replicas.
#   school — self-contained overlay (docker-compose.school.yml): bundled mariadb,
#            local-filesystem moodledata, no S3; default 1 replica.
# COMPOSE and REPLICAS are assembled after flag parsing, once PROFILE is known.
PROFILE="prod"
COMPOSE=()

# We only need two knobs from .env (PHP_REPLICAS, HEALTH_TIMEOUT). Sourcing
# the whole file with `set -a; source .env` is brittle — any unquoted value
# containing shell metacharacters (e.g. `MOODLE_SITENAME=My Site [eLearning]`)
# triggers globbing or word-splitting and the script aborts before it ever
# reaches docker compose. Docker Compose itself reads .env directly via
# --project-directory, so the variables every service needs still arrive.
# Here we just pluck the two values this script reads, with no shell eval.
read_env_var() {
    # Strip optional surrounding quotes from the value.
    local key="$1" line value
    [ -f .env ] || return 0
    line=$(grep -E "^${key}=" .env | tail -n1 || true)
    [ -z "$line" ] && return 0
    value="${line#*=}"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}

HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-$(read_env_var HEALTH_TIMEOUT)}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"

# Extra args appended to `docker compose build` (e.g. --no-cache) for build-based
# profiles. School is pull-based and ignores these.
BUILD_ARGS=()

php_exec() {
    "${COMPOSE[@]}" exec -T php "$@"
}

wait_healthy() {
    local service="$1"
    local elapsed=0
    echo "Waiting for '$service' replicas to report healthy (timeout ${HEALTH_TIMEOUT}s)..."
    while [ "$elapsed" -lt "$HEALTH_TIMEOUT" ]; do
        local ids all_healthy=true status
        ids=$("${COMPOSE[@]}" ps -q "$service" || true)
        if [ -z "$ids" ]; then
            sleep 3
            elapsed=$((elapsed + 3))
            continue
        fi
        for id in $ids; do
            status=$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id" 2>/dev/null || echo none)
            if [ "$status" != "healthy" ]; then
                all_healthy=false
                break
            fi
        done
        if $all_healthy; then
            echo "  all '$service' replicas healthy."
            return 0
        fi
        sleep 3
        elapsed=$((elapsed + 3))
    done
    echo "ERROR: timeout waiting for '$service' to become healthy." >&2
    return 1
}

refresh_images() {
    if [ "$PROFILE" = "school" ]; then
        if [ ${#BUILD_ARGS[@]} -gt 0 ]; then
            echo "NOTE: --no-cache is ignored for --profile school (GHCR pull-based deploy)."
        fi
        echo "Pulling school GHCR images..."
        "${COMPOSE[@]}" pull php cron nginx
    else
        if [ ${#BUILD_ARGS[@]} -gt 0 ]; then
            echo "Building images (${BUILD_ARGS[*]})..."
        else
            echo "Building images..."
        fi
        "${COMPOSE[@]}" build "${BUILD_ARGS[@]}"
    fi
}

cmd_code() {
    echo "== Rolling code deploy (no DB migration) =="
    refresh_images

    local old_ids
    old_ids=$("${COMPOSE[@]}" ps -q php || true)
    if [ -z "$old_ids" ]; then
        echo "No existing php replicas — first-time bring-up."
        "${COMPOSE[@]}" up -d --scale "php=$REPLICAS"
        wait_healthy php
        echo "Code deploy complete."
        return 0
    fi

    for old_id in $old_ids; do
        echo "---"
        echo "Adding one new php replica with the new image..."
        "${COMPOSE[@]}" up -d --no-recreate --scale "php=$((REPLICAS + 1))"
        wait_healthy php

        echo "Retiring old container $old_id ..."
        docker stop "$old_id" >/dev/null
        docker rm "$old_id" >/dev/null || true

        # Re-level the scale so Compose's accounting matches reality.
        "${COMPOSE[@]}" up -d --no-recreate --scale "php=$REPLICAS"
    done

    echo "---"
    echo "Recreating cron (singleton)..."
    "${COMPOSE[@]}" up -d --no-recreate --scale "php=$REPLICAS"
    "${COMPOSE[@]}" up -d --force-recreate --scale "php=$REPLICAS" cron

    echo "Recreating nginx (singleton; brief blip)..."
    "${COMPOSE[@]}" up -d --force-recreate --scale "php=$REPLICAS" nginx

    echo "Code deploy complete."
}

cmd_upgrade() {
    echo "== Full upgrade (DB migration) =="
    echo "This will enable Moodle maintenance mode, rebuild, and run upgrade.php."
    read -r -p "Proceed? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }

    echo "Enabling Moodle maintenance mode..."
    php_exec php /var/www/html/moodle_app/admin/cli/maintenance.php --enable

    refresh_images

    echo "Recreating all containers..."
    "${COMPOSE[@]}" up -d --force-recreate --scale "php=$REPLICAS"
    wait_healthy php

    echo "Running Moodle upgrade..."
    php_exec php /var/www/html/moodle_app/admin/cli/upgrade.php --non-interactive --allow-unstable

    echo "Disabling maintenance mode..."
    php_exec php /var/www/html/moodle_app/admin/cli/maintenance.php --disable

    echo "Upgrade complete."
}

SUBCMD="${1:-}"
shift || true

# Parse trailing flags.
while [ $# -gt 0 ]; do
    case "$1" in
        --no-cache)  BUILD_ARGS+=(--no-cache) ;;
        --profile)   shift; PROFILE="${1:?ERROR: --profile needs a value (prod|school)}" ;;
        --profile=*) PROFILE="${1#*=}" ;;
        -h|--help)   SUBCMD="" ;;
        *)
            echo "ERROR: unknown flag '$1'" >&2
            exit 1
            ;;
    esac
    shift
done

# Assemble the compose invocation + default replica count from the profile.
case "$PROFILE" in
    prod)   OVERLAY="compose/docker-compose.prod.yml";   DEFAULT_REPLICAS=3 ;;
    school) OVERLAY="compose/docker-compose.school.yml"; DEFAULT_REPLICAS=1 ;;
    *)      echo "ERROR: unknown --profile '$PROFILE' (expected prod|school)" >&2; exit 1 ;;
esac
# --project-directory . keeps the project name as the repo dir (volume prefix
# stability) and lets .env load from root.
COMPOSE=(docker compose --project-directory . -f compose/docker-compose.yml -f "$OVERLAY")
# Honour a host-specific override (e.g. an external moodledata volume) when
# compose/docker-compose.local.yml is committed/present on this host.
if [ -f compose/docker-compose.local.yml ]; then
    COMPOSE+=(-f compose/docker-compose.local.yml)
fi
REPLICAS="${PHP_REPLICAS:-$(read_env_var PHP_REPLICAS)}"
REPLICAS="${REPLICAS:-$DEFAULT_REPLICAS}"

case "$SUBCMD" in
    code)    cmd_code ;;
    upgrade) cmd_upgrade ;;
    *)
        cat >&2 <<EOF
Usage: $0 {code|upgrade} [--no-cache] [--profile prod|school]

  code         Rolling deploy for code-only changes (no DB migration).
  upgrade      Maintenance-mode upgrade for DB schema changes.

  --no-cache   Prod only: force a no-cache image rebuild (re-clones git
               plugins even when moodle-config.json hasn't changed).
               Ignored for school, which pulls GHCR images.
  --profile P  Target stack: prod (default, external DB, build-based) or school
               (self-contained: bundled mariadb, local-filesystem storage,
               GHCR pull-based).
EOF
        exit 1
        ;;
esac
