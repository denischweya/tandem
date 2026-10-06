# tandem-api

Laravel 13 API for Tandem. Source of truth for the API contract: `openapi.json` is generated
from this codebase and consumed by `tandem-shared`.

PHP 8.4 is required and is **not** the machine default. Use the Makefile, never bare `php`
or bare `composer` — see `CLAUDE.md`.

## First-time setup

    make install   composer install
    make setup     copy .env.example to .env (if missing) and generate an APP_KEY
    make up        start postgres, redis, mailpit
    make migrate   run migrations

## Day to day

    make api       serve on :8000
    make queue     run the queue worker
    make verify    the gate: lint + phpstan + openapi drift check + full test suite

`make verify` is the command that must pass before anything is considered done — not
`make test` alone, which skips the OpenAPI drift check (`openapi.json` is byte-gated: see
`make openapi`) and does not guarantee `tandem_test` exists.

Architecture, schema and endpoint list: see `tandem-docs/docs/specs/`.
