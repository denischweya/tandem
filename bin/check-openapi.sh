#!/usr/bin/env bash
set -euo pipefail

# Overridable, because CI's PHP is on PATH and is not a Homebrew build. A plain
# assignment here made `make openapi-check PHP=php` silently ignore the override,
# which is why the CI workflow used to re-implement this check inline — defining
# anti-drift guarantee 1 in two places, free to diverge.
PHP="${PHP:-/opt/homebrew/opt/php/bin/php}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# PHP="$PHP" explicitly, because the assignment above is a shell variable, not an exported
# one - the child script's own ${PHP:-...} default would otherwise fall back to the Homebrew
# path and silently ignore CI's `PHP=php`.
PHP="$PHP" ./bin/generate-openapi.sh "$TMP/openapi.json"

if ! diff -q openapi.json "$TMP/openapi.json" >/dev/null 2>&1; then
  echo "openapi.json is out of date. Run 'make openapi' and commit the result." >&2
  diff -u openapi.json "$TMP/openapi.json" | head -60 >&2
  exit 1
fi

echo "openapi.json is in sync with the code"
