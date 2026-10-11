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
 * FIX ROUND 1, MINOR (and FIX ROUND 2, IMPORTANT I-C, which reopened this): round 1 fixed a
 * follower that could hang FOREVER if the leading tab vanished before posting a `result`, by
 * giving up after `FOLLOWER_TIMEOUT_MS` and falling through to performing its OWN
 * `/auth/refresh` attempt - the same action the no-BroadcastChannel path takes.
 *
 * MEASURED to reopen the exact disease this file exists to prevent: with a HEALTHY leader on
 * an 8-second round trip and the then-5-second timeout, the follower's own attempt fired at
 * 5.001s while the leader's was STILL in flight - two concurrent `POST /auth/refresh` calls
 * presenting the SAME `tandem_rt` value, which is precisely §9.1.1's Rotated/RevokeChain
 * case: one wins, the other is read as a replay and the API deletes every access token the
 * user holds. A follower cannot distinguish "the leader died" from "the leader is just slow"
 * from silence alone - no fixed deadline is immune to a leader slower than it, and a longer
 * deadline only narrows the window, it does not close it.
 *
 * THE FIX: on timeout, the follower does NOT perform its own `/auth/refresh` at all. It
 * re-reads `GET /api/auth/session` instead and reports THAT as the final answer:
 *   - `signedIn` -> resolve `true`. The leader already succeeded (its cookies are live); no
 *     refresh is needed, and none is attempted.
 *   - anything else -> resolve `false`. Nothing is known to have changed, so nothing is
 *     assumed; `useSession` is left to react to `false` the way it already does (write
 *     `signedOut`). The refresh token itself is never touched by this tab in this round, so
 *     whatever the leader is doing with it - succeeding, failing, or still mid-flight -
 *     happens with no second presenter. A later, FRESH call to `refreshSession()` (the next
 *     page visit, the next retried action) starts a clean round against a token nobody is
 *     mid-flight on, which is what actually resolves a truly dead leader - not a guess made
 *     at the moment of this timeout.
 *
 * This is a DELIBERATE narrowing of what round 1's fix claimed: "the follower can then
 * legitimately claim and refresh" is not implemented here, because it is exactly the action
 * that reopens the double-rotation path for any leader slower than the deadline - proven by
 * `refresh-client.test.ts`'s "a slow but healthy leader must produce no second refresh" case,
 * which a session-check-then-refresh-anyway design cannot pass (the session read, taken
 * while the access token is still the pre-refresh one, reports `expired` regardless of
 * whether the leader is dead or merely slow - there is no observable difference between the
 * two from this tab until the leader's OWN request settles). Settling for "the slot never
 * gets stuck" rather than "a dead leader's refresh is transparently completed by someone
 * else" is the trade this file now makes, and it is the one round 1's own test had backwards:
 * that test asserted the second fetch as the DESIRED outcome, which was the test agreeing
 * with the bug.
 *
 * Still a DELIBERATE departure from `tandem-mobile/src/session/sessionStore.ts`'s "no
 * timers" position, for the reason round 1 gave: this timer exists purely to stop a wait that
 * would otherwise never end, not to poll a healthy session.
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
 * The timed-out follower's recovery action - see this module's own docblock for why this is
 * a READ, never a second `/auth/refresh` POST. Duck-typed on `status` rather than parsed
 * through `@tandem/shared`'s `UserSchema`/a zod schema: this module has no need for the user
 * object, only the one bit "is the session now live", and staying free of that import keeps
 * this file's only network dependency the one endpoint its own docblock describes.
 */
async function isSignedInAccordingToServer(): Promise<boolean> {
  try {
    const response = await fetch('/api/auth/session');
    if (!response.ok) return false;
    const json: unknown = await response.json();
    return (
      typeof json === 'object' &&
      json !== null &&
      'status' in json &&
      (json as { status: unknown }).status === 'signedIn'
    );
  } catch {
    return false;
  }
}

/**
 * Waits for the leading tab's `result` broadcast. Resolves with that outcome, OR with `null`
 * if `FOLLOWER_TIMEOUT_MS` elapses first - the leader-vanished-or-merely-slow case, where
 * `run()` below reads the server's own session state rather than guessing. A late result
 * arriving after the timeout finds no waiter left (removed below) and is silently dropped -
 * correct, since this tab already settled on the session-read's answer by then.
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
      resolve(null);
    }, FOLLOWER_TIMEOUT_MS);
  });
}

async function run(): Promise<boolean> {
  if (channel !== null && anotherTabIsLeading) {
    const outcome = await waitForLeaderResult();
    if (outcome !== null) return outcome;

    // Timed out. The leader's claim might still be genuinely active (it may simply be slow -
    // nothing observable here distinguishes that from dead), so this tab does NOT touch
    // `/auth/refresh` itself. It only asks the server what is currently true, and that
    // answer - not a refresh attempt - is this call's result. `anotherTabIsLeading` is reset
    // so a LATER, fresh `refreshSession()` call (not this one) is free to become a new
    // leader against a token nobody is then mid-flight on.
    anotherTabIsLeading = false;
    return isSignedInAccordingToServer();
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
 * `refreshHandler` before this ever sees a response. A FOLLOWER that timed out waiting for
 * the leader resolves from a session read instead of its own POST - see `run()`'s docblock.
 */
export function refreshSession(): Promise<boolean> {
  if (localInFlight !== null) return localInFlight;

  const inFlight = run().finally(() => {
    if (localInFlight === inFlight) localInFlight = null;
  });
  localInFlight = inFlight;
  return inFlight;
}
