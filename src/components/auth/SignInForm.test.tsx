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
