#!/usr/bin/env bash
set -euo pipefail

# ONE definition of how openapi.json is generated, used by both `make openapi` and
# bin/check-openapi.sh. Two definitions would be free to diverge - the mistake finding I10
# corrected for the test gate, not repeated here.
#
# The committed document is gated on byte equality, so generation must not depend on the
# machine doing it. Wave 0 already found info.title leaking through two hops
# (ui.title -> config('app.name') -> env('APP_NAME')), which made a fresh clone with no .env
# generate "title": "Laravel". Spec §5 names two further leaks that go live the moment a
# route exists under api/v1 - which is now:
#
#   - config('app.locale') = env('APP_LOCALE', 'en'), read through Scramble's
#     TranslatedStringType, which resolves __() strings. A developer with APP_LOCALE=fr
#     generates a different document from the same code.
#   - config('auth.providers.users.model') = env('AUTH_MODEL', User::class), read by
#     Scramble's RequestExtension when inferring the type of $request->user().
#
# Pinning the whole environment is preferred over chasing individual keys: "this config file
# contains no env() call" proved insufficient evidence in Wave 0, because anything reached
# through config(...) can be env-backed somewhere else. These are command-scoped
# assignments, so they beat both the ambient environment and .env - Laravel's dotenv
# repository does not overwrite variables that are already set.
OUT="${1:?usage: generate-openapi.sh <output-path>}"
PHP="${PHP:-/opt/homebrew/opt/php/bin/php}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Refuse to generate from cached config, because a cached config makes every pin above a
# no-op AND leaves the seal test green - the worst combination available.
#
# With bootstrap/cache/config.php present, LoadConfiguration::bootstrap() requires that file
# and never calls loadConfigurationFiles(), so no config file evaluates env() at all: all six
# assignments below are dead, and config(...) returns whatever was frozen at cache time.
# tests/Feature/Contract/OpenApiEnvironmentTest.php still passes in that state, because the
# hostile run and the committed document were both generated from the same frozen values -
# the test compares two runs, and freezing makes any two runs agree. That is precisely the
# "guard reports green while verifying nothing" shape the seal exists to detect.
#
# Failing hard rather than clearing silently: a cached config here means someone ran
# `artisan optimize` or `artisan config:cache`, which CLAUDE.md forbids outright because
# RefreshDatabase then targets the development database. Clearing it quietly would repair
# this script's output while hiding the fact that a destructive command was run - the
# developer needs to know, not be papered over. The Makefile's `test:` target depends on
# `config-clear` for the same hazard; `openapi-check` never did, and `verify` reaches
# config-clear only as a prerequisite of `test`, i.e. after openapi-check has already run.
if [[ -f "$ROOT/bootstrap/cache/config.php" ]]; then
  cat >&2 <<'ERR'
Refusing to generate openapi.json: bootstrap/cache/config.php exists.

Laravel loads cached config instead of reading the config files, so every environment pin
in bin/generate-openapi.sh is silently ignored and config(...) returns values frozen when
the cache was written. The document would be generated from one machine's frozen state, and
the environment-seal test would still pass - it compares two runs, and a frozen config makes
any two runs agree.

That file should not exist in this project at all: `artisan optimize` and
`artisan config:cache` are forbidden here (see CLAUDE.md - a cached config also points
RefreshDatabase at the development database).

Run: /opt/homebrew/opt/php/bin/php artisan config:clear
ERR
  exit 1
fi

APP_NAME=Tandem \
APP_ENV=local \
APP_URL=http://localhost \
APP_LOCALE=en \
APP_FALLBACK_LOCALE=en \
AUTH_MODEL='App\Models\User' \
  "$PHP" artisan scramble:export --path="$OUT" --fail-on-unknown >/dev/null
