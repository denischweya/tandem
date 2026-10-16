import { describe, expect, it } from 'vitest';

import { noticeFor } from './copy';

describe('noticeFor', () => {
  it('explains a registered-but-not-signed-in hand-off', () => {
    expect(noticeFor('registered')).toBe(
      "Your account is ready. We couldn't sign you in automatically — sign in below.",
    );
  });

  it('confirms a completed password reset', () => {
    expect(noticeFor('passwordReset')).toBe('Your password is changed. Sign in with it.');
  });

  /**
   * Wave 1b final review, W7/D3. The global constraints' prescribed sentence for a terminal
   * 401 on `POST /auth/refresh`, verbatim: "Discard both tokens, route to sign-in, show
   * 'You were signed out. Sign in again.'"
   */
  it('shows the prescribed terminal-401 sentence for a signedOut hand-off', () => {
    expect(noticeFor('signedOut')).toBe('You were signed out. Sign in again.');
  });

  it('renders no notice for an unrecognised reason, rather than a blank one', () => {
    expect(noticeFor('something-else')).toBeNull();
    expect(noticeFor(undefined)).toBeNull();
  });
});
