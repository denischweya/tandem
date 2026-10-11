import {
  ForgotPasswordSchema,
  LoginSchema,
  RegisterSchema,
  ResetPasswordSchema,
} from '@tandem/shared';
import type { z } from 'zod';

/**
 * The fields each form's `fieldErrors` (from `src/lib/auth/bffClient.ts`) can map onto its own
 * inputs - DERIVED from each schema's `.shape` at runtime, never hand-typed, mirroring
 * `tandem-mobile/src/features/auth/screenLogic.ts`'s `REGISTER_FIELDS`/`RESET_FIELDS` exactly
 * (down to the reasoning below, which is transcribed from that file's own docblock because the
 * defect it closes is the one this wave has hit most often).
 *
 * MEASURED, from that file, why a hand-typed array is "half-live": a DEAD entry (a field
 * removed from the schema but left in the array) is a plain string literal with no link back
 * to the schema, so `tsc` does not catch it. A MISSING entry (a field added to the schema but
 * not listed here) is the worse direction, because it fails SILENTLY: that field's 422 is
 * dropped by the `isXField` guard below, and every form in this task shows its generic
 * `FormError` ONLY when no field absorbed the error - so a schema field this array forgot
 * would make the screen render NOTHING for that field's validation message. Reading the keys
 * straight off each schema's `.shape` removes the human step where either drift could enter:
 * the array cannot name a field the schema does not have, and cannot omit one it does, because
 * it is not a separate piece of data to keep in sync at all.
 */
export const LOGIN_FIELDS = Object.keys(LoginSchema.shape) as ReadonlyArray<
  keyof z.input<typeof LoginSchema>
>;
export type LoginField = (typeof LOGIN_FIELDS)[number];
export function isLoginField(key: string): key is LoginField {
  return (LOGIN_FIELDS as readonly string[]).includes(key);
}

export const REGISTER_FIELDS = Object.keys(RegisterSchema.shape) as ReadonlyArray<
  keyof z.input<typeof RegisterSchema>
>;
export type RegisterField = (typeof REGISTER_FIELDS)[number];
export function isRegisterField(key: string): key is RegisterField {
  return (REGISTER_FIELDS as readonly string[]).includes(key);
}

export const FORGOT_PASSWORD_FIELDS = Object.keys(ForgotPasswordSchema.shape) as ReadonlyArray<
  keyof z.input<typeof ForgotPasswordSchema>
>;
export type ForgotPasswordField = (typeof FORGOT_PASSWORD_FIELDS)[number];
export function isForgotPasswordField(key: string): key is ForgotPasswordField {
  return (FORGOT_PASSWORD_FIELDS as readonly string[]).includes(key);
}

/**
 * `token` IS one of `ResetPasswordSchema`'s fields, but it is excluded here deliberately, not
 * missed: a dead-token 422 is handled by `ResetPasswordForm` as a full `FormError` with a
 * "Request a new link" action, NEVER by `form.setError('token', …)` against the hidden input
 * that field renders as - see that component and Step 6.2's break proof for why rendering it
 * there is a real, user-visible defect (the message exists; nothing on screen shows it). The
 * exclusion is a separate, named `.filter`, not folded into the derivation itself, so a reader
 * sees it rather than has to notice an absence.
 */
const RESET_SCHEMA_FIELDS = Object.keys(ResetPasswordSchema.shape) as ReadonlyArray<
  keyof z.input<typeof ResetPasswordSchema>
>;
export const RESET_FIELDS = RESET_SCHEMA_FIELDS.filter((key) => key !== 'token');
export type ResetField = (typeof RESET_FIELDS)[number];
export function isResetField(key: string): key is ResetField {
  return (RESET_FIELDS as readonly string[]).includes(key);
}
