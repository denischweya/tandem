#!/usr/bin/env bash
set -euo pipefail

PHP=/opt/homebrew/opt/php/bin/php
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

$PHP artisan scramble:export --path="$TMP/openapi.json" --fail-on-unknown >/dev/null

if ! diff -q openapi.json "$TMP/openapi.json" >/dev/null 2>&1; then
  echo "openapi.json is out of date. Run 'make openapi' and commit the result." >&2
  diff -u openapi.json "$TMP/openapi.json" | head -60 >&2
  exit 1
fi

echo "openapi.json is in sync with the code"
