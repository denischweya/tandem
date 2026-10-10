'use client';

/**
 * THE ONLY refresh initiator in the web app. Nothing on the server refreshes - not
 * middleware, not a Server Component, not a data handler (`src/server/session.ts`'s
 * `getSession()` reports `{status: 'expired'}` and stops there).
 *
 * Why: a page load fans out N server-side fetches (RSC renders plus route handlers), all
 * reading the same refresh cookie value. Against an expired access token they would all 401
 * and all post that same token to `/auth/refresh`. The first rotates it; every other one is
 * then presenting an already-Rotated token, and the API's rotation policy maps `Rotated` to
 * revoking the whole lineage AND deleting every access token the user holds (§9.1.1). Two
 * concurrent server-side refreshes would therefore sign the user out of every device and look
 * exactly like a stolen-token replay. An in-process promise map would fix one Next instance
 * and fail the moment the web tier scales - which ADR 0004 makes a required component rather
 * than a static deployment.
 *
 * Moving the initiator into the browser removes the problem rather than mitigating it: a
 * refresh cookie lives in exactly one browser, so one single-flight per browser is complete.
 *
 * Two layers:
 *   - a module-level promise, so N components in one tab produce one request;
 *   - a BroadcastChannel election, so N TABS produce one request. The channel is constructed
 *     once at module load (not lazily inside the first call) so that a tab's listener is
 *     already live by the time ANOTHER tab's claim arrives, regardless of which tab happens
 *     to call `refreshSession()` first.
 *
 * Residual, named, matching the brief's own risk-acceptance for the no-BroadcastChannel case:
 *   - In a browser with no BroadcastChannel, two tabs can double-rotate. The loser gets a
 *     401, its cookies are cleared and it routes to sign-in - bounded and recoverable, not a
 *     global sign-out, because the server-side chain revocation only fires on a token that
 *     was already rotated by THIS browser.
 *   - A SECOND, unnamed-by-the-brief residual this implementation also accepts: if the
 *     LEADING tab is closed (not just slow) before it posts its `result` message, a follower
 *     in another tab is left waiting on a promise that never settles - there is no timeout
 *     fallback. `sessionStore.ts` in `tandem-mobile` is the precedent for the position taken
 *     here (“there are deliberately NO TIMERS anywhere in this module”): a timeout would add
 *     a race of its own (how long is long enough for a slow network, vs. how fast is
 *     responsive for a genuinely abandoned leader) for a case - a tab closed mid-refresh,
 *     with another tab mid-render waiting on the SAME exchange - narrow enough that the
 *     user's next action (a fresh navigation, which re-mounts and re-requests) resolves it in
 *     practice. Recorded here rather than silently accepted.
 */

type BroadcastMessage =
  { readonly type: 'claim' } | { readonly type: 'result'; readonly ok: boolean };

const CHANNEL_NAME = 'tandem:refresh-session';

let localInFlight: Promise<boolean> | null = null;

/** True once this tab has heard another tab's `claim` with no `result` yet seen for it. */
let anotherTabIsLeading = false;

let followerResolvers: Array<(ok: boolean) => void> = [];

const channel: BroadcastChannel | null =
  typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL_NAME);

if (channel !== null) {
  channel.onmessage = (event: MessageEvent<BroadcastMessage>) => {
    if (event.data.type === 'claim') {
      anotherTabIsLeading = true;
      return;
    }
    anotherTabIsLeading = false;
    const resolvers = followerResolvers;
    followerResolvers = [];
    for (const resolve of resolvers) resolve(event.data.ok);
  };
}

async function postRefresh(): Promise<boolean> {
  try {
    const response = await fetch('/api/auth/refresh', { method: 'POST' });
    return response.ok;
  } catch {
    // A transport failure resolves false exactly like a 401 does, from this function's
    // point of view - `useSession` treats "refresh did not produce a session" uniformly and
    // never distinguishes network failure from an invalid token here (that distinction, and
    // the decision NOT to treat either as evidence of theft, lives in `refreshHandler`).
    return false;
  }
}

async function run(): Promise<boolean> {
  if (channel !== null && anotherTabIsLeading) {
    return new Promise<boolean>((resolve) => {
      followerResolvers.push(resolve);
    });
  }

  if (channel !== null) channel.postMessage({ type: 'claim' } satisfies BroadcastMessage);

  const ok = await postRefresh();

  if (channel !== null) channel.postMessage({ type: 'result', ok } satisfies BroadcastMessage);

  return ok;
}

/**
 * Presents the `tandem_rt` cookie to `POST /api/auth/refresh` at most once per browser at a
 * time. Resolves `true` on a 200 (both cookies re-set by the handler) and `false` on anything
 * else - a 401 (terminal: both cookies cleared server-side) or a transport failure (cookies
 * left untouched) look identical from here, by design: this function's only job is "did the
 * session renew", and the two failure shapes are already handled correctly inside
 * `refreshHandler` before this ever sees a response.
 */
export function refreshSession(): Promise<boolean> {
  if (localInFlight !== null) return localInFlight;

  const inFlight = run().finally(() => {
    if (localInFlight === inFlight) localInFlight = null;
  });
  localInFlight = inFlight;
  return inFlight;
}
