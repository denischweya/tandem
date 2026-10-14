// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

const replace = vi.fn();
const push = vi.fn();
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, push, refresh: vi.fn() }),
}));

import { ResetPasswordForm } from './ResetPasswordForm';

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status });
}

function noBodyResponse(status: number): Response {
  return new Response(null, { status });
}

const TOKEN = 'a'.repeat(64);

describe('ResetPasswordForm', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    replace.mockClear();
    push.mockClear();
  });

  it('renders the token as a HIDDEN field, carrying the value from the query string', () => {
    render(<ResetPasswordForm token={TOKEN} email="ada@example.test" />);
    const tokenField = screen.getByTestId('reset-password-token');
    expect(tokenField).toHaveAttribute('type', 'hidden');
    expect(tokenField).toHaveValue(TOKEN);
    // Email IS visible and editable - unlike the token, it is an ordinary form input the
    // visitor could see and change (src/lib/auth/fields.ts's own docblock on why `token` is
    // excluded from the mapped field list, not just hidden visually).
    expect(screen.getByLabelText('Email')).toHaveValue('ada@example.test');
  });

  it('blocks submission locally when the two password fields do not match', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(<ResetPasswordForm token={TOKEN} email="ada@example.test" />);
    await user.type(screen.getByLabelText('New password'), 'hunter2hunter2');
    await user.type(screen.getByLabelText('Confirm new password'), 'different-password');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    expect(await screen.findByText('Passwords do not match.')).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('on 204 routes to /signin with the password-reset confirmation reason, no refresh attempted', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(noBodyResponse(204)));
    const user = userEvent.setup();

    render(<ResetPasswordForm token={TOKEN} email="ada@example.test" />);
    await user.type(screen.getByLabelText('New password'), 'hunter2hunter2');
    await user.type(screen.getByLabelText('Confirm new password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    await waitFor(() =>
      expect(replace).toHaveBeenCalledWith('/signin?email=ada%40example.test&reason=passwordReset'),
    );
  });

  /**
   * THE guarded case: `errors.token` is carved out BEFORE the generic field-mapping loop, so
   * it renders as a form-level `ErrorState` with a "Request a new link" action - never
   * against the hidden token input, where Step 6.2 of the task report shows it would be
   * invisible.
   */
  it('a dead-token 422 replaces the form with an ErrorState, never a hidden-field message', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'This password reset link is no longer valid. Request a new one.',
          errors: { token: 'This password reset link is no longer valid. Request a new one.' },
        }),
      ),
    );
    const user = userEvent.setup();

    render(<ResetPasswordForm token={TOKEN} email="ada@example.test" />);
    await user.type(screen.getByLabelText('New password'), 'hunter2hunter2');
    await user.type(screen.getByLabelText('Confirm new password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(
      'This password reset link is no longer valid. Request a new one.',
    );
    // The form itself is GONE - replaced, not overlaid - so the message is not sitting next
    // to a hidden input that happens to carry the same error.
    expect(screen.queryByTestId('reset-password-token')).toBeNull();
    expect(screen.getByRole('button', { name: 'Request a new link' })).toBeInTheDocument();
  });

  it('"Request a new link" navigates to /forgot-password', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'This password reset link is no longer valid. Request a new one.',
          errors: { token: 'This password reset link is no longer valid. Request a new one.' },
        }),
      ),
    );
    const user = userEvent.setup();

    render(<ResetPasswordForm token={TOKEN} email="ada@example.test" />);
    await user.type(screen.getByLabelText('New password'), 'hunter2hunter2');
    await user.type(screen.getByLabelText('Confirm new password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));
    await user.click(await screen.findByRole('button', { name: 'Request a new link' }));

    expect(push).toHaveBeenCalledWith('/forgot-password');
  });

  /**
   * FIX ROUND 1, Important 2. The same unconditional-return defect `SignInForm` was measured
   * with, reproduced here on the branch that runs AFTER the token carve-out: an `errors` map
   * naming a field this form does not render, with no `token` key present at all.
   */
  it('a 422 whose errors map names no rendered field (and no token) falls back to a form-level FormError', async () => {
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

    render(<ResetPasswordForm token={TOKEN} email="ada@example.test" />);
    await user.type(screen.getByLabelText('New password'), 'hunter2hunter2');
    await user.type(screen.getByLabelText('Confirm new password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('The given data was invalid.');
    // Still the form (the FormError sits inside it), not the dead-token ErrorState.
    expect(screen.getByTestId('reset-password-token')).toBeInTheDocument();
  });

  it('maps a non-token field error (e.g. email) onto its own field, leaving the form in place', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { email: 'must look like an email address' },
        }),
      ),
    );
    const user = userEvent.setup();

    render(<ResetPasswordForm token={TOKEN} email="ada@example.test" />);
    await user.type(screen.getByLabelText('New password'), 'hunter2hunter2');
    await user.type(screen.getByLabelText('Confirm new password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Reset password' }));

    await waitFor(() =>
      expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true'),
    );
    expect(screen.getByText('must look like an email address')).toBeInTheDocument();
    // Still the form, not the dead-token ErrorState.
    expect(screen.getByTestId('reset-password-token')).toBeInTheDocument();
  });
});
