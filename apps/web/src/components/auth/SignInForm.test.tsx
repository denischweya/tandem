// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

const replace = vi.fn();
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, push: vi.fn(), refresh: vi.fn() }),
}));

import { TEST_USER } from '@/test/fixtures';

import { SignInForm } from './SignInForm';

function jsonResponse(status: number, body: unknown, headers?: Record<string, string>): Response {
  return new Response(JSON.stringify(body), {
    status,
    ...(headers !== undefined ? { headers } : {}),
  });
}

describe('SignInForm', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    replace.mockClear();
  });

  it('submits email and password to /api/auth/login and routes to /plans on success', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { user: TEST_USER }));
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(<SignInForm />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/auth/login');
    expect(JSON.parse(init.body as string)).toEqual({
      email: 'ada@example.test',
      password: 'hunter2hunter2',
    });
  });

  it('routes to `next` when it was given, instead of /plans', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignInForm next="/invite/abc123" />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/invite/abc123'));
  });

  /**
   * FIX ROUND 1, Important 1. MEASURED: `/signin?next=https://evil.example/tandem` used to
   * send a user who had JUST AUTHENTICATED straight to an off-origin page - the classic
   * post-login open-redirect phishing hop, via `router.replace(next ...)` with no same-origin
   * check. `safeNextPath` (src/lib/auth/redirect.ts) now gates every `next` value; this test
   * reproduces the EXACT attack string from the finding and asserts the fallback, not just
   * the accept case the pre-existing test above already had.
   */
  it('falls back to /plans rather than following an off-origin `next` (open-redirect guard)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignInForm next="https://evil.example/tandem" />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
    expect(replace).not.toHaveBeenCalledWith('https://evil.example/tandem');
  });

  it('falls back to /plans rather than following a scheme-relative `next` (//evil.example)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignInForm next="//evil.example" />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
  });

  /**
   * FIX ROUND 2, CRITICAL. MEASURED end to end (not just at `safeNextPath`'s own boundary):
   * `/\t/evil.example` passed round 1's guard untouched - it starts with one `/`, not `//` -
   * but the WHATWG URL parser strips the tab before `router.replace` resolves the string, so
   * the browser would have hard-navigated off-origin exactly as if `next` had been
   * `//evil.example` outright. This reproduces that through the real component, the same way
   * the two tests above do for the round-1 cases.
   */
  it('falls back to /plans rather than following a tab-smuggled scheme-relative `next`', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignInForm next={'/\t/evil.example'} />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
    expect(replace).not.toHaveBeenCalledWith('/\t/evil.example');
  });

  /**
   * FIX ROUND 1 (reviewer Important 2). This link was added so a visitor who followed
   * "Sign in and join," realised they need an account, and clicked "Create an account"
   * instead would not have their invite context dropped on the way - a gap found while
   * fixing `SignUpForm`'s class, not named by the original brief. Untested until now.
   */
  it('the "Create an account" link forwards `next` when given', () => {
    render(<SignInForm next="/invite/abc123" />);
    expect(screen.getByTestId('sign-in-signup-link')).toHaveAttribute(
      'href',
      '/signup?next=%2Finvite%2Fabc123',
    );
  });

  it('a 401 renders the API sentence verbatim as a form-level error, never against a field', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(401, { message: 'These credentials do not match our records.' }),
        ),
    );
    const user = userEvent.setup();

    render(<SignInForm />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'wrong-password');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('These credentials do not match our records.');
    // The API cannot say which field was wrong - guessing would be a lie - so neither Input
    // carries a field-level error for a 401.
    expect(screen.getByLabelText('Email')).not.toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText('Password')).not.toHaveAttribute('aria-invalid', 'true');
  });

  it('a 429 appends "Try again in N minutes" and shows no remaining-attempts count', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(429, { message: 'Too Many Attempts.' }, { 'retry-after': '120' }),
        ),
    );
    const user = userEvent.setup();

    render(<SignInForm />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'x');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Too Many Attempts. Try again in 2 minutes.');
    expect(alert.textContent).not.toMatch(/\d+ attempts? (left|remaining)/i);
  });

  /**
   * FIX ROUND 1, Important 2. MEASURED by the reviewer: `errors: {captcha: 'This field is
   * required.'}` produced `alerts=[]` - no field absorbed it (no Input here is named
   * `captcha`), the unconditional `return` right after the mapping loop swallowed the API's
   * own `message`, and the submit SILENTLY DID NOTHING while the button re-enabled. This is
   * the exact probe from the finding, reproduced against this form directly.
   */
  it('a 422 whose errors map names no rendered field falls back to a form-level FormError, not a silent no-op', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { captcha: 'This field is required.' },
        }),
      ),
    );
    const user = userEvent.setup();

    render(<SignInForm />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('The given data was invalid.');
    expect(screen.getByLabelText('Email')).not.toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByLabelText('Password')).not.toHaveAttribute('aria-invalid', 'true');
    expect(replace).not.toHaveBeenCalled();
  });

  it('shows the registered-but-not-signed-in notice when reason=registered', () => {
    render(<SignInForm reason="registered" prefilledEmail="ada@example.test" />);
    expect(screen.getByTestId('sign-in-notice')).toHaveTextContent(
      "Your account is ready. We couldn't sign you in automatically",
    );
    expect(screen.getByLabelText('Email')).toHaveValue('ada@example.test');
  });

  it('shows the password-reset confirmation notice when reason=passwordReset', () => {
    render(<SignInForm reason="passwordReset" />);
    expect(screen.getByTestId('sign-in-notice')).toHaveTextContent(
      'Your password is changed. Sign in with it.',
    );
  });

  /**
   * Wave 1b final review, W7/D3. The hand-off `ExpiredSessionRefresh` pushes once it has
   * confirmed a failed refresh was a genuine terminal 401, not just an echo of `copy.test.ts`
   * - this is the end-to-end render path a visitor actually sees after that redirect.
   */
  it('shows the prescribed terminal-401 notice when reason=signedOut', () => {
    render(<SignInForm reason="signedOut" />);
    expect(screen.getByTestId('sign-in-notice')).toHaveTextContent(
      'You were signed out. Sign in again.',
    );
  });

  it('never calls fetch for a client-side-invalid submission (empty email)', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(<SignInForm />);
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() =>
      expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true'),
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
