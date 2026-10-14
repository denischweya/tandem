import styles from './FormError.module.css';

export interface FormErrorProps {
  /** Already-mapped user copy - `userMessageFor`'s output, or a BFF `message` field that has
   *  already been through it server-side. This component never formats an error itself. */
  message: string;
}

/**
 * A form-level `role="alert"` message, for an error `fieldErrorsFor` could not (or must not)
 * attach to any one field: login's 401 (no field to blame) and register's/forgot-password's/
 * reset-password's generic failures. See `src/components/ui/README` for why this mirrors
 * mobile by name and prop rather than by shared code.
 *
 * FIX ROUND 1, Ruling A: this used to carry an `action` prop mirroring `ErrorState`'s, for
 * the reset-token case - but the controller accepted `ResetPasswordForm`'s dead-token branch
 * rendering a full `ErrorState` (which replaces the whole form) rather than a `FormError`
 * (which would sit inside it), so `action` ended up with ZERO production callers, exercised
 * only by this file's own test. Per the wave's YAGNI constraint: removed, rather than kept
 * for a caller that does not exist. `ErrorState` already carries the one production `action`
 * this task needs (its own prop, unaffected by this change).
 */
export function FormError({ message }: FormErrorProps) {
  return (
    <div role="alert" className={styles.formError}>
      <p className={styles.message}>{message}</p>
    </div>
  );
}
