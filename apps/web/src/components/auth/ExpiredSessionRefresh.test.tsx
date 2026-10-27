// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

// `router` is hoisted as ONE stable object, not re-created per `useRouter()` call: the real
// Next.js router context hands out a stable reference for the life of a component (this
// component's own docblock relies on that), and a mock that returned a fresh object on every
// call would make `useEffect(..., [router, attempt])` see a "changed" dependency on every
// render and re-fire the effect - a test-only artefact that has nothing to do with the
// behaviour under test.
const { refresh, push, refreshSessionMock, router } = vi.hoisted(() => {
  const refresh = vi.fn();
  const push = vi.fn();
  return {
    refresh,
    push,
    refreshSessionMock: vi.fn(),
    router: { refresh, push, replace: vi.fn() },
  };
});
vi.mock('next/navigation', () => ({ useRouter: () => router }));
vi.mock('@/lib/session/refresh-client', () => ({ refreshSession: refreshSessionMock }));

import { ExpiredSessionRefresh } from './ExpiredSessionRefresh';

function sessionResponse(status: 'signedOut' | 'expired' | 'signedIn'): Response {
  return new Response(JSON.stringify({ status }), { status: 200 });
}

/**
 * FIX ROUND 1, Important 3. MEASURED: `router.refresh()` used to fire REGARDLESS of
 * `refreshSession()`'s outcome. On a 429/500/503/transport failure both cookies stay
 * untouched, so the re-render reads `expired` again, remounts this exact component, and
 * fires another attempt - no delay, no cap, no visible error, two network requests per
 * iteration, forever. These tests pin the fix: `router.refresh()` only on `true`, an
 * `ErrorState` with a MANUAL retry on anything else.
 *
 * WAVE 1B FINAL REVIEW, W7/D3: a failed `refreshSession()` now also triggers ONE read of
 * `/api/auth/session` (`isNowSignedOut`) to tell a genuinely TERMINAL 401 (both cookies
 * cleared server-side, so this read comes back `signedOut`) apart from a merely transient
 * failure (cookies untouched, this read comes back `expired` again, same as before). Every
 * test below that exercises the `false` branch now stubs `fetch` for that read.
 */
describe('ExpiredSessionRefresh', () => {
  afterEach(() => {
    refresh.mockClear();
    push.mockClear();
    refreshSessionMock.mockReset();
    vi.unstubAllGlobals();
  });

  it('calls router.refresh() exactly once when refreshSession() resolves true', async () => {
    refreshSessionMock.mockResolvedValue(true);
    render(<ExpiredSessionRefresh />);

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
    expect(refreshSessionMock).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('a non-401 refresh failure never calls router.refresh() and renders a manual-retry ErrorState instead of looping', async () => {
    refreshSessionMock.mockResolvedValue(false);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(sessionResponse('expired')));
    render(<ExpiredSessionRefresh />);

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Something went wrong on our end. Please try again.');
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    expect(refresh).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
    // Exactly ONE attempt happened on its own - no automatic second call.
    expect(refreshSessionMock).toHaveBeenCalledTimes(1);
  });

  it('the manual "Try again" action calls refreshSession() again, only on click, never on its own', async () => {
    refreshSessionMock.mockResolvedValue(false);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(sessionResponse('expired')));
    const user = userEvent.setup();
    render(<ExpiredSessionRefresh />);

    await screen.findByRole('button', { name: 'Try again' });
    expect(refreshSessionMock).toHaveBeenCalledTimes(1);

    await user.click(screen.getByRole('button', { name: 'Try again' }));
    await waitFor(() => expect(refreshSessionMock).toHaveBeenCalledTimes(2));
    // Still never called router.refresh() - the retry also failed (mock still resolves false).
    expect(refresh).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
  });

  it('a retry that succeeds calls router.refresh()', async () => {
    refreshSessionMock.mockResolvedValueOnce(false).mockResolvedValueOnce(true);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(sessionResponse('expired')));
    const user = userEvent.setup();
    render(<ExpiredSessionRefresh />);

    await screen.findByRole('button', { name: 'Try again' });
    await user.click(screen.getByRole('button', { name: 'Try again' }));

    await waitFor(() => expect(refresh).toHaveBeenCalledTimes(1));
  });

  /**
   * Wave 1b final review, W7/D3 - the break-and-restore proof's green half. MEASURED
   * REGRESSION this guards: before the fix, a terminal 401 (both cookies cleared
   * server-side) looked IDENTICAL to a transient one from this component's own point of
   * view - `refreshSession()` resolves `false` either way - so "Try again" just repeated
   * the same doomed `refreshSession()` call forever, with no navigation to `/signin` ever
   * reachable from here.
   */
  it('routes to /signin with the prescribed reason once a failed refresh is confirmed terminal', async () => {
    refreshSessionMock.mockResolvedValue(false);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(sessionResponse('signedOut')));
    render(<ExpiredSessionRefresh />);

    await waitFor(() =>
      expect(push).toHaveBeenCalledWith('/signin?next=%2Fplans&reason=signedOut'),
    );
    expect(refresh).not.toHaveBeenCalled();
    // The ErrorState/manual-retry path never renders for this outcome.
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('does not route to /signin when the confirming read itself fails - stays on the manual-retry ErrorState', async () => {
    refreshSessionMock.mockResolvedValue(false);
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    render(<ExpiredSessionRefresh />);

    await screen.findByRole('button', { name: 'Try again' });
    expect(push).not.toHaveBeenCalled();
  });
});
