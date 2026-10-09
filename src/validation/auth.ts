import { z } from 'zod';

import type { paths } from '../types/api';

import type { Expect, WireEquivalent } from './assignability';

/**
 * Hand-written request validators for the auth surface, each one proved against the
 * generated contract.
 *
 * The duplication of tandem-api's rules() arrays is deliberate and is the whole point of
 * guarantee 3 (spec §5): a validator generated from OpenAPI is unusable, so these are
 * written by hand, and the `WireEquivalent` assertion under each schema is what stops them
 * drifting. Rename a field in Laravel, regenerate, and this file stops compiling.
 *
 * ACCESSORS, read off src/types/api.ts after `npm run sync` rather than assumed. Scramble
 * does emit named component schemas here - all eight of them - so `components['schemas'][…]`
 * would resolve. These go through `paths` anyway, because that form additionally pins the
 * URL, the HTTP method and the media type: moving POST /auth/register would break this file,
 * where the `components` form would not notice.
 *
 *   POST /auth/register        NonNullable<paths['/auth/register']['post']['requestBody']>
 *                                ['content']['application/json']  -> RegisterRequest
 *   POST /auth/login           …['/auth/login']['post']…          -> LoginRequest
 *   POST /auth/refresh         …['/auth/refresh']['post']…        -> RefreshTokenRequest
 *   POST /auth/logout          …['/auth/logout']['post']…         -> RefreshTokenRequest
 *   POST /auth/forgot-password …['/auth/forgot-password']['post']… -> ForgotPasswordRequest
 *   POST /auth/reset-password  …['/auth/reset-password']['post']… -> ResetPasswordRequest
 *   PATCH /me                  (in users.ts)                      -> UpdateProfileRequest
 *   POST /auth/verify-email    NO request body. Its four parameters are QUERY parameters:
 *                                paths['/auth/verify-email']['post']['parameters']['query']
 *
 * That last one is not a quirk of the generator, and the brief's expectation of a body
 * schema for it is wrong: VerifyEmailRequest marks every parameter `@query` because
 * Request::hasValidSignature() reconstructs the signed URL from the query string alone, so a
 * client that POSTed these as a JSON body would get 403 on every attempt. The accessor
 * follows the document.
 *
 * `NonNullable` is a no-op for every body above (all are required in the document) and is
 * load-bearing only for PATCH /me, whose `requestBody?` is optional. It is written uniformly
 * so that a body becoming optional upstream does not break the accessor itself - which would
 * report as a resolution error rather than as the field-level diff it is.
 *
 * Response parsers live in session.ts, PATCH /me's body in users.ts.
 */

type RegisterBody = NonNullable<
  paths['/auth/register']['post']['requestBody']
>['content']['application/json'];

/**
 * Mirrors RegisterRequest::rules() in tandem-api.
 *
 * `display_name`, not `displayName`: snake_case is what the API speaks, and this is the one
 * file whose job is to prove the shapes agree, so a camelCase alias here would be proving
 * the wrong thing. A transform belongs in each client.
 *
 * password is length-only at 12, matching Password::min(12). No composition rules, for the
 * reason RegisterRequest records: NIST SP 800-63B advises against them.
 *
 * `.trim()` before `.min(1)` mirrors prepareForValidation(), which trims display_name before
 * `required` and `max:80` see it - so '  ' is rejected and ' <80 chars> ' is accepted, in
 * both places.
 *
 * timezone is `.min(1)` and not an IANA check, deliberately. The API validates it against
 * the tz database via IanaTimeZone; the equivalent here would be a probe through
 * Intl.DateTimeFormat, whose accepted zone set depends on the engine's ICU build - and these
 * schemas run on Hermes as well as on Node, where that set is not the same. A client-side
 * check that rejects a zone the server accepts is worse than no check: the server is
 * authoritative and returns 422.
 *
 * `locale` is `.max(10)` with no `.min(1)` because its rule is ['sometimes', 'string',
 * 'max:10'] - no `required`, so the API accepts an empty string here. UpdateProfileRequest's
 * locale rule DOES carry `required`, and users.ts mirrors that difference rather than
 * smoothing it over.
 */
export const RegisterSchema = z.object({
  email: z.email().max(254),
  password: z.string().min(12),
  display_name: z.string().trim().min(1).max(80),
  timezone: z.string().min(1),
  locale: z.string().max(10).optional(),
});

// The schema's OUTPUT must describe the same wire format as the API's request body - not
// merely be assignable to it. See WireEquivalent: one-way assignability passes when an
// optional field has been made required, which is real drift.
export type _RegisterMatchesApi = Expect<
  WireEquivalent<z.infer<typeof RegisterSchema>, RegisterBody>
>;

type LoginBody = NonNullable<
  paths['/auth/login']['post']['requestBody']
>['content']['application/json'];

/**
 * Mirrors LoginRequest::rules().
 *
 * No minimum on `password`, matching the API: a length rule on a *submitted* password would
 * refuse a sign-in attempt for a legacy credential before checking it.
 *
 * install_id and platform are ['sometimes', 'required', …], which is optional-but-not-empty -
 * hence `.min(1)` inside `.optional()`.
 */
export const LoginSchema = z.object({
  email: z.email().max(254),
  password: z.string().min(1),
  install_id: z.string().min(1).max(255).optional(),
  platform: z.enum(['ios', 'android', 'web']).optional(),
});

export type _LoginMatchesApi = Expect<WireEquivalent<z.infer<typeof LoginSchema>, LoginBody>>;

type RefreshBody = NonNullable<
  paths['/auth/refresh']['post']['requestBody']
>['content']['application/json'];

/**
 * Mirrors RefreshTokenRequest::rules(). `size:64` is exactly 64 characters, matching
 * bin2hex(random_bytes(32)) - what RefreshTokenIssuer actually mints - so `.length(64)`
 * turns away malformed input before a round trip without revealing which tokens exist.
 */
export const RefreshSchema = z.object({
  refresh_token: z.string().length(64),
});

export type _RefreshMatchesApi = Expect<WireEquivalent<z.infer<typeof RefreshSchema>, RefreshBody>>;

type LogoutBody = NonNullable<
  paths['/auth/logout']['post']['requestBody']
>['content']['application/json'];

/**
 * POST /auth/logout takes the same single field as POST /auth/refresh, and upstream shares
 * one RefreshTokenRequest between the two endpoints, so this is an alias rather than a
 * duplicate z.object.
 *
 * It still carries its own assertion, against its own path accessor, and that is the point:
 * if the two endpoints ever diverge upstream, `_LogoutMatchesApi` fails while
 * `_RefreshMatchesApi` keeps passing, which is precisely the signal to split them here.
 */
export const LogoutSchema = RefreshSchema;

export type _LogoutMatchesApi = Expect<WireEquivalent<z.infer<typeof LogoutSchema>, LogoutBody>>;

type ForgotPasswordBody = NonNullable<
  paths['/auth/forgot-password']['post']['requestBody']
>['content']['application/json'];

/** Mirrors ForgotPasswordRequest::rules(). */
export const ForgotPasswordSchema = z.object({
  email: z.email().max(254),
});

export type _ForgotPasswordMatchesApi = Expect<
  WireEquivalent<z.infer<typeof ForgotPasswordSchema>, ForgotPasswordBody>
>;

type ResetPasswordBody = NonNullable<
  paths['/auth/reset-password']['post']['requestBody']
>['content']['application/json'];

/** Mirrors ResetPasswordRequest::rules(). `token` is `size:64`, like the refresh token. */
export const ResetPasswordSchema = z.object({
  token: z.string().length(64),
  email: z.email().max(254),
  password: z.string().min(12),
});

export type _ResetPasswordMatchesApi = Expect<
  WireEquivalent<z.infer<typeof ResetPasswordSchema>, ResetPasswordBody>
>;

type VerifyEmailQuery = paths['/auth/verify-email']['post']['parameters']['query'];

/**
 * Mirrors VerifyEmailRequest::rules(), which validates QUERY parameters - see the accessor
 * note at the top of this file for why there is no body to validate.
 *
 * `expires` is `integer` in the contract and arrives as a query-string digit run, so it is
 * coerced: the schema's input is whatever came out of the URL, and its OUTPUT is the `number`
 * the contract declares, which is the side `_VerifyEmailMatchesApi` compares. `.positive()`
 * is not decoration - z.coerce.number() turns '' and null into 0, and a signed link's expiry
 * is a Unix timestamp, never 0.
 *
 * The client relays all four parameters verbatim; it cannot usefully recompute `signature`.
 * These rules exist to reject a mangled deep link before a round trip, not to authorize -
 * authorize() runs the HMAC server-side before any rule here has any bearing.
 */
export const VerifyEmailSchema = z.object({
  id: z.uuid(),
  hash: z.string().min(1),
  expires: z.coerce.number().int().positive(),
  signature: z.string().min(1),
});

export type _VerifyEmailMatchesApi = Expect<
  WireEquivalent<z.infer<typeof VerifyEmailSchema>, VerifyEmailQuery>
>;
