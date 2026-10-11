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

describe('SignUpForm', () => {
  beforeEach(() => {
    vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue({
      resolvedOptions: () => ({ timeZone: 'Africa/Nairobi' }),
    } as unknown as Intl.DateTimeFormat);
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
