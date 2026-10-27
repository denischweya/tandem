import type { Metadata } from 'next';
import { redirect } from 'next/navigation';

import { getSession, type SessionReadResult } from '@/server/session';
import { HOUSE_SERVER_MESSAGE } from '@/server/errors';
import { ExpiredSessionRefresh } from '@/components/auth/ExpiredSessionRefresh';
import { SessionErrorFallback } from '@/components/auth/SessionErrorFallback';
import { EmptyState } from '@/components/ui/EmptyState';
import styles from '@/components/auth/authPage.module.css';

export const metadata: Metadata = {
  title: 'Plans - Tandem',
};

type SafeSessionRead =
  { readonly ok: true; readonly session: SessionReadResult } | { readonly ok: false };

/**
 * `getSession()` throws `SessionReadError` for anything that is not one of its three
 * documented states (see that function's own docblock) - a non-401 error status, a transport
 * failure, or a 2xx body that failed to parse. It has no `error.tsx` boundary anywhere under
 * `src/app`, and its docblock says whichever task gives it a first caller decides how. This
 * page decides by catching AT THE CALL SITE, narrowed through a discriminated result rather
 * than a nullable + a second flag, so the compiler (not a convention) stops a signedOut/
 * expired/signedIn branch below from ever running over a session that was never actually read.
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
 * The signed-in landing page (spec §11). A signed-out visitor is redirected to
 * `/signin?next=/plans`; an expired access token renders `ExpiredSessionRefresh` (a client
 * component that attempts one `refreshSession()` and then `router.refresh()`s this page);
 * otherwise this renders §134's empty state plus a "verify your email" banner when
 * `me.email_verified` is false - the only signal Cycle 1 has for that, since the `verified`
 * middleware is registered upstream but applied to no Cycle 1 route yet (Wave 2's Plan routes
 * are where a 403 would actually arrive).
 *
 * No "Create a Plan" action is wired onto the empty state: that button has nothing to open
 * yet - Wave 2 is where a Plan-creation route exists - and a button with nothing behind it is
 * exactly what `VerifyEmailClient`'s docblock argues against for the dead-link case too.
 */
export default async function PlansPage() {
  const result = await readSessionSafely();
  if (!result.ok) {
    return (
      <main className={styles.page}>
        <SessionErrorFallback message={HOUSE_SERVER_MESSAGE} />
      </main>
    );
  }

  const { session } = result;

  if (session.status === 'signedOut') {
    redirect('/signin?next=%2Fplans');
  }

  if (session.status === 'expired') {
    return (
      <main className={styles.page}>
        <ExpiredSessionRefresh />
      </main>
    );
  }

  return (
    <main className={styles.page}>
      <div className={styles.card}>
        {!session.user.email_verified ? (
          <div className={styles.banner} data-testid="plans-verify-banner">
            <p>Verify your email to unlock everything Tandem can do.</p>
            <a className={styles.link} href="/verify-email">
              Verify your email
            </a>
          </div>
        ) : null}
        <EmptyState title="Nothing planned yet." body="Create a Plan and invite your people." />
      </div>
    </main>
  );
}
