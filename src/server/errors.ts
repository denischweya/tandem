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

/** House copy for a status this API does not author a sentence for, and for the two local
 *  failure modes (`contractErrorMessage`'s caller, a transport failure) that never reach the
 *  API at all. Never logged, never templated with any backend text - a constant string. */
export const HOUSE_SERVER_MESSAGE = 'Something went wrong on our end. Please try again.';
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
 * First message per field, ready for a form's field-level error state (Task 11). Kept here
 * rather than only inline in `handlers.ts` because the shape (`Record<string, string[]>` ->
 * `Record<string, string>`) is exactly the one `tandem-mobile/src/api/errors.ts` already
 * standardised, and a second client reaching for the same transform should find it named the
 * same way.
 */
export function fieldErrorsFor(envelope: ErrorEnvelope | null): Record<string, string> | null {
  if (envelope?.errors === undefined) return null;
  const out: Record<string, string> = {};
  for (const [field, messages] of Object.entries(envelope.errors)) {
    const first = messages[0];
    if (first !== undefined) out[field] = first;
  }
  return Object.keys(out).length > 0 ? out : null;
}
