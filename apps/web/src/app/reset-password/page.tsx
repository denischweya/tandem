import type { Metadata } from 'next';
import { ResetPasswordForm } from '@/components/auth/ResetPasswordForm';
import styles from '@/components/auth/authPage.module.css';

export const metadata: Metadata = {
  title: 'Reset password - Tandem',
};

/**
 * The landing page for `AppServiceProvider`'s reset URL (`?token=…&email=…`). `searchParams`
 * IS the correct way to read these two - unlike `/verify-email`'s signed query, nothing here
 * is cryptographically tied to byte-exact encoding, so the normal parsed-object form costs
 * nothing. Both default to the empty string when absent (a bookmarked or hand-typed URL with
 * no query at all): `ResetPasswordForm`'s own Zod resolver rejects an empty token/email on
 * submit with its own validation message, which is a far better failure than a page that
 * throws on a missing param.
 */
export default async function ResetPasswordPage({ searchParams }: PageProps<'/reset-password'>) {
  const params = await searchParams;
  const token = typeof params.token === 'string' ? params.token : '';
  const email = typeof params.email === 'string' ? params.email : '';

  return (
    <main className={styles.page}>
      <ResetPasswordForm token={token} email={email} />
    </main>
  );
}
