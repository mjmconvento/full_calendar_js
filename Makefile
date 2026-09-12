# Booking calendar — container task runner.
#
# IMPORTANT: these targets are for YOU to run manually. Nothing in this repo
# starts, builds or stops containers automatically. Every recipe goes through
# `docker compose`, so the dev overlay (compose.override.yaml) always applies
# unless you pass -f compose.yaml yourself.
#
# Usage: make <target> [ARGS="..."]

DC   := docker compose
PHP  := $(DC) exec php
ARGS ?=

.DEFAULT_GOAL := help

.PHONY: help build up down restart logs sh console composer migrate fixtures assets test test-postgres xdebug-on xdebug-off db clean

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build all images (php dev/prod stage per compose files)
	$(DC) build

up: ## Start the stack in the background
	$(DC) up -d

down: ## Stop and remove containers (volumes are kept)
	$(DC) down

restart: ## Restart every service
	$(DC) restart

logs: ## Follow logs (ARGS="php" to narrow to one service)
	$(DC) logs -f --tail=200 $(ARGS)

sh: ## Open a shell in the php container
	$(PHP) sh

console: ## Run bin/console, e.g. make console ARGS="debug:router"
	$(PHP) php bin/console $(ARGS)

composer: ## Run composer, e.g. make composer ARGS="require symfony/uid"
	$(PHP) composer $(ARGS)

migrate: ## Apply Doctrine migrations
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

fixtures: ## Load Doctrine fixtures (requires doctrine/doctrine-fixtures-bundle)
	$(PHP) php bin/console doctrine:fixtures:load --no-interaction

assets: ## Install importmap vendors and compile AssetMapper output
	$(PHP) php bin/console importmap:install
	$(PHP) php bin/console asset-map:compile

test: ## Run the test suite (SQLite) in the test environment
	# Real environment variables beat .env.test, so the compose stack's Postgres
	# and Mailpit values are pushed aside here or the suite would hit them.
	$(DC) exec -e APP_ENV=test -e DATABASE_URL='sqlite:///%kernel.project_dir%/var/test.db' -e MAILER_DSN=null://null php sh -c 'if [ -f bin/phpunit ]; then php bin/phpunit $(ARGS); else php vendor/bin/phpunit $(ARGS); fi'

test-postgres: ## Run the test suite against the compose Postgres (booking_calendar_test)
	$(DC) exec -e APP_ENV=test -e MAILER_DSN=null://null php sh -c 'if [ -f bin/phpunit ]; then php bin/phpunit $(ARGS); else php vendor/bin/phpunit $(ARGS); fi'

xdebug-on: ## Enable Xdebug 3 in the running dev container (host IDE on port 9003)
	$(PHP) cp /usr/local/etc/php/xdebug.ini.disabled /usr/local/etc/php/conf.d/zzz-xdebug.ini
	$(DC) restart php
	@echo "Xdebug enabled. It is dropped again whenever the container is recreated."

xdebug-off: ## Disable Xdebug in the running dev container
	$(PHP) rm -f /usr/local/etc/php/conf.d/zzz-xdebug.ini
	$(DC) restart php

db: ## Open a psql shell on the database
	$(DC) exec database psql -U booking_calendar booking_calendar

clean: ## Stop the stack and delete its volumes (database data)
	$(DC) down -v --remove-orphans
