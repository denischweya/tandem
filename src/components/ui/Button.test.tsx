// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Button } from './Button';

describe('Button', () => {
  it('is always a real <button>, never a styled div', () => {
    render(<Button variant="primary">Sign in</Button>);
    expect(screen.getByRole('button', { name: 'Sign in' }).tagName).toBe('BUTTON');
  });

  it('pending sets aria-busy and disabled without changing the rendered label', () => {
    render(
      <Button variant="primary" pending>
        Sign in
      </Button>,
    );
    const button = screen.getByRole('button', { name: 'Sign in' });
    expect(button).toHaveAttribute('aria-busy', 'true');
    expect(button).toBeDisabled();
  });

  it('is not busy or disabled when pending is absent', () => {
    render(<Button variant="primary">Sign in</Button>);
    const button = screen.getByRole('button', { name: 'Sign in' });
    expect(button).not.toHaveAttribute('aria-busy');
    expect(button).not.toBeDisabled();
  });

  it('pressed sets aria-pressed for a toggle-like button', () => {
    render(
      <Button variant="secondary" pressed>
        Maybe
      </Button>,
    );
    expect(screen.getByRole('button', { name: 'Maybe' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('a non-toggle button has no aria-pressed at all, not aria-pressed="false" by default', () => {
    render(<Button variant="primary">Sign in</Button>);
    expect(screen.getByRole('button', { name: 'Sign in' })).not.toHaveAttribute('aria-pressed');
  });

  it('accepts the destructive variant', () => {
    render(<Button variant="destructive">Delete account</Button>);
    expect(screen.getByRole('button', { name: 'Delete account' })).toBeInTheDocument();
  });

  it('defaults to type="button", not a form-submitting type="submit"', () => {
    render(<Button variant="primary">Sign in</Button>);
    expect(screen.getByRole('button', { name: 'Sign in' })).toHaveAttribute('type', 'button');
  });
});
