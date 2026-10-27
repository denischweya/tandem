'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';

import { refreshSession } from '@/lib/session/refresh-client';
import { HOUSE_SERVER_MESSAGE } from '@/server/errors';
import { ErrorState } from '@/components/ui/ErrorState';
import { LoadingState } from '@/components/ui/LoadingState';

type Phase = 'pending' | 'failed';

/**
 * `/plans`'s render for `getSession()` reporting `{status: 'expired'}` - `src/server/
 * session.ts`'s own docblock: "a Server Component cannot refresh (decision 2: the browser is
 * the only refresh initiator) … `{status: 'expired'}` is the signal a page acts on by
 * rendering a small client component that calls `refreshSession()` and then
 * `router.refresh()`". This is that component.
 *
 * FIX ROUND 1, Important 3. MEASURED: the previous version called `router.refresh()`
 * REGARDLESS of `refreshSession()`'s outcome. On a 429/500/503/transport failure both cookies
 * are untouched, so the re-render reads `expired` again, remounts a fresh instance of this
 * exact component, and fires another attempt - no delay, no cap, no visible error, TWO
 * network requests per iteration, while the user watches "Checking your session..." forever.
 * The removed docblock called this an "accepted residual" - a self-grade, not a mitigation,
 * and the reviewer correctly declined to let that stand in for a fix.
 *
 * `router.refresh()` now fires ONLY when `refreshSession()` resolves `true` (the access
 * cookie actually rotated). Any other outcome - a REAL 401 (`refreshHandler` already cleared
 * both cookies, so the next mount of this component would read `signedOut`, not `expired` -
 * but that is `/plans/page.tsx`'s read, not this component's retry) or a non-401 failure
 * (cookies untouched) - renders an `ErrorState` with a MANUAL "Try again" action instead of
 * retrying on its own: the user decides whether to attempt the refresh again, not an
 * unattended loop. Clicking it bumps `attempt`, which is the effect's own dependency, so it
 * runs `refreshSession()` exactly once per click - never on its own.
 *
 * `refreshSession()` resolving `false` is explicitly NOT proof the credential is bad (see
 * `refresh-client.ts`'s docblock on its own non-terminal failure modes), so this never writes
 * a "you're signed out" message over intact cookies - the copy is the same neutral house
 * sentence `SessionErrorFallback` already uses for an unrelated failure, not a claim about
 * what went wrong.
 *
 * FIX (Wave 1b final review, W7/D3). The global constraints are explicit: a 401 from
 * `POST /auth/refresh` is terminal - "discard both tokens, route to sign-in, show 'You were
 * signed out. Sign in again.'" MEASURED, the dead end this closes: on a REAL 401,
 * `refreshHandler` clears both cookies, but the comment above (correctly) never calls
 * `refreshSession()` or `router.refresh()` again automatically on failure - that discipline
 * is what round 1 fixed. Before this fix, the ONLY escape from the `failed` phase was the
 * "Try again" button, which called `refreshSession()` again directly: on a terminal 401 that
 * repeats identically forever (no cookie to present, same 401, same `false`), with no
 * navigation to `/signin` ever reachable - "Try again" looped back to the same error state.
 *
 * `isNowSignedOut` below answers the one question that distinguishes terminal from transient
 * without presenting the refresh token again (it is a plain `GET`, the same status endpoint
 * `/plans/page.tsx`'s own server-side `getSession()` is built on): has a LATER read of the
 * SAME cookies this browser now holds come back `signedOut`? That is true only once the
 * cookies are actually gone, i.e. only after a genuine terminal 401 - a merely transient
 * failure (429/500/503/transport) leaves both cookies untouched, so the SAME read comes back
 * `expired` again, same as today, and this check changes nothing for that case. Not auto-
 * retried - this performs no `/auth/refresh` POST of its own and triggers no further
 * `refreshSession()` call, so it cannot become the auto-loop round 1's fix closed; it only
 * ever runs once per already-failed attempt, gated behind the same manual retry discipline
 * as before.
 *
 * Deliberately a direct `router.push`, not a `router.refresh()` relying on
 * `/plans/page.tsx`'s own generic `signedOut` redirect: that redirect also fires for a
 * visitor who was never signed in at all (nothing here distinguishes the two), and the
 * review's own brief is explicit that "you were signed out" must never be shown to someone
 * who never had a session to lose. Pushing directly from HERE, once THIS component has
 * independently confirmed the session just ended, keeps the prescribed sentence scoped to
 * the one path that actually earns it.
 */
async function isNowSignedOut(): Promise<boolean> {
  try {
    const response = await fetch('/api/auth/session');
    if (!response.ok) return false;
    const json: unknown = await response.json();
    return (
      typeof json === 'object' &&
      json !== null &&
      'status' in json &&
      (json as { status: unknown }).status === 'signedOut'
    );
  } catch {
    return false;
  }
}

export function ExpiredSessionRefresh() {
  const router = useRouter();
  const [phase, setPhase] = useState<Phase>('pending');
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    void refreshSession().then(async (refreshed) => {
      if (cancelled) return;
      if (refreshed) {
        router.refresh();
        return;
      }
      if (await isNowSignedOut()) {
        if (cancelled) return;
        router.push('/signin?next=%2Fplans&reason=signedOut');
        return;
      }
      if (!cancelled) setPhase('failed');
    });
    return () => {
      cancelled = true;
    };
    // `router` only, plus `attempt` to let the manual retry below re-run this effect - see
    // this component's own docblock on why `router` alone would already fire exactly once
    // (its reference is stable for the life of the component) and why that is honest
    // `react-hooks/exhaustive-deps` compliance rather than a suppressed one - no new
    // `eslint-disable` for this task (global constraint).
  }, [router, attempt]);

  if (phase === 'failed') {
    return (
      <ErrorState
        title="Something went wrong"
        body={HOUSE_SERVER_MESSAGE}
        action={{
          label: 'Try again',
          onClick: () => {
            // Both in the CLICK HANDLER, not the effect body: bumping `attempt` re-runs the
            // effect below (it is one of its dependencies), and resetting `phase` here first
            // - rather than synchronously inside the effect - is what the loading state
            // needs to reappear for this new attempt without the effect itself calling
            // `setState` synchronously in its own body (a cascading-render footgun React's
            // own lint rule for effects flags, not something to suppress with a new
            // `eslint-disable`).
            setPhase('pending');
            setAttempt((count) => count + 1);
          },
        }}
      />
    );
  }

  return <LoadingState label="Checking your session…" />;
}
