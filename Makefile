COMPOSE_BASE = docker compose -f docker-compose.yml
COMPOSE_DEV = $(COMPOSE_BASE) -f docker-compose.dev.yml
COMPOSE_STAGING = $(COMPOSE_BASE) -f docker-compose.staging.yml
COMPOSE_PROD = $(COMPOSE_BASE) -f docker-compose.prod.yml
COMPOSE_DB = docker compose -f docker-compose.db.yml

.PHONY: help build build-fresh dev dev-down staging staging-down prod prod-down db-up db-down logs shell

help: ## Show available commands
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# --- Build ---

build: ## Build Docker images
	$(COMPOSE_BASE) build php

build-fresh: ## Force full rebuild: no cache + remove moodle_app volume
	$(COMPOSE_BASE) down || true
	docker volume rm $$(docker volume ls -q --filter name=moodle_app) 2>/dev/null || true
	$(COMPOSE_BASE) build --no-cache php

# --- Development ---

dev: ## Start development environment
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
