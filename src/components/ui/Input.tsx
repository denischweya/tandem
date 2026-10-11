'use client';

import { forwardRef, useId, useState, type InputHTMLAttributes, type Ref } from 'react';
import styles from './Input.module.css';

/**
 * See `src/components/ui/README` for why this mirrors `tandem-mobile/src/components/Input.tsx`
 * by name and prop rather than by shared code: `@tandem/shared` ships no React, and a shared
 * component library would couple an Expo renderer and a DOM renderer through one package.
 *
 * Built with `forwardRef` so it can be spread directly from React Hook Form's `register()`
 * (`<Input {...register('email')} />`) - a DOM `<input>` has a real `ref`, where RN's
 * `TextInput` on mobile did not make that the natural wiring, hence that file's
 * `Controller`-based `value`/`onChangeText`/`onBlur` props instead.
 */
export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string;
  /** Already-mapped user copy (a Zod resolver message or a server field error), never raw.
   *  `| undefined` explicitly, not just `?:` - React Hook Form's `fieldState.error?.message`
   *  is typed `string | undefined`, and `exactOptionalPropertyTypes` treats an omittable
   *  property and one that may be explicitly `undefined` as two different things. */
  error?: string | undefined;
  helpText?: string | undefined;
  /** Password field with a `Show`/`Hide` toggle. Forces `type="password"`/`type="text"`,
   *  overriding whatever `type` was passed in. */
  revealable?: boolean;
  id?: string;
}

function InputImpl(
  { label, error, helpText, revealable = false, id, type = 'text', ...rest }: InputProps,
  ref: Ref<HTMLInputElement>,
) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const errorId = `${inputId}-error`;
  const helpId = `${inputId}-help`;
  const [revealed, setRevealed] = useState(false);

  // Wires BOTH ids when both are present, so a screen reader announces help text and the
  // error together rather than one silently losing the association - `aria-describedby`
  // accepts a space-separated list.
  const describedByParts: string[] = [];
  if (helpText !== undefined) describedByParts.push(helpId);
  if (error !== undefined) describedByParts.push(errorId);
  const describedBy = describedByParts.length > 0 ? describedByParts.join(' ') : undefined;

  const resolvedType = revealable ? (revealed ? 'text' : 'password') : type;

  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={inputId}>
        {label}
      </label>
      <div className={styles.row}>
        <input
          id={inputId}
          ref={ref}
          type={resolvedType}
          aria-invalid={error !== undefined ? true : undefined}
          aria-describedby={describedBy}
          className={styles.input}
          {...rest}
        />
        {revealable ? (
          <button
            type="button"
            className={styles.reveal}
            onClick={() => setRevealed((current) => !current)}
            aria-pressed={revealed}
          >
            {revealed ? 'Hide' : 'Show'}
          </button>
        ) : null}
      </div>
      {helpText !== undefined ? (
        <p id={helpId} className={styles.help}>
          {helpText}
        </p>
      ) : null}
      {
        /* `role="alert"` so the error is ANNOUNCED on appearance, not merely readable on
         *  request - §136. Only rendered once `error` is non-empty: an empty alert region
         *  would still fire a (silent) live-region announcement in some screen readers, and
         *  `fieldErrorsFor`'s own blank-message guard (src/server/errors.ts) is the server-side
         *  half of the same rule - never render a blank error element. */
        error !== undefined && error.length > 0 ? (
          <p id={errorId} role="alert" className={styles.error}>
            {error}
          </p>
        ) : null
      }
    </div>
  );
}

export const Input = forwardRef<HTMLInputElement, InputProps>(InputImpl);
Input.displayName = 'Input';
