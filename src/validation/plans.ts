import { z } from 'zod';

import type { paths } from '../types/api';

import type { Equivalent, Expect, WireEquivalent } from './assignability';

/**
 * Hand-written parsers for the Plans surface, each proved against the generated contract.
 *
 * ACCESSORS go through `paths` rather than `components['schemas'][...]`, for the reason auth.ts
 * records: the path form additionally pins the URL, the HTTP METHOD, the media type and the
 * STATUS, so a moved or re-numbered endpoint breaks this file, where the components form would
 * not notice. Responses are indexed through ['data'], which is what proves the envelope KEY as
 * well as the resource shape, and each enveloped endpoint is asserted separately for the same
 * reason session.ts asserts UserResource four times.
 *
 * `['data'][number]` needs no NonNullable: measured against this repo's own tsconfig, a
 * type-level index of an ARRAY type with `number` is not widened by noUncheckedIndexedAccess.
 *
 * DIRECTION. Responses use z.input and put the API's type FIRST; requests use z.infer and put
 * the schema's type first. Both go through WireEquivalent, which is symmetric, so a field ADDED
 * upstream fails as loudly as a field renamed - see session.ts for why that is wanted.
 *
 * ENUM-VALUED FIELDS ARE PARSED AS STRINGS, and that is the contract rather than laziness.
 * Spec §16.2 forbids typing a Resource field or a FormRequest rule as a native PHP enum, because
 * Scramble publishes such an enum as a component schema carrying the enum's whole CLASS docblock
 * as its public description - confirmed, and not controlled by the config key that appears to
 * control it. So `status`, `visibility`, `role` on a Resource and `activity_type` publish as
 * plain `string`, and z.enum() here would be STRICTER than the contract and fail its own proof.
 * The REQUEST side is the opposite: `Rule::in(...)` does publish an `enum` list, which
 * openapi-typescript turns into a literal union, so those schemas use z.enum() and must.
 *
 * TIMESTAMPS are parsed with z.iso.datetime({ offset: true }), not the default: the API formats
 * every one of them with DATE_RFC3339_EXTENDED, which renders UTC as `+00:00` rather than `Z`,
 * and z.iso.datetime()'s default rejects a numeric offset. The parsed output stays a `string`,
 * so the contract comparison is unaffected and the clients get a guarantee that `new Date(...)`
 * on these fields cannot yield Invalid Date.
 *
 * NO QUERY-PARAMETER SCHEMAS, deliberately. The three list endpoints take `limit`, `cursor` and
 * (for GET /plans) `status`, and the document publishes them as `parameters` rather than as a
 * request body - a GET route's FormRequest registers no component schema at all. There is no
 * `paths[...]['requestBody']` for a proof to reach, so a Zod schema for them could carry no
 * proof, and the pairing gate in auth.test.ts would reject it as unproven. Query strings are
 * each client's HTTP layer's business, the way the error envelope used to be.
 */

type PlanShowBody =
  paths['/plans/{plan}']['get']['responses'][200]['content']['application/json']['data'];
type PlanCreateBody =
  paths['/plans']['post']['responses'][201]['content']['application/json']['data'];
type PlanUpdateBody =
  paths['/plans/{plan}']['patch']['responses'][200]['content']['application/json']['data'];
type PlanArchiveBody =
  paths['/plans/{plan}/archive']['post']['responses'][200]['content']['application/json']['data'];
type PlanJoinBody =
  paths['/plans/{plan}/join']['post']['responses'][200]['content']['application/json']['data'];

type PlanPageBody = paths['/plans']['get']['responses'][200]['content']['application/json'];
type PlanMemberPageBody =
  paths['/plans/{plan}/members']['get']['responses'][200]['content']['application/json'];
type PlanActivityPageBody =
  paths['/plans/{plan}/activity']['get']['responses'][200]['content']['application/json'];

type PlanMemberListItem = PlanMemberPageBody['data'][number];
type PlanMemberUpdateBody =
  paths['/plans/{plan}/members/{user}']['patch']['responses'][200]['content']['application/json']['data'];
type PlanActivityListItem = PlanActivityPageBody['data'][number];
type PlanInviteListItem =
  paths['/plans/{plan}/invites']['get']['responses'][200]['content']['application/json']['data'][number];
type PlanInviteCreatedBody =
  paths['/plans/{plan}/invites']['post']['responses'][201]['content']['application/json']['data'];
type InvitePreviewBody =
  paths['/invites/{token}']['get']['responses'][200]['content']['application/json']['data'];

/** Every timestamp this API returns, in the one format it returns them in. */
const apiTimestamp = z.iso.datetime({ offset: true });

/**
 * Mirrors PlanResource::toArray().
 *
 * `id` and `owner_id` are z.uuid() though the document says only `string`: both are `uuid`
 * columns, so a non-UUID is impossible rather than merely unexpected, and failing loudly beats
 * propagating it - the same reasoning UserSchema.id records.
 *
 * `description` is nullable and NOT optional: plans.description is a nullable `text` column, the
 * document publishes ["string","null"], and null is a JSON value that WireShape deliberately
 * does not strip. An `.optional()` here would be a different contract and would not compile.
 *
 * `member_count` counts `status = active` rows only, matching the member list, and arrives as a
 * withCount aggregate cast to int.
 *
 * `viewer_role` is the authenticated member's own role, and is never null on any endpoint that
 * publishes this resource: every one of the five is reachable only by an active member (GET
 * /plans/{plan}, PATCH, POST /archive), by the creator (POST /plans), or by someone who has just
 * become one (POST /join).
 *
 * `group_id` and `cover_image_path` are absent, not null: nothing in Cycle 1 can set either, and
 * publishing a permanently-null field documents a capability the API does not have. These proofs
 * are symmetric, so adding one later is a deliberate, visible contract change.
 */
export const PlanResourceSchema = z.object({
  id: z.uuid(),
  title: z.string(),
  description: z.string().nullable(),
  status: z.string(),
  visibility: z.string(),
  timezone: z.string(),
  owner_id: z.uuid(),
  member_count: z.number().int(),
  viewer_role: z.string(),
  archived_at: apiTimestamp.nullable(),
  created_at: apiTimestamp,
  updated_at: apiTimestamp,
});

export type _PlanResourceAcceptsShowResponse = Expect<
  WireEquivalent<PlanShowBody, z.input<typeof PlanResourceSchema>>
>;
export type _PlanResourceAcceptsCreateResponse = Expect<
  WireEquivalent<PlanCreateBody, z.input<typeof PlanResourceSchema>>
>;
export type _PlanResourceAcceptsUpdateResponse = Expect<
  WireEquivalent<PlanUpdateBody, z.input<typeof PlanResourceSchema>>
>;
export type _PlanResourceAcceptsArchiveResponse = Expect<
  WireEquivalent<PlanArchiveBody, z.input<typeof PlanResourceSchema>>
>;
export type _PlanResourceAcceptsJoinResponse = Expect<
  WireEquivalent<PlanJoinBody, z.input<typeof PlanResourceSchema>>
>;

/**
 * Mirrors PlanMemberResource::toArray().
 *
 * No `email`, by blueprint §126: the member list exposes `user_id` and a display name, and the
 * address-bearing view is the invite list, which only owners and admins can read.
 *
 * `joined_at` is NOT nullable, and that is a decision resting on two facts rather than on the
 * column, which IS nullable. The list returns `status = active` rows only, and its cursor is
 * keyed on (joined_at, id) - a row with a NULL joined_at is dropped by the cursor predicate, so
 * such a row could never appear in a page in the first place. If this ever publishes
 * ["string","null"], the use case has started returning rows the cursor cannot page, and the
 * compile error here is the right place to find that out.
 */
export const PlanMemberResourceSchema = z.object({
  user_id: z.uuid(),
  display_name: z.string(),
  role: z.string(),
  status: z.string(),
  joined_at: apiTimestamp,
});

export type _PlanMemberResourceAcceptsListResponse = Expect<
  WireEquivalent<PlanMemberListItem, z.input<typeof PlanMemberResourceSchema>>
>;
export type _PlanMemberResourceAcceptsRoleChangeResponse = Expect<
  WireEquivalent<PlanMemberUpdateBody, z.input<typeof PlanMemberResourceSchema>>
>;

/**
 * Mirrors PlanInviteResource::toArray(). Neither `token_hash` nor any plaintext token appears
 * here: the token is returned exactly once, at issuance, by PlanInviteCreatedResourceSchema.
 *
 * `email` is nullable because a link-only invite has none (plan_invites.email is nullable), and
 * because account deletion nulls the address of an unaccepted invite addressed to the departing
 * user.
 *
 * `invited_by_name` is nullable because plan_invites.invited_by is `nullOnDelete`: a hard-deleted
 * inviter leaves the historical row with no name to render, and this list carries accepted and
 * revoked rows as well as live ones.
 */
export const PlanInviteResourceSchema = z.object({
  id: z.uuid(),
  email: z.string().nullable(),
  role: z.string(),
  expires_at: apiTimestamp,
  accepted_at: apiTimestamp.nullable(),
  revoked_at: apiTimestamp.nullable(),
  invited_by_name: z.string().nullable(),
});

export type _PlanInviteResourceAcceptsListResponse = Expect<
  WireEquivalent<PlanInviteListItem, z.input<typeof PlanInviteResourceSchema>>
>;

/**
 * Mirrors PlanInviteCreatedResource::toArray() - the 201 from POST /plans/{plan}/invites, and the
 * only response in the API that carries an invite token.
 *
 * A SEPARATE schema rather than PlanInviteResourceSchema with an optional `token`, which is the
 * same decision the API makes with two Resource classes, for the same reason: WireShape moves
 * optionality into the value type so that required-versus-optional survives as a type difference,
 * and an optional `token` would be a schema whose own proof cannot tell "absent because this is a
 * list" from "absent because the server stopped sending it".
 *
 * `token` is `.length(64)` because the server mints it as bin2hex(random_bytes(32)) - 64 lowercase
 * hex characters, 256 bits - so a different length is impossible rather than unexpected. This is
 * the UserSchema.id argument, not the UserSchema.email one: it is not a narrowing of what the
 * server can legitimately send. No `.trim()`: nothing trims a response.
 */
export const PlanInviteCreatedResourceSchema = z.object({
  id: z.uuid(),
  email: z.string().nullable(),
  role: z.string(),
  expires_at: apiTimestamp,
  accepted_at: apiTimestamp.nullable(),
  revoked_at: apiTimestamp.nullable(),
  invited_by_name: z.string().nullable(),
  token: z.string().length(64),
});

export type _PlanInviteCreatedResourceAcceptsCreateResponse = Expect<
  WireEquivalent<PlanInviteCreatedBody, z.input<typeof PlanInviteCreatedResourceSchema>>
>;

/**
 * Mirrors PlanActivityResource::toArray().
 *
 * `actor_id` and `actor_name` are both nullable, and that is the anonymisation path rather than a
 * defensive guess: account deletion sets plan_activity.actor_id to NULL across the feed, and the
 * client renders a placeholder for the row.
 *
 * `metadata` is the writer's closed set - {"from":"member","to":"admin"} for a role change,
 * {"fields":["title"]} for an update (FIELD NAMES ONLY, never values), {"channel":"link",
 * "role":"member"} for an invite, and {} for everything else - published via the `@var` tag on
 * PlanActivityResource's `metadata` key, the same mechanism every other field in this file relies
 * on for its wire type.
 */
export const PlanActivityResourceSchema = z.object({
  id: z.uuid(),
  activity_type: z.string(),
  actor_id: z.uuid().nullable(),
  actor_name: z.string().nullable(),
  subject_type: z.string().nullable(),
  subject_id: z.uuid().nullable(),
  metadata: z.record(z.string(), z.union([z.string(), z.array(z.string())])),
  created_at: apiTimestamp,
});

export type _PlanActivityResourceAcceptsListResponse = Expect<
  WireEquivalent<PlanActivityListItem, z.input<typeof PlanActivityResourceSchema>>
>;

/**
 * Mirrors InvitePreviewResource::toArray() - the unauthenticated 200 from GET /invites/{token}.
 *
 * Deliberately minimal, per blueprint §126 and §154: a title, a display name and a count. No
 * member list, no description, no addresses, no invitee identity, no Plan status. Anything added
 * here would be a disclosure to whoever holds the link, so this proof failing on an ADDED field
 * is the point of it being symmetric.
 */
export const InvitePreviewResourceSchema = z.object({
  plan_id: z.uuid(),
  plan_title: z.string(),
  inviter_name: z.string().nullable(),
  member_count: z.number().int(),
});

export type _InvitePreviewResourceAcceptsShowResponse = Expect<
  WireEquivalent<InvitePreviewBody, z.input<typeof InvitePreviewResourceSchema>>
>;

/**
 * The cursor envelope, once per paginated list.
 *
 * `next_cursor` is nullable-but-PRESENT, never `.optional()`: the document publishes `meta` as
 * required and next_cursor as ["string","null"], null being the end-of-list value, and an absent
 * key is a different contract from a null one. Measured both ways against this repo's harness -
 * `.nullable()` compiles, `.optional()` fails with TS2344 - which corrects the baseline's note
 * that an `.optional()` here "would be a proof that cannot fail": it is a proof that does not
 * compile.
 *
 * Three concrete schemas rather than one generic `cursorPage(item)` helper. A const arrow
 * function is not an `export const ...Schema`, so the pairing gate in auth.test.ts would neither
 * require nor count a proof for it, and the three instantiations are what the clients import
 * anyway.
 *
 * GET /plans/{plan}/invites has no page schema because it is not paginated - that list is bounded
 * by the 20/hour/plan invite limit and a 14-day TTL. Its items are PlanInviteResourceSchema and
 * its envelope is {data: [...]} with no meta.
 */
export const PlanPageSchema = z.object({
  data: z.array(PlanResourceSchema),
  meta: z.object({ next_cursor: z.string().nullable() }),
});

export const PlanMemberPageSchema = z.object({
  data: z.array(PlanMemberResourceSchema),
  meta: z.object({ next_cursor: z.string().nullable() }),
});

export const PlanActivityPageSchema = z.object({
  data: z.array(PlanActivityResourceSchema),
  meta: z.object({ next_cursor: z.string().nullable() }),
});

export type _PlanPageAcceptsListResponse = Expect<
  WireEquivalent<PlanPageBody, z.input<typeof PlanPageSchema>>
>;
export type _PlanMemberPageAcceptsListResponse = Expect<
  WireEquivalent<PlanMemberPageBody, z.input<typeof PlanMemberPageSchema>>
>;
export type _PlanActivityPageAcceptsListResponse = Expect<
  WireEquivalent<PlanActivityPageBody, z.input<typeof PlanActivityPageSchema>>
>;

type CreatePlanBody = NonNullable<
  paths['/plans']['post']['requestBody']
>['content']['application/json'];
type UpdatePlanBody = NonNullable<
  paths['/plans/{plan}']['patch']['requestBody']
>['content']['application/json'];
type ChangeMemberRoleBody = NonNullable<
  paths['/plans/{plan}/members/{user}']['patch']['requestBody']
>['content']['application/json'];
type InvitePlanMemberBody = NonNullable<
  paths['/plans/{plan}/invites']['post']['requestBody']
>['content']['application/json'];
type JoinPlanBody = NonNullable<
  paths['/plans/{plan}/join']['post']['requestBody']
>['content']['application/json'];

/**
 * The email rule, copied from auth.ts rather than imported, for the reason that file gives: a
 * client-side validator must never be stricter than the server's, so this is NOT z.email().
 * tandem-api's gate is filter_var(FILTER_VALIDATE_EMAIL) via Email::fromString, which accepts
 * addresses Zod's regex rejects (an IP-literal domain being the clearest case), and rejecting one
 * here would mean an address the API accepts cannot be sent through either client.
 *
 * `.trim()` first, then the cap, then the shape, which is the order the server applies:
 * TrimStrings is in Laravel's GLOBAL middleware stack, so '  Ada@Example.test  ' reaches
 * ValidEmail as 'ada@example.test' and is accepted. Lowercasing is not mirrored - the server
 * normalises before `unique` and before the CHECK, and nothing is accepted or refused on case.
 */
const apiEmail = z
  .string()
  .trim()
  .max(254)
  .regex(/^[^\s@]+@[^\s@]+$/, 'must look like an email address');

/**
 * Mirrors CreatePlanRequest::rules().
 *
 * `title` is ['required','string','max:255'] - varchar(255). `description` is
 * ['sometimes','required','string','max:2000'] against a `text` column, so the 2000 is a product
 * cap rather than a storage one. `timezone` is ['required','string', new IanaTimeZone] and is
 * REQUIRED rather than defaulted from the profile, because a Plan's wall-clock meaning needs an
 * explicit owner before anything hangs a time off it.
 *
 * `.trim().min(1)` on each is what `required` means on this stack rather than what it reads like:
 * TrimStrings trims before `required` sees the value, so '   ' is a 422 upstream, while Zod's
 * `.min(1)` counts characters and would accept it. `.min(1)` inside `.optional()` on
 * `description` is the `sometimes|required` shape - omit it and nothing changes, send it empty and
 * it is a 422, because ConvertEmptyStringsToNull turns "" into null before any rule runs.
 *
 * No `group_id`: POST /plans does not accept one, and no Cycle 1 route can set it.
 */
export const CreatePlanRequestSchema = z.object({
  title: z.string().trim().min(1).max(255),
  description: z.string().trim().min(1).max(2000).optional(),
  timezone: z.string().trim().min(1),
});

export type _CreatePlanMatchesApi = Expect<
  WireEquivalent<z.infer<typeof CreatePlanRequestSchema>, CreatePlanBody>
>;

/**
 * Mirrors UpdatePlanRequest::rules() - UpdateProfileRequest's shape, where omitting a key leaves
 * the value unchanged and sending an empty one is a 422. An empty body is valid and writes
 * nothing, which is why every field is optional and the whole requestBody is optional upstream -
 * the one place NonNullable above is load-bearing rather than uniform.
 *
 * `status` is absent (that is POST /archive), `owner_id` is absent (that is ownership transfer),
 * and `visibility` is published read-only in Cycle 1. All three absences are deliberate, and this
 * proof is symmetric, so adding one upstream fails here.
 */
export const UpdatePlanRequestSchema = z.object({
  title: z.string().trim().min(1).max(255).optional(),
  description: z.string().trim().min(1).max(2000).optional(),
  timezone: z.string().trim().min(1).optional(),
});

export type _UpdatePlanMatchesApi = Expect<
  WireEquivalent<z.infer<typeof UpdatePlanRequestSchema>, UpdatePlanBody>
>;

/**
 * Mirrors ChangeMemberRoleRequest::rules().
 *
 * z.enum() here and z.string() on the Resource side, and the asymmetry is the contract rather than
 * an oversight: `Rule::in(array_column(MemberRole::cases(), 'value'))` DOES publish an `enum`
 * list - Rules\In has __toString(), which RuleSetToSchemaTransformer calls, and InRule turns the
 * parsed values into one - and openapi-typescript renders that as a literal union. A response
 * field, by contrast, publishes as a bare `string`, because typing it as a native PHP enum would
 * publish the enum's class docblock as public documentation (spec §16.2).
 *
 * All three cases are accepted by the RULE, and `owner` is refused by the use case rather than by
 * validation: the matrix makes promotion owner-only, so an Admin sending `role: owner` is a 403
 * and not a 422. Narrowing this schema to two cases would refuse client-side a request the API
 * answers with the more informative status, and would not compile against the published enum.
 */
export const ChangeMemberRoleRequestSchema = z.object({
  role: z.enum(['owner', 'admin', 'member']),
});

export type _ChangeMemberRoleMatchesApi = Expect<
  WireEquivalent<z.infer<typeof ChangeMemberRoleRequestSchema>, ChangeMemberRoleBody>
>;

/**
 * Mirrors InvitePlanMemberRequest::rules() - `email` ['sometimes','required', new ValidEmail,
 * 'max:254'] and `role` ['sometimes','required','in:admin,member'], defaulting to `member`.
 *
 * Both optional, because a link-only invite sends neither: the API mints a token either way and
 * only emails it when an address is supplied.
 *
 * `role` is TWO cases, not three, and this is the one enum in the file that is narrower than
 * MemberRole: an `owner`-role invite would be an ownership transfer by the back door. An Admin is
 * further restricted to `member` invites by the use case, which is a 403 rather than a 422.
 */
export const InvitePlanMemberRequestSchema = z.object({
  email: apiEmail.optional(),
  role: z.enum(['admin', 'member']).optional(),
});

export type _InvitePlanMemberMatchesApi = Expect<
  WireEquivalent<z.infer<typeof InvitePlanMemberRequestSchema>, InvitePlanMemberBody>
>;

/**
 * Mirrors JoinPlanRequest::rules() - ['required','string','size:64'].
 *
 * `.trim()` ahead of `.length(64)` is the case where the order earns its keep: the server trims
 * before `size:64` counts, so a padded token is accepted upstream, and `.trim()` first makes the
 * parsed output exactly the value the server will look up. Measured in auth.ts for the identical
 * rule on refresh_token: `z.string().trim().length(64)` accepts ' <64 hex> ' and still refuses
 * ' <63 hex> '.
 */
export const JoinPlanRequestSchema = z.object({
  token: z.string().trim().length(64),
});

export type _JoinPlanMatchesApi = Expect<
  WireEquivalent<z.infer<typeof JoinPlanRequestSchema>, JoinPlanBody>
>;

/**
 * The error envelope, which this package did not have.
 *
 * session.ts recorded why: "an error envelope belongs to each client's HTTP layer, where the
 * status code is in hand", and nothing in Wave 1a consumed one. Wave 2 is what changes that
 * calculus - it finalises the vocabulary at seven statuses and two bodies, and it gives the
 * package a second consumer with the same need. Both clients otherwise hand-roll the identical
 * discriminator over {message} versus {message, errors}.
 *
 * TWO member schemas and a union, rather than one object with an optional `errors`, because the
 * two bodies really are two shapes: the document declares `errors` REQUIRED on
 * components.responses.ValidationException and absent everywhere else.
 *
 * WHERE THE PROOF LIVES, and this is the part worth reading before changing anything here. The
 * contract pinning is done by the per-member proofs below, one per status that actually publishes
 * each body. ErrorEnvelopeSchema's own assertion proves only its COMPOSITION, and that is
 * deliberate: a WireEquivalent proof of the union against the union of the two bodies is VACUOUS
 * in the direction that matters. Measured - WireEquivalent<{message,errors} | {message},
 * {message}> COMPILES, because each member of the left union is assignable to {message} and
 * {message} is assignable to the second member. So such a proof would still pass if `errors`
 * disappeared from the contract entirely, which is exactly the class of guard this project has
 * shipped by accident before. The composition proof does fail when a member is dropped or added
 * (measured: Equivalent<Val, Val | Msg> is false), which is all it claims.
 *
 * ValidationErrorSchema comes FIRST in the union: Zod tries members in order and returns the
 * first that parses, and ErrorMessageSchema strips unknown keys, so the other order would throw
 * `errors` away on every 422.
 */
export const ErrorMessageSchema = z.object({
  message: z.string(),
});

export const ValidationErrorSchema = z.object({
  message: z.string(),
  errors: z.record(z.string(), z.array(z.string())),
});

export const ErrorEnvelopeSchema = z.union([ValidationErrorSchema, ErrorMessageSchema]);

type UnauthenticatedBody = paths['/me']['get']['responses'][401]['content']['application/json'];
type ForbiddenBody = paths['/plans']['post']['responses'][403]['content']['application/json'];
type NotFoundBody = paths['/plans/{plan}']['get']['responses'][404]['content']['application/json'];
type ConflictBody =
  paths['/plans/{plan}']['patch']['responses'][409]['content']['application/json'];
type TooManyRequestsBody = paths['/plans']['post']['responses'][429]['content']['application/json'];
type RegisterValidationBody =
  paths['/auth/register']['post']['responses'][422]['content']['application/json'];
type CreatePlanValidationBody =
  paths['/plans']['post']['responses'][422]['content']['application/json'];

// One per status, and both mechanisms. 401 and 422 are published by Scramble as $refs into
// components.responses, while 403, 404, 409 and 429 are authored by #[Response] attributes and
// published inline - so these seven assertions are what prove the two mechanisms agree on one
// body, which is the claim spec §5.4's table makes and nothing checked.
export type _ErrorMessageAcceptsUnauthenticated = Expect<
  WireEquivalent<UnauthenticatedBody, z.input<typeof ErrorMessageSchema>>
>;
export type _ErrorMessageAcceptsForbidden = Expect<
  WireEquivalent<ForbiddenBody, z.input<typeof ErrorMessageSchema>>
>;
export type _ErrorMessageAcceptsNotFound = Expect<
  WireEquivalent<NotFoundBody, z.input<typeof ErrorMessageSchema>>
>;
export type _ErrorMessageAcceptsConflict = Expect<
  WireEquivalent<ConflictBody, z.input<typeof ErrorMessageSchema>>
>;
export type _ErrorMessageAcceptsTooManyRequests = Expect<
  WireEquivalent<TooManyRequestsBody, z.input<typeof ErrorMessageSchema>>
>;
export type _ValidationErrorAcceptsRegisterFailure = Expect<
  WireEquivalent<RegisterValidationBody, z.input<typeof ValidationErrorSchema>>
>;
export type _ValidationErrorAcceptsCreatePlanFailure = Expect<
  WireEquivalent<CreatePlanValidationBody, z.input<typeof ValidationErrorSchema>>
>;

// Composition only. See the docblock above for why this is not a WireEquivalent against the
// contract: that form cannot fail in the direction that matters.
export type _ErrorEnvelopeIsTheTwoBodies = Expect<
  Equivalent<
    z.input<typeof ErrorEnvelopeSchema>,
    z.input<typeof ValidationErrorSchema> | z.input<typeof ErrorMessageSchema>
  >
>;
