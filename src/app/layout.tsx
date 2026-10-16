import type { Metadata } from 'next';
import { SessionProvider } from '@/lib/session/useSession';
import './tokens.css';
import './globals.css';

/**
 * Task 11, finding m3: the two `next/font/google` faces this file used to load
 * (`--font-geist-sans`, `--font-geist-mono`) were consumed by NO CSS rule in `src/` - `grep -rn
 * font-geist src` returned only their own declarations - so `next build` paid for a
 * `fonts.googleapis.com` fetch for zero visual effect. Deleted rather than wired up: the
 * promoted palette's `--font-body` (tokens.css) is this app's one font stack now, and a design
 * task picks a webfont, if one is ever wanted, rather than this task reviving an unused one.
 */
export const metadata: Metadata = {
  title: 'Tandem',
  description: 'Tandem web client.',
};

/**
 * `SessionProvider` wraps `{children}`, not the other way into making THIS file a client
 * component: `RootLayout` stays a Server Component (so every page under it can itself default
 * to one), and `SessionProvider` - a `'use client'` boundary exported from
 * `src/lib/session/useSession.ts` - supplies the `QueryClientProvider` `useSession()` would
 * need above every page that called it.
 *
 * CORRECTED (Wave 1b final review, branch coherence): this used to say "every page that
 * calls it" as if that were already true - MEASURED FALSE, no page calls `useSession()`
 * anywhere in this tree (see that hook's own corrected docblock). `SessionProvider` is wired
 * in ahead of its only consumer, not in response to one.
 */
export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html lang="en">
      <body>
        <SessionProvider>{children}</SessionProvider>
      </body>
    </html>
  );
}
