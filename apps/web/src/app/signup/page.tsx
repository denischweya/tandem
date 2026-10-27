import type { Metadata } from 'next';
import { SignUpForm } from '@/components/auth/SignUpForm';
import styles from '@/components/auth/authPage.module.css';

export const metadata: Metadata = {
  title: 'Create an account - Tandem',
};

/**
 * Server Component shell, mirroring `/signin/page.tsx`: reads the one optional query param
 * this route is reached with (`?next=`, e.g. from `InviteActions`'s "Create an account and
 * join") and passes it down as a plain prop - `SignUpForm` is the `'use client'` child that
 * validates it (via `safeNextPath`, never a second check here) and actually redirects.
 */
export default async function SignUpPage({ searchParams }: PageProps<'/signup'>) {
  const params = await searchParams;
  const next = typeof params.next === 'string' ? params.next : undefined;

  return (
    <main className={styles.page}>
      <SignUpForm next={next} />
    </main>
  );
}
