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
 * MIDDLEWARE, and why several rules below carry a `.trim()` that no rules() array mentions.
 * TrimStrings and ConvertEmptyStringsToNull sit in Laravel's GLOBAL middleware stack - read
 * off Illuminate\Foundation\Configuration\Middleware::getGlobalMiddleware() in
 * laravel/framework v13.35.0, with tandem-api's bootstrap/app.php neither removing nor
 * replacing either - so they transform every api/* request before any rule in any request
 * class runs. A schema that mirrors rules() alone therefore diverges from the API, and did,
 * in three ways measured against the real endpoints:
 *
 *   - '  Ada@Example.test  ' is ACCEPTED upstream - TrimStrings trims it, then
 *     NormalizesEmailInput lowercases and trims it a second time via EmailInput::normalize's
 *     strtolower(trim(...)) - and was refused here by the regex's `[^\s@]` classes.
 *   - `locale: ""` is a 422 upstream (ConvertEmptyStringsToNull makes it null, and null fails
 *     `string`) and was accepted here.
 *   - '   ' satisfies Zod's `.min(1)`, which counts characters, while `required` upstream sees
 *     the trimmed value and refuses it.
 *
 * `.trim()` ahead of the length and shape checks closes all three, and its POSITION is
 * load-bearing: Zod applies string checks in declaration order, so - measured -
 * `z.string().min(1).trim()` accepts '   ' and returns '', while `z.string().trim().min(1)`
 * rejects it. Written first throughout, so every cap and shape check sees the value the
 * server would see, and the parsed output is the value a client should send. Str::trim
 * upstream also strips Unicode whitespace and invisible characters, where JavaScript's
 * `.trim()` strips whitespace only - which leaves these schemas marginally LOOSER than the
 * server on exotic input rather than stricter, the same direction apiEmail chooses and the
 * only one that keeps the server authoritative.
 *
 * password is the one string field left untrimmed, and that too is measured rather than
 * assumed: TrimStrings `$except` is ['current_password', 'password', 'password_confirmation']
 * in that same framework version, and tandem-api neither extends it nor calls
 * TrimStrings::except(), so the server hashes and compares a submitted password EXACTLY as
 * sent, and `min`/`max` upstream count its padding. Trimming it here would BE the divergence
 * rather than the fix: ' hunter2two ' is a different credential upstream from 'hunter2two',
 * so a client that trimmed would register one password and then sign in with another.
 *
 * One password divergence is therefore left open, and is recorded here rather than implied.
 * `required` does its own trim before deciding a value is empty - ValidatesAttributes::
 * validateRequired returns false for `is_string($value) && trim($value) === ''`, which is
 * PHP's native trim and runs whether or not TrimStrings touched the field - so a
 * whitespace-only password long enough to clear the length rule is a 422 upstream and is
 * accepted here. Closing it needs a value-PRESERVING check (at least one character outside
 * PHP trim's charlist, " \t\n\r\0\x0B") and not a `.trim()`; note that charlist is narrower
 * than JavaScript's `\s`, so the obvious `.regex(/\S/)` would make this schema stricter than
 * the server for a password of non-breaking spaces. Left as a decision rather than guessed
 * at, and pinned by a test in auth.test.ts so it cannot be closed without this note moving.
 *
 * Response parsers live in session.ts, PATCH /me's body in users.ts.
 */

/**
 * The email rule every request in this file shares, and deliberately NOT `z.email()`.
 *
 * A client-side validator must never be stricter than the server's, and `z.email()` is.
 * tandem-api's gate is `filter_var(FILTER_VALIDATE_EMAIL)` via `Email::fromString`, which
 * accepts addresses Zod's regex rejects - an IP-literal domain (`ada@[127.0.0.1]`) being the
 * clearest case. Rejecting one here would mean an address the API accepts cannot be used
 * through either client at all: a client-side narrowing of the product's own contract, and
 * the same mistake `UserSchema.email` in session.ts avoids on the response side. The brief
 * prescribed `.email()`, but it prescribed validating the address - not out-validating the
 * server.
 *
 * So this is a shape check for form UX plus the server's own length cap. The regex accepts a
 * superset of what filter_var does on the TRIMMED value: every address filter_var accepts has
 * exactly one unquoted `@` and no whitespace (`"ada bob"@example.test` is rejected there, as
 * ValidEmail's docblock records), so an obviously-mistyped address still fails before a round
 * trip while the server stays authoritative and answers 422.
 *
 * `.trim()` is a correction, not a nicety. This docblock used to claim that nothing the API
 * would accept is refused here, and that claim was false for a whole class of input: any
 * address with leading or trailing whitespace. TrimStrings (see the MIDDLEWARE note above)
 * means '  Ada@Example.test  ' reaches ValidEmail as 'ada@example.test' and registers, while
 * `[^\s@]` refused it here - so one trailing space off a paste or an autofill made an address
 * unregisterable through either client. Measured both ways: without the trim that input is
 * rejected, with it the schema parses and yields 'Ada@Example.test'.
 *
 * Trim, then cap, then shape, which is the order the server applies: `.max(254)` mirrors
 * `'max:254'` and both it and the regex see the trimmed value, so even the cap is the server's
 * rule and not an extra one. Lowercasing is NOT mirrored - NormalizesEmailInput lowercases
 * server-side before `unique` and before the users_email_lowercase CHECK, and nothing a client
 * sends is accepted or refused on the strength of its case, so a client-side `.toLowerCase()`
 * would alter the value sent for no gain in agreement.
 */
const apiEmail = z
  .string()
  .trim()
  .max(254)
  .regex(/^[^\s@]+@[^\s@]+$/, 'must look like an email address');

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
 * timezone is `.trim().min(1)` and not an IANA check, deliberately. The API validates it
 * against the tz database via IanaTimeZone; the equivalent here would be a probe through
 * Intl.DateTimeFormat, whose accepted zone set depends on the engine's ICU build - and these
 * schemas run on Hermes as well as on Node, where that set is not the same. A client-side
 * check that rejects a zone the server accepts is worse than no check: the server is
 * authoritative and returns 422. The trim is what makes `.min(1)` mean what `required` means
 * upstream rather than merely "one character": '   ' is a 422 there and is refused here.
 *
 * `locale` is the same shape users.ts gives UpdateProfileSchema.locale, and this paragraph
 * used to explain why it was not. The explanation was wrong rather than out of date:
 * RegisterRequest's rule reads ['sometimes', 'string', 'max:10'] with no `required`, which
 * looks like it accepts the empty string, but ConvertEmptyStringsToNull reaches the value
 * before any rule does and turns "" into null, which fails `string`. `locale: ""` is a 422 at
 * BOTH endpoints and always was, so the asymmetry this schema mirrored existed only in the
 * rules() arrays and never on the wire. Upstream is adding the missing `required` so the rule
 * reads the way the stack already behaves; either way this is the shape that agrees with it.
 *
 * openapi.json does not move with that correction and no assertion or constraint-table row
 * does either: `required` adds no `minLength`, so `locale` stays an optional string with
 * `maxLength: 10` in the document.
 */
export const RegisterSchema = z.object({
  email: apiEmail,
  password: z.string().min(12),
  display_name: z.string().trim().min(1).max(80),
  timezone: z.string().trim().min(1),
  locale: z.string().trim().min(1).max(10).optional(),
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
 * `password` is `.min(1)` and nothing more: the empty string is rejected and no length is
 * imposed beyond that. The API's LoginRequest deliberately omits Password::min(12), because a
 * length rule on a *submitted* password would refuse a sign-in attempt for a legacy credential
 * before checking it - so there is no 12-character minimum here either. `.min(1)` is a near
 * mirror of `required` rather than an exact one, and the gap is the whitespace-only case the
 * MIDDLEWARE note records: the empty string agrees (422 both sides), '   ' does not.
 *
 * install_id and platform are ['sometimes', 'required', …], which is optional-but-not-empty -
 * hence `.min(1)` inside `.optional()`. install_id is trimmed ahead of both its length checks,
 * since TrimStrings does reach it; password is not, for the reason the MIDDLEWARE note gives.
 */
export const LoginSchema = z.object({
  email: apiEmail,
  password: z.string().min(1),
  install_id: z.string().trim().min(1).max(255).optional(),
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
 *
 * `.trim()` goes ahead of `.length(64)` too, and the exact-length rule is the case where that
 * needed thinking about rather than copying: a hex token has no interior whitespace to lose,
 * TrimStrings runs before `size:64` upstream, and the trimmed 64 characters are therefore
 * precisely the value the server validates and looks up. Without it a padded token is a
 * client-side rejection of a token the API would accept. Measured:
 * `z.string().trim().length(64)` accepts ' <64 hex> ' and still refuses ' <63 hex> '. It earns
 * its place at reset-password, where a human pastes the token out of an email; a refresh token
 * comes straight back out of a login response, where padding would be a bug upstream.
 */
export const RefreshSchema = z.object({
  refresh_token: z.string().trim().length(64),
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
  email: apiEmail,
});

export type _ForgotPasswordMatchesApi = Expect<
  WireEquivalent<z.infer<typeof ForgotPasswordSchema>, ForgotPasswordBody>
>;

type ResetPasswordBody = NonNullable<
  paths['/auth/reset-password']['post']['requestBody']
>['content']['application/json'];

/**
 * Mirrors ResetPasswordRequest::rules(). `token` is `size:64`, like the refresh token, and
 * trimmed ahead of it for the reason RefreshSchema records - this is the endpoint where that
 * matters, because the token arrives by copy and paste. `password` stays untrimmed, like every
 * other password field here.
 */
export const ResetPasswordSchema = z.object({
  token: z.string().trim().length(64),
  email: apiEmail,
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
