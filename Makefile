# When docker-compose.local.yml is present (host-specific overrides like
# pre-existing volume names), include it in every stack-aware target.
LOCAL_OVERRIDE = $(if $(wildcard docker-compose.local.yml),-f docker-compose.local.yml,)

COMPOSE_BASE = docker compose -f docker-compose.yml $(LOCAL_OVERRIDE)
COMPOSE_DEV = docker compose -f docker-compose.yml -f docker-compose.dev.yml $(LOCAL_OVERRIDE)
COMPOSE_STAGING = docker compose -f docker-compose.yml -f docker-compose.staging.yml $(LOCAL_OVERRIDE)
COMPOSE_PROD = docker compose -f docker-compose.yml -f docker-compose.prod.yml $(LOCAL_OVERRIDE)
COMPOSE_DB = docker compose -f docker-compose.db.yml

.PHONY: help build build-fresh dev dev-down staging staging-down prod prod-down db-up db-down logs shell clean-cache

help: ## Show available commands
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

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

prod: ## Start production environment
	$(COMPOSE_PROD) up --build -d

prod-down: ## Stop production environment
	$(COMPOSE_PROD) down

# --- Utilities ---

logs: ## Show logs (usage: make logs s=php)
	$(COMPOSE_BASE) logs -f $(s)

shell: ## Open shell in PHP container
	docker exec -it $$(docker ps -qf "name=php") bash

clean-cache: ## Purge Moodle caches
	docker exec $$(docker ps -qf "name=php") php /var/www/html/moodle_app/admin/cli/purge_caches.php
