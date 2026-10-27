# The live-stack E2E runbook

`e2e/live/**` runs against a **real** `tandem-api` with Postgres 17, Redis 7 and Mailpit. It
proves Cycle 1 spec §17's criteria 1, 2, 3 and 11 on web end to end — the registration email
actually leaves the outbox and is read back out of Mailpit, the cookies ADR 0004 requires are
read back off a real Chromium, and the refresh token is observed rotating.

**It is a local acceptance gate, not a CI job.** Running it in CI needs `tandem-api` checked
out into this repo's job, which needs either a cross-repo checkout token or a published API
image. Neither exists, and a job gated on a possibly-absent secret skips silently — the
failure mode this project has already shipped three times. CI runs `npm run e2e`, which is the
`fixture` project only. Recorded as an added spec §16 prerequisite.

```
npm run e2e        # fixture project only. No API needed. This is what CI runs.
npm run e2e:live   # every project: fixture + live-setup + live. Needs everything below.
```

> **Correction, 2026-10-27, per ADR 0010:** `tandem-api` is no longer a sibling repository — it
> was merged into this repo as `backend/`. The `cd` commands below have been updated from the
> old sibling path (`.../tandem-api`) to `.../tandem/backend`; everything else in this runbook
> (Mailpit, the outbox dispatcher loop, the trap list) is unchanged and still accurate.

---

## 0. Node 22

```bash
nvm use 22          # .nvmrc says 22; vitest's config loader refuses to run on Node 20
node -v             # must print v22.x
```

## 1. Infrastructure

```bash
cd '/Volumes/M2 Cloud/projects/Tandem/tandem/backend'
make up                 # postgres 17, redis 7, mailpit — docker only, never installed locally
make migrate
```

## 2. Point the API's emails at THIS app — required, and silent if you skip it

`tandem-api/config/app.php` reads `web_url` from `APP_WEB_URL` and **defaults to
`http://localhost:3000`**, while Playwright serves this app on **:3100**
(`playwright.config.ts`). Every emailed link — verification, password reset, plan invite — is
built from that value by `EmailVerificationLink::for()` and `AppServiceProvider`'s outbox
handlers. With it unset, the suite follows a link to a port nothing is listening on, or worse,
drives whatever `next dev` someone left running on :3000.

```bash
# in tandem-api/.env  (gitignored local config — not a code change to tandem-api)
APP_WEB_URL=http://localhost:3100
```

`e2e/support/mailpit.ts`'s `assertLinkTargetsThisApp` turns the mistake into a failure that
names this fix, rather than an `ERR_CONNECTION_REFUSED`. Restart `make api` after changing it.

## 3. The API, and the outbox dispatcher

```bash
cd '/Volumes/M2 Cloud/projects/Tandem/tandem/backend'
make api                # :8000   (leave running)

# A SECOND terminal. Mail goes through outbox_events, so WITHOUT a dispatcher no verification
# or reset email ever reaches Mailpit and every live test that reads one times out.
while true; do /opt/homebrew/opt/php/bin/php artisan outbox:dispatch; sleep 2; done
```

**`make queue` is NOT the dispatcher, and running it does nothing for this suite.** The brief
for this task said it was; measured, it is not. `make queue` is `artisan queue:work`, and a
grep for `ShouldQueue` across `tandem-api/app/` returns **nothing** — no job is ever pushed, so
the queue worker processes zero jobs and produces no mail. The handlers that actually call
`Mail::to(...)->send(...)` are registered on `OutboxHandlers` and run only inside
`OutboxDispatcher`, i.e. only when `artisan outbox:dispatch` runs. In production that command
is driven by the **scheduler** (`routes/console.php`: `Schedule::command('outbox:dispatch')
->everyMinute()`), so `artisan schedule:work` would also work — but at one-minute granularity,
which makes a nine-test suite spend minutes waiting on mail. The two-second loop above is the
same command at a useful interval.

`/opt/homebrew/opt/php/bin/php` is the explicit binary path `tandem-api/CLAUDE.md` requires
(the machine's linked `php` is 7.4 and must not be relinked). There is no `Makefile` target for
this loop and this task deliberately added none — the Wave 1b plan's §6 is explicit that it
changes no `tandem-api` code.

## 4. The web suite

```bash
cd '/Volumes/M2 Cloud/projects/Tandem/tandem-web'
API_BASE_URL=http://localhost:8000/api/v1 npm run e2e:live
```

`API_BASE_URL` is `src/server/env.ts`'s own default, so the bare `npm run e2e:live` also works
against a stock local API; it is written out here because setting it is what makes the target
explicit rather than inherited.

Expected: **18 passed** — 1 setup, 8 fixture, 9 live. `npm run e2e:live` pins `--workers=1`:
these tests share ONE live account and one per-IP rate-limit budget, so parallel workers would
both multiply the budget pressure and race on that account's password.

---

## Trap 1 — never run `artisan optimize` or `artisan config:cache`

A cached config makes `phpunit.xml`'s `<env>` block inert, which retargets the API suite at the
development database, where `RefreshDatabase` wipes it. This has destroyed a database before.
If anything refuses to run, the fix is `artisan config:clear` (or `make config-clear`).

## Trap 2 — a throttled run poisons the next hour

`register` is **5/hour/IP** and `forgot-password` is **3/hour/email**, and both count
**requests**, so neither is self-healing the way login is (login's budget counts failures and a
success clears it). One clean run uses **two registrations** (the setup project, and §11 case 3) and **one reset**, which fits. A flaky run that retries can exceed it, and so can three runs
in a row — which is exactly what the vacuity proofs below need. The reset:

```bash
cd '/Volumes/M2 Cloud/projects/Tandem/tandem/backend'
docker compose exec -T redis redis-cli FLUSHALL
```

That also clears the application cache, which is acceptable locally and is **not** a thing to
do anywhere else. `RateLimiter::clear('auth-register')` is **not** an alternative: it is a
measured no-op, because the real key is `md5(name.key)`.

## Trap 3 — a live session is not a fixture, and two tests cannot share one

Two measured properties of the real API that a fixture-backed suite never has to think about,
both of which cost a test run before they were understood:

1. **A refresh token is single-use.** §9.1.1 rotates it on every use, and presenting an
   already-rotated one is read as a replay — which revokes the whole lineage _and deletes every
   access token the user holds_. The brief's design seeded every test from one shared
   `e2e/.auth/user.json`, so the first test to refresh rotated the token every later test would
   present, and the second such test replayed it. Observed: the AC 11 rotation test passed, and
   the test that ran next landed on `/signin` showing "You were signed out. Sign in again."
2. **A password reset revokes every token the account holds, on every device** — by design. So
   the AC 3 test invalidated that seeded session for everything that ran after it.

Neither is a bug. The consequence: **there is no seeded session at all.** The `live` project
sets no `storageState` (see `playwright.config.ts`), every test that needs a signed-in browser
calls `e2e/support/session.ts`'s `signInFresh`, and what the setup project hands over is the
ACCOUNT in `e2e/.auth/account.json` — which is what keeps its one registration the only one any
live test needs. **Do not add a test that depends on arriving already signed in**; it will pass
in isolation and fail in the suite.

## Proving the suite is not vacuous

A live suite that passes against a dead API is worse than none. Six break-and-restore proofs,
each recorded with its full output in this task's report
(`tandem-docs/.superpowers/sdd/2026-10-10-cycle-1-wave-1b-clients/task-13-report.md`, §5).
Expected failures are what was **measured**, not what was predicted — two of the brief's own
predictions were wrong, and rows 1 and 3 say so, because a reproduction that looks like a failed
repro is worse than no reproduction.

| #   | Break                                                              | Measured outcome                                                                                                                                                                                                                                                                                                                                |
| --- | ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Stop `make api`                                                    | the setup project fails at the register **submit**, not at the first navigation — the app serves `/signup` fine with no API — rendering `HOUSE_NETWORK_MESSAGE`: "We couldn't reach Tandem. Check your connection and try again."                                                                                                               |
| 2   | Stop the dispatcher loop                                           | the setup project fails in `linkMatching`: "No message to … arrived within 30000ms (0 message(s) to that address in Mailpit)". The Mailpit read is real, not satisfied by an empty inbox.                                                                                                                                                       |
| 3   | `refreshSession()` returns `false` without calling anything        | **both** AC 11 tests fail. The rotation test renders `ErrorState` "Something went wrong" + a "Try again" button — **not** a sign-in screen. That is correct: `false` with both cookies intact is a TRANSIENT failure, `isNowSignedOut()` still answers `expired`, and `ExpiredSessionRefresh` deliberately declines to claim the session ended. |
| 4   | Remove the BroadcastChannel `claim` broadcast                      | AC 11's second-tab test fails "the follower must not post its own refresh / Expected: 1 / Received: 2"                                                                                                                                                                                                                                          |
| 4b  | Remove the module-level promise in `refreshSession`                | the live suite still passes 10/10 — recorded, not hidden: the app has one refresh initiator per document, so this half is only reachable from `src/lib/session/refresh-client.test.ts`, which the same break fails with 2 assertions. This is why row 4 breaks the election instead.                                                            |
| 5a  | Comment out the reset-token replay assertion                       | the AC 3 test still passes, which is what shows that assertion is the only thing covering single-use                                                                                                                                                                                                                                            |
| 5b  | Replay a **fresh, unused** reset token instead of the consumed one | the AC 3 test fails on `This link isn't usable` — so the dead-token assertions genuinely observe single-use rather than a page state that is always there                                                                                                                                                                                       |

Re-run `docker compose exec -T redis redis-cli FLUSHALL` between proofs — each one registers.
