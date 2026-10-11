import type { Metadata } from 'next';
import { SignInForm } from '@/components/auth/SignInForm';
import styles from '@/components/auth/authPage.module.css';

export const metadata: Metadata = {
  title: 'Sign in - Tandem',
};

/**
 * Server Component shell: reads the three optional query params this route is reached with
 * (`?next=` from `/plans`'s signed-out redirect, `?email=&reason=registered` from
 * `SignUpForm`'s hand-off, `?reason=passwordReset` from `ResetPasswordForm`'s) and passes them
 * down as plain props - `SignInForm` is the `'use client'` child that actually renders the
 * form and calls the BFF.
 */
export default async function SignInPage({ searchParams }: PageProps<'/signin'>) {
  const params = await searchParams;
  const next = typeof params.next === 'string' ? params.next : undefined;
  const email = typeof params.email === 'string' ? params.email : undefined;
  const reason = typeof params.reason === 'string' ? params.reason : undefined;

  return (
    <main className={styles.page}>
      <SignInForm next={next} prefilledEmail={email} reason={reason} />
    </main>
  );
}
