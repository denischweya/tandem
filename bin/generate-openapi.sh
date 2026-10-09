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

APP_NAME=Tandem \
APP_ENV=local \
APP_URL=http://localhost \
APP_LOCALE=en \
APP_FALLBACK_LOCALE=en \
AUTH_MODEL='App\Models\User' \
  "$PHP" artisan scramble:export --path="$OUT" --fail-on-unknown >/dev/null
