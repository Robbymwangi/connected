# Front door for the development environment. The API runs in Docker through
# Laravel Sail; the frontend runs natively on Node. Run `make` for the list.
#
# Nothing here needs PHP, Composer, or Postgres on the host: the one-time
# `composer install` runs in a throwaway container so that vendor/bin/sail exists.

SAIL := ./vendor/bin/sail
COMPOSER_IMAGE := laravelsail/php84-composer:latest

.DEFAULT_GOAL := help
.PHONY: help setup up down restart logs api-shell api-test api-migrate api-fresh \
        dev offline fe-check fe-test check reset

help: ## List the targets
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_-]+:.*##/ {printf "  \033[1m%-13s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# --- One-time setup ---------------------------------------------------------

setup: api/vendor/bin/sail api/.env frontend/node_modules ## First run: install, start, key, migrate, seed if empty
	cd api && $(SAIL) up -d
	@if grep -q '^APP_KEY=$$' api/.env; then cd api && $(SAIL) artisan key:generate --no-interaction; fi
	cd api && $(SAIL) artisan migrate --no-interaction
	@cd api && output=$$($(SAIL) artisan tinker --execute='echo \App\Models\Institution::count();' 2>&1); \
	status=$$?; \
	count=$$(echo "$$output" | tr -d '[:space:]'); \
	if [ $$status -ne 0 ] || ! echo "$$count" | grep -qE '^[0-9]+$$'; then \
		echo "Could not tell whether the database is empty; not guessing. Output was:"; \
		echo "$$output"; \
		exit 1; \
	elif [ "$$count" = "0" ]; then \
		echo "Empty database: seeding the demo school."; \
		$(SAIL) artisan db:seed --no-interaction; \
	else \
		echo "Database already has data ($$count institution(s)); leaving it alone. Use 'make api-fresh' to wipe and reseed."; \
	fi
	@echo
	@echo "Ready. API on http://localhost:8000, then 'make dev' for the frontend."

# Re-runs when either manifest changes. Day to day, `sail composer` inside the
# running container is the faster way to add a package; this rule then re-installs
# once more on the next `make setup`, which is redundant but correct.
api/vendor/bin/sail: api/composer.json api/composer.lock
	docker run --rm -u "$$(id -u):$$(id -g)" -v "$(CURDIR)/api:/var/www/html" \
		-w /var/www/html $(COMPOSER_IMAGE) composer install --ignore-platform-reqs --no-interaction
	@touch $@

api/.env:
	cp api/.env.example api/.env

frontend/node_modules: frontend/package-lock.json
	cd frontend && npm ci
	@touch frontend/node_modules

# --- API (Docker) ---------------------------------------------------------------

up: ## Start the API, Postgres, and the queue worker
	cd api && $(SAIL) up -d

down: ## Stop them (data kept)
	cd api && $(SAIL) down

restart: down up ## Stop and start

logs: ## Follow the container logs
	cd api && $(SAIL) logs -f

api-shell: ## Shell inside the API container
	cd api && $(SAIL) shell

api-test: ## Pest tests against the testing database
	cd api && $(SAIL) test

api-migrate: ## Run pending migrations
	cd api && $(SAIL) artisan migrate

api-fresh: ## Drop and rebuild the database with seeders
	cd api && $(SAIL) artisan migrate:fresh --seed

# --- Frontend (native Node) ---------------------------------------------------

dev: ## Frontend dev server on http://localhost:5173 (no service worker)
	cd frontend && npm run dev

offline: ## Production build served on http://localhost:4173 (the installable app)
	cd frontend && npm run offline

fe-check: ## Typecheck and lint the frontend
	cd frontend && npm run typecheck && npm run lint

fe-test: ## Vitest
	cd frontend && npm test

# --- Everything -------------------------------------------------------------------

check: fe-check fe-test api-test ## What must pass before a commit

reset: ## Wipe containers, volumes, and installs, then set up again
	cd api && $(SAIL) down -v
	rm -rf api/vendor frontend/node_modules
	$(MAKE) setup
