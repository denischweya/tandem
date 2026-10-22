import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  // A committed `test.only` would otherwise run just that one test and exit 0 in CI,
  // silently skipping the rest of the suite.
  forbidOnly: !!process.env.CI,
  // Added in Wave 1b: a flake in an auth flow is expensive to diagnose from a bare failure.
  retries: process.env.CI ? 1 : 0,
  // Port 3100, not 3000, and deliberately no `reuseExistingServer`. With reuse enabled and a
  // `next dev` already on :3000, this suite goes green in ~1.4s without `npm run build` ever
  // running — and nothing in the output hints at it. The smoke test exists to guard the
  // PRODUCTION build, so it must always build. A dedicated port lets a dev server coexist
  // instead of forcing you to kill it.
  //
  // `trace` lives HERE, inside `use`, and not as a top-level key. The brief's snippet put it
  // at the top level; `defineConfig` has no top-level `trace` option and `tsc --noEmit`
  // rejects it (TS2353). Corrected rather than copied.
  use: { baseURL: 'http://localhost:3100', trace: 'on-first-retry' },
  webServer: {
    command: 'npm run build && PORT=3100 npm run start',
    url: 'http://localhost:3100',
    timeout: 120_000,
  },
  projects: [
    {
      // Chromium PINNED. CI installs chromium only (`npx playwright install --with-deps
      // chromium`), so the browser was previously chosen by Playwright's default rather than
      // declared - and a future webkit/firefox project would have silently inherited the
      // Secure-cookie assumption in src/server/cookies.ts.
      name: 'fixture',
      testIgnore: /live\//,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // Registers and verifies ONE account and writes it to `e2e/.auth/account.json`. Every
      // live test signs in as that account, because POST /auth/register is 5/hour/IP counting
      // REQUESTS (§8.2) and a suite that registers per test throttles itself inside one run.
      //
      // It does NOT hand the live project a signed-in browser - see the `live` project below.
      name: 'live-setup',
      testMatch: /live\/auth\.setup\.ts/,
      // 60s, not the 30s default. MEASURED while running this task's own vacuity proof 2
      // (dispatcher stopped): `latestMessageTo`'s poll gives up at 30s and throws an error
      // naming the missing dispatcher and the runbook step that starts it - but the DEFAULT
      // test timeout is also 30s, so Playwright killed the test first and reported a bare
      // "Test timeout of 30000ms exceeded" instead. A diagnostic that loses a race with the
      // timeout it is written for is a diagnostic nobody reads.
      timeout: 60_000,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'live',
      testMatch: /live\/.*\.spec\.ts/,
      // `dependencies`, but deliberately NO `storageState`. The dependency is still
      // load-bearing - it is what registers and verifies the account and writes
      // `e2e/.auth/account.json`, which every live test reads - but a SEEDED SIGNED-IN
      // BROWSER is not something this suite can use, and offering one invites the exact bug
      // this task had to diagnose around.
      //
      // MEASURED, twice, against the real API: a refresh token is single-use (§9.1.1 rotates
      // it on every use, and presenting an already-rotated one is read as a replay, which
      // revokes the lineage AND deletes every access token the user holds), and a password
      // reset revokes every token the account holds. Both are correct behaviour. Both mean a
      // shared, pre-seeded session is dead the moment one test refreshes or resets - so all
      // nine live tests discard it as their first action and establish their own via
      // `e2e/support/session.ts`'s `signInFresh`. `storageState` here pointed at a file
      // nothing read: the state holds two cookies and `origins: []` (this app writes no
      // localStorage, sessionStorage or IndexedDB anywhere - grepped), so `clearCookies()`
      // removed the whole of it. Removed rather than left as a comment, because a live
      // `storageState` line reads as an invitation to depend on it.
      dependencies: ['live-setup'],
      // Same 60s, same reason: the AC 3 test also waits on a mail that only a running
      // dispatcher produces, and it does three authentication round trips besides.
      timeout: 60_000,
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
