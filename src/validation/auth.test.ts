import { readFile, readdir } from 'node:fs/promises';

import { describe, expect, it } from 'vitest';

import {
  ForgotPasswordSchema,
  LoginSchema,
  LogoutSchema,
  RefreshSchema,
  RegisterSchema,
  ResetPasswordSchema,
  VerifyEmailSchema,
} from './auth';
import { SessionSchema, UserSchema } from './session';
import { UpdateProfileSchema } from './users';

/**
 * One case per RULE boundary rather than one per schema: the assignability assertions in
 * auth.ts, users.ts and session.ts already prove the SHAPES agree, and no runtime test can
 * add to that. What they cannot prove is that a length or an enum matches the API's, which is
 * the only thing left for these to check.
 */

const DOMAIN = '@example.test';

/** An address of exactly `length` characters that is otherwise well-formed. */
const emailOfLength = (length: number): string => 'a'.repeat(length - DOMAIN.length) + DOMAIN;

const validRegistration = {
  email: 'ada@example.test',
  password: 'correct horse battery',
  display_name: 'Ada',
  timezone: 'Europe/London',
};

describe('RegisterSchema', () => {
  it('accepts an email at the 254-character limit and rejects 255', () => {
    expect(emailOfLength(254)).toHaveLength(254);
    expect(
      RegisterSchema.safeParse({ ...validRegistration, email: emailOfLength(254) }).success,
    ).toBe(true);
    expect(
      RegisterSchema.safeParse({ ...validRegistration, email: emailOfLength(255) }).success,
    ).toBe(false);
  });

  it('rejects a malformed address regardless of length', () => {
    expect(RegisterSchema.safeParse({ ...validRegistration, email: 'ada' }).success).toBe(false);
  });

  it('rejects a password of 11 characters and accepts 12', () => {
    expect(
      RegisterSchema.safeParse({ ...validRegistration, password: 'a'.repeat(11) }).success,
    ).toBe(false);
    expect(
      RegisterSchema.safeParse({ ...validRegistration, password: 'a'.repeat(12) }).success,
    ).toBe(true);
  });

  it('accepts a display_name of 80 characters and rejects 81', () => {
    expect(
      RegisterSchema.safeParse({ ...validRegistration, display_name: 'a'.repeat(80) }).success,
    ).toBe(true);
    expect(
      RegisterSchema.safeParse({ ...validRegistration, display_name: 'a'.repeat(81) }).success,
    ).toBe(false);
  });

  it('trims display_name before measuring it, as prepareForValidation does', () => {
    const padded = RegisterSchema.safeParse({
      ...validRegistration,
      display_name: `  ${'a'.repeat(80)}  `,
    });
    expect(padded.success).toBe(true);
    expect(padded.success && padded.data.display_name).toHaveLength(80);

    // '   ' is why the API trims first: `required` must see the trimmed value, or
    // DisplayName::fromString throws and a 422 becomes a 500.
    expect(RegisterSchema.safeParse({ ...validRegistration, display_name: '   ' }).success).toBe(
      false,
    );
  });

  it('rejects a missing required field', () => {
    expect(
      RegisterSchema.safeParse({
        email: validRegistration.email,
        password: validRegistration.password,
        display_name: validRegistration.display_name,
      }).success,
    ).toBe(false);
  });

  it('treats locale as optional, capped at 10, and permits the empty string the API permits', () => {
    expect(RegisterSchema.safeParse(validRegistration).success).toBe(true);
    expect(RegisterSchema.safeParse({ ...validRegistration, locale: 'en-GB' }).success).toBe(true);
    expect(RegisterSchema.safeParse({ ...validRegistration, locale: '' }).success).toBe(true);
    expect(RegisterSchema.safeParse({ ...validRegistration, locale: 'a'.repeat(11) }).success).toBe(
      false,
    );
  });

  it('strips an unknown field rather than rejecting it', () => {
    // Recorded rather than assumed, because it is load-bearing twice over: a stripping
    // z.object() is what keeps the schema's output assignable to the generated request body
    // (an extra property would fail _RegisterMatchesApi at compile time), and it is what lets
    // a server deploy a new response field ahead of a client without throwing.
    const parsed = RegisterSchema.parse({ ...validRegistration, nickname: 'ada' });
    expect(parsed).not.toHaveProperty('nickname');
    expect(Object.keys(parsed).sort()).toEqual(['display_name', 'email', 'password', 'timezone']);
  });
});

describe('LoginSchema', () => {
  it('imposes no minimum length on the submitted password', () => {
    // Matching LoginRequest, which deliberately omits Password::min(12): a rule on the
    // submitted password would refuse a legacy credential before checking it.
    expect(LoginSchema.safeParse({ email: 'ada@example.test', password: 'x' }).success).toBe(true);
  });

  it('rejects a platform outside the enum and accepts each member', () => {
    for (const platform of ['ios', 'android', 'web'] as const) {
      expect(
        LoginSchema.safeParse({ email: 'ada@example.test', password: 'x', platform }).success,
      ).toBe(true);
    }
    expect(
      LoginSchema.safeParse({ email: 'ada@example.test', password: 'x', platform: 'windows' })
        .success,
    ).toBe(false);
  });

  it('treats install_id as optional but not empty, capped at 255', () => {
    const base = { email: 'ada@example.test', password: 'x' };
    expect(LoginSchema.safeParse(base).success).toBe(true);
    expect(LoginSchema.safeParse({ ...base, install_id: '' }).success).toBe(false);
    expect(LoginSchema.safeParse({ ...base, install_id: 'a'.repeat(255) }).success).toBe(true);
    expect(LoginSchema.safeParse({ ...base, install_id: 'a'.repeat(256) }).success).toBe(false);
  });
});

describe('RefreshSchema and LogoutSchema', () => {
  const token = (length: number) => ({ refresh_token: 'f'.repeat(length) });

  it('requires a refresh_token of exactly 64 characters', () => {
    expect(RefreshSchema.safeParse(token(63)).success).toBe(false);
    expect(RefreshSchema.safeParse(token(64)).success).toBe(true);
    expect(RefreshSchema.safeParse(token(65)).success).toBe(false);
  });

  it('applies the same rule at logout, which shares the upstream request class', () => {
    expect(LogoutSchema.safeParse(token(63)).success).toBe(false);
    expect(LogoutSchema.safeParse(token(64)).success).toBe(true);
    expect(LogoutSchema.safeParse(token(65)).success).toBe(false);
  });
});

describe('ForgotPasswordSchema', () => {
  it('accepts a well-formed address and rejects a malformed one', () => {
    expect(ForgotPasswordSchema.safeParse({ email: 'ada@example.test' }).success).toBe(true);
    expect(ForgotPasswordSchema.safeParse({ email: 'ada@' }).success).toBe(false);
  });
});

describe('ResetPasswordSchema', () => {
  const valid = {
    token: 'f'.repeat(64),
    email: 'ada@example.test',
    password: 'correct horse battery',
  };

  it('requires a 64-character token', () => {
    expect(ResetPasswordSchema.safeParse(valid).success).toBe(true);
    expect(ResetPasswordSchema.safeParse({ ...valid, token: 'f'.repeat(63) }).success).toBe(false);
    expect(ResetPasswordSchema.safeParse({ ...valid, token: 'f'.repeat(65) }).success).toBe(false);
  });

  it('enforces the 12-character minimum the register endpoint enforces', () => {
    expect(ResetPasswordSchema.safeParse({ ...valid, password: 'a'.repeat(11) }).success).toBe(
      false,
    );
    expect(ResetPasswordSchema.safeParse({ ...valid, password: 'a'.repeat(12) }).success).toBe(
      true,
    );
  });
});

describe('VerifyEmailSchema', () => {
  const link = {
    id: '0199e2e4-0000-7000-8000-000000000000',
    hash: 'd0b4a1',
    expires: '1792000000',
    signature: 'a'.repeat(64),
  };

  it('coerces expires from the string a query parameter actually arrives as', () => {
    const parsed = VerifyEmailSchema.parse(link);
    expect(parsed.expires).toBe(1792000000);
  });

  it('rejects an expires that is not a positive integer', () => {
    // z.coerce.number() turns both of these into 0, which is why .positive() is there.
    expect(VerifyEmailSchema.safeParse({ ...link, expires: '' }).success).toBe(false);
    expect(VerifyEmailSchema.safeParse({ ...link, expires: null }).success).toBe(false);
    expect(VerifyEmailSchema.safeParse({ ...link, expires: 'soon' }).success).toBe(false);
    expect(VerifyEmailSchema.safeParse({ ...link, expires: '17.5' }).success).toBe(false);
  });

  it('rejects an id that is not a UUID', () => {
    expect(VerifyEmailSchema.safeParse({ ...link, id: '42' }).success).toBe(false);
  });

  it('rejects a missing signature', () => {
    expect(
      VerifyEmailSchema.safeParse({ id: link.id, hash: link.hash, expires: link.expires }).success,
    ).toBe(false);
  });
});

describe('UpdateProfileSchema', () => {
  it('accepts an empty patch, because every field is optional upstream', () => {
    expect(UpdateProfileSchema.safeParse({}).success).toBe(true);
  });

  it('rejects a field that is present but empty', () => {
    // ['sometimes', 'required', ...]: omit it to leave it alone, but do not send it blank.
    expect(UpdateProfileSchema.safeParse({ display_name: '' }).success).toBe(false);
    expect(UpdateProfileSchema.safeParse({ display_name: '   ' }).success).toBe(false);
    expect(UpdateProfileSchema.safeParse({ timezone: '' }).success).toBe(false);
    expect(UpdateProfileSchema.safeParse({ locale: '' }).success).toBe(false);
  });

  it('applies the same 80 and 10 character caps as registration', () => {
    expect(UpdateProfileSchema.safeParse({ display_name: 'a'.repeat(80) }).success).toBe(true);
    expect(UpdateProfileSchema.safeParse({ display_name: 'a'.repeat(81) }).success).toBe(false);
    expect(UpdateProfileSchema.safeParse({ locale: 'a'.repeat(10) }).success).toBe(true);
    expect(UpdateProfileSchema.safeParse({ locale: 'a'.repeat(11) }).success).toBe(false);
  });

  it('rejects email, which is not a profile field upstream, by stripping it', () => {
    expect(UpdateProfileSchema.parse({ email: 'new@example.test' })).toEqual({});
  });
});

describe('UserSchema and SessionSchema', () => {
  const user = {
    id: '0199e2e4-0000-7000-8000-000000000000',
    email: 'ada@example.test',
    email_verified: true,
    display_name: 'Ada',
    timezone: 'Europe/London',
    locale: 'en',
  };

  const session = {
    access_token: 'at',
    access_token_expires_at: '2026-10-10T12:34:56.789+00:00',
    refresh_token: 'f'.repeat(64),
    refresh_token_expires_at: '2026-11-09T12:34:56.789+00:00',
    user,
  };

  it('parses a response in the exact format the API emits', () => {
    expect(UserSchema.safeParse(user).success).toBe(true);
    expect(SessionSchema.safeParse(session).success).toBe(true);
  });

  it('accepts the numeric UTC offset DATE_RFC3339_EXTENDED produces', () => {
    // z.iso.datetime() defaults to offset: false, which rejects '+00:00' and would have
    // failed every real login response. This is the regression test for that default.
    expect(
      SessionSchema.safeParse({
        ...session,
        access_token_expires_at: '2026-10-10T12:34:56.789+01:00',
      }).success,
    ).toBe(true);
    expect(
      SessionSchema.safeParse({ ...session, access_token_expires_at: '2026-10-10 12:34:56' })
        .success,
    ).toBe(false);
  });

  it('rejects a user id that is not a UUID', () => {
    expect(UserSchema.safeParse({ ...user, id: '42' }).success).toBe(false);
  });

  it('rejects a missing response field', () => {
    expect(
      UserSchema.safeParse({
        id: user.id,
        email: user.email,
        display_name: user.display_name,
        timezone: user.timezone,
        locale: user.locale,
      }).success,
    ).toBe(false);
  });

  it('strips a field the server adds, so a server can deploy ahead of a client', () => {
    const parsed = UserSchema.parse({ ...user, avatar_url: 'https://example.test/a.png' });
    expect(parsed).not.toHaveProperty('avatar_url');
    // Note that the same addition FAILS _UserAcceptsMeShowResponse at compile time once the
    // contract is re-synced. Runtime tolerance and compile-time strictness are both wanted:
    // the client keeps working, and this repo still has to be updated.
    expect(parsed).toEqual(user);
  });
});

describe('the assertion harness itself', () => {
  // Step 4's third outcome: deleting an `Expect<...>` line leaves every gate green, so
  // nothing makes an assertion mandatory and a schema can be added with no proof at all.
  // This test fails when the number of exported schemas and the number of exported
  // assertions diverge - crude, but it converts a silent omission into a failing test.
  it('carries one assignability assertion per exported schema', async () => {
    const directory = new URL('.', import.meta.url);
    const files = (await readdir(directory)).filter(
      (name) => name.endsWith('.ts') && !name.endsWith('.test.ts'),
    );

    // Read the directory rather than a hard-coded list, so a schema file added later is
    // covered without anyone remembering to add it here. The two guards below are what stop
    // that convenience from making the test vacuous: an empty or regex-broken scan would
    // otherwise compare 0 against 0 in every file and pass.
    expect(files).toEqual(expect.arrayContaining(['auth.ts', 'session.ts', 'users.ts']));

    let schemaCount = 0;

    for (const file of files) {
      const source = await readFile(new URL(file, directory), 'utf8');
      const schemas = source.match(/^export const \w+Schema/gm) ?? [];
      const assertions = source.match(/^export type _\w+ = Expect</gm) ?? [];

      schemaCount += schemas.length;

      expect(
        assertions.length,
        `${file}: ${schemas.length} exported schema(s) but ${assertions.length} assertion(s)`,
      ).toBeGreaterThanOrEqual(schemas.length);
    }

    // The ten schemas this wave specifies. Not an exact count - adding an eleventh with its
    // assertion must not fail - but a floor, so a regex that stopped matching is caught.
    expect(schemaCount).toBeGreaterThanOrEqual(10);
  });
});
