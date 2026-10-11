// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { ForgotPasswordForm } from './ForgotPasswordForm';

function noBodyResponse(status: number): Response {
  return new Response(null, { status });
}

describe('ForgotPasswordForm', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('POSTs the email and shows the ONE fixed confirmation sentence on 202', async () => {
    const fetchMock = vi.fn().mockResolvedValue(noBodyResponse(202));
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));

    await waitFor(() =>
      expect(screen.getByTestId('forgot-password-confirmation')).toHaveTextContent(
        "If that address has an account, we've sent a link. Check your inbox.",
      ),
    );
    const [path, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/auth/forgot-password');
    expect(JSON.parse(init.body as string)).toEqual({ email: 'ada@example.test' });
    // 202 has an EMPTY body - forgotPasswordHandler never parses one. Nothing in the success
    // branch above read `result.json` to decide what to show; the sentence is fixed.
    expect(
      screen.getByText('Requesting another link will invalidate the one we just sent.'),
    ).toBeInTheDocument();
  });

  it('shows the SAME confirmation sentence whether or not the fixture pretends the address exists', async () => {
    // There is nothing in a 202-with-an-empty-body to branch on - this is the point, not a
    // limitation of the test. Both calls hit the exact same code path with the exact same
    // response shape, because that is what forgotPasswordHandler actually sends for a known
    // address, an unknown one and a suspended account alike.
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(noBodyResponse(202)));
    const user = userEvent.setup();

    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('Email'), 'unknown@example.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));

    await waitFor(() =>
      expect(screen.getByTestId('forgot-password-confirmation')).toHaveTextContent(
        "If that address has an account, we've sent a link. Check your inbox.",
      ),
    );
  });

  it('the resend button re-submits the same address without asking the visitor to retype it', async () => {
    const fetchMock = vi.fn().mockResolvedValue(noBodyResponse(202));
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));
    await screen.findByTestId('forgot-password-confirmation');

    await user.click(screen.getByRole('button', { name: 'Send another link' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    const [, secondInit] = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(JSON.parse(secondInit.body as string)).toEqual({ email: 'ada@example.test' });
  });

  it('a transport failure shows a form-level error and stays on the form, not the success state', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    const user = userEvent.setup();

    render(<ForgotPasswordForm />);
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(
      "We couldn't reach Tandem. Check your connection and try again.",
    );
    expect(screen.queryByTestId('forgot-password-confirmation')).toBeNull();
  });
});
