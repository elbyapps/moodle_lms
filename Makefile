# Compose files live under compose/, but every relative path inside them
# (build context:, bind-mount sources, env_file:) is resolved against
# --project-directory, NOT against the YAML file's own directory. We pin
# --project-directory . (the repo root) so:
#   - paths like ./moodledata, ./docker/..., context: . resolve to the repo
#     root as they did when the YAMLs lived there — no rewriting needed.
#   - the project name stays "moodle_lms" instead of becoming "compose"
#     (volume names are prefixed by project name — changing it would orphan
#     moodle_lms_moodledata, moodle_lms_redis_data, etc.)
#   - .env at the repo root is picked up for ${VAR} interpolation and for
#     the services' env_file: .env directive.
# Do not strip --project-directory . from these commands.
PROJECT_DIR = --project-directory .

# When compose/docker-compose.local.yml is present (host-specific overrides like
# pre-existing volume names), include it in every stack-aware target.
LOCAL_OVERRIDE = $(if $(wildcard compose/docker-compose.local.yml),-f compose/docker-compose.local.yml,)

COMPOSE_BASE = docker compose $(PROJECT_DIR) -f compose/docker-compose.yml $(LOCAL_OVERRIDE)
COMPOSE_DEV = docker compose $(PROJECT_DIR) -f compose/docker-compose.yml -f compose/docker-compose.dev.yml $(LOCAL_OVERRIDE)
COMPOSE_STAGING = docker compose $(PROJECT_DIR) -f compose/docker-compose.yml -f compose/docker-compose.staging.yml $(LOCAL_OVERRIDE)
COMPOSE_PROD = docker compose $(PROJECT_DIR) -f compose/docker-compose.yml -f compose/docker-compose.prod.yml $(LOCAL_OVERRIDE)
COMPOSE_DB = docker compose $(PROJECT_DIR) -f compose/docker-compose.db.yml

.PHONY: help build build-fresh dev dev-down staging staging-down prod prod-down deploy-code deploy-upgrade db-up db-down logs shell clean-cache objectfs-setup objectfs-setup-force objectfs-setup-dry test-s3 migrate-auth-externalid migrate-auth-externalid-dry admin-cli reconcile-plugins reconcile-plugins-dry

help: ## Show available commands
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# --- Build ---

build: ## Build Docker images (every service with a build: stanza)
	$(COMPOSE_BASE) build

build-fresh: ## Force full rebuild: no cache + remove moodle_app volume
	$(COMPOSE_BASE) down || true
	docker volume rm $$(docker volume ls -q --filter name=moodle_app) 2>/dev/null || true
	$(COMPOSE_BASE) build --no-cache

# --- Development ---

dev: ## Start development environment
	@if [ ! -d "./moodle_app" ]; then \
		echo "moodle_app directory not found. Running build.sh..."; \
		./build.sh; \
	fi
	$(COMPOSE_DEV) up --build -d

dev-down: ## Stop development environment
	$(COMPOSE_DEV) down

# --- Staging ---

db-up: ## Start standalone database
	$(COMPOSE_DB) up -d

db-down: ## Stop standalone database
	$(COMPOSE_DB) down

staging: ## Start staging environment
	$(COMPOSE_STAGING) up --build -d

staging-down: ## Stop staging environment
	$(COMPOSE_STAGING) down

# --- Production ---

prod: ## Start production environment (first-time bring-up; for live updates use deploy-code or deploy-upgrade)
	$(COMPOSE_PROD) up --build -d

prod-down: ## Stop production environment
	$(COMPOSE_PROD) down

deploy-code: ## Zero-downtime rolling deploy of code-only changes (no DB migration)
	./scripts/deploy.sh code

deploy-upgrade: ## Maintenance-mode deploy for changes that include a DB migration
	./scripts/deploy.sh upgrade

# --- Utilities ---

logs: ## Show logs (usage: make logs s=php)
	$(COMPOSE_BASE) logs -f $(s)

# Admin recipes spin a throwaway php container from the same image the stack
# is running. Benefits over `docker exec` on a serving replica:
#   - works regardless of how many replicas are running (no "docker cp wants
#     one container, got 3" failures in prod);
#   - never touches a serving replica (no memory contention, no /tmp litter);
#   - works even when the stack is down, as long as the image exists;
#   - scripts/ is baked into the image (Dockerfile: COPY scripts ./scripts)
#     so no docker cp dance is needed.
#
# Detect the currently-running stack's compose files from container labels,
# so the same `make admin-cli ...` invocation works on dev/staging/prod
# without env flags. Falls back to the dev stack when nothing is running yet.
COMPOSE_FILES_RUNNING = $$(docker ps --filter "label=com.docker.compose.project=moodle_lms" --format '{{.Label "com.docker.compose.project.config_files"}}' | head -n1 | tr ',' '\n' | awk 'NF{printf "-f %s ", $$0}')
COMPOSE_FILES_FALLBACK = -f compose/docker-compose.yml -f compose/docker-compose.dev.yml $(LOCAL_OVERRIDE)
COMPOSE_AUTO = docker compose --project-directory . $$( files="$(COMPOSE_FILES_RUNNING)"; if [ -n "$$files" ]; then printf '%s' "$$files"; else printf '%s' '$(COMPOSE_FILES_FALLBACK)'; fi )

# php-run: throwaway container for one-off admin scripts. --no-deps so we
# don't accidentally start mariadb/redis; -T disables TTY (non-interactive).
PHP_RUN = $(COMPOSE_AUTO) run --rm --no-deps -T php

shell: ## Open interactive shell in a fresh php container (from current stack's image)
	$(COMPOSE_AUTO) run --rm --no-deps php bash

clean-cache: ## Purge Moodle caches
	$(PHP_RUN) php /var/www/html/moodle_app/admin/cli/purge_caches.php

# --- ObjectFS / S3 ---

# Seed tool_objectfs operational settings (sizethreshold, minimumage, etc.)
# into the DB from OBJECTFS_* env vars. Idempotent by default; --force
# overwrites existing values. Run after the initial Moodle install, or
# whenever you change the OBJECTFS_* defaults you want enforced.
objectfs-setup: ## Seed tool_objectfs tuning settings from OBJECTFS_* env vars (idempotent)
	$(PHP_RUN) php /var/www/html/scripts/setup_objectfs.php

objectfs-setup-force: ## Same as objectfs-setup but overwrites existing DB values
	$(PHP_RUN) php /var/www/html/scripts/setup_objectfs.php --force

objectfs-setup-dry: ## Show what objectfs-setup would change without writing
	$(PHP_RUN) php /var/www/html/scripts/setup_objectfs.php --dry-run

test-s3: ## Run the tool_objectfs end-to-end round-trip test against the dev stack
	./scripts/test-s3-roundtrip.sh

# --- Auth migration ---

# Move every user whose authentication method is the custom auth_externalid
# plugin onto core `manual` auth, so auth_externalid (and its companion
# local_custom_service) can be removed safely. Idempotent: a second run is a
# no-op. Passwords keep working — auth_externalid stored them with Moodle's
# internal hash already.
migrate-auth-externalid: ## Migrate users from auth_externalid to manual auth
	$(PHP_RUN) php /var/www/html/scripts/migrate_auth_externalid.php

migrate-auth-externalid-dry: ## Show what migrate-auth-externalid would change without writing
	$(PHP_RUN) php /var/www/html/scripts/migrate_auth_externalid.php --dry-run

# --- Admin CLI passthrough ---

# Run any script under moodle_app/public/admin/cli/. Pass the script name +
# its args via cmd="...". Example:
#   make admin-cli cmd="uninstall_plugins.php --plugins=block_progress --run"
#   make admin-cli cmd="upgrade.php --non-interactive"
admin-cli: ## Run a Moodle admin/cli script (usage: make admin-cli cmd="script.php --args")
	@if [ -z "$(cmd)" ]; then echo 'usage: make admin-cli cmd="script.php --args"'; exit 1; fi
	$(PHP_RUN) php /var/www/html/moodle_app/admin/cli/$(cmd)

# --- Plugin reconciliation (moodle-config.json -> live Moodle) ---

# Diff the installed plugin set against moodle-config.json. Anything installed
# but not declared in the config is uninstalled cleanly (Moodle's uninstall
# API: DB tables, settings, language strings, then source dir). Core/standard
# plugins are never touched.
#
# This is intentionally a manual step — it is destructive (drops plugin DB
# tables) and must not be hooked into container startup, where a malformed
# config or temporarily-commented-out entry would wipe user data.
# reconcile_plugins.php reads /var/www/html/moodle-config.json (baked into
# the image alongside scripts/), so no docker cp is needed here either.
reconcile-plugins-dry: ## List plugins installed in Moodle but not in moodle-config.json
	$(PHP_RUN) php /var/www/html/scripts/reconcile_plugins.php --dry-run

reconcile-plugins: ## Uninstall (DB + disk) any plugins no longer in moodle-config.json
	$(PHP_RUN) php /var/www/html/scripts/reconcile_plugins.php
