/**
 * Pure copy helpers shared by the auth forms - kept framework-free (no React, no fetch) so
 * each is one unit test, the same split `tandem-mobile/src/features/auth/screenLogic.ts` uses
 * for exactly this reason.
 */

/**
 * Hand-offs that land on `/signin` from elsewhere in this flow. `registered` and
 * `passwordReset` are not errors: `SignUpForm`'s registered-but-not-signed-in outcome, and a
 * completed password reset from `ResetPasswordForm`. Both carry their explanatory text on
 * Sign In itself rather than on the page that navigated away, because the user is looking at
 * Sign In when they read it. `reason` is an open string (a `?reason=` query param), not a
 * closed enum - an unrecognised value (a stale bookmark, a typo) renders no notice at all
 * rather than a blank one.
 *
 * CORRECTED (Wave 1b final review, branch coherence / D10): this docblock used to claim it
 * "mirrors `tandem-mobile/src/features/auth/screenLogic.ts`'s `noticeFor` copy verbatim" -
 * false even before `signedOut` was added below: mobile's `noticeFor` has three rows
 * (`registered`, `passwordReset`, `emailVerified`), this had two, and `registered`'s own
 * sentence differs by one byte (an em dash here, a hyphen there, both pinned by their own
 * tests). Web legitimately needs no `emailVerified` row - its verify-email success stays
 * on-page with "Continue to Tandem" rather than redirecting to Sign In - so the right claim
 * is "the two shared reasons use the same wording", not "mirrors verbatim".
 *
 * `signedOut` (W7/D3) is NOT a hand-off FROM elsewhere in this flow in the same sense - it is
 * the prescribed terminal-401 sentence the global constraints require
 * ("Discard both tokens, route to sign-in, show 'You were signed out. Sign in again.'"),
 * reached only via `ExpiredSessionRefresh`'s own confirmed-signed-out check. It has no mobile
 * counterpart in THIS function either: mobile's equivalent sentence lives in
 * `tandem-mobile/src/api/errors.ts`'s `userMessageFor`, rendered as an error, not a notice.
 */
export function noticeFor(reason: string | undefined): string | null {
  if (reason === 'registered') {
    return "Your account is ready. We couldn't sign you in automatically — sign in below.";
  }
  if (reason === 'passwordReset') {
    return 'Your password is changed. Sign in with it.';
  }
  if (reason === 'signedOut') {
    return 'You were signed out. Sign in again.';
  }
  return null;
}
