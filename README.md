# tandem-api

Laravel 13 API for Tandem. Source of truth for the API contract: `openapi.json` is generated
from this codebase and consumed by `tandem-shared`.

PHP 8.4 is required and is **not** the machine default. Use the Makefile, never bare `php`.

    make up        start postgres, redis, mailpit
    make migrate   run migrations
    make api       serve on :8000
    make queue     run the queue worker
    make test      full suite

Architecture, schema and endpoint list: see `tandem-docs/docs/specs/`.
