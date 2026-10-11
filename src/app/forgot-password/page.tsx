import type { Metadata } from 'next';
import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import styles from '@/components/auth/authPage.module.css';

export const metadata: Metadata = {
  title: 'Forgot password - Tandem',
};

export default function ForgotPasswordPage() {
  return (
    <main className={styles.page}>
      <ForgotPasswordForm />
    </main>
  );
}
