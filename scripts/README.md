# scripts/

## `release-shared.sh`

Cuts a `tandem-mobile`-installable tag of `packages/shared`, using `git subtree split`
to flatten `packages/shared/*` onto the root of a throwaway branch, tags that branch's
tip `shared-v<version>` (read from `packages/shared/package.json`), then deletes the
throwaway branch — the tag is all that needs to survive.

Run after any merge touching `packages/shared/` that bumps its `package.json` version:

    ./scripts/release-shared.sh
    git push origin shared-v<version>

Then update `tandem-mobile/package.json`'s `@tandem/shared` line to
`github:denischweya/tandem#shared-v<version>` and run `npm install` there.
