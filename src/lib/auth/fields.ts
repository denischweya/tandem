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

/**
 * FIX ROUND 1, Important 2. MEASURED by the reviewer on `SignInForm` with
 * `errors: {captcha: 'This field is required.'}`: `alerts=[]`, the API's `message` absent
 * from `document.body`, no field marked invalid - the submit SILENTLY DID NOTHING and the
 * button re-enabled. Cause was the unconditional `return` right after each form's
 * field-mapping loop, IDENTICAL IN ALL FOUR FORMS (`SignInForm`, `SignUpForm`,
 * `ForgotPasswordForm`, `ResetPasswordForm`): a `fieldErrors` map whose every key is
 * unrecognised still took the early `return`, never reaching `setFormError(failure.message)`.
 *
 * This is the one place that loop now lives. It reports how many entries actually matched
 * `isField`, and every one of the four forms' submit handlers only takes its own early return
 * when that count is greater than zero - otherwise it falls through to its generic
 * `setFormError(failure.message)`, exactly as if `fieldErrors` had been `null`. Centralising
 * it here means a fifth form (or a sixth) inherits the fix rather than needing its own copy
 * of the same five lines - which is the literal mechanism the prior defect shipped through.
 *
 * NOT a fix for a different, narrower residual the reviewer also named: `LoginSchema` carries
 * `install_id`/`platform` and `RegisterSchema` carries `locale`, and `isLoginField`/
 * `isRegisterField` return TRUE for all three even though no form renders an Input for any of
 * them - `matchedFieldErrors` would count an error keyed by one of those as "matched" and
 * still suppress `setFormError`, same as before this fix. Left open deliberately rather than
 * patched over, but on the ARGUMENT THAT IS ACTUALLY TRUE for each field, not a single
 * "nothing sends any of them" claim - FIX ROUND 2 correction: that single claim was false for
 * `platform`. The 422 that could ever reach a browser is relayed from the REAL API's response
 * to the BFF's OWN outbound request (`relayFailure`, src/server/handlers.ts), not gated by
 * what the browser's `postJson` body contains - and `loginHandler`/`registerHandler`
 * (handlers.ts, the `/auth/login` call each one makes) hardcode `platform: 'web'` on EVERY
 * login, confirmed against `tandem-api`'s own `LoginRequest::rules()`: `platform =>
 * ['sometimes','required','string','in:ios,android,web']`. So `platform` is a VALUE argument,
 * not an absence one: it is always present on the wire, and `'web'` always satisfies `in:`, so
 * no 422 can ever be keyed by it for a reason unrelated to whether it was sent.
 * `install_id` and `locale` remain absence arguments, each for its own reason: `install_id`
 * is deliberately NOT forwarded by `loginHandler` at all (ADR 0003: "the web BFF has no
 * install_id concept to bind to" - ONE of this task's own FIX ROUND 1 changes made that
 * explicit by building the outbound body field-by-field rather than spreading
 * `validated.data`), and `locale` is never collected or sent by `SignUpForm` in the first
 * place, so it never reaches `registerHandler`'s outbound body either. Both are therefore
 * still `['sometimes', ...]` fields validated only when present, and present is a state
 * neither one ever reaches - unlike `platform`, which is always present and always valid.
 * Net conclusion unchanged either way: the real API cannot produce a 422 keyed by any of the
 * three for a request either handler actually sends, so this residual is not reachable in
 * practice. Excluding them from `LOGIN_FIELDS`/`REGISTER_FIELDS` the way `token` is excluded
 * from `RESET_FIELDS` would close even the unreachable case, but would do so by
 * re-introducing exactly the "enumerate what this screen renders, by hand" list the
 * schema-derivation above exists to avoid - for three fields that, unlike `token`, are not
 * merely hidden from view but structurally incapable of 422ing.
 */
export function matchedFieldErrors<TField extends string>(
  fieldErrors: Record<string, string>,
  isField: (key: string) => key is TField,
): ReadonlyArray<readonly [TField, string]> {
  const matched: Array<[TField, string]> = [];
  for (const [field, message] of Object.entries(fieldErrors)) {
    if (isField(field)) matched.push([field, message]);
  }
  return matched;
}
