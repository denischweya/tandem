// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { ErrorState } from './ErrorState';

describe('ErrorState', () => {
  it('renders the body in a role="alert" region', () => {
    render(
      <ErrorState
        title="This link isn't usable"
        body="This password reset link is no longer valid. Request a new one."
      />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent(
      'This password reset link is no longer valid. Request a new one.',
    );
  });

  it('wires the action - the dead-token "Request a new link" case', async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    render(
      <ErrorState
        title="This link isn't usable"
        body="This password reset link is no longer valid. Request a new one."
        action={{ label: 'Request a new link', onClick }}
      />,
    );
    await user.click(screen.getByRole('button', { name: 'Request a new link' }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
