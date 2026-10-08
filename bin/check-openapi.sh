#!/usr/bin/env bash
set -euo pipefail

# Overridable, because CI's PHP is on PATH and is not a Homebrew build. A plain
# assignment here made `make openapi-check PHP=php` silently ignore the override,
# which is why the CI workflow used to re-implement this check inline — defining
# anti-drift guarantee 1 in two places, free to diverge.
PHP="${PHP:-/opt/homebrew/opt/php/bin/php}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

$PHP artisan scramble:export --path="$TMP/openapi.json" --fail-on-unknown >/dev/null

if ! diff -q openapi.json "$TMP/openapi.json" >/dev/null 2>&1; then
  echo "openapi.json is out of date. Run 'make openapi' and commit the result." >&2
  diff -u openapi.json "$TMP/openapi.json" | head -60 >&2
  exit 1
fi

echo "openapi.json is in sync with the code"
