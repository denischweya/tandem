import type { ButtonHTMLAttributes } from 'react';
import styles from './Button.module.css';

/**
 * `destructive` joins the enum rather than becoming a third boolean modifier alongside
 * `pressed`/`pending`, mirroring `tandem-mobile/src/components/Button.tsx`'s own
 * `ButtonVariant = 'primary' | 'secondary' | 'destructive'` - the mobile file's docblock
 * records the one colour decision behind it (accent[700], #a80c58, white label at 7.35:1,
 * Task 9) with "an arm with no caller is data; an invented colour would be a product
 * decision." Task 11 adds no destructive-button caller either - no auth page in this task
 * destroys anything - and inherits that same reasoning rather than re-deciding it: the arm
 * exists so the next task that needs one does not also have to invent a colour. `#a80c58` is
 * not reinvented here; it is `--color-accent-700` in `tokens.css`, the same hex mobile chose.
 */
export type ButtonVariant = 'primary' | 'secondary' | 'chip' | 'destructive';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant: ButtonVariant;
  /**
   * For a toggle-like button (one of the RSVP choices): sets both the
   * selected visual treatment and `aria-pressed`, so the selection is
   * conveyed to assistive tech the same way it is conveyed visually.
   */
  pressed?: boolean;
  /**
   * Sets `disabled` and `aria-busy`, and nothing else - the box size is whatever the
   * variant's own CSS already gives it, because this never swaps the label for a spinner
   * that could change it. A second submit while pending matters most on a rate-limited
   * endpoint (login, register, forgot-password all throttle), where a double-click would
   * burn two attempts against one budget for one user action.
   */
  pending?: boolean;
}

/**
 * A real `<button>` for every pill in the design system this page uses
 * (`.pbtn` / `.sbtn` / `.chip`) — never a styled `<div>` or `<span>`, so
 * every choice is reachable and operable by keyboard without extra markup.
 */
export function Button({
  variant,
  pressed,
  pending,
  className,
  type = 'button',
  disabled,
  ...rest
}: ButtonProps) {
  const variantClass = styles[variant];
  const classes = [styles.button, variantClass, pressed ? styles.pressed : '', className]
    .filter(Boolean)
    .join(' ');

  return (
    <button
      type={type}
      className={classes}
      aria-pressed={pressed === undefined ? undefined : pressed}
      aria-busy={pending === true ? true : undefined}
      disabled={disabled === true || pending === true}
      {...rest}
    />
  );
}
