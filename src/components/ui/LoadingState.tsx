import styles from './LoadingState.module.css';

export interface LoadingStateProps {
  /** Visible AND spoken. Never a bare spinner: a spinner alone communicates by motion only,
   *  which is the same failure mode as communicating by colour only (§136). */
  label: string;
}

/**
 * Mirrors `tandem-mobile/src/components/LoadingState.tsx` by name and prop (see
 * `src/components/ui/README`). `role="status"`/`aria-live="polite"` is the DOM adaptation of
 * that file's `accessibilityRole="progressbar"`/`accessibilityLiveRegion="polite"` - React
 * Native's accessibility roles have no 1:1 ARIA mapping, and `status` is the idiomatic web
 * role for an indeterminate "something is happening, here is why" region (a determinate
 * `progressbar` additionally needs `aria-valuenow`, which nothing here has to report).
 */
export function LoadingState({ label }: LoadingStateProps) {
  return (
    <div role="status" aria-live="polite" className={styles.loadingState}>
      <span className={styles.spinner} aria-hidden="true" />
      <p className={styles.label}>{label}</p>
    </div>
  );
}
