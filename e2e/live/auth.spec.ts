import { expect, test, type BrowserContext, type Page } from '@playwright/test';

import {
  loadAccount,
  REJECTED_ACCESS_TOKEN,
  ROTATED_PASSWORD,
  saveAccount,
} from '../support/accounts';
import { assertLinkTargetsThisApp, linkMatching } from '../support/mailpit';
import { signIn, signInFresh } from '../support/session';

/**
 * The live auth suite: §17 criteria 2, 3 and 11 proved on web against a real `tandem-api`,
 * Postgres 17, Redis 7 and Mailpit. Criterion 1 is `live/auth.setup.ts`, which this project
 * depends on; criterion 4 is mobile's (Task 9 Step 10) because §11's five web surfaces include
 * no profile page.
 *
 * EVERY TEST HERE STARTS SIGNED OUT AND ESTABLISHES ITS OWN SESSION - via
 * `e2e/support/session.ts`'s `signInFresh` (or `freshSessionWithExpiredAccessToken` below,
 * which begins with it). The `live` project seeds NO storage state, deliberately; see
 * `playwright.config.ts`'s own comment on why that line was removed rather than left.
 *
 * Do not write a test here that depends on arriving already signed in. It would pass in
 * isolation and fail in the suite, and the reason is real API behaviour rather than a test
 * harness quirk: a refresh token is single-use (§9.1.1 rotates it on every use and reads an
 * already-rotated one as a replay, revoking the lineage and deleting every access token the
 * user holds), and the AC 3 test below completes a real password reset, which revokes every
 * token the account holds on every device. Any session shared across these tests is dead the
 * moment one of them refreshes or resets.
 *
 * What the setup project DOES hand over is the account itself, in `e2e/.auth/account.json`:
 * one registration and one verification for the whole run, which is what keeps the suite
 * inside `POST /auth/register`'s 5/hour/IP budget - a budget that counts REQUESTS, so it does
 * not self-heal.
 *
 * Every sign-in reads the password from that file at the moment it signs in, never from the
 * `STRONG_PASSWORD` constant, because the AC 3 test genuinely rotates it on the server and
 * writes the new one back. `npm run e2e:live` pins `--workers=1` for the same reason: these
 * tests share ONE live account and one per-IP rate-limit budget, so parallel workers would
 * both multiply the budget pressure and race on that account's password.
 */

const PLANS_EMPTY_STATE = 'Nothing planned yet.';

/**
 * A session nothing else in this run shares, then an access token the API answers 401 to -
 * the browser state of an EXPIRED access token.
 *
 * TWO measured corrections to the brief's snippet are folded in here.
 *
 * 1. NOT `clearCookies({name: 'tandem_at'})`. With the access cookie ABSENT,
 *    `src/server/session.ts`'s `getSession()` returns `signedOut` at its first line
 *    (`if (access === null)`) and `/plans` redirects to `/signin` - the opposite of what
 *    criterion 11 asserts, and it never reaches `refreshSession()` at all. Expiry does not
 *    remove the cookie; it makes the token stop being accepted. See `REJECTED_ACCESS_TOKEN`.
 *
 * 2. A FRESH SIGN-IN FIRST, rather than refreshing whatever session the browser arrived with.
 *    A REFRESH TOKEN IS SINGLE-USE: §9.1.1 rotates it on every use, and presenting an
 *    already-rotated one is read as a replay, which revokes the whole lineage AND deletes
 *    every access token the user holds. The brief's design had the `live` project seed every
 *    test from one shared `e2e/.auth/user.json`, which made the FIRST test to refresh rotate
 *    the token every LATER test would present - and the second such test replay it. Observed
 *    exactly once, before this helper existed: the AC 11 rotation test passed, the two-tab
 *    test that ran next landed on `/signin` showing "You were signed out. Sign in again.",
 *    and that replay also deleted the access token `live/invite.spec.ts`'s tests were relying
 *    on. That seeding is gone (`playwright.config.ts`), and signing in here mints a chain
 *    belonging to this test alone, so no live test ever presents a rotated token and the
 *    suite is re-runnable in any order.
 */
async function freshSessionWithExpiredAccessToken(
  page: Page,
  context: BrowserContext,
): Promise<string> {
  await signInFresh(page, context);

  const before = await context.cookies();
  const refresh = before.find((cookie) => cookie.name === 'tandem_rt');
  expect(refresh, 'signing in must have set a tandem_rt cookie').toBeDefined();

  await context.addCookies([
    {
      name: 'tandem_at',
      value: REJECTED_ACCESS_TOKEN,
      domain: 'localhost',
      path: '/',
      httpOnly: true,
      secure: true,
      sameSite: 'Lax',
    },
  ]);
  return refresh?.value ?? '';
}

test('AC 2 - signs in with email and password on web, with ADR 0004 cookie attributes', async ({
  page,
  context,
}) => {
  await context.clearCookies();
  await signIn(page);

  await expect(page).toHaveURL(/\/plans$/);
  // `/plans` is a Server Component that redirects a `signedOut` read to `/signin`, so
  // rendering its signed-in body AT ALL is the proof that a real session cookie was set and
  // that `GET /me` accepted the bearer the BFF attached.
  await expect(page.getByRole('heading', { name: PLANS_EMPTY_STATE })).toBeVisible();
  // Absent, not merely unasserted: this account was verified in the setup project, so a
  // banner here would mean `GET /me` reported the wrong user or a stale `email_verified`.
  await expect(page.getByTestId('plans-verify-banner')).toHaveCount(0);

  const cookies = await context.cookies();
  const access = cookies.find((cookie) => cookie.name === 'tandem_at');
  const refresh = cookies.find((cookie) => cookie.name === 'tandem_rt');
  expect(access).toMatchObject({ httpOnly: true, secure: true, sameSite: 'Lax', path: '/' });
  expect(refresh).toMatchObject({
    httpOnly: true,
    secure: true,
    sameSite: 'Lax',
    path: '/api/auth',
  });
  // Chromium treats localhost as a secure context, which is what lets a Secure cookie be set
  // over http://localhost:3100. ADR 0004 requires Secure with no carve-out, so this assertion
  // is what turns "it probably works on localhost" into an observed fact. `cookies.test.ts`
  // proves the BFF ASKS for `Secure`; only a real browser can prove it was accepted.

  // §91 / blueprint §91: browser JavaScript holds no token. `document.cookie` is the whole of
  // what script can read, and both credentials are HttpOnly, so it must not name either.
  const scriptVisibleCookies = await page.evaluate(() => document.cookie);
  expect(scriptVisibleCookies).not.toContain('tandem_at');
  expect(scriptVisibleCookies).not.toContain('tandem_rt');

  // The brief asked for "the display name" here. `/plans` as Task 11 built it renders no
  // display name anywhere (its signed-in body is the verify banner plus §134's empty state),
  // so the identity assertion is made against the BFF's own session read instead - the same
  // `GET /me` round trip, through the same cookie, naming the account that signed in. The
  // display name IS asserted as rendered copy, in `live/invite.spec.ts`, on the one Cycle 1
  // web surface that shows it.
  const account = loadAccount();
  const session = await page.evaluate(async () => {
    const response = await fetch('/api/auth/session');
    return (await response.json()) as {
      status: string;
      user?: { display_name: string; email: string };
    };
  });
  expect(session.status).toBe('signedIn');
  expect(session.user?.email).toBe(account.email);
  expect(session.user?.display_name).toBe(account.displayName);
});

test('AC 11 - an expired access token is transparently refreshed, and the refresh token rotates', async ({
  page,
  context,
}) => {
  const refreshBefore = await freshSessionWithExpiredAccessToken(page, context);

  let refreshCalls = 0;
  await page.route('**/api/auth/refresh', async (route) => {
    refreshCalls += 1;
    await route.continue();
  });

  await page.goto('/plans');

  // getSession() answers `expired`, ExpiredSessionRefresh calls POST /api/auth/refresh once,
  // and the page re-renders signed in - with no sign-in screen in between. `/plans`'s expired
  // branch renders ONLY that component, so there is never a sign-in action on screen to
  // click: if the refresh failed, this URL would become /signin and the assertion below would
  // fail on the heading.
  await expect(page.getByRole('heading', { name: PLANS_EMPTY_STATE })).toBeVisible();
  await expect(page).toHaveURL(/\/plans$/);
  await expect(page.getByTestId('plans-verify-banner')).toHaveCount(0);

  const after = await context.cookies();
  const access = after.find((cookie) => cookie.name === 'tandem_at');
  expect(access).toBeDefined();
  // Not merely present - REPLACED. The rejected value was still a well-formed cookie, so
  // "defined" alone would pass against a handler that wrote no access cookie at all.
  expect(access?.value).not.toBe(REJECTED_ACCESS_TOKEN);
  // And the refresh token ROTATED: every refresh issues a new one (§9.1.1), so a handler that
  // re-set only the access cookie would silently cap the session at 30 days.
  expect(after.find((cookie) => cookie.name === 'tandem_rt')?.value).not.toBe(refreshBefore);
  expect(refreshBefore).not.toBe('');

  // One recovery, one refresh. This is the assertion that fails if ExpiredSessionRefresh ever
  // goes back to calling `router.refresh()` regardless of outcome (the unattended loop its
  // own docblock records as fix round 1).
  expect(refreshCalls).toBe(1);
});

test('AC 11 - a second tab asking at the same moment produces no second POST /auth/refresh', async ({
  page,
  context,
}) => {
  await freshSessionWithExpiredAccessToken(page, context);

  // ---------------------------------------------------------------------------------------
  // WHY TWO TABS AND NOT ONE BURST. The brief asked to "trigger several same-origin requests
  // at once from the page" and count one refresh. MEASURED: the web app has exactly ONE
  // refresh initiator per document - `ExpiredSessionRefresh`, mounted once by `/plans` and
  // once by `/invite/[token]`, and no route mounts two - so no sequence of UI actions
  // produces two concurrent `refreshSession()` calls inside one document, and the
  // module-level promise's end-to-end reachability is nil (it is proved directly in
  // `src/lib/session/refresh-client.test.ts`). A burst of raw `fetch('/api/auth/refresh')`
  // calls from `page.evaluate` would bypass `refreshSession()` entirely and assert nothing
  // about the single-flight.
  //
  // What IS reachable, and is the half the brief named as the expensive regression, is the
  // CROSS-TAB election: a BroadcastChannel `claim` makes every other tab in the browser wait
  // for the leader's `result` instead of presenting the same `tandem_rt` concurrently - which
  // §9.1.1 would treat as a replay and revoke the whole lineage for. This test observes that
  // end to end, deterministically, by putting a second tab in the one state from which it
  // asks for a refresh WITHOUT navigating (which would destroy its channel listener): the
  // manual "Try again" of `ExpiredSessionRefresh`'s failed phase.
  // ---------------------------------------------------------------------------------------

  const follower = await context.newPage();
  // Step 1: drive the follower into its `failed` phase with a 503 it never sees the server
  // for. A page route takes precedence over a context route, and this one is removed before
  // the counting route below is installed, so it can never touch the count.
  await follower.route('**/api/auth/refresh', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: '{"signedIn":false}' }),
  );
  await follower.goto('/plans');
  // A 503 leaves both cookies untouched, so `isNowSignedOut()`'s session read still answers
  // `expired` rather than `signedOut`, which is what renders the manual retry instead of
  // pushing to /signin.
  const tryAgain = follower.getByRole('button', { name: 'Try again' });
  await expect(tryAgain).toBeVisible();
  await follower.unroute('**/api/auth/refresh');

  // Step 2: from here on, every refresh POST in this browser is counted, and the first one is
  // HELD open so the leader is still in flight when the follower asks.
  let refreshCalls = 0;
  let releaseRefresh: () => void = () => undefined;
  const gate = new Promise<void>((resolve) => {
    releaseRefresh = resolve;
  });
  await context.route('**/api/auth/refresh', async (route) => {
    refreshCalls += 1;
    await gate;
    await route.continue();
  });

  // Step 3: a fresh tab becomes the leader. Its `claim` is broadcast BEFORE its POST, so the
  // already-loaded follower's channel listener records it.
  const leader = await context.newPage();
  await leader.goto('/plans');
  await expect(leader.getByText('Checking your session…')).toBeVisible();
  await expect.poll(() => refreshCalls, { timeout: 10_000 }).toBe(1);

  // Step 4: the follower asks again while that claim is honoured. `CLAIM_BOUND_MS` is 30s and
  // `FOLLOWER_TIMEOUT_MS` is 5s, so the release below has to happen inside 5s of this click -
  // the 1s settle is what lets the follower's effect reach `waitForLeaderResult()` first.
  await tryAgain.click();
  await follower.waitForTimeout(1_000);
  // Asserted BEFORE the release, which is what makes this test's failure diagnostic rather
  // than merely red: a follower that did not hear the claim posts its own refresh the moment
  // it is clicked, so the count is already 2 by now. Measured in vacuity proof 4 - with the
  // claim broadcast removed, the first version of this test failed four assertions later, on
  // a leader stuck in "Checking your session…", because both tabs had presented the same
  // refresh token and §9.1.1 had revoked the chain out from under them. That is the exact
  // hazard this election exists to prevent, and the count is where it should be reported.
  expect(refreshCalls, 'the follower must not post its own refresh').toBe(1);
  releaseRefresh();

  // Step 5: the leader's result reaches the follower, which re-renders signed in off the back
  // of the leader's rotation rather than presenting the same refresh token itself.
  await expect(leader.getByRole('heading', { name: PLANS_EMPTY_STATE })).toBeVisible();
  await expect(follower.getByRole('heading', { name: PLANS_EMPTY_STATE })).toBeVisible();
  expect(refreshCalls).toBe(1);

  await follower.close();
  await leader.close();
});

test('AC 3 - requests a reset, signs in with the new password, and the token cannot be reused', async ({
  page,
  context,
}) => {
  const account = loadAccount();
  await context.clearCookies();

  const requestedAt = new Date();
  await page.goto('/forgot-password');
  await page.getByLabel('Email').fill(account.email);
  await page.getByTestId('forgot-password-submit').click();
  // One fixed sentence for a known address, an unknown one and a suspended account - there is
  // nothing in the 202's empty body to brand the three differently.
  await expect(page.getByTestId('forgot-password-confirmation')).toBeVisible();

  // `after` matters: this address already holds the setup project's verification mail, so a
  // poll that accepted "any message to this address" would pass instantly against the wrong
  // one and then fail to find a /reset-password link in it.
  const link = await linkMatching(account.email, /\/reset-password\?/, { after: requestedAt });
  assertLinkTargetsThisApp(link, 'http://localhost:3100');

  await page.goto(link);
  // The API puts `?token=…&email=…` on the link and the page prefills both; the token is a
  // hidden input and the address a visible one.
  await expect(page.getByTestId('reset-password-email')).toHaveValue(account.email);
  // By test id, not by label: `getByLabel('New password')` is a SUBSTRING match and resolves
  // to both this field and "Confirm new password" (strict-mode violation, measured).
  await page.getByTestId('reset-password-new-password').fill(ROTATED_PASSWORD);
  await page.getByTestId('reset-password-confirm-password').fill(ROTATED_PASSWORD);
  await page.getByTestId('reset-password-submit').click();

  await expect(page).toHaveURL(/\/signin\?.*reason=passwordReset/);
  await expect(page.getByTestId('sign-in-notice')).toBeVisible();
  // The reset revoked every token the account held, so the BFF cleared both cookies with the
  // 204 - asserted here because "the reset worked" and "the session it invalidated is gone"
  // are two different claims.
  const afterReset = await context.cookies();
  expect(afterReset.find((cookie) => cookie.name === 'tandem_at')).toBeUndefined();
  expect(afterReset.find((cookie) => cookie.name === 'tandem_rt')).toBeUndefined();

  // The new password works. Written back to the account file FIRST, so that a failure in the
  // rest of this test leaves the file agreeing with the server rather than naming a password
  // the server has already replaced.
  saveAccount({ ...account, password: ROTATED_PASSWORD });
  await signIn(page, ROTATED_PASSWORD);
  await expect(page).toHaveURL(/\/plans$/);
  await expect(page.getByRole('heading', { name: PLANS_EMPTY_STATE })).toBeVisible();

  // The old password does not. 401 answers `{message}` with no `errors`, so it renders as a
  // form-level message and never against either field.
  await context.clearCookies();
  await signIn(page, account.password);
  await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
  await expect(page).toHaveURL(/\/signin/);

  // AND THE LINK IS DEAD. The single-use half of criterion 3 is the half most easily lost: a
  // 422 whose `errors.token` carries the same sentence as `message` would be INVISIBLE if it
  // were rendered against the hidden token input, which is exactly what `ResetPasswordForm`'s
  // carve-out exists to prevent.
  await page.goto(link);
  await page.getByTestId('reset-password-new-password').fill('a-third-passphrase-x');
  await page.getByTestId('reset-password-confirm-password').fill('a-third-passphrase-x');
  await page.getByTestId('reset-password-submit').click();

  await expect(page.getByRole('heading', { name: "This link isn't usable" })).toBeVisible();
  await expect(
    page.getByText('This password reset link is no longer valid. Request a new one.'),
  ).toBeVisible();
  // A state plus a working action, per §135 - not a dead end.
  await page.getByRole('button', { name: 'Request a new link' }).click();
  await expect(page).toHaveURL(/\/forgot-password$/);
});

test('a signed-out visitor is redirected to sign in and lands back where they were going', async ({
  page,
  context,
}) => {
  await context.clearCookies();

  await page.goto('/plans');
  await expect(page).toHaveURL('http://localhost:3100/signin?next=%2Fplans');

  const account = loadAccount();
  await page.getByLabel('Email').fill(account.email);
  await page.getByLabel('Password').fill(account.password);
  await page.getByTestId('sign-in-submit').click();

  await expect(page).toHaveURL(/\/plans$/);
  await expect(page.getByRole('heading', { name: PLANS_EMPTY_STATE })).toBeVisible();
});
