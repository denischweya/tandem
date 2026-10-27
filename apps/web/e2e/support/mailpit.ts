/**
 * Reads the outbox through Mailpit's HTTP API (docker-compose exposes 8025).
 *
 * The API's mail goes through the `outbox_events` table and the `outbox:dispatch` command, so
 * a message appears only after a DISPATCHER run - which is why `latestMessageTo` polls rather
 * than reading once.
 *
 * MEASURED CORRECTION to the brief, which said "the runbook runs `make queue`". It does not
 * help: `make queue` is `artisan queue:work`, and a grep for `ShouldQueue` across
 * `tandem-api/app/` returns NOTHING - no job is ever pushed, so the queue worker processes
 * zero jobs and no mail is produced by it. The handlers that call `Mail::to(...)->send(...)`
 * are registered on `OutboxHandlers` and are run by `OutboxDispatcher`, which only runs when
 * `artisan outbox:dispatch` does. That command is driven by the SCHEDULER
 * (`routes/console.php`: `Schedule::command('outbox:dispatch')->everyMinute()`), not by the
 * queue. `docs/local-e2e-runbook.md` therefore runs a dispatcher loop, and the default
 * timeout below is sized for that loop's interval plus the API's own send, not for the
 * scheduler's one-minute granularity.
 */
const MAILPIT_BASE = process.env.MAILPIT_BASE_URL ?? 'http://localhost:8025';

interface MailpitSummary {
  readonly ID: string;
  readonly Subject: string;
  readonly Created: string;
}

interface MailpitSearchResponse {
  readonly messages: readonly MailpitSummary[];
}

interface MailpitMessage {
  readonly HTML: string;
  readonly Text: string;
}

/**
 * Mailpit's HTML bodies are HTML, so an `href` holding a query string arrives with its
 * ampersands entity-encoded: measured on a real verification mail,
 * `...?expires=1792775135&amp;hash=9f92...`. Following that string verbatim would send
 * `amp;hash=...` to the API as a parameter name and permanently 403 the signed link. Only the
 * five XML/HTML predefined entities can appear in an attribute value Laravel's mail renderer
 * produced, so this decodes exactly those, `&amp;` LAST so a literal `&amp;amp;` is not
 * double-decoded into `&`.
 */
function decodeHtmlEntities(value: string): string {
  return value
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');
}

const HREF_PATTERN = /href="([^"]+)"/g;

function extractLinks(html: string): string[] {
  const links: string[] = [];
  for (const match of html.matchAll(HREF_PATTERN)) {
    const raw = match[1];
    if (raw !== undefined) links.push(decodeHtmlEntities(raw));
  }
  return links;
}

async function searchFor(email: string): Promise<readonly MailpitSummary[]> {
  // `to:"<address>"` - quoted, because an unquoted address with a '+' tag is read as two
  // terms. Mailpit returns matches NEWEST FIRST (measured against two messages for one
  // address: the reset mail, sent second, came back at index 0), which is what makes
  // `messages[0]` "the latest message to this address" rather than an arbitrary one.
  const url = `${MAILPIT_BASE}/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`;
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(
      `Mailpit search failed: HTTP ${response.status}. Is Mailpit up (make up in tandem-api)?`,
    );
  }
  const json = (await response.json()) as MailpitSearchResponse;
  return json.messages ?? [];
}

async function readMessage(id: string): Promise<MailpitMessage> {
  const response = await fetch(`${MAILPIT_BASE}/api/v1/message/${id}`);
  if (!response.ok) {
    throw new Error(`Mailpit message ${id} failed: HTTP ${response.status}`);
  }
  return (await response.json()) as MailpitMessage;
}

/**
 * The newest message to `email`, polled until one arrives. `after` makes the wait specific:
 * pass the instant a second mail was REQUESTED after, and a message already sitting in the
 * inbox from an earlier step cannot satisfy the wait - which matters for the reset test,
 * where the same address already holds a verification mail.
 */
export async function latestMessageTo(
  email: string,
  options: { timeoutMs?: number; after?: Date } = {},
): Promise<{ html: string; links: string[]; subject: string }> {
  const timeoutMs = options.timeoutMs ?? 30_000;
  const afterMs = options.after?.getTime() ?? 0;
  const deadline = Date.now() + timeoutMs;
  let seen = 0;

  for (;;) {
    const messages = await searchFor(email);
    seen = messages.length;
    const candidate = messages.find((summary) => Date.parse(summary.Created) >= afterMs);
    if (candidate !== undefined) {
      const message = await readMessage(candidate.ID);
      const html = message.HTML.length > 0 ? message.HTML : message.Text;
      return { html, links: extractLinks(html), subject: candidate.Subject };
    }
    if (Date.now() >= deadline) {
      throw new Error(
        `No message to ${email} arrived within ${timeoutMs}ms (${seen} message(s) to that ` +
          `address in Mailpit, none after ${options.after?.toISOString() ?? 'the epoch'}).\n` +
          `Mail reaches Mailpit ONLY when the outbox dispatcher runs - see ` +
          `docs/local-e2e-runbook.md step 3. 'make queue' does NOT dispatch the outbox ` +
          `(nothing in tandem-api implements ShouldQueue); the dispatcher loop does.`,
      );
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
}

/**
 * The one link in the newest message to `email` matching `pattern`. Throws with the message
 * body in the error when there is none, so a failure names what arrived.
 */
export async function linkMatching(
  email: string,
  pattern: RegExp,
  options: { timeoutMs?: number; after?: Date } = {},
): Promise<string> {
  const message = await latestMessageTo(email, options);
  const found = message.links.find((link) => pattern.test(link));
  if (found === undefined) {
    throw new Error(
      `No link matching ${String(pattern)} in the newest message to ${email} ` +
        `(subject: "${message.subject}").\nLinks found: ${JSON.stringify(message.links)}\n` +
        `Body:\n${message.html}`,
    );
  }
  return found;
}

/**
 * Fails LOUDLY, and with the fix, when the API is mailing links to a web app that is not the
 * one Playwright is driving.
 *
 * MEASURED: `tandem-api/config/app.php` reads `web_url` from `APP_WEB_URL` and defaults to
 * `http://localhost:3000`, while `playwright.config.ts` serves this app on :3100 - and
 * `APP_WEB_URL` was absent from `tandem-api/.env` entirely. Without this check, following the
 * emailed link produces `net::ERR_CONNECTION_REFUSED` (or, worse, silently drives a
 * `next dev` someone left on :3000), and neither failure names the one line that fixes it.
 */
export function assertLinkTargetsThisApp(link: string, baseURL: string): void {
  const expected = new URL(baseURL).origin;
  const actual = new URL(link).origin;
  if (actual !== expected) {
    throw new Error(
      `The API mailed a link to ${actual} but this suite drives ${expected}.\n` +
        `Set APP_WEB_URL=${expected} in tandem-api/.env and restart 'make api' ` +
        `(config/app.php's web_url defaults to http://localhost:3000). ` +
        `See docs/local-e2e-runbook.md step 2.`,
    );
  }
}
