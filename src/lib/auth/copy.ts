/**
 * Pure copy helpers shared by the auth forms - kept framework-free (no React, no fetch) so
 * each is one unit test, the same split `tandem-mobile/src/features/auth/screenLogic.ts` uses
 * for exactly this reason.
 */

/**
 * The two hand-offs that land on `/signin` from elsewhere in this flow, neither of which is an
 * error: `SignUpForm`'s registered-but-not-signed-in outcome, and a completed password reset
 * from `ResetPasswordForm`. Both carry their explanatory text on Sign In itself rather than on
 * the page that navigated away, because the user is looking at Sign In when they read it.
 * `reason` is an open string (a `?reason=` query param), not a closed enum - an unrecognised
 * value (a stale bookmark, a typo) renders no notice at all rather than a blank one. Mirrors
 * `tandem-mobile/src/features/auth/screenLogic.ts`'s `noticeFor` copy verbatim.
 */
export function noticeFor(reason: string | undefined): string | null {
  if (reason === 'registered') {
    return "Your account is ready. We couldn't sign you in automatically — sign in below.";
  }
  if (reason === 'passwordReset') {
    return 'Your password is changed. Sign in with it.';
  }
  return null;
}
