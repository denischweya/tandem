import { expect, type BrowserContext, type Page } from '@playwright/test';

import { loadAccount } from './accounts';

/**
 * Signing in through the real form and the real BFF - the one way any live test obtains a
 * session, because §17 criterion 3 makes a seeded one unsafe to depend on.
 *
 * NOT IN THE BRIEF'S FILE LIST, and here is why it earns its place rather than being two
 * copies in two specs. MEASURED, in this order, on a full live run:
 *
 *   1. The `live` project seeds every test from `e2e/.auth/user.json`, which the setup project
 *      wrote - the brief's design, and the right one for keeping the suite inside
 *      `POST /auth/register`'s 5/hour/IP budget.
 *   2. `live/auth.spec.ts`'s AC 3 test completes a real password reset, which the API
 *      deliberately treats as "revoke every token this account holds, on every device"
 *      (§9.1.1 / `resetPasswordHandler`'s own docblock).
 *   3. Every later test's seeded cookies are therefore dead. Observed: `live/invite.spec.ts`'s
 *      §11 case 1 rendered `/signin` with "You were signed out. Sign in again." instead of the
 *      invite's signed-in branch - the seeded access token 401'd, so `getSession()` answered
 *      `expired`, and `refreshSession()` then presented a refresh token the reset had revoked.
 *
 * That is correct product behaviour and a correct `ExpiredSessionRefresh` terminal-401 path;
 * it is the TEST that was wrong to assume a shared session survives a test that revokes
 * sessions. So no live test depends on the seeded cookies staying live: a test that needs a
 * signed-in browser calls `signInFresh`, and the seeded state's remaining job is to make the
 * setup project's one registration the only one any of them needs.
 */
export async function signIn(page: Page, password?: string): Promise<void> {
  const account = loadAccount();
  await page.goto('/signin');
  await page.getByLabel('Email').fill(account.email);
  await page.getByLabel('Password').fill(password ?? account.password);
  await page.getByTestId('sign-in-submit').click();
}

/** A session this test alone holds: cookies cleared, then one real sign-in, asserted to have
 *  landed on the signed-in surface before the caller relies on it. */
export async function signInFresh(page: Page, context: BrowserContext): Promise<void> {
  await context.clearCookies();
  await signIn(page);
  await expect(page).toHaveURL(/\/plans$/);
}
