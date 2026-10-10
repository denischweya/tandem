# tandem-shared

The contract package for **Tandem**, a private-by-default shared planning app. It holds the
three things both clients must agree with the API about:

- **`src/types/api.ts`** — TypeScript types generated from `tandem-api`'s `openapi.json`.
  **Never hand-edit this file.**
- **`src/validation/`** — hand-written Zod schemas, each one *proved at compile time* to match
  the generated wire type.
- **`src/date-time/`** — timezone-safe date helpers (`toZonedParts`, `instantFromZonedParts`).

It is consumed by `tandem-mobile` and `tandem-web` as a **private Git dependency pinned to a
ref**, never from a registry.

---

## It ships raw TypeScript, not a build

There is no compile step and no `dist/`. `package.json` sets both `"main"` and `"types"` to
`src/index.ts`, and the `files` allowlist installs only `src/` (plus `package.json` and this
README). `tsconfig.json` sets `"noEmit": true`.

**That means each consumer's own compiler typechecks this package's source.** It is the single
most important fact about this repo, and the reason for the rule below.

### The strict compiler options must match across all three TypeScript repos

`exactOptionalPropertyTypes` and `noUncheckedIndexedAccess` are **on** here, in
`tandem-mobile` and in `tandem-web`, and **must not be relaxed in any of them** (spec §14.1,
review finding I6).

Because the assignability harness in `src/validation/` is typechecked by the consumer's `tsc`
rather than by this repo's, a laxer option in one repo makes **the same assertion produce two
verdicts** — passing in `tandem-web` while failing in this repo's own CI, for identical code.
`exactOptionalPropertyTypes` is the one that matters most: it changes assignability for
optional properties, which is most of an OpenAPI request body.

A single base `tsconfig` that all three repos `extends` was considered and **rejected**: this
package is consumed as a pinned Git dependency, not a workspace, and only its `files` allowlist
is installed — no `tsconfig` ships with it, and shipping one would mean bumping and re-pointing
the pinned dependency just to carry a dev-tooling option. Each repo therefore sets both options
explicitly, with a comment pointing back at the spec. **Any new repo that consumes this package
must set both too.**

> **A measured subtlety, because it has produced wrong code twice.**
> `noUncheckedIndexedAccess` widens index **signatures**, not a `Record` over a literal key
> union. Verified against the installed TypeScript 5.9.3 with both options on:
> `Record<'a'|'b', number>` indexes to `number`, while `{ [k: string]: number }` indexes to
> `number | undefined`. So defensive narrowing added for a `Record` over a literal union
> (`Record<EndpointName, Decision>`, `Record<CalendarProvider, string>`) is **dead code** — it
> cannot be reached, and it is a sign that the author expected a widening that does not happen.
> Narrowing *is* required for a `Record<string, T>` lookup table or a computed array index.

---

## Before you clone: all five repositories are private

`git clone` fails until your GitHub invitation has been accepted — for this repo and for the
four siblings.

This repo's own `npm ci` needs **no** GitHub authentication: every dependency here comes from
the npm registry. It is the two *client* repos that need credentials, because they install
**this** repo over Git. Their READMEs cover it.

## `main` is nearly empty — the work is on `wave-0-foundation`

```bash
git log --oneline main -3      # a single commit: "Initial commit: README and gitignore"
git branch -a                  # main, wave-0-foundation
git checkout wave-0-foundation # the whole package lives here
```

A developer who clones and reads `main` will conclude the package is empty. `wave-0-foundation`
is also where the currently-pinned commit `8ed9d48` lives — it is that branch's tip.

---

## How this fits — the five repositories

Cloned side by side, all under the `denischweya` GitHub account. All five are private.

| Repo | Owns | Work is on |
|---|---|---|
| `tandem-docs` | Blueprint, specs, ADRs, plans, reviews. Authoritative for *why*. | `main` |
| `tandem-api` | Laravel 13 / PHP 8.4 API. **Source of truth for the contract** — generates the `openapi.json` this repo copies. | `wave-0-foundation` |
| **`tandem-shared`** (this repo) | Generated types, hand-written Zod schemas with compile-time proofs, date helpers. | `wave-0-foundation` |
| `tandem-mobile` | Expo SDK 57 / React Native client. Consumes this package. | `feature/home-dashboard` |
| `tandem-web` | Next.js 16 App Router client. Consumes this package. | `feature/guest-invite` |

This package sits in the middle of the project's **anti-drift chain**, which exists because the
repos are deliberately separate rather than a monorepo (ADR 0002) — a monorepo would give this
sharing for free, and blueprint §100 forbids hand-maintaining the same interface in two places:

```
tandem-api code  ──(1)──▶  tandem-api/openapi.json  ──(2)──▶  this repo's openapi.json
                                                                        │
                                                                       (3)
                                                                        ▼
                                                      src/types/api.ts (generated)
                                                                        │
                                                                       (4)
                                                                        ▼
                                                    src/validation/*.ts (hand-written Zod)
```

1. `tandem-api`'s `make openapi-check` fails if its committed document drifts from its code.
2. This repo's CI fails if its copy drifts from `tandem-api`'s `main`.
3. `npm run types-check` fails if `src/types/api.ts` drifts from the committed document.
4. The `WireEquivalent` assertions fail to **compile** if a Zod schema drifts from the type.

**Do not weaken any link.** Each one replaces something a monorepo would have given free.

---

## Install, run, verify

Node 22. (This repo has no `.nvmrc` — its CI pins `node-version: '22'` as a literal, where
`tandem-mobile` and `tandem-web` both use `node-version-file: '.nvmrc'`. Use 22 here too.)

```bash
npm ci
npm run verify
```

`npm run verify` is the gate, and it chains, in order:

| Step | Command | What it proves |
|---|---|---|
| 1 | `npm run types-check` | regenerate `src/types/api.ts`, assert it is **tracked**, then `git diff --exit-code` it |
| 2 | `npm run typecheck` | `tsc --noEmit` — this is where the compile-time proofs are checked |
| 3 | `npm run lint` | `eslint src scripts --max-warnings 0` |
| 4 | `npm run format-check` | `prettier --check .` |
| 5 | `npm run test` | `vitest run` |

The other scripts:

| Script | What it does |
|---|---|
| `npm run sync` | copy `tandem-api`'s `openapi.json` here **and** regenerate the types |
| `npm run generate` | regenerate `src/types/api.ts` from the local `openapi.json` only |

`npm run sync` reads `tandem-api` from `$TANDEM_API_PATH`, defaulting to `../tandem-api`. It
validates the source as JSON **before** copying, so a malformed upstream document can never
overwrite a known-good `openapi.json`.

The `git ls-files --error-unmatch` in the middle of `types-check` is not decoration: without it
the gate exited 0 when `src/types/api.ts` was untracked, because `git diff` has nothing to say
about a file Git does not know about. That is one of several guards in this project that
reported green while verifying nothing.

### CI, and the one step that is red by design

`.github/workflows/ci.yml` runs `npm ci`, then `npm run verify`, then a cross-repo drift check
that `curl`s `tandem-api`'s `openapi.json` from its `main` branch and `diff`s it against the
committed copy. It also runs **nightly at 06:00 UTC** and on `workflow_dispatch`, because the
in-repo gate only tells the truth when something re-runs it and nothing in `tandem-api` can
trigger it — a contract change merged there would otherwise leave this repo's last run green
while its committed document silently went stale. (Both of those triggers only take effect once
the workflow is on `main`: GitHub reads schedules and manual dispatches from the default branch
only.)

**Measured 2026-10-10: CI is failing, and only at that last step.** `npm ci` and
`npm run verify` both pass. Two independent reasons the step cannot succeed today:

1. `tandem-api`'s pull request has not merged, so `openapi.json` does not exist on its `main`.
2. **`tandem-api` is now private**, and that `curl` sends no credentials. Verified: the raw URL
   returns **404** — so the step will keep failing *after* the merge until it is given a token
   with access to `tandem-api`. A workflow's default `GITHUB_TOKEN` is scoped to its own
   repository, so it cannot be used for this.

A gate that tolerates a 404 by passing is not a gate, so **do not "fix" this by adding
`|| true`.** The fix is the token (or the `repository_dispatch` from `tandem-api` that this
project has not yet issued).

### Consumed by a pinned ref; cutting a tag is a separate, deliberate step

Both clients currently hold:

```json
"@tandem/shared": "github:denischweya/tandem-shared#8ed9d48"
```

A **commit**, deliberately — never a branch. ADR 0002 is authoritative on the
`github:owner/repo#ref` **form** and is indifferent between a tag and a SHA; the project's
convention is that the steady state is a **tag**, and the commit pin is the temporary form used
while a tag cannot yet be cut.

Why it is a commit right now: `git tag -l` in this repo prints **`v0.1.0` and nothing else**,
while `package.json` says version `0.2.0`. `v0.2.0` cannot be cut yet, because the commit it
would point at has failing CI (the upstream drift step above) — and a tag pointing at a
red commit is the opposite of what a tag is for. The dependency chain is: `tandem-api` merges to
`main` → this repo's drift gate can go green → `v0.2.0` is cut here → both clients move their
specifier to `#v0.2.0`.

**Cutting the tag is its own explicit, verified step**, not a side effect of merging. The Wave
1b plan's final task refuses to merge until it has happened.

Two traps when you do bump it:

- **`npm pkg set` followed by a bare `npm install` is a silent no-op for a Git dependency.**
  Measured on npm 10.8.2: `package.json` said `#8ed9d48` while the installed package stayed at
  `v0.1.0`, and nothing reported it. Always pass the specifier to install directly:
  `npm install '@tandem/shared@github:denischweya/tandem-shared#<ref>'`.
- **Never commit a `file:` override.** Only the `github:owner/repo#ref` form may appear in a
  committed `package.json`. ADR 0002 permits a temporary local `file:` override for development
  against an unpublished change here, and the Wave 1b plan tightens that to "neither
  `package.json` may carry one at any point". Note honestly: **nothing mechanically enforces
  this today.** ADR 0002 says "CI rejects a non-tag specifier", and no such check exists in any
  of the three workflows — they run `npm ci` and `npm run verify` and nothing else. The rule is
  real; the gate is not. Treat it as a review responsibility.

---

## The hand-written Zod + compile-time proof pattern

This is the part of the repo that looks over-engineered until you know what it is for.

**Why the schemas are hand-written.** Generating usable *validators* from OpenAPI does not
work: the generated types erase most constraints, and a generated validator is not something a
form can show errors from. So the Zod schemas duplicate `tandem-api`'s `rules()` arrays by
hand. That duplication is exactly what blueprint §100 forbids doing *silently* — so each
schema is followed by a type-level assertion that **proves** it still matches the generated
wire type:

```ts
type RegisterBody = NonNullable<
  paths['/auth/register']['post']['requestBody']
>['content']['application/json'];

export type _RegisterMatchesApi = Expect<
  WireEquivalent<z.infer<typeof RegisterSchema>, RegisterBody>
>;
```

Rename a field in Laravel, regenerate, and **this file stops compiling**. A backend contract
change becomes a client compile error rather than a runtime 422 discovered by a user.

Reach the contract through `paths`, not through `components['schemas'][...]`. Both resolve to
the same type, but the `paths` form additionally pins the URL, the HTTP method and the media
type — so moving an endpoint breaks the proof instead of passing it.

### What the helpers in `src/validation/assignability.ts` actually do

| Helper | Behaviour |
|---|---|
| `Expect<T extends true>` | Fails to compile unless `T` is exactly `true`. The assertion mechanism. |
| `Assignable<A, B>` | `true` when `A` is assignable to `B` — **except** that `any` on either side answers `false`. |
| `Equivalent<A, B>` | `true` only when `A` and `B` are *mutually* assignable, in `Assignable`'s sense. |
| `WireShape<T>` | `T` rewritten into a canonical JSON wire form (below). |
| `WireEquivalent<A, B>` | `Equivalent<WireShape<A>, WireShape<B>>`. **This is the one to reach for.** |

`WireShape` canonicalises two things, both measured rather than anticipated:

1. **`undefined` is dropped from an optional property's value type.** `openapi-typescript` 7.13
   emits `locale?: string`; Zod 4's `.optional()` infers `locale?: string | undefined`. Under
   `exactOptionalPropertyTypes` those are **not** mutually assignable, so without this step a
   *correct* schema raises TS2344 against a *correct* contract — a difference in representation
   rather than drift, which would train the next person to delete assertions. The step is sound
   rather than merely convenient because `undefined` is not a JSON value: `JSON.stringify` omits
   such a property, so the two describe the same bytes. **`null` is a JSON value and is not
   stripped** — a nullable field is a different contract from a non-nullable one.

2. **Every property is then made required with `-?`, and the optionality it carried moves into
   the value type as `| undefined`.** This is the subtle half. Two object types that differ only
   in the *name* of an optional property are mutually assignable:
   `{ id: string; locale?: string }` and `{ id: string; locale_code?: string }` are each
   assignable to the other, because neither optional key must be present and excess-property
   checking does not apply outside fresh object literals. Measured, not assumed — an earlier
   version stopped at step 1 and let a renamed `locale` through. Making every key required forces
   an exact key-set comparison; **moving optionality into the value type keeps it checkable**,
   because `string | undefined` and `string` are not mutually assignable, so an optional field
   made required still fails, **and so does a required field made optional**.

Both normalisations recurse, so a rename nested inside `SessionResource.user` fails too.

`WireEquivalent` is built on `Equivalent` rather than `Assignable` and that choice is
load-bearing: one-way assignability is genuinely *true* when a schema makes an optional contract
field required (`{ a: string }` is assignable to `{ a: string | undefined }`), so the one-way
form cannot detect drift in the direction that makes a client send a field the server never
asked for.

`any` is the one input the harness **refuses** rather than describes. `any` is assignable to
everything and everything is assignable to `any`, so an unguarded `[A] extends [B]` answers
`true` in *both* directions the moment either side is `any` — every proof standing on it passes
while proving nothing. That is vacuous rather than wrong, which is harder to notice because it
reports as a green gate. `Assignable` catches the whole-type case with an `IsAny` guard;
`WireShape` catches the one-`any`-*property* case, which `IsAny` cannot see, by mapping it to a
`unique symbol`-branded type. There is no `any` in `src/types/api.ts` today — this guards the
version of that file that acquires one.

### `src/validation/proof.ts` is the regression test for the harness

Positive assertions alone cannot prove a type-level guard still guards: if `Expect` lost its
`extends true` constraint, every positive assertion in the project would keep compiling. So
`proof.ts` holds **negative** assertions written with `@ts-expect-error`, which **fail when the
line below them stops erroring.** That inversion is the only thing that makes the file a real
regression test rather than decoration.

**`proof.ts` is the only place in this project where `@ts-expect-error` is permitted**, because
a negative type-level assertion has no other way to be checkable. (It is also the only place
`@typescript-eslint/no-explicit-any` is disabled, for the `any` fixtures.) Two mechanical
details if you add a case: each `@ts-expect-error` suppresses only the line immediately after
it, so fixtures are **named** rather than written inline — Prettier wrapping a long `Expect<...>`
onto a second line would silently turn the assertion into an "unused directive" report.

### What the harness does *not* prove — its real blind spot

**Every value constraint is erased.** `openapi.json` records `maxLength: 254`, `minLength: 64`
and `format: email`; `openapi-typescript` keeps only enums, so `email` and `display_name` are
both bare `string` in `src/types/api.ts`. Changing `max:254` to `max:320` upstream therefore
fails **nothing** at the type level, however strict `WireEquivalent` is.

Those lengths, caps and enums are checked against `openapi.json` by a **runtime** test instead —
"the contract's own constraints" in `src/validation/auth.test.ts` — because that document is
where the constraints actually live. If you add a schema, add both halves.

### One more thing the schemas encode: Laravel's global middleware

Several schemas carry a `.trim()` that no `rules()` array mentions, and its **position is
load-bearing**. `TrimStrings` and `ConvertEmptyStringsToNull` are in Laravel's *global*
middleware stack, so they transform every `api/*` request before any rule runs — a schema that
mirrors `rules()` alone diverges from the API, and did, three measured ways. Zod applies string
checks in declaration order: `z.string().min(1).trim()` accepts `'   '` and returns `''`, while
`z.string().trim().min(1)` rejects it. `.trim()` is written **first** throughout, so every cap
and shape check sees the value the server would see.

`password` is the one string field left untrimmed, and that is measured too: `TrimStrings`
`$except` lists `password`, so the server hashes exactly what was sent. Trimming it here would
*be* the divergence — a client that trimmed would register one password and sign in with
another.

---

## Repository conventions

- `src/types/api.ts` is generated. Never hand-edit it; it is excluded from ESLint and Prettier
  and gated by `npm run types-check`.
- `openapi.json` is a copy of `tandem-api`'s and is Prettier-ignored. Update it with
  `npm run sync`, never by hand.
- Prettier: `semi`, `singleQuote`, `trailingComma: all`, `printWidth: 100`.
- No `any`, no `@ts-ignore`, no `@ts-nocheck`. `@ts-expect-error` only in `proof.ts`.
- `.env` files are never committed anywhere in this project; this repo needs none.
