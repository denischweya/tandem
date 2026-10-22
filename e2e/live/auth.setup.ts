import { expect, test as setup } from '@playwright/test';

import { saveAccount, STORAGE_STATE_FILE, STRONG_PASSWORD, uniqueEmail } from '../support/accounts';
import { assertLinkTargetsThisApp, linkMatching } from '../support/mailpit';

const DISPLAY_NAME = 'Ada Lovelace';

/**
 * ACCEPTANCE CRITERION 1 end to end, and the fixture every other live test depends on: a user
 * registers on the web, receives a verification email through the real outbox and Mailpit,
 * and verifies.
 *
 * This is the suite's FIRST of exactly two registrations per run (the second is
 * `live/invite.spec.ts`'s §11 case 3), which keeps it inside `POST /auth/register`'s
 * 5/hour/IP budget - a budget that counts REQUESTS, not failures, so it does not self-heal.
 * See `docs/local-e2e-runbook.md`'s Trap 2 for the reset.
 *
 * FOUR LABEL CORRECTIONS against the brief's snippet, all measured against
 * `src/components/auth/SignUpForm.tsx` and `src/app/plans/page.tsx` as Task 11 actually built
 * them:
 *   - the display-name field is labelled "Name", not "Display name";
 *   - the submit button is "Create an account", not "Create account";
 *   - `/plans`'s unverified banner is `data-testid="plans-verify-banner"`, not
 *     `verify-email-banner`;
 *   - the verify button is "Verify email", not "Verify my email".
 * A test written to the brief's names would have failed on the selector rather than on the
 * behaviour, which is the failure mode that teaches a reader to loosen the assertion.
 */
setup(
  'AC 1 - registers, receives a verification email, verifies, and signs in',
  async ({ page, baseURL }) => {
    const email = uniqueEmail('ada');

    await page.goto('/signup');
    await page.getByLabel('Name').fill(DISPLAY_NAME);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(STRONG_PASSWORD);
    await page.getByRole('button', { name: 'Create an account' }).click();

    // register (201, no tokens) then login (200) - two API calls, sequenced by the BFF's
    // `registerHandler`, which is also what sets both cookies.
    await expect(page).toHaveURL(/\/plans$/);
    // The `verified` middleware is applied to no Cycle 1 route, so an unverified user reaches
    // /plans; the banner is the only signal there is.
    await expect(page.getByTestId('plans-verify-banner')).toBeVisible();

    // The emailed link points at APP_WEB_URL, i.e. this app, carrying the API's signed query.
    const link = await linkMatching(email, /\/verify-email\?/);
    assertLinkTargetsThisApp(link, baseURL ?? 'http://localhost:3100');
    // Proof the link is the API's signed one and not something this suite constructed: all four
    // parameters `EmailVerificationLink::for()` puts on it, which `verifyEmailHandler` relays
    // byte-for-byte.
    expect(link).toMatch(/[?&]id=/);
    expect(link).toMatch(/[?&]hash=/);
    expect(link).toMatch(/[?&]expires=/);
    expect(link).toMatch(/[?&]signature=/);

    await page.goto(link);
    // A BUTTON, not an automatic POST: the endpoint is a POST precisely because link scanners
    // prefetch GETs, and firing on mount would re-create that hazard.
    await page.getByRole('button', { name: 'Verify email' }).click();
    await expect(page.getByRole('heading', { name: 'Your email is verified' })).toBeVisible();

    await page.goto('/plans');
    await expect(page.getByTestId('plans-verify-banner')).toHaveCount(0);

    await page.context().storageState({ path: STORAGE_STATE_FILE });
    // Written to a file the other specs read, because `process.env` does not cross a Playwright
    // project boundary - each project runs in its own worker process. See `saveAccount`.
    saveAccount({ email, displayName: DISPLAY_NAME, password: STRONG_PASSWORD });
  },
);
