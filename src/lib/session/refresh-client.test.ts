import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Not given by the brief - its prose names four behaviours ("ten concurrent calls produce
 * one POST /api/auth/refresh"; "the slot resets so an eleventh produces a second"; "a 401
 * resolves false and does not retry"; "a second 'tab' … awaits the first's result rather
 * than issuing its own") without supplying code, so this file is written from that prose.
 *
 * `FakeBroadcastChannel` is a minimal same-process stand-in for the real thing: instances
 * sharing a name form a bus, `postMessage` delivers synchronously to every OTHER open
 * instance on that bus (never to the sender - matching the real API, and load-bearing for
 * the single-flight logic under test), and `FakeBroadcastChannel.resetBus()` clears it
 * between tests.
 *
 * "A second tab" is simulated with `vi.resetModules()` plus a second dynamic `import` of
 * `./refresh-client`: each import gets its own module-scope closure (`localInFlight`,
 * `anotherTabIsLeading`, …), exactly like two separate browser tabs each running their own
 * copy of the app's JS, while both constructed `BroadcastChannel`s share the one fake bus -
 * exactly like two tabs sharing the one real, OS-level channel.
 */

class FakeBroadcastChannel {
  private static bus = new Map<string, Set<FakeBroadcastChannel>>();

  onmessage: ((event: { data: unknown }) => void) | null = null;
  private closed = false;

  constructor(private readonly name: string) {
    const members = FakeBroadcastChannel.bus.get(name) ?? new Set<FakeBroadcastChannel>();
    members.add(this);
    FakeBroadcastChannel.bus.set(name, members);
  }

  postMessage(data: unknown): void {
    const members = FakeBroadcastChannel.bus.get(this.name);
    if (members === undefined) return;
    for (const member of members) {
      if (member === this || member.closed) continue;
      member.onmessage?.({ data });
    }
  }

  close(): void {
    this.closed = true;
    FakeBroadcastChannel.bus.get(this.name)?.delete(this);
  }

  static resetBus(): void {
    FakeBroadcastChannel.bus.clear();
  }
}

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((res) => {
    resolve = res;
  });
  return { promise, resolve };
}

beforeEach(() => {
  FakeBroadcastChannel.resetBus();
  vi.stubGlobal('BroadcastChannel', FakeBroadcastChannel);
  vi.resetModules();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('refreshSession - single tab', () => {
  it('ten concurrent calls produce exactly one POST /api/auth/refresh', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const { refreshSession } = await import('./refresh-client');

    const results = await Promise.all(Array.from({ length: 10 }, () => refreshSession()));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/auth/refresh');
    expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ method: 'POST' });
    expect(results).toEqual(Array(10).fill(true));
  });

  it('the slot resets so an eleventh call produces a second POST', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const { refreshSession } = await import('./refresh-client');

    await Promise.all(Array.from({ length: 10 }, () => refreshSession()));
    expect(fetchMock).toHaveBeenCalledTimes(1);

    await refreshSession();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('a 401 resolves false and does not retry', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 401 }));
    vi.stubGlobal('fetch', fetchMock);

    const { refreshSession } = await import('./refresh-client');

    const ok = await refreshSession();

    expect(ok).toBe(false);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('a transport failure (fetch itself throwing) resolves false rather than rejecting', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError('Failed to fetch'));
    vi.stubGlobal('fetch', fetchMock);

    const { refreshSession } = await import('./refresh-client');

    await expect(refreshSession()).resolves.toBe(false);
  });
});

describe('refreshSession - two tabs', () => {
  it("a second tab awaits the first's result rather than issuing its own", async () => {
    const gate = deferred<Response>();
    const fetchMock = vi.fn().mockReturnValue(gate.promise);
    vi.stubGlobal('fetch', fetchMock);

    // Both tabs' channels must exist (and be listening) before either calls
    // refreshSession - exactly as they would at real page load, well before either tab's
    // access token actually expires.
    const tabA = await import('./refresh-client');
    vi.resetModules();
    const tabB = await import('./refresh-client');

    const resultA = tabA.refreshSession();
    const resultB = tabB.refreshSession();

    // B must have deferred rather than racing: still only one fetch in flight.
    expect(fetchMock).toHaveBeenCalledTimes(1);

    gate.resolve(new Response(null, { status: 200 }));

    await expect(resultA).resolves.toBe(true);
    await expect(resultB).resolves.toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("propagates the leader's failure to the following tab too", async () => {
    const gate = deferred<Response>();
    const fetchMock = vi.fn().mockReturnValue(gate.promise);
    vi.stubGlobal('fetch', fetchMock);

    const tabA = await import('./refresh-client');
    vi.resetModules();
    const tabB = await import('./refresh-client');

    const resultA = tabA.refreshSession();
    const resultB = tabB.refreshSession();

    gate.resolve(new Response(null, { status: 401 }));

    await expect(resultA).resolves.toBe(false);
    await expect(resultB).resolves.toBe(false);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('a tab with no claim pending still performs its own fetch (no leader to follow)', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const tabA = await import('./refresh-client');
    vi.resetModules();
    const tabB = await import('./refresh-client');

    // Sequential, not overlapping: A's round fully completes before B starts, so B sees no
    // active leader and performs its own request rather than hanging forever.
    await tabA.refreshSession();
    await tabB.refreshSession();

    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('a DEAD leader (never posts a result) settles the follower via a session read, never a second /auth/refresh (fix round 2, Important I-C)', async () => {
    // Round 1's fix fell through to the follower performing its OWN /auth/refresh POST on
    // timeout. PROVEN by the reviewer to reopen the double-rotation disease this file
    // exists to prevent, for any leader slower than the deadline - see this module's own
    // docblock. The fix: a timed-out follower re-reads GET /api/auth/session and reports
    // THAT, never presenting the refresh token itself. Here the leader is genuinely dead
    // (its fetch never settles) and the session read correctly reports there is nothing
    // live yet - `refresh` is called exactly ONCE (the leader's own, original, doomed
    // attempt), never a second time from the follower.
    vi.useFakeTimers();
    try {
      const leaderNeverResolves = new Promise<Response>(() => {
        // Stands in for a leader tab that was closed (not just slow) before its fetch
        // returned - from the follower's point of view these are indistinguishable,
        // which is exactly why the follower must not guess by refreshing.
      });
      const refreshMock = vi.fn().mockReturnValue(leaderNeverResolves);
      const sessionMock = vi
        .fn()
        .mockResolvedValue(new Response(JSON.stringify({ status: 'expired' }), { status: 200 }));
      const fetchMock = vi.fn((url: string) => {
        if (url === '/api/auth/refresh') return refreshMock();
        if (url === '/api/auth/session') return sessionMock();
        throw new Error(`unexpected fetch url: ${url}`);
      });
      vi.stubGlobal('fetch', fetchMock);

      const tabA = await import('./refresh-client');
      vi.resetModules();
      const tabB = await import('./refresh-client');

      void tabA.refreshSession(); // the leader; never settles, by construction above.
      const resultB = tabB.refreshSession(); // the follower.

      await vi.advanceTimersByTimeAsync(10_000);

      // Settles - no hang - via the session read's answer, NOT via a second refresh.
      await expect(resultB).resolves.toBe(false);
      expect(refreshMock).toHaveBeenCalledTimes(1);
      expect(sessionMock).toHaveBeenCalledTimes(1);

      // The follower tab's OWN slot must also have reset, not just this one call's
      // promise - a later, FRESH attempt in the same tab can still genuinely refresh,
      // against a token nobody is mid-flight on by then.
      refreshMock.mockResolvedValue(new Response(null, { status: 200 }));
      const resultBAgain = tabB.refreshSession();
      await vi.advanceTimersByTimeAsync(0);
      await expect(resultBAgain).resolves.toBe(true);
      expect(refreshMock).toHaveBeenCalledTimes(2);
    } finally {
      vi.useRealTimers();
    }
  }, 2000);

  it('a SLOW BUT HEALTHY leader produces NO second refresh, even after the follower times out (fix round 2, Important I-C)', async () => {
    // THE acceptance test for this fix: measured against round 1's design, a healthy
    // leader on an 8-second round trip against a 5-second follower timeout produced a
    // SECOND /auth/refresh at 5.001s while the first was still in flight - two concurrent
    // presentations of the SAME refresh token, which is precisely the Rotated/RevokeChain
    // case this file exists to prevent. The fix must survive exactly this scenario: no
    // second POST to /auth/refresh, ever, regardless of what the follower's session read
    // reports while the leader is still working (it will see the pre-refresh, still-
    // expired state, same as if the leader were dead - there is no way to tell the two
    // apart from here, which is the whole reason this design does not guess).
    vi.useFakeTimers();
    try {
      const leaderGate = deferred<Response>();
      const refreshMock = vi.fn().mockReturnValue(leaderGate.promise);
      const sessionMock = vi
        .fn()
        .mockResolvedValue(new Response(JSON.stringify({ status: 'expired' }), { status: 200 }));
      const fetchMock = vi.fn((url: string) => {
        if (url === '/api/auth/refresh') return refreshMock();
        if (url === '/api/auth/session') return sessionMock();
        throw new Error(`unexpected fetch url: ${url}`);
      });
      vi.stubGlobal('fetch', fetchMock);

      const tabA = await import('./refresh-client');
      vi.resetModules();
      const tabB = await import('./refresh-client');

      const resultA = tabA.refreshSession(); // the leader; slow, not dead.
      const resultB = tabB.refreshSession(); // the follower.

      // Follower's timeout fires at 5s. The leader is STILL working (8s total).
      await vi.advanceTimersByTimeAsync(5_001);
      expect(refreshMock).toHaveBeenCalledTimes(1); // only the leader's own call, ever.
      expect(sessionMock).toHaveBeenCalledTimes(1); // the follower's recovery read.

      // The leader finally succeeds at 8s.
      leaderGate.resolve(new Response(null, { status: 200 }));
      await vi.advanceTimersByTimeAsync(3_000);

      await expect(resultA).resolves.toBe(true);
      await expect(resultB).resolves.toBe(false); // settled earlier, from the session read.

      // THE assertion: still exactly one call to /auth/refresh across the whole scenario.
      expect(refreshMock).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  }, 2000);
});

describe('refreshSession - no BroadcastChannel', () => {
  it('still performs the request directly (the accepted double-rotation residual)', async () => {
    vi.stubGlobal('BroadcastChannel', undefined);
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const { refreshSession } = await import('./refresh-client');

    await expect(refreshSession()).resolves.toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});
