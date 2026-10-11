// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { Input } from './Input';

describe('Input', () => {
  it('associates the label with the field by id', () => {
    render(<Input label="Email" />);
    const input = screen.getByLabelText('Email');
    expect(input.tagName).toBe('INPUT');
  });

  it('renders help text and wires it through aria-describedby', () => {
    render(<Input label="Password" helpText="At least 12 characters." />);
    const input = screen.getByLabelText('Password');
    const describedBy = input.getAttribute('aria-describedby');
    expect(describedBy).not.toBeNull();
    const helpNode = document.getElementById(describedBy as string);
    expect(helpNode?.textContent).toBe('At least 12 characters.');
  });

  it('renders an error in a role="alert" region, wires aria-invalid and aria-describedby', () => {
    render(<Input label="Email" error="must look like an email address" />);
    const input = screen.getByLabelText('Email');
    expect(input.getAttribute('aria-invalid')).toBe('true');

    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('must look like an email address');

    const describedBy = input.getAttribute('aria-describedby') ?? '';
    expect(describedBy.split(' ')).toContain(alert.id);
  });

  it('wires BOTH help text and error into aria-describedby when both are present', () => {
    render(<Input label="Password" helpText="At least 12 characters." error="Too short." />);
    const input = screen.getByLabelText('Password');
    const describedBy = (input.getAttribute('aria-describedby') ?? '').split(' ');
    expect(describedBy).toHaveLength(2);
  });

  it('never renders an empty alert element for an absent error', () => {
    render(<Input label="Email" />);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('does not set aria-invalid when there is no error', () => {
    render(<Input label="Email" />);
    expect(screen.getByLabelText('Email').hasAttribute('aria-invalid')).toBe(false);
  });

  it('a revealable field starts masked and toggles to plain text on click', async () => {
    const user = userEvent.setup();
    render(<Input label="Password" revealable />);
    const input = screen.getByLabelText('Password');
    expect(input).toHaveAttribute('type', 'password');

    await user.click(screen.getByRole('button', { name: 'Show' }));
    expect(input).toHaveAttribute('type', 'text');
    expect(screen.getByRole('button', { name: 'Hide' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Hide' }));
    expect(input).toHaveAttribute('type', 'password');
  });

  it('a non-revealable field is not forced into type="password"', () => {
    render(<Input label="Email" type="email" />);
    expect(screen.getByLabelText('Email')).toHaveAttribute('type', 'email');
  });
});
