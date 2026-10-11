// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const { redirectMock, getSessionMock } = vi.hoisted(() => ({
  redirectMock: vi.fn((url: string) => {
    throw new Error(`NEXT_REDIRECT:${url}`);
  }),
  getSessionMock: vi.fn(),
}));

vi.mock('next/navigation', () => ({
  redirect: redirectMock,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn() }),
}));

vi.mock('@/server/session', () => ({ getSession: getSessionMock }));

import { TEST_USER } from '@/test/fixtures';

import PlansPage from './page';

describe('PlansPage', () => {
  afterEach(() => {
    redirectMock.mockClear();
    getSessionMock.mockReset();
  });

  it('redirects a signed-out visitor to /signin?next=/plans', async () => {
    getSessionMock.mockResolvedValue({ status: 'signedOut' });

    await expect(PlansPage()).rejects.toThrow('NEXT_REDIRECT:/signin?next=%2Fplans');
  });

  it('renders the §134 empty state with no verify-email banner for a verified user', async () => {
    getSessionMock.mockResolvedValue({
      status: 'signedIn',
      user: { ...TEST_USER, email_verified: true },
    });

    render(await PlansPage());

    expect(screen.getByText('Nothing planned yet.')).toBeInTheDocument();
    expect(screen.getByText('Create a Plan and invite your people.')).toBeInTheDocument();
    expect(screen.queryByTestId('plans-verify-banner')).toBeNull();
  });

  it('renders the verify-email banner when email_verified is false - the only Cycle 1 signal', async () => {
    getSessionMock.mockResolvedValue({
      status: 'signedIn',
      user: { ...TEST_USER, email_verified: false },
    });

    render(await PlansPage());

    const banner = screen.getByTestId('plans-verify-banner');
    expect(banner).toHaveTextContent('Verify your email');
    expect(screen.getByRole('link', { name: 'Verify your email' })).toHaveAttribute(
      'href',
      '/verify-email',
    );
  });

  it('renders ExpiredSessionRefresh (not a redirect) when the access token has expired', async () => {
    getSessionMock.mockResolvedValue({ status: 'expired' });

    render(await PlansPage());

    expect(screen.getByRole('status')).toHaveTextContent('Checking your session');
    expect(redirectMock).not.toHaveBeenCalled();
  });

  it('renders SessionErrorFallback, not a thrown error, when getSession() throws', async () => {
    getSessionMock.mockRejectedValue(new Error('HTTP 503'));

    render(await PlansPage());

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Something went wrong on our end. Please try again.',
    );
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });
});
