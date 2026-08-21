COMPOSE := docker compose -f docker/docker-compose.yml
RUN := $(COMPOSE) run --rm --build test

PHP_VERSION ?= 8.3
export PHP_VERSION

.DEFAULT_GOAL := help

.PHONY: help test cs-fix cs-check check run shell build clean

help: ## Show this help
	@grep -E '^[a-zA-Z0-9_-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-12s %s\n", $$1, $$2}'

test: ## Run PHPUnit in Docker (make test PHP_VERSION=8.1)
	$(RUN)

cs-fix: ## Fix code style in Docker
	$(RUN) composer cs-fix

cs-check: ## Check code style in Docker
	$(RUN) composer cs-check

check: ## Fix code style, then run tests in Docker
	$(RUN) composer check

run: ## Run a command in the container (make run c="php -v")
	@test "$(c)" || (echo 'usage: make run c="php -v"' >&2; exit 2)
	$(RUN) $(c)

shell: ## Open a bash shell in the container
	$(RUN) bash

build: ## Build (or rebuild) the Docker image
	$(COMPOSE) build

clean: ## Remove containers and the vendor volume
	$(COMPOSE) down -v --remove-orphans
