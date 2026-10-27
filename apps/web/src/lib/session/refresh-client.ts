'use client';

/**
 * THE ONLY refresh initiator in the web app. Nothing on the server refreshes - not
 * middleware, not a Server Component, not a data handler (`src/server/session.ts`'s
 * `getSession()` reports `{status: 'expired'}` on a 401 and stops there).
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
 * FIX ROUND 1 / ROUND 2 (Important I-C): a follower that gives up waiting for the leader's
 * `result` broadcast does NOT perform its own `/auth/refresh` - it re-reads
 * `GET /api/auth/session` and reports THAT instead, because a session read taken while a
 * leader is still working is indistinguishable from one taken against a leader that already
 * died; refreshing in either case risks presenting the SAME `tandem_rt` the (possibly still
 * in-flight) leader is also presenting.
 *
 * FIX ROUND 3 (Important): round 2's docblock claimed a later fresh call "starts a clean
 * round against a token nobody is mid-flight on" - MEASURED FALSE for the interval between
 * the follower's own timeout and the leader's actual completion. The bug: a single flag
 * (`anotherTabIsLeading`) was reset to `false` the moment a follower gave UP WAITING, which
 * is a different event from the leader ACTUALLY FINISHING. A fresh `refreshSession()` call
 * in that tab after the reset - a retried action, a navigation - read "nobody is leading"
 * and became a leader itself, presenting `tandem_rt` concurrently with a leader that was
 * simply slow, not dead:
 *
 *   after timeout:                           [POST /auth/refresh, GET /auth/session]
 *   fresh call while leader still in flight:  [POST /auth/refresh, GET /auth/session,
 *                                              POST /auth/refresh]   <- two concurrent POSTs
 *
 * THE FIX separates two questions a single flag was conflating:
 *   - WHEN DO I STOP WAITING AND ANSWER? `FOLLOWER_TIMEOUT_MS`, unchanged. This gates a READ
 *     only - it presents no token - so it stays short, and giving up here never by itself
 *     allows a refresh.
 *   - WHEN MAY THIS TAB CLAIM LEADERSHIP (PRESENT THE TOKEN) AGAIN? `CLAIM_BOUND_MS`, a much
 *     larger ABSOLUTE bound measured from the moment a `claim` was last heard with no
 *     `result` since - not from a follower's own wait giving up. `leaderClaimedAt` (a
 *     timestamp, not a boolean) is what is now reset, and only once `CLAIM_BOUND_MS` has
 *     elapsed since it was set - never merely because one follower stopped waiting.
 *
 * `CLAIM_BOUND_MS = 30_000`, argued rather than asserted: this is a single DB-backed POST to
 * a token-rotation endpoint, not a long-running operation - even on a poor connection, under
 * server load, or through a retrying lower layer, 30 seconds is far beyond the time such a
 * request plausibly takes to either complete or definitively fail at the transport level
 * (`fetch` surfaces a connection failure in well under that on every realistic network).
 * It is also short enough that a genuinely abandoned leader (the tab was closed, not merely
 * slow) does not block this tab from attempting its own refresh for an unreasonable span of
 * real time. Unlike `FOLLOWER_TIMEOUT_MS` in round 2's now-deleted claim, this number is not
 * asked to distinguish "dead" from "slow" at the moment it is read - it only has to be longer
 * than any plausible single request, which is a much easier bar to argue and does not need
 * to be perfect: if it is ever too short for a genuinely slow-but-alive leader, the cost is
 * the SAME bounded, recoverable double-rotation the no-BroadcastChannel path already accepts
 * (one tab's chain revoked, that tab signs back in) - not a silent correctness violation.
 *
 * A fresh call inside the leftover window (the claim is still within `CLAIM_BOUND_MS`, but
 * THIS call's own `waitForLeaderResult` has separately timed out) re-reads the session again
 * rather than claiming - `refresh-client.test.ts`'s "a fresh initiation inside the leftover
 * window" case proves this directly: still exactly one `/auth/refresh` POST across two
 * separate `refreshSession()` calls in the same tab.
 *
 * Still a DELIBERATE departure from `tandem-mobile/src/session/sessionStore.ts`'s "no
 * timers" position, for the reason round 1 gave: these timers exist purely to stop a wait
 * (and a claim's honoured lifetime) that would otherwise never end, not to poll a healthy
 * session.
 */
const FOLLOWER_TIMEOUT_MS = 5_000;
const CLAIM_BOUND_MS = 30_000;

type BroadcastMessage =
  { readonly type: 'claim' } | { readonly type: 'result'; readonly ok: boolean };

const CHANNEL_NAME = 'tandem:refresh-session';

let localInFlight: Promise<boolean> | null = null;

/**
 * `Date.now()` of the most recently heard `claim` with no `result` seen since, or `null` if
 * none is outstanding. A TIMESTAMP, not a boolean (round 2's `anotherTabIsLeading`) - see this
 * module's own docblock for why a boolean conflated "stopped waiting" with "no longer
 * honoured" and reopened the double-rotation this file exists to prevent.
 */
let leaderClaimedAt: number | null = null;

let followerResolvers: Array<(ok: boolean) => void> = [];

const channel: BroadcastChannel | null =
  typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL_NAME);

if (channel !== null) {
  channel.onmessage = (event: MessageEvent<BroadcastMessage>) => {
    if (event.data.type === 'claim') {
      leaderClaimedAt = Date.now();
      return;
    }
    leaderClaimedAt = null;
    const resolvers = followerResolvers;
    followerResolvers = [];
    for (const resolve of resolvers) resolve(event.data.ok);
  };
}

/** Is another tab's claim still within its honoured window? The ONLY gate on whether a fresh
 *  call may present the refresh token itself - never a follower's own wait giving up. */
function anotherClaimIsHonoured(): boolean {
  return leaderClaimedAt !== null && Date.now() - leaderClaimedAt < CLAIM_BOUND_MS;
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
 * if `FOLLOWER_TIMEOUT_MS` elapses first - the caller-waited-long-enough case, where `run()`
 * below reads the server's own session state rather than guessing. A late result arriving
 * after the timeout finds no waiter left (removed below) and is silently dropped - correct,
 * since this tab already settled on the session-read's answer by then. This timeout is
 * UNRELATED to `CLAIM_BOUND_MS` - giving up here never by itself permits a new claim; see
 * this module's own docblock.
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
  if (channel !== null && anotherClaimIsHonoured()) {
    const outcome = await waitForLeaderResult();
    if (outcome !== null) return outcome;

    // This call's own wait gave up, but the CLAIM itself may still be within its honoured
    // window - the leader may simply be slow, and nothing here distinguishes that from
    // dead. Re-check rather than falling through to a fresh claim unconditionally.
    if (anotherClaimIsHonoured()) {
      return isSignedInAccordingToServer();
    }
    // The claim's own bound has ALSO now elapsed - fall through to becoming a fresh leader.
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
