import { expect, test } from '@playwright/test';

import { loadAccount, STRONG_PASSWORD, uniqueEmail } from '../support/accounts';
import { signInFresh } from '../support/session';

/**
 * Spec §11's accept-invite page, all three cases, end to end against the live stack.
 *
 * `e2e/invite.spec.ts` (the `fixture` project) already covers this page's rendering, its
 * keyboard operability and its unresolvable-token state with no API at all. This file covers
 * only what needs a REAL session: the two cases that authenticate, the one that reads an
 * existing session, and the notice that stands in for the join step.
 *
 * The invite itself is still `src/lib/invite/fixture.ts`'s one token - no endpoint resolves a
 * token-only §132 link to a plan (the Wave 2 design spec §10.3 is the named successor), so a
 * live run cannot create a real invite. What IS live here is every session read and every
 * credential: `getSession()` calls the real `GET /me`, and both authentication round trips go
 * through the real BFF to the real API.
 *
 * The two tests that need an already-signed-in browser call `signInFresh` rather than relying
 * on the `live` project's seeded storage state - see that helper's docblock for the measured
 * reason (the AC 3 password-reset test revokes every token the shared account holds, which is
 * correct product behaviour and makes a seeded session unsafe to depend on).
 */

const VALID_TOKEN = '7Jm9kP3';
const INVITE_PATH = `/invite/${VALID_TOKEN}`;
/** Computed once by hand, not re-derived from the same `URLSearchParams` call the components
 *  make - see `e2e/invite.spec.ts`'s own note on why an assertion built from the
 *  implementation's encoding call moves with it and stays green over a real change. */
const EXPECTED_SIGNIN_URL = `/signin?next=%2Finvite%2F${VALID_TOKEN}`;
const EXPECTED_SIGNUP_URL = `/signup?next=%2Finvite%2F${VALID_TOKEN}`;

test('§11 case 1 - a signed-in visitor sees the signed-in branch immediately', async ({
  page,
  context,
}) => {
  const account = loadAccount();
  await signInFresh(page, context);

  await page.goto(INVITE_PATH);

  // The one Cycle 1 web surface that renders the signed-in user's display name, which is why
  // `live/auth.spec.ts`'s AC 2 test defers the rendered-copy half of its identity assertion
  // to here.
  await expect(page.getByTestId('deferred-join-notice')).toBeVisible();
  await expect(page.getByText(`You're signed in as ${account.displayName}.`)).toBeVisible();

  // NO sign-in actions rendered. `toHaveCount(0)`, not "not visible": the signed-in branch
  // does not render `InviteActions` at all, and a hidden-but-present button would mean the
  // page had rendered both branches.
  await expect(page.getByTestId('invite-signin')).toHaveCount(0);
  await expect(page.getByTestId('invite-signup')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Join this plan' })).toHaveCount(0);
});

test('§11 case 2 - a signed-out user with an account signs in and returns to the invite', async ({
  page,
  context,
}) => {
  const account = loadAccount();
  await context.clearCookies();

  await page.goto(INVITE_PATH);
  await expect(page.getByRole('heading', { level: 2, name: 'Join this plan' })).toBeVisible();
  await page.getByRole('button', { name: 'Sign in and join' }).click();
  await expect(page).toHaveURL(EXPECTED_SIGNIN_URL);

  await page.getByLabel('Email').fill(account.email);
  await page.getByLabel('Password').fill(account.password);
  await page.getByTestId('sign-in-submit').click();

  // Back on THIS invite, signed in - not on `/plans`. The round trip through `?next=` is the
  // whole of §11 case 2, and `safeNextPath` is what lets the value be honoured at all.
  await expect(page).toHaveURL(new RegExp(`${INVITE_PATH}$`));
  await expect(page.getByText(`You're signed in as ${account.displayName}.`)).toBeVisible();
  await expect(page.getByTestId('deferred-join-notice')).toBeVisible();
});

test('§11 case 3 - a brand-new user registers and returns to the invite', async ({
  page,
  context,
}) => {
  // The run's SECOND and LAST registration, which keeps the suite under `POST /auth/register`'s
  // 5/hour/IP budget (a budget that counts requests, so it does not self-heal - see
  // docs/local-e2e-runbook.md's Trap 2).
  const email = uniqueEmail('grace');
  const displayName = 'Grace Hopper';
  await context.clearCookies();

  await page.goto(INVITE_PATH);
  await page.getByRole('button', { name: 'Create an account and join' }).click();
  await expect(page).toHaveURL(EXPECTED_SIGNUP_URL);

  await page.getByLabel('Name').fill(displayName);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(STRONG_PASSWORD);
  await page.getByRole('button', { name: 'Create an account' }).click();

  // register (201) then login (200), both live, and the signed-in branch of `registerHandler`'s
  // two 201 shapes - a `{signedIn: false}` body would have routed to `/signin` instead.
  await expect(page).toHaveURL(new RegExp(`${INVITE_PATH}$`));
  await expect(page.getByText(`You're signed in as ${displayName}.`)).toBeVisible();
  // An UNVERIFIED account reaches the signed-in branch. That is deliberate, not an oversight:
  // the `verified` middleware is applied to no Cycle 1 route, and the Wave 2 design spec §7.5
  // decides that joining is never gated on verification.
  await expect(page.getByTestId('invite-signin')).toHaveCount(0);
});

/**
 * THE TRIPWIRE for the deferred join. Named after its successor in the TEST TITLE and not only
 * in `DeferredJoinNotice`'s docblock, so the grep that finds the component also finds this and
 * the follow-up really is one line: delete the component, its copy and this test together.
 *
 * The Wave 2 design spec is Approved and names this page as one of the two its §10.3
 * unblocks, so this is a tripwire for that wave landing - not a statement that the endpoint
 * will never exist.
 */
test('shows the deferred-join notice until Wave 2 §10.3 and §10.7 land', async ({
  page,
  context,
}) => {
  await signInFresh(page, context);
  await page.goto(INVITE_PATH);

  const notice = page.getByTestId('deferred-join-notice');
  await expect(notice).toBeVisible();

  // §135: a state plus an action. The notice must NAME the missing capability...
  await expect(
    notice.getByText("Joining a plan needs the Plans API, which isn't built yet."),
  ).toBeVisible();
  // ...and offer an action that actually works, rather than a join button that records
  // nothing. Asserted by following it, not by asserting the href: a link to a page that 404s
  // or bounces to /signin would satisfy an href assertion and still be a dead end.
  await notice.getByTestId('deferred-join-go-to-plans').click();
  await expect(page).toHaveURL(/\/plans$/);
  await expect(page.getByRole('heading', { name: 'Nothing planned yet.' })).toBeVisible();

  // And it must NOT claim the join happened. These are the two sentences `RsvpResponse` used
  // to show for a reply it recorded nowhere; if either ever comes back, this fails.
  await page.goto(INVITE_PATH);
  await expect(page.getByText(/You're in/)).toHaveCount(0);
  await expect(page.getByText(/Are you in\?/)).toHaveCount(0);
});
