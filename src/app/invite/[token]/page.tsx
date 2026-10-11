import type { Metadata } from 'next';
import { InviteHeader } from '@/components/invite/InviteHeader';
import { PlanSummary } from '@/components/invite/PlanSummary';
import { RsvpResponse } from '@/components/invite/RsvpResponse';
import { SignupPrompt } from '@/components/invite/SignupPrompt';
import { getInviteByToken } from '@/lib/invite/fixture';
import styles from './page.module.css';

export async function generateMetadata({
  params,
}: PageProps<'/invite/[token]'>): Promise<Metadata> {
  const { token } = await params;
  const invite = await getInviteByToken(token);

  if (!invite) {
    return { title: 'Invite unavailable' };
  }

  return { title: `${invite.inviter.name} invited you to ${invite.plan.title}` };
}

/**
 * A guest invite link: `/invite/{token}`. The token is the whole route, not
 * a shareable-looking id in a query string, because Blueprint §131 requires
 * invite links to be random, unguessable, expirable and revocable — a token
 * that fails to resolve (expired, revoked, mistyped) is an expected outcome
 * here, not an edge case bolted on later, so `getInviteByToken` returning
 * `null` is handled below rather than assumed away.
 *
 * Per spec §11, this page must work for all three guests who can land here:
 * a signed-in user, a signed-out user with an account, and someone brand
 * new. None of those require signing in first — responding is the primary
 * action, and `SignupPrompt` only offers an account afterwards, as an
 * optional step.
 */
export default async function InvitePage({ params }: PageProps<'/invite/[token]'>) {
  const { token } = await params;
  const invite = await getInviteByToken(token);

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
        <RsvpResponse hostName={invite.inviter.name} />
        <SignupPrompt />
      </div>
    </main>
  );
}
