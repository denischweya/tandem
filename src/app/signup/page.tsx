import type { Metadata } from 'next';
import { SignUpForm } from '@/components/auth/SignUpForm';
import styles from '@/components/auth/authPage.module.css';

export const metadata: Metadata = {
  title: 'Create an account - Tandem',
};

export default function SignUpPage() {
  return (
    <main className={styles.page}>
      <SignUpForm />
    </main>
  );
}
