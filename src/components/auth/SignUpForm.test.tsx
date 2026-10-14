// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const replace = vi.fn();
const push = vi.fn();
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, push, refresh: vi.fn() }),
}));

import { TEST_USER } from '@/test/fixtures';

import { SignUpForm } from './SignUpForm';

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status });
}

// See src/lib/auth/timezone.test.ts's identical helper for why this is a real instance with
// one method shadowed (not a plain object cast through `unknown`) built from a NATIVE
// constructor reference captured before any `vi.spyOn(Intl, 'DateTimeFormat')` runs.
const NativeDateTimeFormat = Intl.DateTimeFormat;

function fakeDateTimeFormat(timeZone: string): Intl.DateTimeFormat {
  const real = new NativeDateTimeFormat();
  const options = real.resolvedOptions();
  real.resolvedOptions = () => ({ ...options, timeZone });
  return real;
}

describe('SignUpForm', () => {
  beforeEach(() => {
    vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue(fakeDateTimeFormat('Africa/Nairobi'));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    replace.mockClear();
    push.mockClear();
  });

  it('shows the detected timezone read-only, and sends it along with the other fields', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(201, { user: TEST_USER }));
    vi.stubGlobal('fetch', fetchMock);
    const user = userEvent.setup();

    render(<SignUpForm />);
    const timezoneInput = screen.getByLabelText('Timezone');
    expect(timezoneInput).toHaveValue('Africa/Nairobi');
    expect(timezoneInput).toHaveAttribute('readonly');

    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(JSON.parse(init.body as string)).toEqual({
      email: 'ada@example.test',
      password: 'hunter2hunter2',
      display_name: 'Ada Lovelace',
      timezone: 'Africa/Nairobi',
    });
  });

  it('routes to /plans when the response has NO signedIn key (the signed-in success shape)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(201, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignUpForm />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
    expect(push).not.toHaveBeenCalled();
  });

  it('routes to /signin with the address prefilled when signedIn is false', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(201, { user: TEST_USER, signedIn: false, email: 'ada@example.test' }),
        ),
    );
    const user = userEvent.setup();

    render(<SignUpForm />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() =>
      expect(push).toHaveBeenCalledWith('/signin?email=ada%40example.test&reason=registered'),
    );
    expect(replace).not.toHaveBeenCalled();
  });

  /**
   * FIX ROUND 1, Important 4. MEASURED by the reviewer: a 201 body of
   * `{user, signedIn: false}` WITHOUT `email` matched the broad `{user}` member (non-strict
   * by default), silently stripping `signedIn`, and routed a registered-but-not-signed-in
   * user straight to `/plans` - probe output `push= [] replace= [["/plans"]]`. This is a
   * DIFFERENT drift than reordering the union (which the test below already guards): the
   * narrow member is tried first either way, but it requires `email` and this body has none,
   * so it falls through to the broad member regardless of order. `.strict()` on the broad
   * member is what actually closes it.
   */
  it('a 201 body with signedIn: false but no email does NOT silently route to /plans (Important 4)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(201, { user: TEST_USER, signedIn: false })),
    );
    const user = userEvent.setup();

    render(<SignUpForm />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Something went wrong on our end. Please try again.');
    expect(replace).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
  });

  /**
   * FIX ROUND 1, Important 2. The same unconditional-return defect `SignInForm` was measured
   * with, reproduced here: an `errors` map naming a field this form does not render.
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

    render(<SignUpForm />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('The given data was invalid.');
    expect(replace).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
  });

  it('maps a 422 field error (a duplicate email) onto the Email field, not a generic FormError', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { email: 'has already been taken' },
        }),
      ),
    );
    const user = userEvent.setup();

    render(<SignUpForm />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() =>
      expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true'),
    );
    expect(screen.getByText('has already been taken')).toBeInTheDocument();
    expect(screen.queryByRole('alert', { name: '' })).not.toHaveTextContent('Something went wrong');
  });

  it('a 429 (register is 5/hour/IP) renders as a form-level error with Retry-After', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(JSON.stringify({ message: 'Too Many Attempts.' }), {
          status: 429,
          headers: { 'retry-after': '3600' },
        }),
      ),
    );
    const user = userEvent.setup();

    render(<SignUpForm />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Too Many Attempts. Try again in 60 minutes.');
  });
});
