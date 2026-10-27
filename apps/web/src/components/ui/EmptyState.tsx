import type { ReactNode } from 'react';
import { Button } from './Button';
import styles from './EmptyState.module.css';

export interface EmptyStateProps {
  /** §134's first line. */
  title: string;
  /** §134's second line. */
  body: string;
  action?: { readonly label: string; readonly onClick: () => void };
  /** Decorative; hidden from the accessibility tree. */
  icon?: ReactNode;
}

/**
 * Required by §134 ("every major screen needs an intentional empty state") - `/plans`'s
 * "Nothing planned yet. / Create a Plan and invite your people." is this task's one caller.
 * See `src/components/ui/README` for why this mirrors
 * `tandem-mobile/src/components/EmptyState.tsx` by name and prop.
 */
export function EmptyState({ title, body, action, icon }: EmptyStateProps) {
  return (
    <div className={styles.emptyState}>
      {icon !== undefined ? (
        <div aria-hidden="true" className={styles.icon}>
          {icon}
        </div>
      ) : null}
      <h2 className={styles.title}>{title}</h2>
      <p className={styles.body}>{body}</p>
      {action !== undefined ? (
        <Button variant="primary" onClick={action.onClick}>
          {action.label}
        </Button>
      ) : null}
    </div>
  );
}
