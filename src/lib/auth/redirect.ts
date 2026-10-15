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
 * MEASURED (fix round 2, CRITICAL): round 1's guard rejected a LITERAL `//` or `/\` prefix,
 * but never accounted for the WHATWG URL parser's own preprocessing step - "remove all ASCII
 * tab or newline" (U+0009 TAB, U+000A LF, U+000D CR), applied to the WHOLE input string,
 * before anything else resolves it. `/\t/evil.example` passes round 1's checks untouched
 * (it starts with one `/`, not `//`, not `/\`), but by the time `router.replace` builds
 * `new URL(href, location.href)`, the tab between the two `/` characters has been stripped
 * and the string IS `//evil.example` - scheme-relative, off-origin - the identical hazard one
 * character later. Measured end to end: `new URLSearchParams('next=/%09/evil.example').get
 * ('next')` already decodes to `'/\t/evil.example'` before this function ever sees it, and
 * Next 16.3.8's `router.replace` resolves that string, sees `url.origin !== location.origin`,
 * and hard-navigates via `location.replace` to the attacker's origin.
 *
 * The fix is on the CHARACTER CLASS, not the handful of concrete strings a reviewer could
 * enumerate (`/\t/evil.example`, `/\n/evil.example`, `/\r/evil.example`, `/\t\\evil.example`,
 * …): reject a candidate containing a tab, LF or CR ANYWHERE, not merely immediately after
 * the leading slash. Two reasons it is deliberately this wide rather than "simulate the
 * stripping, then re-check for `//`":
 *   1. This function cannot validate a string that the browser will silently mutate before
 *      acting on it - any candidate containing one of these three characters is not
 *      trustworthy material to begin with, independent of where the character sits, because
 *      soundly predicting the stripped result would mean re-implementing (and keeping in
 *      sync with) the URL Standard's own preprocessing step rather than refusing to guess.
 *   2. None of this app's own routes ever contain a literal tab, LF or CR, so rejecting on
 *      sight costs nothing in the accept case and closes every arrangement at once, not just
 *      "right after the first slash".
 *
 * Accepted: a string starting with exactly one `/`, containing no ASCII tab/LF/CR anywhere,
 * and not followed immediately by a second `/` or a `\`. Rejected (falls back to `fallback`):
 *   - `undefined` / the empty string - nothing was given.
 *   - anything not starting with `/` at all - `https://evil.example`, `http://evil.example`,
 *     a bare host, a `javascript:` URL, etc. all fail this first check.
 *   - any candidate containing a tab, LF or CR - see above; this is a character-class test,
 *     unanchored, so it catches the hazard at ANY position, not only right after the slash.
 *   - `//evil.example` - scheme-relative; a browser resolves this against the CURRENT
 *     protocol, reaching an attacker's origin exactly like a full `https://` URL would.
 *   - a leading-backslash variant (`/\evil.example`, `/\/evil.example`) - several browsers
 *     normalise a backslash to a forward slash before resolving a URL, so `/\evil.example`
 *     is the same hazard as `//evil.example` one character later, not a different one.
 *
 * Still accepted, because both are genuinely same-origin against this app's own origin: a
 * same-document path carrying its own query string and/or fragment (`/invite/abc123?q=1#hash`
 * - `new URL()` resolves that against the current origin, untouched), and a path whose
 * SLASHES are themselves percent-encoded (`/%2f%2fevil.example` - `%2f` is a literal
 * three-character path segment to the URL parser; it is never decoded back into a real `/`
 * before the origin is determined, so this never becomes scheme-relative).
 */
export function safeNextPath(candidate: string | undefined, fallback = '/plans'): string {
  if (candidate === undefined || candidate.length === 0) return fallback;
  if (/[\t\n\r]/.test(candidate)) return fallback;
  if (!candidate.startsWith('/')) return fallback;
  if (candidate.startsWith('//') || candidate.startsWith('/\\')) return fallback;
  return candidate;
}
