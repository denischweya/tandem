/**
 * Validates a `?next=` redirect target before it is ever handed to `router.replace` -
 * `SignInForm`'s post-login hop is the only consumer-facing redirect in this app whose
 * destination comes from a query string rather than a literal the app itself wrote.
 *
 * MEASURED (fix round 1, Important 1): `SignInForm.tsx` used to do
 * `router.replace(next !== undefined && next.length > 0 ? next : '/plans')` with no
 * same-origin check at all, so `/signin?next=https://evil.example/tandem` sent a user who had
 * JUST AUTHENTICATED straight to an off-origin page of the attacker's choosing - the classic
 * post-login open-redirect phishing hop. `SignInForm.test.tsx`'s own existing test (`next`
 * given a relative path) pinned the PASS-THROUGH behaviour without ever asserting a rejection
 * case, which is exactly why it did not catch this.
 *
 * Accepted: a string starting with exactly one `/`, followed by anything other than a second
 * `/` or a `\`. Rejected (falls back to `fallback`):
 *   - `undefined` / the empty string - nothing was given.
 *   - anything not starting with `/` at all - `https://evil.example`, `http://evil.example`,
 *     a bare host, a `javascript:` URL, etc. all fail this first check.
 *   - `//evil.example` - scheme-relative; a browser resolves this against the CURRENT
 *     protocol, reaching an attacker's origin exactly like a full `https://` URL would.
 *   - a leading-backslash variant (`/\evil.example`, `/\/evil.example`) - several browsers
 *     normalise a backslash to a forward slash before resolving a URL, so `/\evil.example`
 *     is the same hazard as `//evil.example` one character later, not a different one.
 */
export function safeNextPath(candidate: string | undefined, fallback = '/plans'): string {
  if (candidate === undefined || candidate.length === 0) return fallback;
  if (!candidate.startsWith('/')) return fallback;
  if (candidate.startsWith('//') || candidate.startsWith('/\\')) return fallback;
  return candidate;
}
