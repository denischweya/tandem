import { cache } from 'react';
import type { Metadata } from 'next';

import { getSession, type SessionReadResult } from '@/server/session';
import { getInviteByToken } from '@/lib/invite/fixture';
import { ExpiredSessionRefresh } from '@/components/auth/ExpiredSessionRefresh';
import { DeferredJoinNotice } from '@/components/invite/DeferredJoinNotice';
import { InviteActions } from '@/components/invite/InviteActions';
import { InviteHeader } from '@/components/invite/InviteHeader';
import { PlanSummary } from '@/components/plan/PlanSummary';
import styles from './page.module.css';

/**
 * FIX (Task 12, Step 1): `generateMetadata` and the page component below it both used to call
 * `getInviteByToken(token)` independently, and Next runs both for the same request - free
 * against this in-memory fixture, but two HTTP calls against the real `/invites/{token}`
 * endpoint §10.3 will eventually add. `cache()` (from `react`) memoises the call for the
 * lifetime of this one request, so both call sites below share a single lookup regardless of
 * which one runs first.
 */
const loadInvite = cache(getInviteByToken);

export async function generateMetadata({
  params,
}: PageProps<'/invite/[token]'>): Promise<Metadata> {
  const { token } = await params;
  const invite = await loadInvite(token);

  if (!invite) {
    return { title: 'Invite unavailable' };
  }

  return { title: `${invite.inviter.name} invited you to ${invite.plan.title}` };
}

type SafeSessionRead =
  { readonly ok: true; readonly session: SessionReadResult } | { readonly ok: false };

/**
 * `getSession()` throws `SessionReadError` for anything that is not one of its three
 * documented states - see that function's own docblock, and `src/server/session.ts`'s
 * docblock on `SessionReadError` for why this page (its second production caller) chooses to
 * handle that AT THE CALL SITE rather than adding a route-tree `error.tsx`, and why its
 * fallback differs from `/plans`'s: this page can always render the plan and the signed-out
 * actions below, session read or no session read, so a failed read degrades to the
 * signed-out branch instead of replacing the page with an error.
 */
async function readSessionSafely(): Promise<SafeSessionRead> {
  try {
    const session = await getSession();
    return { ok: true, session };
  } catch {
    return { ok: false };
  }
}

/**
 * A guest invite link: `/invite/{token}`. The token is the whole route, not
 * a shareable-looking id in a query string, because Blueprint §131 requires
 * invite links to be random, unguessable, expirable and revocable — a token
 * that fails to resolve (expired, revoked, mistyped) is an expected outcome
 * here, not an edge case bolted on later, so `getInviteByToken` returning
 * `null` is handled below rather than assumed away.
 *
 * Spec §11, verbatim: "The accept-invite page is not optional. §131's invite links must work
 * for a recipient who has never installed the app... It handles three cases: signed-in user
 * accepts; signed-out user with an account signs in and accepts; and a brand-new user
 * registers and accepts in one flow." This docblock used to restate those as three
 * AUDIENCES ("none of those require signing in first") while dropping the three FLOWS §11
 * actually names, two of which explicitly involve authenticating. That reinterpretation is
 * withdrawn; the three cases below are §11's, not a paraphrase of them:
 *
 *   1. Signed in           - render the invite and the account that's already signed in.
 *   2. Signed out, has one - "Sign in and join" round-trips through `/signin?next=…`.
 *   3. Signed out, new     - "Create an account and join" round-trips through
 *                            `/signup?next=…`, landing back here signed in (§11's "registers
 *                            and accepts in one flow", as far as authentication goes).
 *
 * WHAT THIS PAGE CANNOT DO YET, AND WHY IT SAYS SO RATHER THAN LYING. Neither action above
 * actually JOINS the plan. There is no `POST /api/v1/plans/{plan}/join` -
 * `routes/api/v1/plans.php` is a three-line stub - and no endpoint resolves a token-only
 * §132 link (`https://app.example.com/invite/{token}`) to a plan id at all, so this page
 * cannot even construct the join URL if the route existed. The Wave 2 design spec
 * (`docs/specs/2026-10-10-cycle-1-wave-2-plans-design.md`), Approved the same day as this
 * plan, names exactly this page as one of the two it unblocks: §10.3 adds
 * `GET /api/v1/invites/{token}` -> `InvitePreviewResource`, and §10.7 adds the join. Until
 * then, a signed-in visitor sees `DeferredJoinNotice` - a state plus an action, per §135,
 * saying what is true - instead of a button that records nothing and claims otherwise. See
 * that component's own docblock for the exact deletion this page's sequencing earns it.
 *
 * Two removed features, recorded rather than silently dropped. `RsvpResponse` (a three-way
 * "Are you in?" RSVP) is deleted: spec §11 names no such feature, spec §8 has no endpoint
 * behind it, and it recorded its answer in component state a reload erased - keeping it
 * would mean shipping a fake confirmation, the clearest §135 violation available here. Its
 * accessibility pattern (real `<button>`s, keyboard-operable, covered by
 * `e2e/support/keyboard.ts`) is kept and re-pointed at `InviteActions`'s two real actions.
 * `SignupPrompt`'s Apple and Google chips are also gone, per ADR 0006 (Cycle 1 ships neither)
 * - see that component's own docblock.
 */
export default async function InvitePage({ params }: PageProps<'/invite/[token]'>) {
  const { token } = await params;
  const invite = await loadInvite(token);

  if (!invite) {
    return (
      <main className={styles.page}>
        <div className={styles.content}>
          <div className={styles.unavailable}>
            <h1 className={styles.unavailableTitle}>This invite link isn&apos;t available</h1>
            <p className={styles.unavailableBody}>
              It may have expired, been cancelled, or the link might be mistyped. Ask whoever sent
              it to share a fresh one.
            </p>
          </div>
        </div>
      </main>
    );
  }

  const attendeeNames = invite.plan.attendees.map((person) => person.name);
  const result = await readSessionSafely();
  const session: SessionReadResult = result.ok ? result.session : { status: 'signedOut' };

  return (
    <main className={styles.page}>
      <div className={styles.content}>
        <InviteHeader inviterName={invite.inviter.name} />
        <PlanSummary
          title={invite.plan.title}
          startInstant={invite.plan.startInstant}
          timeZone={invite.plan.timeZone}
          locationName={invite.plan.locationName}
          attendeeNames={attendeeNames}
        />
        {session.status === 'signedIn' ? (
          <DeferredJoinNotice displayName={session.user.display_name} />
        ) : (
          <>
            <InviteActions token={token} />
            {session.status === 'expired' ? <ExpiredSessionRefresh /> : null}
          </>
        )}
      </div>
    </main>
  );
}
