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
#             Enables Moodle maintenance mode, rebuilds, recreates all
#             containers, runs admin/cli/upgrade.php, then drops maintenance.
#
# Usage:
#   scripts/deploy.sh code [--no-cache]
#   scripts/deploy.sh upgrade [--no-cache]
#
# Flags:
#   --no-cache   Pass --no-cache to `docker compose build`. Useful when you
#                want to force fresh `git clone`s of plugins whose ref in
#                moodle-config.json hasn't changed (so the build.sh layer
#                would otherwise be served from the Docker build cache).
#                Unlike `make build-fresh`, this does NOT stop containers
#                or delete the moodle_app volume — the rolling/maintenance
#                deploy flow is preserved.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_DIR"

# Compose files live under compose/. --project-directory . keeps the project
# name as the repo dir (volume prefix stability) and lets .env load from root.
COMPOSE=(docker compose --project-directory . -f compose/docker-compose.yml -f compose/docker-compose.prod.yml)
# Honour host-specific overrides (e.g. an external moodledata volume) when
# compose/docker-compose.local.yml is committed/present on this host. This keeps
# scripts/deploy.sh aligned with `make prod` on machines that need the override.
if [ -f compose/docker-compose.local.yml ]; then
    COMPOSE+=(-f compose/docker-compose.local.yml)
fi

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

REPLICAS="${PHP_REPLICAS:-$(read_env_var PHP_REPLICAS)}"
REPLICAS="${REPLICAS:-3}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-$(read_env_var HEALTH_TIMEOUT)}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"

# Extra args appended to `docker compose build` (e.g. --no-cache). Populated
# from the CLI flag parser below.
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

cmd_code() {
    echo "== Rolling code deploy (no DB migration) =="
    if [ ${#BUILD_ARGS[@]} -gt 0 ]; then
        echo "Building images (${BUILD_ARGS[*]})..."
    else
        echo "Building images..."
    fi
    "${COMPOSE[@]}" build "${BUILD_ARGS[@]}"

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

    # First-time bring-up: with no php replicas running there is no live site to put
    # into maintenance mode, and exec-ing into a container would fail. Build, start
    # the stack at the configured replica count, then run the upgrade against it.
    if [ -z "$("${COMPOSE[@]}" ps -q php || true)" ]; then
        echo "No running php replicas — first-time bring-up."
        if [ ${#BUILD_ARGS[@]} -gt 0 ]; then
            echo "Building images (${BUILD_ARGS[*]})..."
        else
            echo "Building images..."
        fi
        "${COMPOSE[@]}" build "${BUILD_ARGS[@]}"
        "${COMPOSE[@]}" up -d --scale "php=$REPLICAS"
        wait_healthy php
        echo "Running Moodle upgrade..."
        php_exec php /var/www/html/moodle_app/admin/cli/upgrade.php --non-interactive --allow-unstable
        echo "Upgrade complete (first-time bring-up)."
        return 0
    fi

    echo "This will build images (live), then enable maintenance mode, recreate, and run upgrade.php."
    read -r -p "Proceed? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }

    # Build while the site is still live so the maintenance window covers only the
    # container recreate + DB migration, not the (much longer) image build.
    if [ ${#BUILD_ARGS[@]} -gt 0 ]; then
        echo "Building images (${BUILD_ARGS[*]})..."
    else
        echo "Building images..."
    fi
    "${COMPOSE[@]}" build "${BUILD_ARGS[@]}"

    echo "Enabling Moodle maintenance mode..."
    php_exec php /var/www/html/moodle_app/admin/cli/maintenance.php --enable

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

# Parse trailing flags. Keep this tiny — only --no-cache for now.
while [ $# -gt 0 ]; do
    case "$1" in
        --no-cache) BUILD_ARGS+=(--no-cache) ;;
        -h|--help)  SUBCMD="" ;;
        *)
            echo "ERROR: unknown flag '$1'" >&2
            exit 1
            ;;
    esac
    shift
done

case "$SUBCMD" in
    code)    cmd_code ;;
    upgrade) cmd_upgrade ;;
    *)
        cat >&2 <<EOF
Usage: $0 {code|upgrade} [--no-cache]

  code        Rolling deploy for code-only changes (no DB migration).
  upgrade     Maintenance-mode upgrade for DB schema changes.

  --no-cache  Force a no-cache image rebuild (re-clones git plugins even
              when moodle-config.json hasn't changed). Containers are
              NOT stopped; the rolling/maintenance flow is preserved.
EOF
        exit 1
        ;;
esac
