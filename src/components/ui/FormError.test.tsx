// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { FormError } from './FormError';

describe('FormError', () => {
  it('renders the message in a role="alert" region, so it is announced on appearance', () => {
    render(<FormError message="These credentials do not match our records." />);
    expect(screen.getByRole('alert')).toHaveTextContent(
      'These credentials do not match our records.',
    );
  });

  it('renders no action button when none is given', () => {
    render(<FormError message="Something went wrong." />);
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('renders the action and calls onClick - the reset-token "Request a new link" case', async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    render(
      <FormError
        message="This password reset link is no longer valid. Request a new one."
        action={{ label: 'Request a new link', onClick }}
      />,
    );
    await user.click(screen.getByRole('button', { name: 'Request a new link' }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
