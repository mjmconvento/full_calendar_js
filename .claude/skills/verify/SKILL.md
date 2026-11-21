---
name: verify
description: Run this project's checks (PHPUnit on SQLite and on PostgreSQL, PHPStan level 8, Doctrine schema validation, Twig and container lint, Rector dry run) and report what passed and failed. Use after code changes, before committing, or when asked to run the tests.
---

# Verify

With the stack up (`make up`):

| Check | Command | Notes |
| --- | --- | --- |
| Tests, SQLite | `make test` | Narrow with `make test ARGS="--filter=<name>"` |
| Tests, PostgreSQL | `make test-postgres` | Required whenever a query, ordering or repository changed |
| Static analysis | `docker compose exec php vendor/bin/phpstan analyse` | Level 8 over `src/` and `tests/`; needs the dev container cache (`make console ARGS="cache:warmup"` if it complains) |
| Mapping vs migrated schema | `make console ARGS="doctrine:schema:validate"` | The only check that catches a forgotten migration: both suites build their schema from the mapping |
| Templates | `make console ARGS="lint:twig templates/"` | |
| Config and wiring | `make console ARGS="lint:container"` and `make console ARGS="lint:yaml config/"` | |
| Rector (PHP 8.5 set) | `docker compose exec php vendor/bin/rector process --dry-run` | |

Without Docker, PHP 8.5 with `pdo_sqlite` runs `vendor/bin/phpunit` and `vendor/bin/phpstan analyse`
directly (the SQLite database comes from `.env.test`).

Front-end changes have no build step to check: load the page (`http://localhost:8080`), and for
asset changes run `make assets` and confirm nothing 404s.

## Report

One line per check: command, pass or fail, and for a failure the first failing test or error
verbatim. Say which checks were skipped and why. Never report a check as passing without running it.
