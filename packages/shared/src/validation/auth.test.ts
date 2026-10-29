import { readFile, readdir } from 'node:fs/promises';
import { sep } from 'node:path';

import { describe, expect, it } from 'vitest';
import { z } from 'zod';

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

  it('rejects a malformed address but accepts, and trims, anything the server would', () => {
    // The email rule is a shape check, not z.email(), because the server's gate is
    // filter_var(FILTER_VALIDATE_EMAIL) and a client must not be stricter than it. See
    // apiEmail in auth.ts. These two are the cases that fix the boundary in place.
    expect(RegisterSchema.safeParse({ ...validRegistration, email: 'ada' }).success).toBe(false);
    expect(RegisterSchema.safeParse({ ...validRegistration, email: 'ada@' }).success).toBe(false);
    expect(RegisterSchema.safeParse({ ...validRegistration, email: '@example.test' }).success).toBe(
      false,
    );
    expect(
      RegisterSchema.safeParse({ ...validRegistration, email: 'ada bob@example.test' }).success,
    ).toBe(false);
    expect(
      RegisterSchema.safeParse({ ...validRegistration, email: 'ada@a@example.test' }).success,
    ).toBe(false);

    // An IP-literal domain: filter_var accepts it, z.email() does not. Accepting it here is
    // the whole point - an address the API accepts must be usable through a client.
    expect(
      RegisterSchema.safeParse({ ...validRegistration, email: 'ada@[127.0.0.1]' }).success,
    ).toBe(true);
    // A single-label domain: filter_var rejects this one, and so does the server. The schema
    // is deliberately looser, so the 422 comes from the API rather than from the client.
    expect(RegisterSchema.safeParse({ ...validRegistration, email: 'ada@example' }).success).toBe(
      true,
    );

    // Whitespace is the case that made the old title ("accepts anything the server would")
    // false: TrimStrings normalises before any rule runs, so the API registers this address,
    // while `[^\s@]` refused it here and one trailing space off a paste made an address
    // unregisterable through either client.
    const padded = RegisterSchema.safeParse({
      ...validRegistration,
      email: '  Ada@Example.test  ',
    });
    expect(padded.success).toBe(true);
    // The trimmed value is what the client would send. Case is deliberately left alone - the
    // server lowercases server-side and accepts either, so altering it here would buy nothing.
    expect(padded.success && padded.data.email).toBe('Ada@Example.test');
  });

  it('rejects a password of 7 characters and accepts 8', () => {
    expect(
      RegisterSchema.safeParse({ ...validRegistration, password: 'a'.repeat(7) }).success,
    ).toBe(false);
    expect(
      RegisterSchema.safeParse({ ...validRegistration, password: 'a'.repeat(8) }).success,
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

  it('treats locale as optional and capped at 10, and rejects it present but empty', () => {
    expect(RegisterSchema.safeParse(validRegistration).success).toBe(true);
    expect(RegisterSchema.safeParse({ ...validRegistration, locale: 'en-GB' }).success).toBe(true);

    // This case used to assert that '' is ACCEPTED, on the strength of RegisterRequest's rule
    // carrying no `required`. The rule never got the chance to matter: ConvertEmptyStringsToNull
    // turns '' into null before validation, and null fails `string`, so the API has always
    // answered 422 here - the same answer UpdateProfileSchema.locale already gave.
    expect(RegisterSchema.safeParse({ ...validRegistration, locale: '' }).success).toBe(false);
    // Whitespace-only, which `.min(1)` on its own counted as three characters and accepted.
    expect(RegisterSchema.safeParse({ ...validRegistration, locale: '   ' }).success).toBe(false);

    expect(RegisterSchema.safeParse({ ...validRegistration, locale: 'a'.repeat(11) }).success).toBe(
      false,
    );
    // The cap measures the trimmed value, as `max:10` does upstream: 14 characters in, 10 out.
    const padded = RegisterSchema.safeParse({
      ...validRegistration,
      locale: `  ${'a'.repeat(10)}  `,
    });
    expect(padded.success && padded.data.locale).toBe('a'.repeat(10));
  });

  it('rejects a whitespace-only timezone, and trims the one it keeps', () => {
    // TrimStrings and `required` both reject '   ' upstream - `required` applies PHP's trim
    // itself - while Zod's `.min(1)` counts characters. This is the case that gap allowed.
    expect(RegisterSchema.safeParse({ ...validRegistration, timezone: '   ' }).success).toBe(false);
    expect(RegisterSchema.safeParse({ ...validRegistration, timezone: '' }).success).toBe(false);

    const padded = RegisterSchema.safeParse({
      ...validRegistration,
      timezone: ' Europe/London ',
    });
    expect(padded.success && padded.data.timezone).toBe('Europe/London');
  });

  it('leaves the password exactly as submitted, because TrimStrings excepts it', () => {
    // TrimStrings::$except is ['current_password', 'password', 'password_confirmation'], so the
    // server hashes what was sent, padding and all. A `.trim()` here would register one
    // credential and sign in with another, which is why password is the one untrimmed field.
    const padded = RegisterSchema.parse({ ...validRegistration, password: '  hunter2two  ' });
    expect(padded.password).toBe('  hunter2two  ');
  });

  it('accepts a whitespace-only password the API would 422, which is a recorded divergence', () => {
    // Pinned rather than fixed. `required` runs PHP's trim() before calling a value empty, so
    // eight spaces is a 422 upstream and is accepted here. Closing it needs a value-PRESERVING
    // check, not a `.trim()` - see the MIDDLEWARE note in auth.ts. If this starts failing,
    // someone has closed it and that note must be rewritten rather than this line deleted.
    expect(
      RegisterSchema.safeParse({ ...validRegistration, password: ' '.repeat(8) }).success,
    ).toBe(true);
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
  it('rejects an empty password and imposes no length beyond that', () => {
    // LoginRequest deliberately omits Password::min(8): a rule on the submitted password
    // would refuse a legacy credential before checking it. So one character is accepted...
    expect(LoginSchema.safeParse({ email: 'ada@example.test', password: 'x' }).success).toBe(true);
    // ...and the empty string is not, which is most of what `required` means. Without this
    // case the schema could silently lose its .min(1) and nothing would notice.
    expect(LoginSchema.safeParse({ email: 'ada@example.test', password: '' }).success).toBe(false);
    // The rest of what `required` means is the whitespace-only case, and it is the same
    // recorded divergence as at registration: a 422 upstream, accepted here, and untrimmable
    // without changing the credential the client sends.
    expect(LoginSchema.safeParse({ email: 'ada@example.test', password: '  ' }).success).toBe(true);
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
    // Whitespace-only is the same 422 upstream as the empty string, by `required`'s own trim.
    expect(LoginSchema.safeParse({ ...base, install_id: '   ' }).success).toBe(false);
    expect(LoginSchema.safeParse({ ...base, install_id: 'a'.repeat(255) }).success).toBe(true);
    expect(LoginSchema.safeParse({ ...base, install_id: 'a'.repeat(256) }).success).toBe(false);
    // And the 255 cap measures the trimmed value, as `max:255` does behind TrimStrings.
    const padded = LoginSchema.safeParse({ ...base, install_id: `  ${'a'.repeat(255)}  ` });
    expect(padded.success && padded.data.install_id).toBe('a'.repeat(255));
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

  it('measures the 64 characters after trimming, as `size:64` does behind TrimStrings', () => {
    // A padded token is one the API accepts and this used to refuse. The exact-length rule is
    // the one place `.trim()` could have changed behaviour for the worse, so both ends are
    // pinned: the padding comes off, and a padded SHORT token is still refused.
    const padded = RefreshSchema.safeParse({ refresh_token: ` ${'f'.repeat(64)} ` });
    expect(padded.success && padded.data.refresh_token).toBe('f'.repeat(64));
    expect(RefreshSchema.safeParse({ refresh_token: ` ${'f'.repeat(63)} ` }).success).toBe(false);
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

  it('requires a 64-character token, measured after trimming', () => {
    expect(ResetPasswordSchema.safeParse(valid).success).toBe(true);
    expect(ResetPasswordSchema.safeParse({ ...valid, token: 'f'.repeat(63) }).success).toBe(false);
    expect(ResetPasswordSchema.safeParse({ ...valid, token: 'f'.repeat(65) }).success).toBe(false);
    // This is the endpoint where the trim earns its place: the token is pasted out of an
    // email, and the API trims before `size:64` sees it.
    const padded = ResetPasswordSchema.safeParse({ ...valid, token: `\n${'f'.repeat(64)} ` });
    expect(padded.success && padded.data.token).toBe('f'.repeat(64));
  });

  it('enforces the 8-character minimum the register endpoint enforces', () => {
    expect(ResetPasswordSchema.safeParse({ ...valid, password: 'a'.repeat(7) }).success).toBe(
      false,
    );
    expect(ResetPasswordSchema.safeParse({ ...valid, password: 'a'.repeat(8) }).success).toBe(true);
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
    // And whitespace-only, which only display_name used to catch, since it was the one field
    // here carrying a `.trim()`.
    expect(UpdateProfileSchema.safeParse({ timezone: '   ' }).success).toBe(false);
    expect(UpdateProfileSchema.safeParse({ locale: '   ' }).success).toBe(false);
  });

  it('trims every field before measuring it, as the middleware does upstream', () => {
    expect(UpdateProfileSchema.parse({ timezone: ' Europe/London ' })).toEqual({
      timezone: 'Europe/London',
    });
    expect(UpdateProfileSchema.parse({ locale: `  ${'a'.repeat(10)}  ` })).toEqual({
      locale: 'a'.repeat(10),
    });
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

describe("the contract's own constraints", () => {
  /**
   * The blind spot named in WireShape's docblock: openapi-typescript erases maxLength,
   * minLength and format, so `email` and `display_name` are both bare `string` in
   * src/types/api.ts and NO type-level assertion in this repo can notice `max:254` becoming
   * `max:320` upstream.
   *
   * Those constraints do live in openapi.json, so this reads them from there rather than
   * trusting the numbers the schemas above chose. That makes it a real second end of the
   * anti-drift chain and not another hand-copied constant: CI diffs the committed
   * openapi.json against tandem-api's main, so a constraint changed upstream reaches this
   * test by the same route the types take.
   */
  const ConstraintDocument = z.object({
    components: z.object({
      schemas: z.record(
        z.string(),
        z.object({
          properties: z.record(
            z.string(),
            // z.object() strips the keys this test does not read (`type`, `description`),
            // which is what makes the "no constraint at all" assertion below readable as
            // toEqual({}).
            z.object({
              maxLength: z.number().optional(),
              minLength: z.number().optional(),
              format: z.string().optional(),
              enum: z.array(z.string()).optional(),
            }),
          ),
        }),
      ),
    }),
  });

  type Constraints = {
    maxLength?: number;
    minLength?: number;
    format?: string;
    enum?: readonly string[];
  };

  /** Every constraint the schemas in this directory encode, as openapi.json states it. */
  const ENCODED: ReadonlyArray<readonly [string, string, Constraints]> = [
    ['RegisterRequest', 'email', { maxLength: 254, format: 'email' }],
    ['RegisterRequest', 'password', { minLength: 8 }],
    ['RegisterRequest', 'display_name', { maxLength: 80 }],
    ['RegisterRequest', 'locale', { maxLength: 10 }],
    ['LoginRequest', 'email', { maxLength: 254, format: 'email' }],
    ['LoginRequest', 'install_id', { maxLength: 255 }],
    ['LoginRequest', 'platform', { enum: ['ios', 'android', 'web'] }],
    ['RefreshTokenRequest', 'refresh_token', { minLength: 64, maxLength: 64 }],
    ['ForgotPasswordRequest', 'email', { maxLength: 254, format: 'email' }],
    ['ResetPasswordRequest', 'token', { minLength: 64, maxLength: 64 }],
    ['ResetPasswordRequest', 'email', { maxLength: 254, format: 'email' }],
    ['ResetPasswordRequest', 'password', { minLength: 8 }],
    ['UpdateProfileRequest', 'display_name', { maxLength: 80 }],
    ['UpdateProfileRequest', 'locale', { maxLength: 10 }],
    // The response side of the same chain. SessionSchema parses both expiries with
    // z.iso.datetime({ offset: true }), and the document stated them as bare `string` until
    // upstream annotated them - so a generated client typed them as strings and the reason for
    // returning them at all (scheduling a refresh ahead of expiry, setting a cookie's Max-Age)
    // was left to each client to rediscover. openapi-typescript erases the format into a
    // comment, so this table is the only place in this repo that can hold upstream to it.
    ['SessionResource', 'access_token_expires_at', { format: 'date-time' }],
    ['SessionResource', 'refresh_token_expires_at', { format: 'date-time' }],
    // --- Wave 2. Request-side lengths and enums, the end of the chain openapi-typescript erases.
    ['CreatePlanRequest', 'title', { maxLength: 255 }],
    ['CreatePlanRequest', 'description', { maxLength: 2000 }],
    ['UpdatePlanRequest', 'title', { maxLength: 255 }],
    ['UpdatePlanRequest', 'description', { maxLength: 2000 }],
    ['ChangeMemberRoleRequest', 'role', { enum: ['owner', 'admin', 'member'] }],
    ['InvitePlanMemberRequest', 'email', { maxLength: 254, format: 'email' }],
    ['InvitePlanMemberRequest', 'role', { enum: ['admin', 'member'] }],
    ['JoinPlanRequest', 'token', { minLength: 64, maxLength: 64 }],
    // The response side. Every one of these is parsed with z.iso.datetime({ offset: true }), and
    // openapi-typescript erases `format` into a comment, so this table is the only place in this
    // repository that can hold upstream to publishing them as date-time at all.
    //
    // The five NULLABLE timestamps are here too, and that is measured rather than assumed (D11a):
    // Scramble turns a `string|null` into a StringType with nullable = true, not into a union
    // object, and Type::toArray() emits `type: ["string","null"]` and `format: "date-time"` out of
    // the same array_filter - so a nullable timestamp publishes BOTH. toMatchObject() checks the
    // listed keys only, so a row naming `format` says nothing about `type` either way.
    ['PlanResource', 'created_at', { format: 'date-time' }],
    ['PlanResource', 'updated_at', { format: 'date-time' }],
    ['PlanResource', 'archived_at', { format: 'date-time' }],
    ['PlanMemberResource', 'joined_at', { format: 'date-time' }],
    ['PlanInviteResource', 'expires_at', { format: 'date-time' }],
    ['PlanInviteResource', 'accepted_at', { format: 'date-time' }],
    ['PlanInviteResource', 'revoked_at', { format: 'date-time' }],
    ['PlanInviteCreatedResource', 'expires_at', { format: 'date-time' }],
    ['PlanInviteCreatedResource', 'accepted_at', { format: 'date-time' }],
    ['PlanInviteCreatedResource', 'revoked_at', { format: 'date-time' }],
    ['PlanActivityResource', 'created_at', { format: 'date-time' }],
    // --- Wave 3. Chat request-side lengths, the same end of the chain openapi-typescript erases.
    ['SendMessageRequest', 'body', { maxLength: 4000 }],
    ['EditMessageRequest', 'body', { maxLength: 4000 }],
  ];

  const loadConstraints = async (): Promise<z.infer<typeof ConstraintDocument>> => {
    const raw = await readFile(new URL('../../openapi.json', import.meta.url), 'utf8');
    const data: unknown = JSON.parse(raw);

    return ConstraintDocument.parse(data);
  };

  it('still states every length, format and enum the schemas encode', async () => {
    // The same argument the schemaCount floor in 'the assertion harness itself' makes, applied
    // to this table's own length, because the table had no such floor and the first three waves
    // grew it from 16 rows to 35 to 37. Without this, a row DELETED here takes its constraint out
    // of the anti-drift chain and leaves the loop below iterating a shorter list, all green -
    // which is the one failure this table cannot otherwise see, since every assertion it makes is
    // about rows that are present. A floor rather than an exact count, so adding a thirty-eighth
    // row with its constraint must not fail; it has to MOVE with each wave for the same reason
    // the schema floor does.
    expect(ENCODED.length).toBeGreaterThanOrEqual(37);

    const document = await loadConstraints();

    for (const [schema, field, constraints] of ENCODED) {
      const property = document.components.schemas[schema]?.properties[field];

      expect(property, `${schema}.${field} is missing from openapi.json`).toBeDefined();
      expect(property, `${schema}.${field}`).toMatchObject(constraints);
    }
  });

  it('still imposes no constraint on the submitted login password', async () => {
    // The mirror image of the test above, and the one that keeps LoginSchema's docblock
    // honest: it claims the API imposes no length on a submitted password. If upstream ever
    // adds Password::min() to LoginRequest, this fails and that claim must be revisited.
    const document = await loadConstraints();

    expect(document.components.schemas['LoginRequest']?.properties['password']).toEqual({});
  });
});

describe('the assertion harness itself', () => {
  /**
   * Step 4's third outcome: deleting an `Expect<...>` line leaves every type-level gate
   * green, so nothing makes an assertion mandatory and a schema can be added with no proof at
   * all. This test is what turns that silent omission into a failing one.
   *
   * THE CONVENTION ENFORCED: a schema is proven by an `export type _Name = Expect<...>;` whose
   * Expect<...> body NAMES it. The body is the text that does the proving, so it is the text
   * the gate reads.
   *
   * Two earlier versions of this test are worth recording, because each was believed.
   *
   * The first counted assertions against schemas with `>=`, which left four assertions of
   * slack in session.ts - two schemas there carry six assertions, one per enveloped response,
   * so an unproven schema added to that file would have left 6 >= 3 and every gate green. The
   * slack survived because this test's own proof only ever sampled files whose counts are
   * tight (users.ts, and a scratch devices.ts). A verification that samples only the passing
   * case is worth less than no verification, because it is believed.
   *
   * The second credited an assertion to the schema whose name its OWN name began with, longest
   * match winning. That is a proxy for proof rather than proof, and three things walked
   * through it. Each was constructed, run, and watched to fail before being written down here:
   *
   *   - `export type _FooMatchesApi = Expect<true>;` satisfied the gate for FooSchema while
   *     proving nothing at all.
   *   - An assertion copy-pasted between schemas credited the wrong one. `_LoginMatchesApi =
   *     Expect<WireEquivalent<z.infer<typeof RegisterSchema>, LoginBody>>` was credited to
   *     LoginSchema by its name, while the only schema it mentions is RegisterSchema - so
   *     LoginSchema counted as proven by an assertion about something else.
   *   - `DeviceSchemaV2` matched `/^export const (\w+)Schema\b/gm` ZERO times, because the `\b`
   *     after `Schema` cannot match before `V`. Such a schema was neither required to carry a
   *     proof nor counted toward the floor.
   *
   * Reading the body closes the first two: `Expect<true>` names no schema, and a copy-pasted
   * assertion names the schema it was copied from rather than the one it sits under. Matching
   * `\w*Schema\w*` and keeping the whole identifier closes the third, and makes the
   * longest-prefix tie-break unnecessary rather than merely correct - `\b` around the
   * identifier is what keeps `UserSchema` from being credited by a body that says
   * `SessionUserSchema`, and `DeviceSchema` from being credited by one that says
   * `DeviceSchemaV2`.
   *
   * WHAT THIS STILL DOES NOT CATCH, stated rather than implied:
   *
   *   - Deleting one of several assertions that prove the SAME schema. Any one of session.ts's
   *     four UserResource envelope proofs satisfies the pairing on its own. The guarantee is
   *     that no schema is unproven, not that a particular set of proofs is present - an exact
   *     per-file count would catch this as well, at the cost of failing on every legitimate
   *     addition, and that trade was made deliberately.
   *   - A body that names two schemas credits both, so
   *     `Expect<WireEquivalent<z.infer<typeof ASchema>, z.infer<typeof BSchema>>>` stands as
   *     proof for A and for B while proving neither against the contract; a schema compared to
   *     itself is the degenerate case and credits itself. Closing this means deciding whether
   *     the OTHER side of the comparison came from `paths`, which is a question about resolved
   *     types rather than about text - a typescript-aware pass over the AST, not a regex, and
   *     the only thing in the repo that can really answer it is the compiler.
   *   - A schema whose exported name does not contain `Schema`, or an assertion not named
   *     `_...` and not written as `Expect<...>`. The naming IS the convention here, and
   *     nothing can enforce a convention it cannot see.
   *   - A declaration joined onto one line with another statement, which `^[ \t]*export const`
   *     cannot see. Left open deliberately: `prettier --check` runs in the same `npm run
   *     verify` and rejects that form. Measured, not assumed - `const a = 1; export const
   *     JoinedSchema = …;` is reported by `prettier --check` and split onto two lines by
   *     `prettier`, at which point the anchor sees it. Indentation, the other half of the same
   *     hole, IS handled: the anchor allows leading spaces and tabs, while a docblock's ` * `
   *     and a `//` comment still cannot match it.
   *   - Anything outside src/validation/. The scan is recursive now, so a nested directory
   *     under src/validation/ would be covered, but this reads a directory tree rather than
   *     the module graph. (Wave 2's plans.ts is a flat file; the recursion is kept because the
   *     reason for it is independent of that.)
   */
  /**
   * Capture group 1 of every match, in order. Named for what it returns rather than for what
   * it returns it from, because the two patterns below capture different things: an exported
   * schema's identifier, and the text of an assertion's Expect<...> body.
   */
  const captured = (pattern: RegExp, source: string): string[] => {
    const matched: string[] = [];

    for (const match of source.matchAll(pattern)) {
      const group = match[1];
      if (group !== undefined) matched.push(group);
    }

    return matched;
  };

  it('pairs an assignability assertion to every exported schema', async () => {
    const directory = new URL('.', import.meta.url);

    // Recursive, because Wave 2 adds src/validation/plans.ts and a non-recursive readdir
    // would never look inside a nested directory - a schema there would be unscanned rather
    // than unproven, which is the failure mode this whole test exists to rule out. The
    // separator swap is for the Windows spelling readdir returns; `new URL` wants forward
    // slashes.
    const files = (await readdir(directory, { recursive: true }))
      .map((name) => name.split(sep).join('/'))
      .filter((name) => name.endsWith('.ts') && !name.endsWith('.test.ts'));

    // Read the directory rather than a hard-coded list, so a schema file added later is
    // covered without anyone remembering to add it here. The guard below is what stops that
    // convenience from making the test vacuous: an empty scan, or a regex that stopped
    // matching, would find nothing to pair and pass.
    expect(files).toEqual(
      expect.arrayContaining(['auth.ts', 'plans.ts', 'session.ts', 'users.ts']),
    );

    let schemaCount = 0;

    for (const file of files) {
      const source = await readFile(new URL(file, directory), 'utf8');
      const schemas = captured(/^[ \t]*export const (\w*Schema\w*)\b/gm, source);
      // The body of each assertion, non-greedily up to the first `>;` - which is the end of
      // the statement whether prettier kept it on one line or wrapped it over three.
      const proofs = captured(/^[ \t]*export type _\w+ = Expect<([\s\S]*?)>;/gm, source);

      const unproven = schemas.filter(
        // Interpolation is safe because the capture group is `\w*Schema\w*`: no regex
        // metacharacter can reach this line.
        (schema) => !proofs.some((proof) => new RegExp(`\\b${schema}\\b`).test(proof)),
      );

      expect(unproven, `${file}: exported schema(s) with no assertion naming them`).toEqual([]);

      schemaCount += schemas.length;
    }

    // The twenty-seven schemas these two waves specify: ten from Wave 1a (auth 7, users 1,
    // session 2) and seventeen from Wave 2 (six resources, three cursor pages, five request
    // bodies, three error shapes). A floor rather than an exact count - adding a twenty-eighth
    // WITH its assertion must not fail - but it has to MOVE with each wave, because a floor left
    // behind is slack: at 27 actual against a floor of 10, a regex that stopped matching would
    // leave 12 >= 10 and this gate green, which is the one thing it exists to catch.
    expect(schemaCount).toBeGreaterThanOrEqual(27);
  });

  it('exports every schema in this directory from the package entry point', async () => {
    // The one gap nothing closed: a schema can be written, proved by an Expect<...> and counted
    // toward the floor above, and never exported from src/index.ts - with every gate in this
    // repository green and the schema unreachable from either client. Measured before this
    // existed: `grep -rn "index.ts" src/ scripts/ eslint.config.mjs tsconfig.json` found exactly
    // one reference, a comment in this file.
    //
    // Set equality in both directions, so it also catches the reverse - a name left in index.ts
    // after its schema was renamed, which is a build error for every consumer and nothing here.
    //
    // WHAT THIS DOES NOT CATCH, stated rather than implied: index.ts is scanned for every
    // \w*Schema\w* identifier anywhere in the file rather than only inside its export lists,
    // because `export { A } from '...'` and a prettier-wrapped multi-line list are different
    // shapes and the regex that reads both reads comments too. So a schema NAMED in a comment in
    // index.ts counts as exported. That is a false pass in one direction only, it needs someone
    // to write the name in a comment, and closing it means parsing the module rather than
    // reading it.
    const directory = new URL('.', import.meta.url);

    const files = (await readdir(directory, { recursive: true }))
      .map((name) => name.split(sep).join('/'))
      .filter((name) => name.endsWith('.ts') && !name.endsWith('.test.ts'));

    const declared: string[] = [];

    for (const file of files) {
      const source = await readFile(new URL(file, directory), 'utf8');
      declared.push(...captured(/^[ \t]*export const (\w*Schema\w*)\b/gm, source));
    }

    const entry = await readFile(new URL('../index.ts', directory), 'utf8');
    const exported = [...new Set(captured(/\b(\w*Schema\w*)\b/g, entry))];

    // No floor here, and none needed: if either scan broke, one list is empty and the other is
    // not, and the comparison fails. An empty-against-empty pass is the failure mode a floor
    // exists to rule out, and set equality against a non-empty counterpart already rules it out.
    expect(
      [...exported].sort(),
      'src/index.ts does not export exactly the schemas in this directory',
    ).toEqual([...declared].sort());
  });
});
