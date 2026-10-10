import { describe, expect, it } from 'vitest';

import {
  ErrorEnvelopeSchema,
  fieldErrorsFor,
  HOUSE_SERVER_MESSAGE,
  userMessageFor,
} from './errors';

// Not in Task 10's brief file list with content given - the brief names errors.test.ts in its
// Files section ("Create: src/server/{cookies,csrf,errors,handlers}.test.ts") but, unlike
// cookies.test.ts and csrf.test.ts, never supplies its body. Written from this module's own
// contract (mirrored from tandem-mobile/src/api/errors.ts's AuthoredMessageStatus rule) rather
// than left absent, per the task's own instruction that an enumerated list needs its liveness
// half.

describe('ErrorEnvelopeSchema', () => {
  it('parses a bare message', () => {
    expect(ErrorEnvelopeSchema.safeParse({ message: 'Unauthenticated.' }).success).toBe(true);
  });

  it('parses a message with a field-errors map', () => {
    const result = ErrorEnvelopeSchema.safeParse({
      message: 'The given data was invalid.',
      errors: { email: ['taken'], password: ['too short', 'required'] },
    });
    expect(result.success).toBe(true);
  });

  it('rejects a body with no message at all', () => {
    expect(ErrorEnvelopeSchema.safeParse({ errors: { email: ['taken'] } }).success).toBe(false);
  });
});

describe('userMessageFor', () => {
  it.each([401, 403, 422, 429])('passes an authored message through verbatim for %i', (status) => {
    const message = 'A sentence this API wrote for a person.';
    expect(userMessageFor(status, { message })).toBe(message);
  });

  it.each([400, 404, 405, 500, 503])(
    'substitutes house copy for an unauthored status (%i)',
    (status) => {
      // MEASURED against tandem-api's bootstrap/app.php: it registers renderers for exactly
      // three application exceptions, so every other HttpException falls through to Laravel's
      // default handler and renders framework text - a 404's "The route … could not be found.",
      // a 405's "Supported methods: …", a 500's generic "Server Error". None of that may reach
      // a user (blueprint §135).
      expect(
        userMessageFor(status, { message: 'Some framework sentence nobody wrote for a user.' }),
      ).toBe(HOUSE_SERVER_MESSAGE);
    },
  );

  it('substitutes house copy when the envelope is null - a non-JSON body, e.g. a proxy error page', () => {
    expect(userMessageFor(502, null)).toBe(HOUSE_SERVER_MESSAGE);
  });

  it('substitutes house copy for an authored status whose message arrived blank', () => {
    // `abort(403)` in tandem-api renders an empty string. ErrorEnvelopeSchema.message is a
    // bare z.string(), so '' parses - the non-blank guard is load-bearing, not decoration:
    // without it, the whole error renders as nothing at all.
    expect(userMessageFor(403, { message: '' })).toBe(HOUSE_SERVER_MESSAGE);
  });

  it('substitutes house copy for an authored status whose message is whitespace-only', () => {
    expect(userMessageFor(422, { message: '   ' })).toBe(HOUSE_SERVER_MESSAGE);
  });
});

describe('fieldErrorsFor', () => {
  it('returns null when the envelope is null', () => {
    expect(fieldErrorsFor(null)).toBeNull();
  });

  it('returns null when there is no errors map', () => {
    expect(fieldErrorsFor({ message: 'Unauthenticated.' })).toBeNull();
  });

  it('takes the FIRST message per field', () => {
    expect(
      fieldErrorsFor({
        message: 'The given data was invalid.',
        errors: { email: ['taken'], password: ['too short', 'required'] },
      }),
    ).toEqual({ email: 'taken', password: 'too short' });
  });

  it('returns null for an empty errors map rather than an empty object', () => {
    expect(fieldErrorsFor({ message: 'The given data was invalid.', errors: {} })).toBeNull();
  });
});
