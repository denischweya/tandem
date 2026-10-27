import type { Metadata } from 'next';
import { VerifyEmailClient } from '@/components/auth/VerifyEmailClient';
import styles from '@/components/auth/authPage.module.css';

export const metadata: Metadata = {
  title: 'Verify your email - Tandem',
};

/**
 * The landing page for `EmailVerificationLink::for()`'s URL - see `VerifyEmailClient`'s own
 * docblock for why the raw query relay and the button-not-auto-POST behaviour BOTH have to
 * live in a client component: a Server Component has no `window.location` and would have to
 * reconstruct the query from the already-parsed `searchParams`, which is exactly the byte-
 * changing rebuild that permanently 403s a signed link.
 */
export default function VerifyEmailPage() {
  return (
    <main className={styles.page}>
      <VerifyEmailClient />
    </main>
  );
}
