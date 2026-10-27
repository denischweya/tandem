import { expect, test } from '@playwright/test';

import { tabUntilFocused } from './support/keyboard';

// Matches the token in `src/lib/invite/fixture.ts`. There is no invite API
// yet, so this is the one token the mocked lookup resolves.
const VALID_TOKEN = '7Jm9kP3';
const INVITE_PATH = `/invite/${VALID_TOKEN}`;

/**
 * FIX ROUND 1 (reviewer Minor 2). These used to be built with
 * `new URLSearchParams({ next: INVITE_PATH }).toString()` - the IDENTICAL call
 * `InviteActions`/`SignupPrompt` themselves make to produce the href they navigate to. An
 * assertion built from the same encoding call as the implementation moves with it: if the
 * production code's encoding ever changed, this expectation would change right along with
 * it and stay green over a real behaviour change - the same "an assertion must be able to
 * observe the value it is named for" trap this wave has hit before (the mobile relay test
 * that could not see its own field). Written as literals instead, computed once by hand
 * (`node -e "new URLSearchParams({next:'/invite/7Jm9kP3'}).toString()"` -> confirmed) rather
 * than re-derived at test-run time.
 */
const EXPECTED_SIGNIN_URL = '/signin?next=%2Finvite%2F7Jm9kP3';
const EXPECTED_SIGNUP_URL = '/signup?next=%2Finvite%2F7Jm9kP3';

/**
 * A `UserSchema`-valid body (`@tandem/shared`) for mocking `/api/auth/login`'s 200 response -
 * see `src/test/fixtures.ts`'s `TEST_USER`, which this mirrors rather than imports: a vitest
 * fixture and a Playwright spec are two different test runners with two different module
 * resolutions, and this object is small enough that importing across that boundary would
 * trade a real simplification for a speculative one.
 */
const MOCK_USER = {
  id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@example.test',
  email_verified: true,
  display_name: 'Ada Lovelace',
  timezone: 'Africa/Nairobi',
  locale: 'en',
};

test.describe('guest invite', () => {
  test('renders the invite with the plan, the date/time and who else is coming', async ({
    page,
  }) => {
    await page.goto(INVITE_PATH);

    await expect(page.getByRole('heading', { level: 1, name: 'Dinner' })).toBeVisible();
    await expect(page.getByText('Denis invited you to a plan')).toBeVisible();
    // The plan starts at 16:00 UTC / 19:00 Africa/Nairobi on 2026-10-10, a Saturday —
    // this is the text toZonedParts-based formatting must produce for that instant.
    await expect(page.getByTestId('plan-when')).toHaveText('Saturday 10 October, 7 PM');
    await expect(page.getByTestId('plan-where')).toHaveText(
      'Westlands, Nairobi · with Sarah, James and Denis',
    );
  });

  test('a signed-out visitor sees both the sign-in and the create-account actions, scoped back to this invite', async ({
    page,
  }) => {
    await page.goto(INVITE_PATH);

    // The replacement for the `'Are you in?'` heading `RsvpResponse` used to carry -
    // `InviteActions` is the new level-2 heading on this page.
    await expect(page.getByRole('heading', { level: 2, name: 'Join this plan' })).toBeVisible();

    const signIn = page.getByRole('button', { name: 'Sign in and join' });
    await expect(signIn).toBeVisible();
    await signIn.click();
    await expect(page).toHaveURL(EXPECTED_SIGNIN_URL);

    await page.goto(INVITE_PATH);
    const signUp = page.getByRole('button', { name: 'Create an account and join' });
    await expect(signUp).toBeVisible();
    await signUp.click();
    await expect(page).toHaveURL(EXPECTED_SIGNUP_URL);
  });

  test('both actions are reachable and operable by keyboard', async ({ page }) => {
    await page.goto(INVITE_PATH);

    await tabUntilFocused(page, 'invite-signin');
    await expect(page.getByTestId('invite-signin')).toBeFocused();
    // Enter activates the focused button, same as a native control — the accessibility
    // pattern `RsvpResponse`'s RSVP buttons established, kept here.
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(EXPECTED_SIGNIN_URL);

    await page.goto(INVITE_PATH);
    await tabUntilFocused(page, 'invite-signup');
    await expect(page.getByTestId('invite-signup')).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(EXPECTED_SIGNUP_URL);
  });

  test('an unrecognised or expired token shows a graceful message, not a crash', async ({
    page,
  }) => {
    const response = await page.goto('/invite/not-a-real-token');

    expect(response?.ok()).toBe(true);
    await expect(
      page.getByRole('heading', { name: "This invite link isn't available" }),
    ).toBeVisible();
  });

  /**
   * The open redirect `safeNextPath` (src/lib/auth/redirect.ts) closes, asserted end to end:
   * `/signin?next=https://evil.test` must fall back to `/plans`, not hard-navigate off-site,
   * once sign-in actually succeeds. `/auth/login` is mocked at the BROWSER's network layer
   * (`page.route`), so this needs no real API - the request never leaves this page.
   */
  test('/signin?next=https://evil.test does not redirect off-site after a successful sign-in', async ({
    page,
  }) => {
    await page.route('**/api/auth/login', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ user: MOCK_USER }),
      });
    });

    await page.goto('/signin?next=https%3A%2F%2Fevil.test');
    await page.getByLabel('Email').fill(MOCK_USER.email);
    await page.getByLabel('Password').fill('hunter2hunter2');
    await page.getByTestId('sign-in-submit').click();

    // The mock above answers 200 with no `Set-Cookie` - `/api/auth/login` never actually ran,
    // so no real session cookie exists - which means `/plans` (a real Server Component) reads
    // `signedOut` and bounces straight back to `/signin?next=%2Fplans` the instant the browser
    // lands there. That round trip is expected and is NOT what this test is about: the
    // property under test is whether the browser ever leaves `next=https…` behind at all. A
    // guarded redirect moves on to `/plans` (then bounces to `/signin?next=%2Fplans`, a
    // DIFFERENT query string) almost immediately; an UNGUARDED one hard-navigates towards
    // `https://evil.test`, which never resolves (`.test` is a reserved, non-routable TLD), so
    // `page.url()` keeps reporting the last COMMITTED url - this exact one - until this
    // assertion's own timeout. Asserting against the query string rather than "landed on
    // /plans" is what keeps this robust to the mock never producing a real, authenticated
    // `/plans` visit.
    await expect(page).not.toHaveURL(/next=https/);
    expect(page.url()).not.toContain('evil.test');
  });
});
