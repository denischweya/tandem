# tandem-api — agent constraints

This is a Laravel 13 API pinned to PHP 8.4. A few hard constraints for any agent working here:

## PHP and Composer

- PHP for this project is `/opt/homebrew/opt/php/bin/php` (8.4.2). The machine's linked `php` is
  7.4.33 and **must not be relinked** — other projects on this machine depend on it.
  Pest 5 requires PHP `^8.4`, so this is a hard floor, not a preference.
- Never invoke bare `php` or bare `composer`. Use the `Makefile` targets (`make test`, `make stan`,
  `make lint`, etc.), or the explicit binary path. For Composer:
  `/opt/homebrew/opt/php/bin/php /usr/local/bin/composer ...`.
- Do not install PHP, and do not run remote install scripts (e.g. anything piped from `php.new`
  or similar) under any circumstances.

## Dependencies

- Dependencies are pinned deliberately. Do not add, upgrade, or float packages without being
  asked — including dev tooling such as Laravel Boost.

## Infrastructure

- Postgres 17, Redis 7, and Mailpit run via `docker compose` (`make up`). Do not install database
  software locally.

## Documentation

- Architecture, schema, and endpoint decisions live in the sibling `tandem-docs` repository,
  under `docs/specs/` and `docs/decisions/`. Check there before assuming behavior.

## Architecture boundary

- The `app/Domain/` layer must not import from `Illuminate\`, `Laravel\`, or any vendor SDK.
  (A later task enforces this with a test.)
