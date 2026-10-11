// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

const push = vi.fn();
vi.mock('next/navigation', () => ({
  useRouter: () => ({ push, replace: vi.fn(), refresh: vi.fn() }),
}));

import { TEST_USER } from '@/test/fixtures';

import { VerifyEmailClient } from './VerifyEmailClient';

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status });
}

// The exact byte-fragile substring this whole surface exists to protect: a space encoded as
// `%20` (which `URLSearchParams` would turn into `+`) and a `~` (which it would turn into
// `%7E`). Neither character is realistic in a real signed link, but using them is what makes
// a `.slice(1)` vs. a `URLSearchParams` round trip provably different in this test, rather
// than coincidentally identical.
const RAW_QUERY = 'id=1&hash=a%20b~c&expires=9999999999&signature=xyz';

function setLocationSearch(query: string): void {
  window.history.pushState({}, '', `/verify-email?${query}`);
}

describe('VerifyEmailClient', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    push.mockClear();
    window.history.pushState({}, '', '/verify-email');
  });

  it('does NOT POST on mount - only a click fires the request', () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    setLocationSearch(RAW_QUERY);

    render(<VerifyEmailClient />);

    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Verify email' })).toBeInTheDocument();
  });

  it('relays window.location.search.slice(1) VERBATIM - not a URLSearchParams re-serialisation', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { user: TEST_USER }));
    vi.stubGlobal('fetch', fetchMock);
    setLocationSearch(RAW_QUERY);
    const user = userEvent.setup();

    render(<VerifyEmailClient />);
    await user.click(screen.getByRole('button', { name: 'Verify email' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe(`/api/auth/verify-email?${RAW_QUERY}`);
    // A URLSearchParams round trip would have turned '%20' into '+' and '~' into '%7E' -
    // asserting the raw bytes is what makes this test fail if that ever regresses, rather
    // than merely checking SOME query string was sent.
    expect(path).toContain('a%20b~c');
    expect(path).not.toContain('a+b');
    expect(path).not.toContain('%7E');
    // No body, no content-type - verifyEmailHandler takes no body at all.
    expect(init.method).toBe('POST');
    expect(init.body).toBeUndefined();
  });

  it('renders success and routes onward to /plans on 200', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, { user: TEST_USER })));
    setLocationSearch(RAW_QUERY);
    const user = userEvent.setup();

    render(<VerifyEmailClient />);
    await user.click(screen.getByRole('button', { name: 'Verify email' }));

    await screen.findByText('Your email is verified');
    await user.click(screen.getByRole('button', { name: 'Continue to Tandem' }));
    expect(push).toHaveBeenCalledWith('/plans');
  });

  it('a 403 (any of the four causes) shows ONE message with "Sign in and we\'ll help", not a dead resend button', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(403, { message: 'This action is unauthorized.' })),
    );
    setLocationSearch(RAW_QUERY);
    const user = userEvent.setup();

    render(<VerifyEmailClient />);
    await user.click(screen.getByRole('button', { name: 'Verify email' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('This action is unauthorized.');
    expect(screen.queryByText(/request a new link/i)).toBeNull();

    await user.click(screen.getByRole('button', { name: "Sign in and we'll help" }));
    expect(push).toHaveBeenCalledWith('/signin');
  });
});
