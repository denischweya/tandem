import { apiBaseUrl } from './env';
import { ErrorEnvelopeSchema, type ErrorEnvelope } from './errors';

export type ApiCallResult =
  | { readonly kind: 'ok'; readonly status: number; readonly json: unknown }
  | {
      readonly kind: 'error';
      readonly status: number;
      readonly envelope: ErrorEnvelope | null;
      readonly retryAfterSeconds: number | null;
    }
  | { readonly kind: 'transportFailure'; readonly error: unknown };

/**
 * The single outbound caller - every one of the eight handlers reaches `tandem-api` through
 * this function and nothing else, which is what lets `handlers.test.ts` mock one module
 * (`vi.mock('./apiFetch', …)`) and exercise every handler with no network.
 *
 * Mirrors `tandem-mobile/src/api/transport.ts`'s `perform`, collapsed from that module's
 * "one attempt, caller decides retry/refresh" shape into a single async function: the web BFF
 * has no retry-after-401 path to share (decision 2 - the browser is the only refresh
 * initiator, so a handler never refreshes on its own 401).
 *
 * NOTHING HERE IS LOGGED. The bearer and the refresh token both pass through this function as
 * plain strings and neither is written to `console`, a thrown error's message, or a header
 * name in a log line - blueprint §91/§154.
 */
export async function callApi(
  path: string,
  init: { method: 'GET' | 'POST' | 'PATCH' | 'DELETE'; body?: unknown; bearer?: string | null },
): Promise<ApiCallResult> {
  const url = `${apiBaseUrl()}${path}`;

  const headers: Record<string, string> = { accept: 'application/json' };
  if (init.body !== undefined) headers['content-type'] = 'application/json';
  if (init.bearer !== null && init.bearer !== undefined) {
    // The access token is an opaque Sanctum string of the form `<token-uuid>|<48 chars>`.
    // Send it verbatim; never split on the pipe.
    headers.authorization = `Bearer ${init.bearer}`;
  }

  let response: Response;
  try {
    response = await fetch(url, {
      method: init.method,
      headers,
      ...(init.body !== undefined ? { body: JSON.stringify(init.body) } : {}),
    });
  } catch (error) {
    return { kind: 'transportFailure', error };
  }

  let json: unknown = null;
  if (response.status !== 204) {
    const text = await response.text();
    if (text.length > 0) {
      try {
        json = JSON.parse(text);
      } catch {
        // An HTML error page from a proxy, or a non-JSON body. Deliberately swallowed: a raw
        // body must never reach the user (§135), and `envelope: null` is what makes
        // `userMessageFor` substitute house copy rather than render this text.
        json = null;
      }
    }
  }

  if (response.ok) {
    return { kind: 'ok', status: response.status, json };
  }

  const parsedEnvelope = ErrorEnvelopeSchema.safeParse(json);
  const retryAfter = response.headers.get('retry-after');
  const retryAfterSeconds = retryAfter === null ? null : Number.parseInt(retryAfter, 10);

  return {
    kind: 'error',
    status: response.status,
    envelope: parsedEnvelope.success ? parsedEnvelope.data : null,
    retryAfterSeconds:
      retryAfterSeconds !== null && Number.isNaN(retryAfterSeconds) ? null : retryAfterSeconds,
  };
}
