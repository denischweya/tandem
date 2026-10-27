# tandem-web

The Next.js 16 (App Router) web client for **Tandem**, a private-by-default shared planning
app. It is also where web session cookies will live: per **ADR 0004**, `tandem-api` speaks
bearer tokens only and never sets a cookie, so Next route handlers under `/api/auth/*` act as
a thin **backend-for-frontend** that holds the token pair as `HttpOnly; Secure; SameSite=Lax`
cookies and browser JavaScript never handles a token.

> **State of the BFF, measured 2026-10-11: built.** `src/server/` now holds the eight route
> handlers' logic (`handlers.ts`), the cookie policy (`cookies.ts`), the Origin-based CSRF
> check (`csrf.ts`), the error vocabulary (`errors.ts`), the single outbound caller
> (`apiFetch.ts`) and the server-only `API_BASE_URL` read (`env.ts`, which replaces the
> deleted `src/lib/env.ts`). `src/app/api/auth/*` wires each handler to a route.
> `src/lib/env.ts` is gone; the one place `API_BASE_URL` is read is `src/server/env.ts`, and
> it is no longer a `NEXT_PUBLIC_*` variable - the browser never calls the API directly under
> ADR 0004, so nothing needs that value inlined into the client bundle. `vitest.config.mts`'s
> comments, written in anticipation of this, now describe the real thing.

---

> **Correction, 2026-10-27, per ADR 0010:** `tandem-api`, `tandem-shared` and this repo
> (`tandem-web`) were merged into one repository, `tandem`. The five-repository table and the
> private-Git-dependency / PAT setup instructions below describe the pre-merge world and no
> longer apply: `@tandem/shared` is now an npm workspace member (`packages/shared`), installed
> by a single root `npm ci` with no GitHub credentials needed for it, and CI is
> `.github/workflows/web-ci.yml`, not a separate `tandem-web` workflow. See ADR 0010 for the
> full picture.

## Read this first

### 1. All five repositories are private

`git clone` fails until your GitHub invitation has been accepted — **and so does
`npm install`**, because `@tandem/shared` is a private Git dependency. See
[Installing](#installing) for what you need configured.

### 2. `main` is nearly empty — the work is on `feature/guest-invite`

```bash
git log --oneline main -3         # a single commit: "Initial commit: README and gitignore"
git branch -a                     # main, wave-0-foundation, feature/guest-invite
git checkout feature/guest-invite  # every page, component and test
```

A developer who clones and reads `main` will conclude the project is empty.
`wave-0-foundation` is an ancestor of `feature/guest-invite`, so the feature branch is the one
to use.
The same is true of `tandem-mobile` (`feature/home-dashboard`), `tandem-api` and
`tandem-shared` (both `wave-0-foundation`).

---

## How this fits — the five repositories

Cloned side by side, all under the `denischweya` GitHub account. All five are private.

| Repo                         | Owns                                                                                                                                     | Work is on               |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------ |
| `tandem-docs`                | Blueprint, specs, ADRs, plans, reviews. Authoritative for _why_.                                                                         | `main`                   |
| `tandem-api`                 | Laravel 13 / PHP 8.4 API. Source of truth for the contract; bearer tokens only.                                                          | `wave-0-foundation`      |
| `tandem-shared`              | Raw TypeScript: types generated from the API's `openapi.json`, hand-written Zod schemas proved against them, timezone-safe date helpers. | `wave-0-foundation`      |
| `tandem-mobile`              | Expo SDK 57 / React Native client. Sibling of this repo.                                                                                 | `feature/home-dashboard` |
| **`tandem-web`** (this repo) | Next.js 16 App Router client, and the BFF cookie layer (ADR 0004).                                                                       | `feature/guest-invite`   |

---

## Installing

Node **22** — pinned in `.nvmrc`, which CI reads via `node-version-file` so a developer's
runtime and CI's cannot drift. This is not cosmetic: Vitest's config loader needs
`require(ESM)`, which Node 20.18 lacks and Node 22 has, so a broken config would be green in
CI and unloadable locally. Run `nvm use` before anything else.

```bash
nvm use        # Node 22, from .nvmrc
npm ci
```

### `npm ci` needs GitHub authentication

`package.json` pins:

```json
"@tandem/shared": "github:denischweya/tandem-shared#8ed9d48"
```

**`tandem-shared` is private**, so this install is a credentialed Git fetch. The lockfile
records the resolution as `git+ssh://git@github.com/denischweya/tandem-shared.git#8ed9d48...`
regardless of the specifier form, so npm attempts SSH and falls back to HTTPS. Either path has
to be authenticated. Configure **one** of:

- `gh auth login` (installs a git credential helper for `github.com`), or
- an SSH key on your GitHub account with `ssh -T git@github.com` succeeding, or
- a personal access token in a git credential helper, scoped to read `tandem-shared`.

Without one of those, `npm ci` fails at the fetch with an authentication or "repository not
found" error — which reads like a typo in the specifier and is not.

### CI needs a token for a _second_ private repository, and does not have one

**Measured 2026-10-10: `.github/workflows/ci.yml` has no such token configured.** It runs
`actions/checkout@v4`, then `npm ci`, with no SSH-agent step, no `NODE_AUTH_TOKEN`, and no
secret of any kind.

A workflow's default `GITHUB_TOKEN` is scoped to **its own repository only**, so it cannot
fetch `tandem-shared`. The last green run here was 2026-10-09, while `tandem-shared` was still
public and npm could fall back to anonymous HTTPS. **Now that it is private again, `npm ci` in
CI will fail** until the workflow is given credentials — a fine-grained PAT or GitHub App token
with read access to `tandem-shared`, or a read-only deploy key plus an ssh-agent step.

ADR 0002 records the mechanism that was in place the last time `tandem-shared` was private, and
one detail in it fails silently if missed: with `webfactory/ssh-agent`, the deploy key's
**comment must be the repository URL**, because that is what makes the action install the scoped
`insteadOf` rules that cover the `ssh://` form the lockfile uses.

### Do not reach for `--legacy-peer-deps`

It hides the real conflict, and in this project it was measured to prune Expo Router's peers
and break Metro in the sibling repo. Fix the specific conflict instead.

### Do not commit a `file:` override

Only the `github:owner/repo#ref` form may appear in a committed `package.json` (ADR 0002, and
the Wave 1b plan's global constraints). A local `file:` override against an unpublished
`tandem-shared` change must never be committed. Noted honestly: **nothing mechanically enforces
this** — ADR 0002 claims "CI rejects a non-tag specifier" and no such check exists in any
workflow in this project. It is a review responsibility.

The ref is a **commit**, deliberately, never a branch. It moves to the `v0.2.0` tag before this
wave merges; `tandem-shared`'s README explains why the tag cannot be cut yet. If you do change
it, pass the specifier to install directly — `npm pkg set` plus a bare `npm install` is a
**silent no-op** for a Git dependency (measured on npm 10.8.2: `package.json` said one ref while
the installed package stayed at another, and nothing reported it):

```bash
npm install '@tandem/shared@github:denischweya/tandem-shared#<ref>'
```

---

## Running

```bash
cp .env.example .env.local   # API_BASE_URL
npm run dev                  # http://localhost:3000
```

`.env.example` is the only environment reference that is committed; **never commit a real
`.env` file** (`.gitignore` ignores `.env*` with an exception for `.env.example`). The default
when the variable is unset is `http://localhost:8000/api/v1`, which is what `make api` serves
in `tandem-api`.

`next.config.ts` sets `transpilePackages: ['@tandem/shared']`, which is what lets this app
consume that package's **raw TypeScript** (it ships no build), and `reactStrictMode: true`.

| Script                 | What it does                                    |
| ---------------------- | ----------------------------------------------- |
| `npm run dev`          | `next dev`                                      |
| `npm run build`        | `next build`                                    |
| `npm run start`        | `next start`                                    |
| `npm run typecheck`    | `next typegen && tsc --noEmit`                  |
| `npm run lint`         | `eslint . --max-warnings 0`                     |
| `npm run format-check` | `prettier --check .`                            |
| `npm run test`         | `vitest run`                                    |
| `npm run e2e`          | `playwright test` — **builds first**, see below |
| `npm run verify`       | the gate                                        |

Installed versions: Next 16.3.8, React 19.2.8, TypeScript 5.9.3, Vitest 4.1.11,
`@playwright/test` 1.61.1, Zod 4.6.5.

---

## The gate

```bash
npm run verify    # typecheck → lint → format-check → test → build
```

`npm run verify` is the **single definition of the gate**, so the local and CI definitions
cannot drift. CI is `npm ci && npm run verify`, **plus** two e2e steps:

```yaml
- run: npm ci
- run: npm run verify
- run: npx playwright install --with-deps chromium
- run: npm run e2e
```

So, precisely: `verify` is the gate for everything except the end-to-end suite, which CI runs
separately because it needs a browser download. **`npm run e2e` is not part of `verify`** — run
it yourself before you claim a change is done. (In the sibling `tandem-mobile`, CI really is
`npm ci && npm run verify` and nothing else.)

Do not quote a test count here — run `npm run verify`.

---

## TypeScript rules that must not be relaxed

### TypeScript is pinned to exactly 5.9.3

Not `^5.9.3`. `openapi-typescript@7.13.0` — used in `tandem-shared`, which this repo consumes
as raw source — declares a **non-optional** peer dependency on `typescript@^5.x` (verified: no
`peerDependenciesMeta`), so **6.x aborts `npm install` and `npm ci` with ERESOLVE**. TypeScript
7 is a compiler rewrite, and this project's anti-drift guarantee rests on exact type-
assignability semantics across Next, Expo, ESLint and `openapi-typescript`; taking a rewrite
underneath that mechanism trades real risk for no present benefit. Revisit after Cycle 1, as a
deliberate upgrade with the assignability proofs as the acceptance gate.

### `exactOptionalPropertyTypes` and `noUncheckedIndexedAccess` are on, and must stay on

Both are set in `tsconfig.json` and are also on in `tandem-shared` and `tandem-mobile` (spec
§14.1, review finding I6). `@tandem/shared` ships **raw TypeScript**, so its
Zod-vs-generated-type assignability harness is typechecked by **this repo's** compiler, not its
own — a laxer option here makes the same assertion pass in `tandem-web` while failing in
`tandem-shared`'s CI, for identical code. `exactOptionalPropertyTypes` matters most: it changes
assignability for optional properties, which is most of an OpenAPI request body.

Centralising them in one base `tsconfig` was considered and rejected: `@tandem/shared` is a
pinned Git dependency, not a workspace, and only its `files` allowlist is installed, so no
`tsconfig` ships with it. Hence the explicit duplication in all three repos.

> **A measured subtlety, because it has produced wrong code twice.**
> `noUncheckedIndexedAccess` widens index **signatures**, not a `Record` over a literal key
> union. Verified against the installed TypeScript 5.9.3: `Record<'a'|'b', number>` indexes to
> `number`, while `{ [k: string]: number }` indexes to `number | undefined`. So defensive
> narrowing added for a `Record` over a literal union is **dead code** — it cannot be reached.
> Narrowing _is_ required for a `Record<string, T>` lookup table or a computed array index; see
> the comments in `src/lib/format-list.ts` and `src/lib/invite/format.ts`, both of which assert
> a bound explicitly for exactly that reason.

---

## Tests

### Unit tests — Vitest, `src/**/*.test.{ts,tsx}`

`vitest.config.mts` is short, and every line in it is load-bearing. Read its comments before
changing it:

- **`environment: 'node'`, deliberately.** The BFF handlers ADR 0004 calls for take and return
  web-standard `Request`/`Response` and will not import `next/headers`, so they need no DOM and
  no Next request scope — `cookies()` is async and throws outside a request scope, which would
  make every handler test a mock of the framework instead of a test of the handler.
  **Neither `jsdom` nor `happy-dom` is installed** (verified), so the first render test added
  under this config fails at `document is not defined` rather than being quietly skipped.
  Install one and set `environment: 'jsdom'` (or an `environmentMatchGlobs` entry) **in the
  task that writes the first render test**, not before.
- **`include: ['src/**/*.test.{ts,tsx}']` — `{ts,tsx}`, not `ts`.** A `.test.tsx` file under a
  `.test.ts`-only glob is not reported as skipped; it is **never collected at all**, so the
  suite goes green and the count simply never mentions it. `src/test/runner.test.ts` pins
  this glob by reading the config file as text, so narrowing it fails loudly.
- **There is no `passWithNoTests`, and adding one back is forbidden.** It was in this file for
  exactly as long as the repo had no test, and it makes `npm run test` — and therefore
  `npm run verify` — pass over **zero assertions**. `src/test/runner.test.ts` is the
  mechanical bound that replaced it: the runner now always has real work, so Vitest exits
  non-zero if the suite ever becomes empty again.

`src/test/runner.test.ts` is this repo's **toolchain sentinel** (moved out of
`src/architecture/` in Wave 1b's final review — see below for what that directory actually
holds now). It asserts the runner executes a real assertion, that the `@` alias from
`vitest.config.mts` resolves, and that the config still collects `.tsx`. If it goes red, read
it before working around it.

`src/architecture/` holds this repo's first real **architecture gate**,
`envOwnership.test.ts`: `process.env` is read only in `src/server/env.ts`, and the
`NEXT_PUBLIC_` identifier — Next's own client-bundle-inlining prefix, and the exact class
`src/lib/env.ts`'s deletion fixed (review finding m6) — appears nowhere else in the tree.
Before this file existed, nothing stopped a future change from re-adding a
`NEXT_PUBLIC_API_BASE_URL` read and silently reopening that leak. It is a deliberately narrow
gate, not a port of `tandem-mobile`'s eight-gate suite — see the file's own docblock for what
it does not cover and why.

### End-to-end — Playwright, `e2e/`

```bash
npx playwright install --with-deps chromium   # once
npm run e2e
```

`playwright.config.ts`:

- **Port 3100, not 3000, and deliberately no `reuseExistingServer`.** With reuse enabled and a
  `next dev` already on :3000, the suite goes green in about 1.4 seconds **without
  `npm run build` ever running**, and nothing in the output hints at it. The smoke test exists
  to guard the **production build**, so it must always build. The dedicated port lets your dev
  server keep running instead of forcing you to kill it. The server command is
  `npm run build && PORT=3100 npm run start`, so expect the first run to take a while.
- **`forbidOnly` when `CI` is set.** A committed `test.only` would otherwise run just that one
  test, exit 0, and silently skip the rest of the suite.

`e2e/smoke.spec.ts` proves `@tandem/shared` is actually bundled and executed during the server
render; its own comment is careful that `/` is statically prerendered, so the test proves the
shared code ran **during the build**, not on every request. `e2e/invite.spec.ts` covers the
guest-invite page, including keyboard reachability of the RSVP controls. There is no invite API
yet, so it exercises the one token the mocked lookup in `src/lib/invite/fixture.ts` resolves.

---

## Layout

```
src/
├── app/                      App Router
│   ├── page.tsx              landing page; renders the API target and a Nairobi clock
│   ├── layout.tsx
│   ├── globals.css
│   ├── api/auth/*/route.ts   eight three-line handlers, each re-exporting src/server/handlers.ts
│   └── invite/[token]/       the guest-invite page (spec §11, screen 3j)
├── architecture/             envOwnership.test.ts - the real architecture gate (see above)
├── components/
│   ├── invite/               InviteHeader, PlanSummary, RsvpResponse, SignupPrompt
│   └── ui/                   Avatar, Button
├── server/                   the BFF (ADR 0004) - see its own module docblocks
│   ├── env.ts                the one place API_BASE_URL is read (server-only)
│   ├── cookies.ts             tandem_at / tandem_rt: Max-Age, Path, HttpOnly/Secure/SameSite
│   ├── csrf.ts                Origin + Sec-Fetch-Site check for every mutating route
│   ├── errors.ts              the error envelope and its user-facing copy rules
│   ├── apiFetch.ts            the single outbound caller to tandem-api
│   ├── handlers.ts            the eight (Request) => Promise<Response> handlers
│   └── session.ts             getSession() for a Server Component render
├── lib/
│   ├── session/               refreshSession() (the browser's one refresh initiator),
│   │                          useSession()/SessionProvider, the query client
│   ├── auth/                  fields, redirect, copy, timezone, the BFF's browser-side client
│   ├── format-list.ts
│   └── invite/                fixture + formatting
├── test/                     runner.test.ts (the toolchain sentinel), fixtures, setup
e2e/                          Playwright specs
```

Styling is CSS Modules, with shared values in `src/components/invite/design-tokens.module.css`.

`AGENTS.md` and `CLAUDE.md` are **gitignored on purpose** in this repo: `next dev` regenerates
them, and this project keeps its agent conventions in `tandem-docs`. The ignore entry stops a
routine `git add -A` from silently re-staging files that were deliberately deleted.
