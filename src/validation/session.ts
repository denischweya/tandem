import { z } from 'zod';

import type { paths } from '../types/api';

import type { Expect, WireEquivalent } from './assignability';

/**
 * Response parsers, proved against the generated contract.
 *
 * ACCESSORS. Every success response in this API is enveloped, so the resource is one level
 * down under `data`:
 *
 *   POST  /auth/register     201 …['content']['application/json']['data'] -> UserResource
 *   GET   /me                200 …                                       -> UserResource
 *   PATCH /me                200 …                                       -> UserResource
 *   POST  /auth/verify-email 200 …                                       -> UserResource
 *   POST  /auth/login        200 …                                       -> SessionResource
 *   POST  /auth/refresh      200 …                                       -> SessionResource
 *
 * Each endpoint is asserted separately rather than once against
 * `components['schemas']['UserResource']`, and that is deliberate: indexing through
 * `['data']` is what proves the envelope KEY as well as the resource shape. If Scramble
 * stopped wrapping, or wrapped under a different key, these accessors would stop resolving
 * and the file would fail to compile - which is the correct outcome, since every client's
 * response handling would be wrong.
 *
 * The non-2xx shapes (`components['responses']`) are not parsed here. They are a separate
 * concern - an error envelope belongs to each client's HTTP layer, where the status code is
 * in hand - and nothing in this wave consumes them.
 *
 * DIRECTION. A response is consumed, not produced, so the brief's instinct is to assert only
 * that the API's response is assignable to what the parser accepts. These use
 * `WireEquivalent`, which is symmetric and therefore stricter in one specific way: a field
 * ADDED upstream also fails. That is wanted. A parser that silently ignores a new field is
 * how a client quietly stops showing something the server now sends, and the whole purpose
 * of this file is to make that a compile error in one place rather than a gap in two
 * clients. `z.object()` still strips unknown keys at runtime, so a server deploying ahead of
 * a client degrades rather than throws - see the extra-key test in auth.test.ts.
 */

/**
 * Mirrors UserResource::toArray().
 *
 * `id` is `z.uuid()` though the document says only `string`: users.id is a `uuid` column and
 * VerifyEmailRequest validates the same value with Laravel's `uuid` rule, so a non-UUID here
 * is impossible rather than merely unexpected, and failing loudly beats propagating it.
 *
 * `email` is deliberately NOT `z.email()`, by the same reasoning run the other way. A
 * response parser must never be stricter than what the server can legitimately store, and
 * the server's gate is filter_var(FILTER_VALIDATE_EMAIL) via Email::fromString, which accepts
 * addresses Zod's regex rejects (an IP-literal domain, for one). Rejecting a stored address
 * would lock a real user out of their own session payload to no benefit - the address was
 * already validated on the way in.
 *
 * `email_verified` is a boolean rather than a timestamp because the API sends when-verified
 * to nobody; see UserResource for that reasoning.
 */
export const UserSchema = z.object({
  id: z.uuid(),
  email: z.string(),
  email_verified: z.boolean(),
  display_name: z.string(),
  timezone: z.string(),
  locale: z.string(),
});

type RegisterResponseUser =
  paths['/auth/register']['post']['responses'][201]['content']['application/json']['data'];
type MeShowResponseUser =
  paths['/me']['get']['responses'][200]['content']['application/json']['data'];
type MeUpdateResponseUser =
  paths['/me']['patch']['responses'][200]['content']['application/json']['data'];
type VerifyEmailResponseUser =
  paths['/auth/verify-email']['post']['responses'][200]['content']['application/json']['data'];

export type _UserAcceptsRegisterResponse = Expect<
  WireEquivalent<RegisterResponseUser, z.input<typeof UserSchema>>
>;
export type _UserAcceptsMeShowResponse = Expect<
  WireEquivalent<MeShowResponseUser, z.input<typeof UserSchema>>
>;
export type _UserAcceptsMeUpdateResponse = Expect<
  WireEquivalent<MeUpdateResponseUser, z.input<typeof UserSchema>>
>;
export type _UserAcceptsVerifyEmailResponse = Expect<
  WireEquivalent<VerifyEmailResponseUser, z.input<typeof UserSchema>>
>;

/**
 * Mirrors SessionResource::toArray().
 *
 * Both expiries are parsed as ISO datetimes with `{ offset: true }`, which is not the default
 * and is required: the API formats them with DATE_RFC3339_EXTENDED, which renders UTC as
 * `+00:00` rather than `Z`, and z.iso.datetime()'s default rejects a numeric offset. Measured
 * against the real format, not assumed. The output stays a `string`, so the contract
 * comparison is unaffected - this buys the clients a parse-time guarantee that
 * `new Date(...)` on these two fields cannot yield Invalid Date.
 *
 * `user` nests UserSchema, and WireShape recurses, so a rename inside UserResource fails
 * `_SessionAcceptsLoginResponse` as well as the four assertions above.
 */
export const SessionSchema = z.object({
  access_token: z.string().min(1),
  access_token_expires_at: z.iso.datetime({ offset: true }),
  refresh_token: z.string().min(1),
  refresh_token_expires_at: z.iso.datetime({ offset: true }),
  user: UserSchema,
});

type LoginResponseSession =
  paths['/auth/login']['post']['responses'][200]['content']['application/json']['data'];
type RefreshResponseSession =
  paths['/auth/refresh']['post']['responses'][200]['content']['application/json']['data'];

export type _SessionAcceptsLoginResponse = Expect<
  WireEquivalent<LoginResponseSession, z.input<typeof SessionSchema>>
>;
export type _SessionAcceptsRefreshResponse = Expect<
  WireEquivalent<RefreshResponseSession, z.input<typeof SessionSchema>>
>;
