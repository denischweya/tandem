// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { EmptyState } from './EmptyState';

describe('EmptyState', () => {
  it("renders spec §134's no-plans copy verbatim", () => {
    render(
      <EmptyState title="Nothing planned yet." body="Create a Plan and invite your people." />,
    );
    expect(screen.getByRole('heading', { name: 'Nothing planned yet.' })).toBeInTheDocument();
    expect(screen.getByText('Create a Plan and invite your people.')).toBeInTheDocument();
  });

  it('renders no action button when none is given - /plans has nothing to open yet', () => {
    render(
      <EmptyState title="Nothing planned yet." body="Create a Plan and invite your people." />,
    );
    expect(screen.queryByRole('button')).toBeNull();
  });

  it('renders and wires an action when one is given', async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    render(
      <EmptyState
        title="Nothing planned yet."
        body="Create a Plan and invite your people."
        action={{ label: 'Create a Plan', onClick }}
      />,
    );
    await user.click(screen.getByRole('button', { name: 'Create a Plan' }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
