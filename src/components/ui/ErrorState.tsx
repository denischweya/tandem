import { Button } from './Button';
import styles from './ErrorState.module.css';

export interface ErrorStateProps {
  title: string;
  /** Already-mapped user copy. This component NEVER formats an error itself - that is
   *  `userMessageFor`'s job in src/server/errors.ts, and keeping the two apart is what makes
   *  "no raw backend error reaches the user" (§135) checkable in one place. */
  body: string;
  action?: { readonly label: string; readonly onClick: () => void };
}

/**
 * Required by §135 and mirrors `tandem-mobile/src/components/ErrorState.tsx` by name and
 * prop (see `src/components/ui/README`). Used in this task by `VerifyEmailClient` (a 403, or
 * any other failure, has no resend endpoint behind it) and `ResetPasswordForm` (a dead reset
 * token replaces the whole form rather than rendering invisibly against a hidden field).
 */
export function ErrorState({ title, body, action }: ErrorStateProps) {
  return (
    <div className={styles.errorState}>
      <h2 className={styles.title}>{title}</h2>
      <p role="alert" className={styles.body}>
        {body}
      </p>
      {action !== undefined ? (
        <Button variant="secondary" onClick={action.onClick}>
          {action.label}
        </Button>
      ) : null}
    </div>
  );
}
