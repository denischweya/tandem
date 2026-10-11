import { Button } from './Button';
import styles from './FormError.module.css';

export interface FormErrorProps {
  /** Already-mapped user copy - `userMessageFor`'s output, or a BFF `message` field that has
   *  already been through it server-side. This component never formats an error itself. */
  message: string;
  /** For the cases the API cannot attribute to a field: login's 401 ("Sign in" has no field to
   *  blame), reset's dead token (the one field it WOULD attach to is hidden). `onClick`, not
   *  `onPress` - the one DOM adaptation of tandem-mobile's identical `FormError`-shaped action
   *  prop (that component is inlined per-screen on mobile; this is the first one factored out
   *  on either platform, named to match what mobile's screens already call this shape). */
  action?: { readonly label: string; readonly onClick: () => void };
}

/**
 * A form-level `role="alert"` message, for an error `fieldErrorsFor` could not (or must not)
 * attach to any one field. See `src/components/ui/README` for why this mirrors mobile by name
 * and prop rather than by shared code.
 */
export function FormError({ message, action }: FormErrorProps) {
  return (
    <div role="alert" className={styles.formError}>
      <p className={styles.message}>{message}</p>
      {action !== undefined ? (
        <Button variant="secondary" onClick={action.onClick}>
          {action.label}
        </Button>
      ) : null}
    </div>
  );
}
