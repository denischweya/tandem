import 'server-only';

import { headers } from 'next/headers';
import { UserSchema } from '@tandem/shared';
import { z } from 'zod';

import { callApi } from './apiFetch';
import { readSessionCookies } from './cookies';

export type SessionReadResult =
  | { readonly status: 'signedIn'; readonly user: z.infer<typeof UserSchema> }
  | { readonly status: 'expired' }
  | { readonly status: 'signedOut' };

/**
 * Thrown by `getSession()` for anything that is NOT one of `SessionReadResult`'s three
 * states - a non-401 error status, a transport failure, or a 2xx body that failed to parse.
 * Mirrors `useSession.ts`'s `SessionFetchError` on the client side of the exact same fix (see
 * `getSession`'s own docblock for why there is no fourth state to return instead): in Next's
 * App Router, an uncaught throw during a Server Component's render is caught by the nearest
 * `error.tsx` boundary ABOVE it in the route tree - the framework mechanism, not a claim
 * about this repo.
 *
 * TASK 11 gave this its first production caller: `src/app/plans/page.tsx`. No `error.tsx`
 * was added anywhere under `src/app` - that page handles this AT THE CALL SITE instead, with
 * its own try/catch (`readSessionSafely`, narrowed through a discriminated result rather than
 * a nullable-plus-flag pair) rendering `SessionErrorFallback`. A page-local catch was chosen
 * over a route-tree `error.tsx` because a single Server Component is already the right
 * granularity for "this one session read failed, offer to retry it" - a global boundary would
 * also catch render errors from pages that have nothing to do with a session read at all.
 * This docblock no longer names an absent boundary: either build the one named here, or
 * handle it at your own call site and update this paragraph in the same change, the way this
 * edit does.
 *
 * TASK 12 gave this a SECOND production caller: `src/app/invite/[token]/page.tsx`. It makes
 * the SAME per-call-site choice, with its own (deliberately un-shared) copy of the identical
 * `readSessionSafely` shape, rather than extracting one module both pages import - the two
 * pages disagree on what a failed read should render. `/plans` has nothing to show without a
 * session (`SessionErrorFallback` replaces the whole page), so it fails toward an error
 * state; `/invite/[token]`'s whole reason for existing is to show the plan to someone who
 * has not signed in yet, so it fails toward the SIGNED-OUT branch instead - the one render
 * this page can always produce regardless of whether the session read itself worked.
 *
 * CORRECTED (Fix round 1): this paragraph used to claim "a shared helper would have to
 * parameterise that fallback" - MEASURED FALSE, by the reviewer: the two copies are
 * byte-identical, neither takes nor uses a fallback parameter, and each page picks its own
 * fallback OUTSIDE the duplicated unit (in the branch reading `result.ok`), not inside it. A
 * single parameterless `readSessionSafely` here would serve both verbatim. The honest reason
 * it stays duplicated: sharing two byte-identical six-line wrappers buys little, not that
 * the call sites structurally differ in a way that would force a parameter. Still only an
 * `error.tsx` or a call-site catch, per the paragraph above - a third caller that wants the
 * SAME fallback as one of these two should import it from there rather than writing a third
 * copy.
 */
export class SessionReadError extends Error {
  constructor(readonly status: number) {
    super(`HTTP ${status}`);
    this.name = 'SessionReadError';
  }
}

function parseUser(json: unknown): z.infer<typeof UserSchema> | null {
  const parsed = z.object({ data: UserSchema }).safeParse(json);
  return parsed.success ? parsed.data.data : null;
}

/**
 * The read used from a Server Component render - `src/app/api/auth/session/route.ts`'s
 * `sessionHandler` is the one a CLIENT component calls over `fetch`, and this is the server-
 * side equivalent for a component that renders before any browser JavaScript runs.
 *
 * Reads the raw `Cookie` header through `next/headers`'s `headers()` (read-only, always legal
 * in a Server Component) and `readSessionCookies` - the exact parser `cookies.test.ts`
 * already proves - rather than `next/headers`'s `cookies()` jar. Either would work for a
 * READ; this one is chosen so the same tested decoding handles both the handler path and this
 * one, and so this module touches nothing that write-restricted `cookies()` guards.
 *
 * A SERVER COMPONENT CANNOT REFRESH (decision 2: the browser is the only refresh initiator),
 * so this never attempts one. `{status: 'expired'}` is the signal a page acts on by rendering
 * a small client component that calls `refreshSession()` and then `router.refresh()` - one
 * extra round trip on an expired access token, and zero cookie writes during a render.
 *
 * FIX ROUND 2, IMPORTANT 3 (reopened - the second half of the same defect round 1 fixed in
 * `sessionHandler`, one file over): this used to map EVERY non-ok `callApi` result to
 * `expired`, including a 429/500/503/transport failure that never examined the access token
 * at all. `expired` is exactly the signal that drives a browser-side `refreshSession()` call
 * per this docblock's own next paragraph - so a Server Component render during a brief API
 * outage walked straight into a refresh attempt for a credential that was never actually
 * invalid, the identical shape `sessionHandler`'s Important 3 fix already closed on the
 * client-fetched path. Only a 401 - and ONLY a 401 - now reports `expired`; everything else
 * (a non-401 error, a transport failure, or a 2xx body that fails to parse) throws
 * `SessionReadError` instead, so it never reaches that signal.
 */
export async function getSession(): Promise<SessionReadResult> {
  const headerBag = await headers();
  const { access } = readSessionCookies(headerBag.get('cookie'));
  if (access === null) return { status: 'signedOut' };

  const result = await callApi('/me', { method: 'GET', bearer: access });

  if (result.kind === 'ok') {
    const user = parseUser(result.json);
    if (user !== null) return { status: 'signedIn', user };
    throw new SessionReadError(502);
  }

  if (result.kind === 'error' && result.status === 401) {
    return { status: 'expired' };
  }

  throw new SessionReadError(result.kind === 'transportFailure' ? 502 : result.status);
}
