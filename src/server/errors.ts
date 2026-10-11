import { z } from 'zod';

/**
 * The API's COMPLETE error vocabulary, from the three exception renderers in
 * tandem-api/bootstrap/app.php plus Laravel's own four bodies:
 *
 *   {message}                       401 Unauthenticated. | credentials | refresh token
 *   {message}                       403 This action is unauthorized.
 *   {message, errors}               422 validation, and the dead-reset-link renderer
 *   {message}                       429 Too Many Attempts.
 *
 * Two shapes, and the second is a superset of the first, so parsing on the PRESENCE of
 * `errors` is sufficient. openapi.json names all three reusable responses
 * (ValidationException, AuthenticationException, AuthorizationException) and
 * openapi-typescript generates all three into `@tandem/shared`'s `components['responses']` -
 * but the shared package exports no RUNTIME parser for any of them, deliberately
 * (session.ts: "an error envelope belongs to each client's HTTP layer, where the status code
 * is in hand").
 *
 * THIS SCHEMA IS DUPLICATED IN tandem-mobile (src/api/errors.ts) AND BELONGS IN
 * @tandem/shared. It is not there because `tandem-shared` is read-only for this wave. Two
 * consumers hand-rolling the same parser is precisely the duplication spec §5 exists to
 * prevent.
 *
 * ITS SUCCESSOR IS NAMED, not left to a future wave:
 * `docs/specs/2026-10-10-cycle-1-wave-2-plans-design.md` §16.3 (Status: Approved, same date as
 * this plan) decides that **Wave 2 adds `ErrorEnvelopeSchema` to `@tandem/shared`**, on the
 * same second-consumer argument, because Wave 2 finalises the error vocabulary at seven
 * statuses and four bodies (its §5.4) and the three error component responses are already
 * generated into `api.ts` for the proof to reach. DELETE this copy and its twin in
 * `tandem-mobile/src/api/errors.ts` in the client change that bumps to `v0.3.0`, and keep the
 * discriminator-on-`errors` note.
 */
export const ErrorEnvelopeSchema = z.object({
  message: z.string(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
});

export type ErrorEnvelope = z.infer<typeof ErrorEnvelopeSchema>;

/**
 * The ONLY statuses whose `message` this API authors FOR A USER, as a COMPILER-CLOSED
 * allowlist rather than the status CLASS `>= 400 && < 500`.
 *
 * MEASURED, and the reason the class test would be a §135 breach rather than a rough edge:
 * tandem-api's bootstrap/app.php registers renderers for exactly three application exceptions
 * (validation, authentication, authorization), so EVERY other HttpException falls through to
 * Laravel's default handler, which renders `$e->getMessage()`. The 4xx surface is therefore
 * much wider than the four statuses this API writes copy for, and the rest is framework text
 * (a 405's "Supported methods: GET, PATCH, DELETE.", a 404's route-not-found sentence, a bare
 * `abort(403)`'s empty string). Mirrors `tandem-mobile/src/api/errors.ts`'s
 * `AuthoredMessageStatus`.
 */
export type AuthoredMessageStatus =
  // 401 - LoginController: "These credentials do not match our records." | the refresh/
  //        logout 401's "The refresh token is not valid." | "Unauthenticated."
  // 403 - "This action is unauthorized.", when the renderer supplied it.
  // 422 - validation, and the dead-reset-link renderer's own sentence.
  // 429 - "Too Many Attempts."
  401 | 403 | 422 | 429;

/**
 * A `Record` over the literal union, not a `Set<number>`, so the COMPILER closes this table -
 * see `tandem-mobile/src/api/errors.ts`'s identical table for the measured reason a `Set`
 * leaves a typo'd status silently inert.
 */
const AUTHORS_ITS_OWN_MESSAGE: Record<AuthoredMessageStatus, true> = {
  401: true,
  403: true,
  422: true,
  429: true,
};

function authorsItsOwnMessage(status: number): boolean {
  return Object.hasOwn(AUTHORS_ITS_OWN_MESSAGE, status);
}

/**
 * House copy for a status this API does not author a sentence for, and for a 2xx body that
 * fails to parse against the schema a handler expected (`handlers.ts`'s `parseEnvelopeData`
 * returning `null` - the contract and the server disagree). Never logged, never templated
 * with any backend text - a constant string.
 *
 * FIX ROUND 3, M-B: this docblock used to say `HOUSE_SERVER_MESSAGE` also covers "a transport
 * failure", citing a `contractErrorMessage` that does not exist anywhere in this repo (M3's
 * sweep for stale docblocks missed this one, in the same file M5 made it stale in - M5 moved
 * `relayFailure`'s `transportFailure` branch to `HOUSE_NETWORK_MESSAGE`, four lines below,
 * which is what actually covers that case now).
 */
export const HOUSE_SERVER_MESSAGE = 'Something went wrong on our end. Please try again.';
/** House copy for a transport failure (`fetch` itself threw - never reached the API at all).
 *  `relayFailure`'s `transportFailure` branch is the one production call site. */
export const HOUSE_NETWORK_MESSAGE =
  "We couldn't reach Tandem. Check your connection and try again.";

/**
 * The single source of user-facing error copy for the BFF (§135). Nothing in `handlers.ts`
 * builds an error string any other way.
 *
 * FOUR statuses pass through VERBATIM - an ALLOWLIST, not the status class - because this API
 * authors those four for users. Substituting house copy there would replace a sentence someone
 * wrote for a person with a sentence nobody wrote for anybody.
 *
 * Everything else gets house copy: every other 4xx (framework text), 5xx (Laravel's generic
 * "Server Error"), and an allowlisted status whose message arrived blank (`abort(403)`, which
 * `ErrorEnvelopeSchema.message` happily parses as `''` - a bare `z.string()`, so the non-blank
 * guard below is load-bearing, not decoration).
 *
 * `envelope: null` happens when the body was not JSON (an HTML error page from a proxy) or did
 * not match the envelope shape at all - either way, nothing authored to show, so house copy.
 */
export function userMessageFor(status: number, envelope: ErrorEnvelope | null): string {
  if (envelope !== null && authorsItsOwnMessage(status)) {
    // `.trim()`, not `.length`: a whitespace-only message is as unusable to a reader as an
    // empty one, and both parse.
    const authored = envelope.message.trim();
    if (authored.length > 0) return envelope.message;
  }
  return HOUSE_SERVER_MESSAGE;
}

/**
 * FIX ROUND 3: `userMessageFor` and `fieldErrorsFor` make several INDEPENDENT decisions about
 * the same envelope, and this task has now found a gate present in one and missing in the
 * other FOUR times (round 2 Important 1's status gate; round 3 M-A's blank-message guard, and
 * two earlier rounds' narrower instances of the same shape elsewhere in this file). Written
 * down once, side by side, rather than re-derived the next time one of them changes:
 *
 *                                  userMessageFor            fieldErrorsFor
 *   Gates on authorsItsOwnMessage   YES (unauthored -> house)  YES (unauthored -> null) - SAME allowlist, same table.
 *   Gates on envelope === null      YES (-> house)              YES, via `envelope?.errors === undefined` (null envelope has no .errors either) - same outcome, different-shaped check because `errors` is optional on the schema and `message` is not.
 *   Unit of decision                ONE value (the message)    MANY values (one per field key) - the structural difference everything else follows from.
 *   Blank/whitespace guard          YES (`.trim().length > 0`) YES as of M-A (`.trim().length > 0`) - now the SAME test, applied per-field instead of once.
 *   Array unwrapping                n/a (message is never an array) YES (`messages[0]`) - `errors` values are `string[]`, `message` never is.
 *   Returned value on the happy path  the ORIGINAL `envelope.message`, untrimmed (trim is only the emptiness test) - the ORIGINAL `first`, untrimmed, for the same reason. AGREE.
 *   Fallback when nothing survives   HOUSE_SERVER_MESSAGE - ALWAYS returns a string, because a handler always has SOMETHING to tell a user.   `null` - no "house copy for field errors" exists, because there is no field to blame once the real ones are gone; the caller (`relayFailure`) simply omits `errors` from the body rather than inventing one.
 *
 * The next new decision either function needs should be added to BOTH rows of whichever line
 * it belongs to, in the same edit, rather than drifting apart again.
 */

/**
 * First message per field, ready for a form's field-level error state (Task 11). Kept here
 * rather than only inline in `handlers.ts` because the shape (`Record<string, string[]>` ->
 * `Record<string, string>`) is exactly the one `tandem-mobile/src/api/errors.ts` already
 * standardised, and a second client reaching for the same transform should find it named the
 * same way.
 *
 * FIX ROUND 2, IMPORTANT 1 (reopened): this used to take only `envelope` and relay
 * `envelope.errors` at ANY status. MEASURED: `relayFailure` called it unconditionally, so a
 * `{message: "...", errors: {token: ["RAW-BACKEND-TEXT-500 SQLSTATE[23000]..."]}}` arriving
 * behind a 500 relayed that raw text verbatim through `errors`, even after round 1 gated
 * `message` itself through `userMessageFor`'s allowlist - the exact "right behaviour at 422,
 * wrong everywhere else" shape Important 1 was raised to close, one field over. `status` is
 * now a REQUIRED parameter and the function gates on `authorsItsOwnMessage` itself, same as
 * `userMessageFor` - structural, not caller discipline, so a future call site cannot forget
 * the gate the way this one did.
 */
export function fieldErrorsFor(
  status: number,
  envelope: ErrorEnvelope | null,
): Record<string, string> | null {
  if (!authorsItsOwnMessage(status)) return null;
  if (envelope?.errors === undefined) return null;
  const out: Record<string, string> = {};
  for (const [field, messages] of Object.entries(envelope.errors)) {
    const first = messages[0];
    // FIX ROUND 3, M-A: `userMessageFor`'s non-blank guard is called "load-bearing, not
    // decoration" in its own docblock, for the identical reason - `abort(403)` parses as
    // `''`. This had no equivalent guard: `messages[0] !== undefined` admits `''` and
    // `'   '`, which Task 11's `setError(field, {message: ''})` would render as a blank
    // error element. `.trim().length > 0`, same test `userMessageFor` makes.
    if (first !== undefined && first.trim().length > 0) out[field] = first;
  }
  return Object.keys(out).length > 0 ? out : null;
}
