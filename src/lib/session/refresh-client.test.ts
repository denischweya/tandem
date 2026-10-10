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
