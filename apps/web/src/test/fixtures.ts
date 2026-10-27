/**
 * A `UserSchema`-valid fixture (`@tandem/shared`) for the handful of component tests that
 * need a 200/201 body to parse successfully - `id` must be a real UUID or `UserSchema.safeParse`
 * fails and the form falls back to its own "something went wrong" copy, which is the right
 * behaviour for a GENUINELY malformed body but the wrong one for a test that means to exercise
 * the happy path.
 */
export const TEST_USER = {
  id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@example.test',
  email_verified: true,
  display_name: 'Ada Lovelace',
  timezone: 'Africa/Nairobi',
  locale: 'en',
};
