// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { FormError } from './FormError';

/**
 * FIX ROUND 1, Ruling A: `action` was removed from `FormErrorProps` (zero production
 * callers - see `FormError.tsx`'s own docblock), so the test that existed only to cover it
 * ("renders the action and calls onClick") is removed along with it, per the wave's YAGNI
 * constraint. The two tests below cover everything this component still does.
 */
describe('FormError', () => {
  it('renders the message in a role="alert" region, so it is announced on appearance', () => {
    render(<FormError message="These credentials do not match our records." />);
    expect(screen.getByRole('alert')).toHaveTextContent(
      'These credentials do not match our records.',
    );
  });

  it('renders no button - this component has no action', () => {
    render(<FormError message="Something went wrong." />);
    expect(screen.queryByRole('button')).toBeNull();
  });
});
