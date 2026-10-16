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

  /**
   * FIX ROUND 1 (reviewer Important 1). MEASURED by the reviewer: `SignUpForm.tsx`'s own
   * `router.replace(safeNextPath(next))` (the exact guard `SignInForm.test.tsx` already
   * covers with four tests) had NONE of its own - reverting it to either a hardcoded
   * `/plans` or the pre-Task-11 unguarded `router.replace(next ?? '/plans')` kept every
   * existing test in this file green. These four mirror `SignInForm.test.tsx`'s positive
   * case and its three rejection cases (round 1's off-origin and scheme-relative strings,
   * round 2's tab-smuggled one) onto the SAME `safeNextPath` call site in this form.
   */
  it('routes to `next` when it was given, instead of /plans', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(201, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignUpForm next="/invite/abc123" />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/invite/abc123'));
  });

  it('falls back to /plans rather than following an off-origin `next` (open-redirect guard)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(201, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignUpForm next="https://evil.example/tandem" />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
    expect(replace).not.toHaveBeenCalledWith('https://evil.example/tandem');
  });

  it('falls back to /plans rather than following a scheme-relative `next` (//evil.example)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(201, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignUpForm next="//evil.example" />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
  });

  it('falls back to /plans rather than following a tab-smuggled scheme-relative `next`', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(201, { user: TEST_USER })));
    const user = userEvent.setup();

    render(<SignUpForm next={'/\t/evil.example'} />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/plans'));
    expect(replace).not.toHaveBeenCalledWith('/\t/evil.example');
  });

  /**
   * The "Already have an account? Sign in" link forwards `next` too (added alongside the
   * `SignInForm` "Create an account" link fix, for the same reason: a visitor who followed
   * "Sign in and join," realised they need an account, used THIS link instead, then
   * realised they already had one, should not have their invite context dropped on the
   * last hop back).
   */
  it('the "Already have an account?" link forwards `next` when given', () => {
    render(<SignUpForm next="/invite/abc123" />);
    expect(screen.getByTestId('create-account-signin-link')).toHaveAttribute(
      'href',
      '/signin?next=%2Finvite%2Fabc123',
    );
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
   * FIX ROUND 1 (reviewer Important 2). `SignUpForm.tsx`'s `params.set('next', next)` is
   * spec §11 case 3's load-bearing hop: drop it and a brand-new user who registers but is
   * NOT auto-signed-in silently loses the invite and lands on a plain `/signin`, with
   * nothing red anywhere - the existing test above only renders with no `next` at all, so it
   * cannot see this. Extends it rather than replacing it, so the no-`next` case stays
   * covered on its own.
   */
  it('forwards `next` into the /signin hand-off when given, for a registered-but-not-signed-in user', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(201, { user: TEST_USER, signedIn: false, email: 'ada@example.test' }),
        ),
    );
    const user = userEvent.setup();

    render(<SignUpForm next="/invite/abc123" />);
    await user.type(screen.getByLabelText('Name'), 'Ada Lovelace');
    await user.type(screen.getByLabelText('Email'), 'ada@example.test');
    await user.type(screen.getByLabelText('Password'), 'hunter2hunter2');
    await user.click(screen.getByRole('button', { name: 'Create an account' }));

    await waitFor(() =>
      expect(push).toHaveBeenCalledWith(
        '/signin?email=ada%40example.test&reason=registered&next=%2Finvite%2Fabc123',
      ),
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

  /**
   * Wave 1b final review, W3/D4/3.9's break-and-restore proof. MEASURED REGRESSION this
   * guards: before the fix, `isRegisterField('timezone')` was `true`, so a 422 keyed ONLY
   * `timezone` (a browser reporting a zone the API's `IanaTimeZone` list lacks) attached its
   * message to the read-only Timezone Input via `form.setError` - unreachable, since the
   * visitor cannot edit it - AND suppressed the form-level `FormError` that would otherwise
   * have shown the API's own message, because `matchedFieldErrors` counted it as "matched".
   * A hard dead end on account creation with nothing on screen to act on, and web has no
   * Profile page this cycle to correct it from either.
   */
  it('a 422 keyed only timezone falls back to a form-level message, not a silent dead end on a read-only field', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'The selected timezone is invalid.',
          errors: { timezone: 'The selected timezone is invalid.' },
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
    expect(alert).toHaveTextContent('The selected timezone is invalid.');
    expect(screen.getByLabelText('Timezone')).not.toHaveAttribute('aria-invalid', 'true');
    expect(replace).not.toHaveBeenCalled();
    expect(push).not.toHaveBeenCalled();
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
