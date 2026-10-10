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
 *
 * FIX ROUND 1, MINOR: a SECOND residual this file used to accept without a fix - "if the
 * LEADING tab is closed before it posts its result message, a follower is left waiting on a
 * promise that never settles" - was WORSE than that sentence said, and is now fixed rather
 * than merely documented. PROVEN: that promise becomes `localInFlight`, so the follower tab
 * could never refresh again for the life of the page, not just for the one call in flight
 * when the leader vanished - a second `refreshSession()` call returned the same stuck
 * promise and never touched `fetch` again.
 *
 * `FOLLOWER_TIMEOUT_MS` below is the fix: a follower that has waited this long for a `result`
 * gives up being a follower, clears the leadership flag, and falls through to performing its
 * own attempt - exactly the no-BroadcastChannel path's behaviour, reintroduced only in this
 * now-recovered case rather than left as a permanent hang. This is a DELIBERATE departure
 * from `tandem-mobile/src/session/sessionStore.ts`'s "no timers" position: that module's
 * timers would have fired forever against a healthy session, trading one correct
 * long-lived state for another; this one timer exists purely to escape a stuck wait that
 * otherwise never ends on its own, which is the one shape "no timers" was never arguing
 * against. 5 seconds is comfortably longer than a healthy `/auth/refresh` round trip and
 * short enough that a genuinely abandoned leader resolves within one user-perceptible beat.
 */
const FOLLOWER_TIMEOUT_MS = 5_000;

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

/**
 * Waits for the leading tab's `result` broadcast. Resolves with that outcome, OR with `null`
 * if `FOLLOWER_TIMEOUT_MS` elapses first - the leader-vanished recovery path. Whichever
 * happens first clears the OTHER path's effect: a late result arriving after the timeout is
 * a no-op (the timer already removed this waiter and reset `anotherTabIsLeading`), and a
 * result arriving in time clears the now-pointless timer so it never fires.
 */
function waitForLeaderResult(): Promise<boolean | null> {
  return new Promise<boolean | null>((resolve) => {
    let settled = false;

    // Referenced inside `onResult`, declared below it: safe, because `onResult` is only
    // ever CALLED later (asynchronously, once this whole block has finished running and
    // `timer` is assigned) - never synchronously from here.
    const onResult = (ok: boolean): void => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      resolve(ok);
    };

    followerResolvers.push(onResult);

    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      followerResolvers = followerResolvers.filter((entry) => entry !== onResult);
      // The presumed leader vanished without ever posting a result. Give up following it -
      // a late result arriving after this point finds itself with no waiter left and is
      // silently dropped, which is correct: this tab is no longer waiting on it.
      anotherTabIsLeading = false;
      resolve(null);
    }, FOLLOWER_TIMEOUT_MS);
  });
}

async function run(): Promise<boolean> {
  if (channel !== null && anotherTabIsLeading) {
    const outcome = await waitForLeaderResult();
    if (outcome !== null) return outcome;
    // Timed out waiting for the leader - fall through to performing our own attempt below,
    // exactly like the no-BroadcastChannel path.
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
