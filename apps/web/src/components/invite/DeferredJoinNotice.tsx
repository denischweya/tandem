import styles from './DeferredJoinNotice.module.css';

export interface DeferredJoinNoticeProps {
  displayName: string;
}

/**
 * THE DEFERRED-JOIN NOTICE. Delete this component, its copy and Task 13's own
 * live-against-a-real-API join test (not yet written - see the task's own "externally
 * blocked" status) when the Wave 2 design spec's §10.3 (GET /api/v1/invites/{token} ->
 * InvitePreviewResource) and §10.7 (POST /plans/{plan}/join) land. Build the preview against
 * InvitePreviewResource's published shape - plan_id, plan_title, inviter_name, member_count,
 * minimal by design per blueprint §126/§154 - and the join against §10.7, which §7.5
 * deliberately does NOT gate on `verified`.
 *
 * If that wave lands FIRST, this file is never written: build the preview and the join
 * instead, and drop the notice, this docblock and that (still-unwritten) test together.
 *
 * CORRECTED (Wave 1b final review, 4.5): this used to name the test by path
 * (`e2e/live/invite.spec.ts`), which does not exist - Task 13 is externally blocked and has
 * not written it. A reader following that path at merge time would find nothing there.
 */
export function DeferredJoinNotice({ displayName }: DeferredJoinNoticeProps) {
  return (
    <section className={styles.notice} data-testid="deferred-join-notice">
      <p className={styles.state}>
        <strong>You&apos;re signed in as {displayName}.</strong>
      </p>
      <p className={styles.body}>
        Joining a plan needs the Plans API, which isn&apos;t built yet. Your account is ready —
        nothing else to do here for now.
      </p>
      <a className={styles.action} href="/plans" data-testid="deferred-join-go-to-plans">
        Go to your plans
      </a>
    </section>
  );
}
