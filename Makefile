# Front door for the development environment. The API runs in Docker through
# Laravel Sail; the frontend runs natively on Node. Run `make` for the list.
#
# Nothing here needs PHP, Composer, or Postgres on the host: the one-time
# `composer install` runs in a throwaway container so that vendor/bin/sail exists.

SAIL := ./vendor/bin/sail
COMPOSER_IMAGE := laravelsail/php84-composer:latest

# The dev data epoch: line 1 of api/database/DEV_DATA_EPOCH is a number the repo
# bumps whenever a change needs the dev database rebuilt (a non-additive migration,
# new seed data, the shape of the sync log). Each machine records the epoch its
# database was built at in an ignored local file; `make up` and `make setup` compare
# the two and rebuild when they differ, so nobody has to be told. Line 2 is the reason.
EPOCH_WANT := api/database/DEV_DATA_EPOCH
EPOCH_HAVE := api/storage/app/.dev-data-epoch

.DEFAULT_GOAL := help
.PHONY: help setup up down restart logs api-shell api-test api-migrate api-fresh \
        api-epoch dev offline fe-check fe-test check reset

help: ## List the targets
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_-]+:.*##/ {printf "  \033[1m%-13s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# --- One-time setup ---------------------------------------------------------

setup: api/vendor/bin/sail api/.env frontend/node_modules ## First run: install, start, key, epoch check, migrate, seed if empty
	cd api && $(SAIL) up -d
	@if grep -q '^APP_KEY=$$' api/.env; then cd api && $(SAIL) artisan key:generate --no-interaction; fi
	@$(MAKE) --no-print-directory api-epoch
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

up: ## Start the API, Postgres, and the queue worker (rebuilds the dev data if the epoch moved)
	cd api && $(SAIL) up -d
	@$(MAKE) --no-print-directory api-epoch

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
	@sed -n 1p $(EPOCH_WANT) > $(EPOCH_HAVE)

api-epoch: ## Rebuild the dev database if the repo's data epoch moved past this machine's, or it has no schema
	@want=$$(sed -n 1p $(EPOCH_WANT)); have=$$(cat $(EPOCH_HAVE) 2>/dev/null || echo none); \
	if [ "$$want" = "$$have" ]; then \
		if (cd api && $(SAIL) artisan migrate:status >/dev/null 2>&1); then exit 0; fi; \
		echo "Dev database has no schema (volume removed?); the recorded epoch $$have cannot be trusted."; \
	else \
		echo "Dev data epoch $$have -> $$want: $$(sed -n 2p $(EPOCH_WANT))"; \
	fi; \
	if [ -n "$$SKIP_DEV_RESET" ]; then \
		echo "SKIP_DEV_RESET is set; not rebuilding. Run 'make api-fresh' when ready."; exit 0; \
	fi; \
	echo "Rebuilding the dev database (migrate:fresh --seed); local dev data is replaced by the demo school."; \
	(cd api && $(SAIL) artisan migrate:fresh --seed --no-interaction) && echo "$$want" > $(EPOCH_HAVE)

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
