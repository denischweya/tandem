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
  it('is exactly RegisterSchema.shape', () => {
    expect([...REGISTER_FIELDS].sort()).toEqual(Object.keys(RegisterSchema.shape).sort());
  });

  it('includes timezone - the one field this form renders read-only but still registers', () => {
    // MEASURED reason this matters: SignUpForm.tsx's field-mapping loop has no special case
    // for `timezone` - it relies on `isRegisterField('timezone')` being true so a 422 against
    // it (an exotic zone IanaTimeZone rejects) still reaches the rendered Input via
    // `form.setError`, rather than being silently dropped the way an array that forgot this
    // entry would drop it.
    expect(isRegisterField('timezone')).toBe(true);
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
