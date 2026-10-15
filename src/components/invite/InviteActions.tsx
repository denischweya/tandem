'use client';

import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';
import { SignupPrompt } from './SignupPrompt';
import styles from './InviteActions.module.css';

export interface InviteActionsProps {
  /** The invite's own route token - `getInviteByToken` already resolved it to a real
   *  invite by the time this renders, so building `/invite/{token}` here is not consuming a
   *  second caller-supplied value, only reusing the one the page itself already trusts. */
  token: string;
}

const HEADING_ID = 'invite-actions-heading';

/**
 * Spec §11's cases 2 and 3 for a signed-out visitor - "signed-out user with an account signs
 * in and accepts" and "a brand-new user registers and accepts in one flow" - as far as
 * AUTHENTICATION goes. This replaces `RsvpResponse`'s "Are you in?" choices: that component
 * recorded a reply nowhere §8 has an endpoint for, in component state that a reload threw
 * away, so its "You're in - Denis will see your reply" confirmation was a real §135
 * violation. Both actions here are real, complete flows that round-trip through `/signin` or
 * `/signup` and land the visitor back on THIS invite, signed in - `AcceptInvite` itself
 * (spec §8's `POST /plans/{plan}/join`) is still missing (see this page's own docblock), so
 * neither button joins anything yet, but neither one lies about what it does either.
 *
 * `RsvpResponse`'s accessibility pattern is kept and re-pointed at these two actions: real
 * `<button>`s (native Enter AND Space activation, not a styled `<div>`), reachable by
 * `e2e/support/keyboard.ts`'s `tabUntilFocused` the same way the RSVP choices were.
 *
 * The second action is `SignupPrompt`, not a second button built here: that component is
 * this exact "Create an account and join" step, rewritten per ADR 0006 to drop the two
 * OAuth providers Cycle 1 never ships.
 */
export function InviteActions({ token }: InviteActionsProps) {
  const router = useRouter();
  const next = `/invite/${token}`;

  return (
    <section className={styles.section} aria-labelledby={HEADING_ID}>
      <h2 id={HEADING_ID} className={styles.heading}>
        Join this plan
      </h2>
      <div className={styles.actions}>
        <Button
          variant="primary"
          onClick={() => router.push(`/signin?${new URLSearchParams({ next }).toString()}`)}
          data-testid="invite-signin"
        >
          Sign in and join
        </Button>
        <SignupPrompt next={next} />
      </div>
    </section>
  );
}
