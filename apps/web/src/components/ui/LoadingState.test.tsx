// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { LoadingState } from './LoadingState';

describe('LoadingState', () => {
  it('the label is visible AND in a live region - never a bare spinner (§136)', () => {
    render(<LoadingState label="Verifying your email…" />);
    const status = screen.getByRole('status');
    expect(status).toHaveTextContent('Verifying your email…');
  });
});
