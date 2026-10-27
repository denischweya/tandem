import {
  ForgotPasswordSchema,
  LoginSchema,
  RegisterSchema,
  ResetPasswordSchema,
} from '@tandem/shared';
import { describe, expect, it } from 'vitest';

import {
  FORGOT_PASSWORD_FIELDS,
  isForgotPasswordField,
  isLoginField,
  isRegisterField,
  isResetField,
  LOGIN_FIELDS,
  matchedFieldErrors,
  REGISTER_FIELDS,
  RESET_FIELDS,
} from './fields';

/**
 * The defect shape this wave has hit most: an enumerated list with no liveness half. Each
 * block below asks the two questions the task brief names for every field list in this
 * repo - "would a dead entry fail? would a missing one?" - and answers them by comparing the
 * exported constant against `Object.keys(Schema.shape)` directly, which is the one thing a
 * hand-typed array cannot do (it has no schema to compare itself against at all).
 */

describe('LOGIN_FIELDS', () => {
  it('is exactly LoginSchema.shape, not a hand-typed copy of it', () => {
    expect([...LOGIN_FIELDS].sort()).toEqual(Object.keys(LoginSchema.shape).sort());
  });

  it('accepts every real field and rejects an invented one', () => {
    expect(isLoginField('email')).toBe(true);
    expect(isLoginField('password')).toBe(true);
    expect(isLoginField('install_id')).toBe(true);
    expect(isLoginField('platform')).toBe(true);
    expect(isLoginField('nonexistent')).toBe(false);
  });
});

describe('REGISTER_FIELDS', () => {
  /**
   * CORRECTED (Wave 1b final review, W3/D4/3.9). This used to assert REGISTER_FIELDS was
   * EXACTLY `RegisterSchema.shape`, and a sibling test below asserted `isRegisterField
   * ('timezone')` was `true` - the opposite of RESET_FIELDS's own `token` exclusion two
   * describe blocks down. `timezone`'s Input is read-only; a 422 keyed `timezone` alone is
   * now routed to the form-level `FormError` instead of a field nothing can correct, which
   * also no longer suppresses that message - see `fields.ts`'s own corrected docblock.
   */
  it('is every RegisterSchema field EXCEPT timezone, not a hand-typed list', () => {
    const schemaFields = Object.keys(RegisterSchema.shape);
    expect(schemaFields).toContain('timezone');
    expect([...REGISTER_FIELDS].sort()).toEqual(
      schemaFields.filter((key) => key !== 'timezone').sort(),
    );
  });

  it('deliberately excludes timezone - its Input is read-only and web has no Profile page to correct it from', () => {
    expect(isRegisterField('timezone')).toBe(false);
  });

  it('accepts the three fields this form actually renders as editable', () => {
    expect(isRegisterField('display_name')).toBe(true);
    expect(isRegisterField('email')).toBe(true);
    expect(isRegisterField('password')).toBe(true);
  });

  it('rejects a field neither this form nor the schema has', () => {
    expect(isRegisterField('nonexistent')).toBe(false);
  });
});

describe('FORGOT_PASSWORD_FIELDS', () => {
  it('is exactly ForgotPasswordSchema.shape', () => {
    expect([...FORGOT_PASSWORD_FIELDS].sort()).toEqual(
      Object.keys(ForgotPasswordSchema.shape).sort(),
    );
  });

  it('accepts email and rejects an invented field', () => {
    expect(isForgotPasswordField('email')).toBe(true);
    expect(isForgotPasswordField('nonexistent')).toBe(false);
  });
});

describe('RESET_FIELDS', () => {
  it('is every ResetPasswordSchema field EXCEPT token, not a hand-typed pair', () => {
    const schemaFields = Object.keys(ResetPasswordSchema.shape);
    expect(schemaFields).toContain('token');
    expect([...RESET_FIELDS].sort()).toEqual(schemaFields.filter((key) => key !== 'token').sort());
  });

  it('deliberately excludes token - ResetPasswordForm routes that one to a FormError instead', () => {
    expect(isResetField('token')).toBe(false);
  });

  it('accepts email and password, the two fields the form actually renders as editable', () => {
    expect(isResetField('email')).toBe(true);
    expect(isResetField('password')).toBe(true);
  });

  it('rejects an invented field', () => {
    expect(isResetField('nonexistent')).toBe(false);
  });
});

/**
 * FIX ROUND 1, Important 2. MEASURED by the reviewer on `SignInForm`:
 * `errors: {captcha: 'This field is required.'}` produced `alerts=[]` - no field absorbed
 * it, and the unconditional `return` after the mapping loop swallowed the API's `message`
 * entirely. `matchedFieldErrors` is the one function every one of the four forms' submit
 * handlers now calls before deciding whether to return early or fall through to
 * `setFormError`.
 */
describe('matchedFieldErrors', () => {
  it('matches every entry whose key the guard recognises', () => {
    const matched = matchedFieldErrors(
      { email: 'has already been taken', password: 'too short' },
      isRegisterField,
    );
    expect([...matched].sort()).toEqual(
      [
        ['email', 'has already been taken'],
        ['password', 'too short'],
      ].sort(),
    );
  });

  it('returns an EMPTY array when every key is unrecognised - the exact captcha probe', () => {
    const matched = matchedFieldErrors({ captcha: 'This field is required.' }, isLoginField);
    expect(matched).toEqual([]);
  });

  it('drops only the unrecognised entries when the map is mixed', () => {
    const matched = matchedFieldErrors(
      { email: 'has already been taken', captcha: 'This field is required.' },
      isRegisterField,
    );
    expect(matched).toEqual([['email', 'has already been taken']]);
  });

  it('an empty fieldErrors map matches nothing, same as an all-unrecognised one', () => {
    expect(matchedFieldErrors({}, isLoginField)).toEqual([]);
  });
});
