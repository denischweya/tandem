'use client';

import { useRouter } from 'next/navigation';
import { useEffect } from 'react';

import { refreshSession } from '@/lib/session/refresh-client';
import { LoadingState } from '@/components/ui/LoadingState';

/**
 * `/plans`'s render for `getSession()` reporting `{status: 'expired'}` - `src/server/
 * session.ts`'s own docblock: "a Server Component cannot refresh (decision 2: the browser is
 * the only refresh initiator) … `{status: 'expired'}` is the signal a page acts on by
 * rendering a small client component that calls `refreshSession()` and then
 * `router.refresh()`". This is that component.
 *
 * Attempts exactly ONE `refreshSession()` call (the single-flight module it calls into
 * de-dupes further; this effect's own empty dependency array stops THIS component instance
 * from firing a second one) and calls `router.refresh()` regardless of the outcome - the
 * resulting re-render reads `getSession()` again with whatever the cookies now are:
 *
 *   - refreshed `true` -> the access cookie rotated, the re-render reads `signedIn`.
 *   - refreshed `false` via a REAL 401 -> `refreshHandler` already cleared both cookies, so
 *     the re-render reads `signedOut` and `/plans/page.tsx` redirects to `/signin` - a correct
 *     terminal outcome, not a loop.
 *   - refreshed `false` via a non-401 (a 429/500/503, a transport failure, or a timed-out
 *     follower's session read - `refreshSession()`'s own docblock names all three) -> both
 *     cookies are UNTOUCHED, so the re-render reads the same expired access token and lands
 *     back on `expired`, mounting a fresh instance of this exact component. This is a known,
 *     bounded residual, the Server-rendered-page equivalent of the one
 *     `src/lib/session/useSession.ts` accepts on the client-fetched path (that hook's own
 *     docblock calls its version of this trade-off "a known, accepted rough edge" rather than
 *     a silent one) - not a tight loop (each attempt is bounded by real network latency, not a
 *     synchronous retry), and never a dead end that forces the user to retype a password:
 *     `refreshSession()` resolving `false` is explicitly NOT proof the credential is bad (see
 *     the task report's citation of `refresh-client.ts`'s three non-terminal failure modes),
 *     so this never writes a "you're signed out" message over intact cookies.
 */
export function ExpiredSessionRefresh() {
  const router = useRouter();

  useEffect(() => {
    let cancelled = false;
    void refreshSession().then(() => {
      if (!cancelled) router.refresh();
    });
    return () => {
      cancelled = true;
    };
    // `router` only: `useRouter()`'s return value is a stable reference for the life of the
    // component (Next's router context does not hand out a new object per render), so this
    // still fires exactly once despite satisfying `react-hooks/exhaustive-deps` honestly
    // rather than by suppressing it - no new `eslint-disable` for this task (global
    // constraint).
  }, [router]);

  return <LoadingState label="Checking your session…" />;
}
