import { readFile, readdir } from 'node:fs/promises';

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

  it('rejects a malformed address but accepts anything the server would', () => {
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
  it('mirrors `required` on the password and imposes no length beyond it', () => {
    // LoginRequest deliberately omits Password::min(12): a rule on the submitted password
    // would refuse a legacy credential before checking it. So one character is accepted...
    expect(LoginSchema.safeParse({ email: 'ada@example.test', password: 'x' }).success).toBe(true);
    // ...and the empty string is not, which is all `required` means. Without this case the
    // schema could silently lose its .min(1) and nothing would notice.
    expect(LoginSchema.safeParse({ email: 'ada@example.test', password: '' }).success).toBe(false);
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
    ['RegisterRequest', 'password', { minLength: 12 }],
    ['RegisterRequest', 'display_name', { maxLength: 80 }],
    ['RegisterRequest', 'locale', { maxLength: 10 }],
    ['LoginRequest', 'email', { maxLength: 254, format: 'email' }],
    ['LoginRequest', 'install_id', { maxLength: 255 }],
    ['LoginRequest', 'platform', { enum: ['ios', 'android', 'web'] }],
    ['RefreshTokenRequest', 'refresh_token', { minLength: 64, maxLength: 64 }],
    ['ForgotPasswordRequest', 'email', { maxLength: 254, format: 'email' }],
    ['ResetPasswordRequest', 'token', { minLength: 64, maxLength: 64 }],
    ['ResetPasswordRequest', 'email', { maxLength: 254, format: 'email' }],
    ['ResetPasswordRequest', 'password', { minLength: 12 }],
    ['UpdateProfileRequest', 'display_name', { maxLength: 80 }],
    ['UpdateProfileRequest', 'locale', { maxLength: 10 }],
  ];

  const loadConstraints = async (): Promise<z.infer<typeof ConstraintDocument>> => {
    const raw = await readFile(new URL('../../openapi.json', import.meta.url), 'utf8');
    const data: unknown = JSON.parse(raw);

    return ConstraintDocument.parse(data);
  };

  it('still states every length, format and enum the schemas encode', async () => {
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
   * It pairs assertions to schemas BY NAME. The first version counted them with `>=`, which
   * left four assertions of slack in session.ts - two schemas there carry six assertions, one
   * per enveloped response, so an unproven schema added to that file would have left 6 >= 3
   * and every gate green. The slack survived because this test's own proof only ever sampled
   * files whose counts are tight (users.ts, and a scratch devices.ts). A verification that
   * samples only the passing case is worth less than no verification, because it is believed.
   *
   * The convention enforced: an assertion proves the schema whose name prefixes its own, so
   * `_RegisterMatchesApi` proves `RegisterSchema` and `_SessionAcceptsLoginResponse` proves
   * `SessionSchema`. Each assertion is credited to the LONGEST schema name it starts with, so
   * a `_RefreshTokenMatchesApi` could never stand in as proof for a separate `RefreshSchema`.
   *
   * What this still does not catch, stated rather than implied: deleting one of several
   * assertions that prove the SAME schema. Any one of session.ts's four UserResource envelope
   * proofs satisfies the pairing on its own. The guarantee is that no schema is unproven, not
   * that a particular set of proofs is present - an exact per-file count would catch the
   * second as well, at the cost of failing on every legitimate addition, and that trade was
   * made deliberately.
   */
  const named = (pattern: RegExp, source: string): string[] => {
    const names: string[] = [];

    for (const match of source.matchAll(pattern)) {
      const name = match[1];
      if (name !== undefined) names.push(name);
    }

    return names;
  };

  it('pairs an assignability assertion to every exported schema', async () => {
    const directory = new URL('.', import.meta.url);
    const files = (await readdir(directory)).filter(
      (name) => name.endsWith('.ts') && !name.endsWith('.test.ts'),
    );

    // Read the directory rather than a hard-coded list, so a schema file added later is
    // covered without anyone remembering to add it here. The guard below is what stops that
    // convenience from making the test vacuous: an empty scan, or a regex that stopped
    // matching, would find nothing to pair and pass.
    expect(files).toEqual(expect.arrayContaining(['auth.ts', 'session.ts', 'users.ts']));

    let schemaCount = 0;

    for (const file of files) {
      const source = await readFile(new URL(file, directory), 'utf8');
      const schemas = named(/^export const (\w+)Schema\b/gm, source);
      const assertions = named(/^export type (_\w+) = Expect</gm, source);

      const proven = new Set<string>();

      for (const assertion of assertions) {
        const owner = schemas
          .filter((schema) => assertion.startsWith(`_${schema}`))
          .sort((a, b) => b.length - a.length)
          .at(0);

        if (owner !== undefined) proven.add(owner);
      }

      expect(
        schemas.filter((schema) => !proven.has(schema)),
        `${file}: exported schema(s) with no matching assignability assertion`,
      ).toEqual([]);

      schemaCount += schemas.length;
    }

    // The ten schemas this wave specifies. A floor rather than an exact count - adding an
    // eleventh WITH its assertion must not fail - so that a regex which stopped matching is
    // still caught, since pairing an empty list against an empty list would otherwise pass.
    expect(schemaCount).toBeGreaterThanOrEqual(10);
  });
});
