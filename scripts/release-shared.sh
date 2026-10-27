#!/usr/bin/env bash
# Cuts a tandem-mobile-installable release of packages/shared from this repo's own
# history, using the same subtree-split technique Symfony and Laravel's illuminate/*
# components use to split packages out of a monorepo. Run from the repo root, on a
# clean working tree, after merging a change under packages/shared/.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree is not clean. Commit or stash before releasing." >&2
  exit 1
fi

VERSION="$(node -p "require('./packages/shared/package.json').version")"
TAG="shared-v${VERSION}"

if git rev-parse "$TAG" >/dev/null 2>&1; then
  echo "Tag $TAG already exists. Bump packages/shared/package.json's version first." >&2
  exit 1
fi

SPLIT_BRANCH="shared-release-${VERSION}"
git subtree split --prefix=packages/shared -b "$SPLIT_BRANCH"
git tag "$TAG" "$SPLIT_BRANCH"
git branch -D "$SPLIT_BRANCH"

echo "Tagged $TAG. Push it with: git push origin $TAG"
echo "tandem-mobile's package.json should then pin: github:denischweya/tandem#$TAG"
