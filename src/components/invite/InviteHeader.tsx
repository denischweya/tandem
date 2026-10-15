import { Avatar } from '@/components/ui/Avatar';
import styles from './InviteHeader.module.css';

export interface InviteHeaderProps {
  inviterName: string;
}

/**
 * "Denis invited you to a plan", with Denis's initial avatar. Rendered unconditionally by
 * `/invite/[token]/page.tsx` across all three of its resolved-invite states (signed in,
 * signed out, session expired) - it names who sent the invite, which is true regardless of
 * who is looking at it or whether they are signed in, so Task 12's session-aware rewrite of
 * the page below it changes nothing here.
 */
export function InviteHeader({ inviterName }: InviteHeaderProps) {
  return (
    <div className={styles.header}>
      <Avatar initial={inviterName} tone="inverse" size={40} />
      <p className={styles.text}>
        <strong>{inviterName}</strong> invited you to a plan
      </p>
    </div>
  );
}
