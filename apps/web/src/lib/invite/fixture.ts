/**
 * Mocked invite data.
 *
 * There is no invite API yet (Blueprint §131 only specifies that invite
 * links must be random, unguessable, expirable and revocable; the token
 * service itself is out of scope here). This module stands in for that
 * future endpoint: `getInviteByToken` is `async` and returns `null` for an
 * unrecognised token on purpose, so the page already handles "this link
 * doesn't resolve to anything" (expired, revoked, mistyped) rather than
 * assuming every token that reaches the route is good forever.
 *
 * CORRECTION (Task 12): this docblock used to claim that swapping the body of
 * `getInviteByToken` for a real `fetch` later "should not require any change to the page or
 * components that consume it." That is not true, and is withdrawn rather than carried
 * forward. `InvitePerson`/`InvitePlan`/`InviteDetails` below are camelCase
 * (`startInstant`, `timeZone`, `locationName`, `attendees`) by this module's own invention -
 * every resource `openapi.json` actually publishes is snake_case - and there is no invite
 * endpoint in spec §8 to generate a real type from in the first place (the Wave 2 design
 * spec's §10.3 is where `GET /api/v1/invites/{token}` -> `InvitePreviewResource`
 * `{plan_id, plan_title, inviter_name, member_count}` gets decided; until it lands and an
 * OpenAPI-generated type exists, there is nothing real for these interfaces to match). They
 * are a guess at a contract that does not exist yet, not a stand-in for one that does, so
 * they stay here rather than being promoted into `@tandem/shared` - promoting a guessed
 * shape into the package every client pins against would hand both clients a contract the
 * API was never asked to honour. When §10.3 lands, the page's own `InvitePreviewResource`
 * fields replace this module's guesses directly - see `DeferredJoinNotice`'s docblock for
 * the full deletion list that change carries with it.
 */

export interface InvitePerson {
  id: string;
  name: string;
}

export interface InvitePlan {
  id: string;
  title: string;
  /** ISO-8601 instant (UTC) the plan starts. */
  startInstant: string;
  /** IANA zone the plan's wall-clock time is expressed in. */
  timeZone: string;
  locationName: string;
  /** Everyone already attending other than the guest viewing this invite. */
  attendees: InvitePerson[];
}

export interface InviteDetails {
  token: string;
  inviter: InvitePerson;
  plan: InvitePlan;
}

const MOCK_INVITE: InviteDetails = {
  token: '7Jm9kP3',
  inviter: { id: 'u_denis', name: 'Denis' },
  plan: {
    id: 'plan_dinner_1',
    title: 'Dinner',
    // 19:00 in Africa/Nairobi (UTC+3, no DST) on Saturday 10 October 2026.
    startInstant: '2026-10-10T16:00:00.000Z',
    timeZone: 'Africa/Nairobi',
    locationName: 'Westlands, Nairobi',
    attendees: [
      { id: 'u_sarah', name: 'Sarah' },
      { id: 'u_james', name: 'James' },
      { id: 'u_denis', name: 'Denis' },
    ],
  },
};

/**
 * Resolves an invite token to its details. `async` on purpose — this is the
 * seam a real `/invites/{token}` call will fill later, and callers already
 * have to handle the `null` (no such invite, or no longer valid) case.
 */
export async function getInviteByToken(token: string): Promise<InviteDetails | null> {
  if (token !== MOCK_INVITE.token) return null;
  return MOCK_INVITE;
}
