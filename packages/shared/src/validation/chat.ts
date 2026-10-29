import { z } from 'zod';

import type { paths } from '../types/api';

import type { Expect, WireEquivalent } from './assignability';

/**
 * Hand-written parsers for the plan chat surface, proved against the generated contract the
 * same way plans.ts proves the rest of the Plans API. Read that file's top docblock first -
 * ACCESSORS, DIRECTION, ENUM-VALUED FIELDS and TIMESTAMPS all apply here unchanged. This
 * docblock records only what is specific to chat.
 *
 * REACTIONS ARE A NAMED RESOURCE ON THE WIRE, A ONE-LINER HERE. Scramble cannot infer a shape
 * from `PlanMessageReactionResource::collection(...)` mapped inline, so the backend gives each
 * reaction its own `PlanMessageReactionResource`, and the published document reaches it through
 * `components["schemas"]["PlanMessageReactionResource"]` rather than an inline object on
 * `PlanMessageResource.reactions`. That is a difference in how the TYPE is NAMED, not in what it
 * structurally contains, and `WireEquivalent` compares structurally (see WireShape in
 * assignability.ts) - so `PlanMessageReactionSummarySchema` is still written as its own
 * `z.object({...})` and referenced from `PlanMessageResourceSchema.reactions` exactly as every
 * other nested object in this package is. `PlanMessageResourceSchema`'s own proof already covers
 * the nested shape recursively (a rename inside PlanMessageReactionResource fails it too, the
 * way session.ts's UserSchema failing SessionSchema's proof is recorded), but
 * PlanMessageReactionSummarySchema still gets its own direct proof below, reached via
 * `['reactions'][number]` - no endpoint returns a bare reaction, so without it this schema would
 * be the first one in the package proved only by implication, and auth.test.ts's pairing gate
 * requires every exported schema's own name to appear in some assertion's body.
 *
 * NO SCHEMA FOR DELETING A MESSAGE OR A REACTION. `DELETE /plans/{plan}/messages/{message}` and
 * `DELETE /plans/{plan}/messages/{message}/reactions/{reaction}` take no request body and return
 * no content (204), so there is nothing for a Zod schema to parse on either side - the same
 * reason plans.ts has no schema for POST /plans/{plan}/archive's request.
 *
 * NO SCHEMA FOR THE REACTION PATH PARAMETER. `{reaction}` on the destroy route is a path segment,
 * not a request body, and plans.ts's "NO QUERY-PARAMETER SCHEMAS" note applies to it for the same
 * reason: there is no `requestBody` for a proof to reach, so a schema for it could carry none.
 */

type PlanMessagePageBody =
  paths['/plans/{plan}/messages']['get']['responses'][200]['content']['application/json'];
type PlanMessageCreatedBody =
  paths['/plans/{plan}/messages']['post']['responses'][201]['content']['application/json']['data'];
type PlanMessageUpdatedBody =
  paths['/plans/{plan}/messages/{message}']['patch']['responses'][200]['content']['application/json']['data'];

// No endpoint returns a bare reaction summary - `reactions` only ever appears nested inside a
// message. `[number]` reaches the item type the same way PlanMemberListItem does in plans.ts,
// and needs no NonNullable for the reason that file's top docblock records: indexing an ARRAY
// type with `number` is not widened by this repo's noUncheckedIndexedAccess.
type PlanMessageReactionItem = PlanMessageCreatedBody['reactions'][number];

/** Every timestamp this API returns, in the one format it returns them in - see plans.ts. */
const apiTimestamp = z.iso.datetime({ offset: true });

/**
 * Mirrors PlanMessageReactionResource::toArray().
 *
 * `reaction` is `z.string()`, not `z.enum()`, though the only way to create one is through
 * AddReactionRequestSchema's five-member enum below: this is plans.ts's ENUM-VALUED FIELDS rule
 * applied here - a Resource field publishes as a bare `string` (spec §16.2), and `z.enum()` on
 * this side would be stricter than the contract and fail its own proof against it.
 *
 * `count` is a plain aggregate (how many members reacted with this emoji) and `reacted_by_viewer`
 * is whether the signed-in user is one of them - both computed server-side per plan_message_id,
 * reaction pair, per the unique constraint on plan_message_reactions.
 */
export const PlanMessageReactionSummarySchema = z.object({
  reaction: z.string(),
  count: z.number().int(),
  reacted_by_viewer: z.boolean(),
});

export type _PlanMessageReactionSummaryAcceptsMessageResponse = Expect<
  WireEquivalent<PlanMessageReactionItem, z.input<typeof PlanMessageReactionSummarySchema>>
>;

/**
 * Mirrors PlanMessageResource::toArray().
 *
 * `id`, `plan_id` and `user_id` are z.uuid() though the document says only `string`: all three
 * are `uuid` columns (plan_messages.id, .plan_id, .user_id), so a non-UUID is impossible rather
 * than merely unexpected - the same reasoning PlanResourceSchema.id and .owner_id record.
 *
 * `edited_at` is nullable and NOT optional: the column is a nullable timestamptz, null means
 * never edited, and the key is always present. `created_at` and `updated_at` are never null -
 * every message row is written with both in the same request.
 *
 * `reactions` is always an array, never absent: a message with no reactions returns `[]`, not a
 * missing key, because PlanMessageReactionResource::collection() of an empty list is still `[]`.
 */
export const PlanMessageResourceSchema = z.object({
  id: z.uuid(),
  plan_id: z.uuid(),
  user_id: z.uuid(),
  body: z.string(),
  edited_at: apiTimestamp.nullable(),
  created_at: apiTimestamp,
  updated_at: apiTimestamp,
  reactions: z.array(PlanMessageReactionSummarySchema),
});

export type _PlanMessageResourceAcceptsCreateResponse = Expect<
  WireEquivalent<PlanMessageCreatedBody, z.input<typeof PlanMessageResourceSchema>>
>;
export type _PlanMessageResourceAcceptsUpdateResponse = Expect<
  WireEquivalent<PlanMessageUpdatedBody, z.input<typeof PlanMessageResourceSchema>>
>;

/**
 * The cursor envelope for GET /plans/{plan}/messages, PlanPageSchema's shape in plans.ts - see
 * that schema's docblock for why `next_cursor` is `.nullable()` rather than `.optional()`, and
 * why this is a concrete schema rather than a shared generic helper.
 */
export const PlanMessagePageSchema = z.object({
  data: z.array(PlanMessageResourceSchema),
  meta: z.object({ next_cursor: z.string().nullable() }),
});

export type _PlanMessagePageAcceptsListResponse = Expect<
  WireEquivalent<PlanMessagePageBody, z.input<typeof PlanMessagePageSchema>>
>;

type SendMessageBody = NonNullable<
  paths['/plans/{plan}/messages']['post']['requestBody']
>['content']['application/json'];
type EditMessageBody = NonNullable<
  paths['/plans/{plan}/messages/{message}']['patch']['requestBody']
>['content']['application/json'];

/**
 * Mirrors SendMessageRequest::rules() and EditMessageRequest::rules() - both
 * ['required', 'string', 'max:4000'] against a `text` column, so 4000 is a product cap rather
 * than a storage one, and both FormRequests trim the body in `prepareForValidation()` before any
 * rule runs.
 *
 * `.trim().min(1)` is what `required` means on this stack, the CreatePlanRequestSchema argument:
 * TrimStrings is also applied client-side here by `.trim()` running before `.min(1)` counts, so
 * '   ' fails the same way it fails upstream, and a leading/trailing-padded body parses to the
 * exact string the server will store.
 *
 * ONE SCHEMA SHAPE, TWO EXPORTS. SendMessageRequestSchema and EditMessageRequestSchema are
 * identical today because SendMessageRequest::rules() and EditMessageRequest::rules() are
 * identical today - that is a coincidence of this wave's two FormRequests, not a reason to
 * collapse them into one name. Keeping them as two `export const`s, each proved separately
 * below against its own endpoint's requestBody, means the day either FormRequest's rule moves
 * independently of the other, exactly one of these two proofs breaks rather than a single
 * shared schema silently drifting for both routes at once.
 */
export const SendMessageRequestSchema = z.object({
  body: z.string().trim().min(1).max(4000),
});

export type _SendMessageMatchesApi = Expect<
  WireEquivalent<z.infer<typeof SendMessageRequestSchema>, SendMessageBody>
>;

export const EditMessageRequestSchema = z.object({
  body: z.string().trim().min(1).max(4000),
});

export type _EditMessageMatchesApi = Expect<
  WireEquivalent<z.infer<typeof EditMessageRequestSchema>, EditMessageBody>
>;

type AddReactionBody = NonNullable<
  paths['/plans/{plan}/messages/{message}/reactions']['post']['requestBody']
>['content']['application/json'];

/**
 * Mirrors AddReactionRequest::rules() - ['required', 'string', Rule::in([...])]. `Rule::in(...)`
 * DOES publish an `enum` list (RuleSetToSchemaTransformer, the same mechanism
 * ChangeMemberRoleRequestSchema's docblock in plans.ts records), so `z.enum()` here is not
 * stricter than the contract and the proof below requires it to compile.
 *
 * No `.trim()`: these are five fixed emoji values, not free text, and nothing upstream trims
 * them. The MVP set is exactly these five - a sixth reaction is a 422 both here and upstream.
 */
export const AddReactionRequestSchema = z.object({
  reaction: z.enum(['👍', '❤️', '😂', '🎉', '❓']),
});

export type _AddReactionMatchesApi = Expect<
  WireEquivalent<z.infer<typeof AddReactionRequestSchema>, AddReactionBody>
>;
