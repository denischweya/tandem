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

  it('renders no notice for an unrecognised reason, rather than a blank one', () => {
    expect(noticeFor('something-else')).toBeNull();
    expect(noticeFor(undefined)).toBeNull();
  });
});
