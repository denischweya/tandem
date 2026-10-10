# tandem-api

The Laravel 13 / PHP 8.4 API for **Tandem**, a private-by-default shared planning app, and
the **source of truth for the API contract**: `openapi.json` at the repo root is generated
from this codebase and consumed downstream by `tandem-shared`.

The API speaks **bearer tokens only**. It never sets a cookie — see
[Authentication](#authentication) below.

---

## Read this before you do anything else

Four things in this repo will cost you hours if you discover them by breaking something. Two
of them can destroy a database.

### 1. All five repositories are private

`git clone` fails until your GitHub invitation has been accepted. That applies to this repo
and to the four siblings.

### 2. `main` is nearly empty — the work is on `wave-0-foundation`

```bash
git log --oneline main -3      # a single commit: "Initial commit: README and gitignore"
git branch -a                  # main, wave-0-foundation
git checkout wave-0-foundation # the entire API lives here
```

A developer who clones and reads `main` will conclude the project is empty. It is not; `main`
has simply not received the foundation branch yet. (The same is true of `tandem-shared`,
`tandem-mobile` and `tandem-web`.)

### 3. PHP is `/opt/homebrew/opt/php/bin/php` — never bare `php`

Measured on this machine:

```
/opt/homebrew/opt/php/bin/php -v   →  PHP 8.4.2
php -v                             →  PHP 7.4.33
```

`composer.json` requires `php: ^8.3`, and Pest 5 requires `^8.4`, so 7.4 is not a
near-miss — it is a hard floor. Bare `php` fails confusingly (parse errors in vendor code,
not a clear version message).

**Never relink Homebrew PHP.** Other projects on this machine depend on the linked 7.4.

Composer is that binary plus the Composer phar — `/opt/homebrew/opt/php/bin/php
/usr/local/bin/composer ...`. Never invoke bare `composer`. The `Makefile` already wires both
(`PHP := /opt/homebrew/opt/php/bin/php`, `COMPOSER := $(PHP) /usr/local/bin/composer`), so
**use the Make targets and you cannot get this wrong.**

### 4. ⚠️ Never run `artisan optimize` or `artisan config:cache`

**This is the most destructive trap in the project, and it has already destroyed a database
once.**

Once `bootstrap/cache/config.php` exists, Laravel's `LoadConfiguration::bootstrap()` requires
that file and **never calls `loadConfigurationFiles()`** — so no config file evaluates `env()`
at all, and every `<env>` pin in `phpunit.xml` becomes inert. `DB_DATABASE` is one of those
pins. `config/database.php` then resolves whatever `.env` names instead, which is the **real
development database**. `RefreshDatabase`'s `migrate:fresh` runs against it and wipes it —
**while the suite reports success.**

If something misbehaves and you suspect a cached config, the fix is:

```bash
/opt/homebrew/opt/php/bin/php artisan config:clear
```

Never commit `bootstrap/cache/config.php`. Two guards back this up, and neither is a reason to
relax the rule:

- `tests/TestCase.php::guardTestDatabase()` refuses to run the suite at all — it checks
  `config_loaded_from_cache`, then that the driver is `pgsql` (the project's own historical
  "silently running on SQLite" failure), then that the resolved database is `tandem_test`, and
  gives a *different* message for "database missing" so you are not sent looking in the wrong
  place. It runs in `setUpTraits()`, the latest point at which it can still precede
  `RefreshDatabase`'s wipe.
- `bin/generate-openapi.sh` refuses to generate at all if that file exists, because a cached
  config silently nullifies its environment pins *and* leaves the environment-seal test green.
- `make test` depends on `config-clear` for the same reason, as does `composer test`.

A further hazard the suite guard does **not** cover: destructive `artisan` commands run from
the CLI. `artisan migrate:fresh --env=testing` only targets the test database because the
tracked `.env.testing` exists and sets `DB_DATABASE=tandem_test`. Before that file existed, a
bare `artisan migrate:fresh --env=testing` fell back to `.env` and wiped the development
database. The guard runs inside PHPUnit's bootstrap; it protects the suite, not your shell.

`make fresh` (`migrate:fresh --seed`) is likewise destructive against whatever `.env` names.

---

## How this fits — the five repositories

Cloned side by side, all under the `denischweya` GitHub account. All five are private.

| Repo | Owns | Work is on |
|---|---|---|
| `tandem-docs` | Blueprint, specs, ADRs, plans, reviews. Authoritative for *why*. | `main` |
| **`tandem-api`** (this repo) | The Laravel API; generates `openapi.json`. | `wave-0-foundation` |
| `tandem-shared` | Raw TypeScript for both clients: types generated from this repo's `openapi.json`, hand-written Zod schemas proved against them, date helpers. | `wave-0-foundation` |
| `tandem-mobile` | Expo SDK 57 / React Native client. | `feature/home-dashboard` |
| `tandem-web` | Next.js 16 App Router client, and (ADR 0004) the BFF that holds session cookies. | `feature/guest-invite` |

A contract change is therefore a **three-repository operation**: merge here, re-sync and tag
`tandem-shared`, bump the pinned ref in each client. There is no atomic cross-repo commit.
That cost is accepted deliberately in ADR 0002.

Architecture, schema and endpoint decisions live in `tandem-docs/docs/specs/` and
`tandem-docs/docs/decisions/`. Check there before assuming behaviour. `CLAUDE.md` in this
directory is the short form of the constraints above.

---

## First-time setup

Requires Docker (Postgres 17, Redis 7 and Mailpit run in containers — do not install database
software locally) and Homebrew PHP 8.4 at the path above.

```bash
make install    # composer install, via the pinned PHP + Composer phar
make setup      # composer install, cp -n .env.example .env, artisan key:generate
make up         # start postgres, redis, mailpit; waits and prints `docker compose ps`
make migrate    # run migrations against the development database
make test-db    # create the tandem_test database if it does not exist
```

Notes that matter:

- `make setup` deliberately does **not** migrate: migrating needs Postgres up, which setup
  must not assume. It uses `cp -n`, so it will not clobber an existing `.env`.
- `.env` is never committed. Copy `.env.example`; it is the only reference. `.env.testing` *is*
  tracked, and exists so that `--env=testing` cannot fall back to `.env`.
- `.env.example` ships a blank `APP_KEY`, which is why `make setup` generates one. The test
  suite does not depend on it: `phpunit.xml` pins a throwaway `APP_KEY` deliberately and
  publicly, because Sanctum tokens and signed URLs need *a* real key and that key secures
  nothing but a disposable database.
- `docker/postgres/init/01-create-test-database.sql` creates `tandem_test` on **first** volume
  initialisation only. `make test-db` is the idempotent fallback for an existing volume.

## Day to day

Every target below exists in the `Makefile`. There are no others.

| Target | What it does |
|---|---|
| `make install` | `composer install` |
| `make setup` | install, create `.env` if missing, generate `APP_KEY` |
| `make up` / `make down` | start / stop postgres, redis, mailpit |
| `make migrate` | `artisan migrate` |
| `make fresh` | `artisan migrate:fresh --seed` — **destructive** |
| `make api` | `artisan serve --host=0.0.0.0 --port=8000` |
| `make queue` | `artisan queue:work --queue=high,default,calendar,notifications,sync,low` |
| `make config-clear` | `artisan config:clear` — the fix for a cached config |
| `make test` | `config-clear`, then `vendor/bin/pest` |
| `make test-db` | create `tandem_test` if missing |
| `make stan` | `phpstan analyse --memory-limit=1G` (level 8) |
| `make lint` / `make lint-fix` | Pint, check-only / rewriting |
| `make openapi` | regenerate `openapi.json` from the code |
| `make openapi-check` | fail if the committed `openapi.json` has drifted |
| `make verify` | **the gate** — see below |
| `make verify-ci` | what CI runs; `verify` minus the two steps a runner cannot provide |

`make test` takes no arguments. To run one file:

```bash
/opt/homebrew/opt/php/bin/php vendor/bin/pest tests/Feature/Auth/LoginTest.php
```

Mailpit's web UI is on <http://localhost:8025>; the API serves on <http://localhost:8000>.

## The gate

```bash
make verify     # lint → stan → openapi-check → test-db → test
```

**`make verify` is the command that must pass before anything is considered done**, not
`make test` alone — `make test` skips the OpenAPI drift check and does not guarantee
`tandem_test` exists.

`make verify-ci` is the same gate with two steps removed, and is what
`.github/workflows/ci.yml` actually invokes (`make verify-ci PHP=php`). It drops the `COMPOSER`
variable (which hardcodes a path no runner has) and `test-db` (which shells into
`docker compose`, which CI's `services:` block does not provide — CI creates the test database
with `psql` two steps earlier). **The point is that there is one definition of the gate**: CI
used to re-implement lint, stan and test inline, which let the local and CI definitions drift
(review finding I10). Do not reintroduce an inline step in the workflow.

`PHP=php` in that invocation is a command-line assignment, which GNU Make exports to every
recipe's environment — so it overrides both the `Makefile`'s `PHP` variable and
`bin/check-openapi.sh`'s own `${PHP:-...}` default in one go. That is why
`bin/check-openapi.sh` reads `PHP` from the environment rather than taking it as an argument.

CI status, measured 2026-10-10: this repo's workflow is **green** on `wave-0-foundation`.

### Static analysis

PHPStan runs at **level 8** over `app`, `database`, `routes` and `tests` (`tests/Architecture/*`
is excluded — a pre-approved deviation). Do **not** lower the level, add a baseline, add
`@phpstan-ignore`, or exclude paths to make analysis pass.

`phpstan-rules/src/DomainPurity/` holds three custom rules registered in `phpstan.neon`. They
exist because the Pest architecture rule they back up was measured to have real gaps: of eight
root facade aliases tried with a leading backslash (`\DB::`, `\Log::`, `\Hash::`, `\Redis::`,
`\Http::`, `\Storage::`, `\Cache::`, `\Auth::`), **six passed the Pest rule clean**, and
`pest-plugin-arch`'s walker does not count a PHP *internal* class as a use at all — so
`toOnlyUse()` could not restrict `DateTime`, `ArrayObject` or `Closure` either. The PHPStan
rules match the literal written name rather than trusting what it resolves to.

### The OpenAPI document is byte-gated

`openapi.json` is compared for **byte equality**, so generation must not depend on the machine
doing it. `bin/generate-openapi.sh` pins `APP_NAME`, `APP_ENV`, `APP_URL`, `APP_LOCALE`,
`APP_FALLBACK_LOCALE` and `AUTH_MODEL` as command-scoped assignments for exactly that reason —
Wave 0 found `info.title` leaking through `ui.title → config('app.name') → env('APP_NAME')`,
so a fresh clone with no `.env` generated `"title": "Laravel"`. Two further leaks go live the
moment a route exists under `api/v1`, which it now does: `config('app.locale')` (read through
Scramble's `TranslatedStringType`) and `config('auth.providers.users.model')` (read by
Scramble's `RequestExtension`).

**Nothing environment-derived may reach `openapi.json`.** After any contract change:

```bash
make openapi    # regenerate
git diff openapi.json
```

…and commit the result, or `make verify` fails.

---

## Architecture

Pragmatic hexagonal layering — **ADR 0001** in `tandem-docs` is authoritative.

```
app/
├── Domain/          pure: value objects, enums, policies, calculators
├── Application/     use cases: one class, one execute()
├── Infrastructure/  adapters: token issuance, mail, outbox, OpenAPI transformers
├── Http/            controllers, form requests, API resources, rules, support
├── Models/          Eloquent models
├── Console/          artisan commands
└── Providers/       service container wiring
```

| Directory | What belongs in it |
|---|---|
| `app/Domain/` | Value objects (`Email`, `DisplayName`, `TimeZoneId`), enums (`PlanStatus`, `MemberRole`), pure policy classes (`RotationPolicy`), and the clock abstraction. Rules take value objects and enums, **never models**. |
| `app/Application/` | One use-case class per operation with a single `execute()` (`Login`, `RefreshSession`, `DeleteAccount`), the exceptions those use cases throw, and any repository interface whose signature names an Eloquent model. |
| `app/Infrastructure/` | Everything that talks to the outside: `RefreshTokenIssuer`, `LoginRateLimiter`, Mailables, the outbox writer/dispatcher, Scramble transformers. |
| `app/Http/` | Controllers (which call use cases), form requests, API resources, custom validation rules. Controllers hold the HTTP shape and nothing else. |
| `app/Models/` | Eloquent models. Repositories return Eloquent models deliberately (ADR 0001); the purity rule lives at the `Domain/` boundary, not at the ORM. |
| `app/Console/Commands/` | `outbox:dispatch` and `users:purge-deleted`, both scheduled in `routes/console.php`. |

**The two layering rules, enforced mechanically:**

1. **`app/Domain/` imports nothing from the framework** — not `Illuminate\`, not `Laravel\`,
   not any vendor SDK, and not `App\Models`, with no exception. Enforced by
   `tests/Architecture/DomainPurityTest.php` (an allow-list, `toOnlyUse`, not a deny-list) *and*
   the three custom PHPStan rules, for the measured reasons above.
2. **Controllers never touch Eloquent** — `arch('controllers touch no Eloquent and no
   persistence')` asserts `App\Http\Controllers` does not use
   `Illuminate\Database\Eloquent`, `App\Models` or `App\Infrastructure`.

Two more arch rules are worth knowing before you trip them: **models may not reach into
`App\Application`** (the reverse edge is a layering inversion and a class-level cycle —
`User::markEmailAsVerified()` once called `app(VerifyEmail::class)->execute($this)`), and
**everything under `App`, `Database` and `Tests` must declare `strict_types`**.

### Authentication

**Bearer tokens only. The API never sets a cookie.** ADR 0003 and ADR 0004 are authoritative.

- Sanctum issues a 15-minute access token. Refresh tokens are a first-party table: 256 bits of
  randomness, stored hashed, single-use, 30-day expiry, bound to a device row.
- `POST /auth/refresh` validates the hash, checks expiry and revocation, issues a new pair and
  sets `replaced_by_id` on the consumed row. **Replaying an already-rotated refresh token
  revokes the whole chain** — the legitimate client and an attacker cannot both rotate the same
  row, so a second use is evidence of compromise.
- `POST /auth/refresh` is deliberately **unauthenticated**: the whole point is that the access
  token has expired. The refresh token is the credential and replay detection is the protection.
- **Sanctum's stateful SPA cookie mode is explicitly rejected.** Cookie handling lives entirely
  in `tandem-web`: Next route handlers under `/api/auth/*` act as a thin backend-for-frontend,
  call this API, receive the token pair and set it as `HttpOnly; Secure; SameSite=Lax` cookies,
  so browser JavaScript never holds a token. **One authentication path in the API, exercised
  identically by both clients**, so an auth bug cannot manifest on only one platform. (That BFF
  is specified in ADR 0004 and not yet implemented — no `src/server/` exists in `tandem-web`
  today. It changes nothing here: this API's job is to stay cookie-free.)
- `routes/web.php` is deliberately **empty**. The file is kept only because
  `bootstrap/app.php`'s `withRouting(web: ...)` expects it to exist; deleting the framework's
  generated welcome route is the point, because with `APP_DEBUG=false` it served Laravel's exact
  version number unauthenticated to anyone. `tests/Feature/WelcomePageTest.php` guards that.

Three auth exceptions (`InvalidCredentials`, `InvalidRefreshToken`, `InvalidPasswordResetToken`)
are rendered as fixed JSON bodies and are in `dontReport()`: they are expected control flow on a
public surface whose rate scales with traffic, not with bugs, and logging them at ERROR buried
the real security signal. A client must branch on **which endpoint answered** a 401, not on the
status: `/auth/login` means a form error, `/auth/refresh` means sign out and never refresh
again, and `/me` or `/auth/logout` means refresh once and retry.

### Routes

Registered in `bootstrap/app.php`. `routes/api/v1/{auth,me,ops,plans}.php`:

- `POST /api/v1/auth/{register,login,verify-email,refresh,logout,forgot-password,reset-password}`
- `GET|PATCH|DELETE /api/v1/me`
- `GET /health`, `GET /ready` — **at the root, not under `/api/v1`**, so monitoring does not
  have to know the API version.
- `routes/api/v1/plans.php` is an intentional empty stub; the plans surface is Wave 2.

`DELETE /me` is deliberately *not* behind the `verified` middleware: App Store Review Guideline
5.1.1(v) requires in-app account deletion, and an unverified account is exactly the one most
likely to want deleting.

Rate limiting is split on purpose. `/auth/register` and `/auth/forgot-password` carry route-level
`throttle:` middleware because spec §8.2 counts *requests* for them. `/auth/login` does **not**:
§8.2 counts login by *failed attempt*, and middleware decides before the controller runs, before
the outcome is known — so `LoginRateLimiter` is applied from inside `Login::execute()` instead.

---

## Cycle 1 is under a migration freeze

**Every migration this cycle needs already exists.** `database/migrations/` holds seven files,
all written up front in Wave 0:

```
0001_01_01_000001_create_cache_table.php
0001_01_01_000002_create_jobs_table.php
2026_10_06_000000_enable_extensions.php
2026_10_06_000100_create_identity_tables.php
2026_10_06_000200_create_plan_tables.php
2026_10_06_000300_create_platform_tables.php
2026_10_06_000400_create_personal_access_tokens_table.php
```

**No task in Cycle 1 may add, edit or delete a migration.**

If you think you need a new column, in order:

1. **Check that it does not already exist.** The freeze was written generously — `audit_logs`,
   `outbox_events` and `idempotency_keys` all shipped in Wave 0 with no model, no writer and no
   reader, precisely so later waves would not need a migration. Most "I need a column" moments
   in this project have turned out to be "the column is already there."
2. **Fold the change into the existing migration file** if the schema genuinely must change and
   you are still pre-release. ADR 0007 did exactly this — it changed the plan-tables migration in
   place rather than adding a new file.
3. **If neither is possible, stop and say so.** Do not add a migration quietly. Several specs
   record features deliberately shaped *around* the freeze (the refresh chain's absent
   `chain_started_at`, invite `max_uses`), with the reasoning written down. That is the intended
   outcome, not a workaround to be undone.

The freeze exists so Cycle 1 cannot accumulate a migration per task and then need a retrofitted
one a cycle later.

---

## Testing

Pest 5 on PHPUnit, three suites declared in `phpunit.xml`:

| Suite | Directory | What it holds |
|---|---|---|
| `Unit` | `tests/Unit` | Pure domain tests — value objects, `RotationPolicy`, enums |
| `Feature` | `tests/Feature` | HTTP, models, schema constraints, outbox, ops, OpenAPI contract |
| `Architecture` | `tests/Architecture` | `DomainPurityTest.php` — the layering gates |

The suite targets the **`tandem_test`** database, never `tandem`. Run it with `make test`
(which clears the config cache first) or `make verify`.

Do not quote a test count here or anywhere else — run `make verify`.

`phpunit.xml`'s `<php>` block is heavily pinned, and each pin has a comment saying what goes
wrong without it. Two worth internalising: `APP_DEBUG=false` is pinned because it changes what
an error *response contains*, so an error-shape test written against the debug payload would
assert the wrong thing for production; and `REDIS_CLIENT=predis` is pinned because
`config/database.php` defaults to `phpredis`, which this project does not install. Note that
PHPUnit's `<env>` defaults to `force="false"` — a pinned value is only a *default* and is
overridden by a real environment variable of the same name already exported in your shell.

### Pre-approved deviations — do not "fix" these, and do not delete their comments

The broad `catch (Throwable)` in `HealthController::check()`; the absent FK on
`refresh_tokens.replaced_by_id`; the absent FK and `updated_at` on `plan_activity`;
`phpstan.neon` excluding `tests/Architecture/*`; `users.email` being uniquely constrained
schema-wide.

---

## Scheduled work

`routes/console.php` registers two scheduled commands, so a working local stack needs
`make queue` (and a scheduler, if you are exercising them):

- `outbox:dispatch` — every minute, `withoutOverlapping()`.
- `users:purge-deleted` — daily at 03:00, with `onFailureWithOutput()` logging the run failure.
  `Log`, not `emailOutputTo`, deliberately: mail leaves this application *through the outbox*, so
  a failure notice sent by mail would travel through the machinery that may itself be broken.
