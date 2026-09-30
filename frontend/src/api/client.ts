/* The one place a request reaches the API (build plan 1.7, #45). Same-origin
   /api, per the Vite dev proxy to Sail (vite.config.ts, added in 1.6) and the
   same-origin production setup (build plan 5.2); this never points at another
   origin.

   Framework-free and dependency-injected, like lib/health.ts: fetch is a
   parameter, so every caller is testable with a mock and no network.

   The distinction that matters to every caller: a non-2xx response is the
   server answering "no", and throws ApiError with that answer. Anything else
   fetch itself throws (TypeError on a network failure, DOMException on an
   abort) means the server was never reached at all. Callers tell the two
   apart with `instanceof ApiError`; a caller that conflates them would sign a
   user out for merely being offline. */

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, body: unknown) {
    super(messageFrom(body, status))
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

function messageFrom(body: unknown, status: number): string {
  const message = body && typeof body === 'object' ? (body as { message?: unknown }).message : undefined
  return typeof message === 'string' ? message : `Request failed with status ${status}`
}

export type FetchImpl = typeof fetch

const DEFAULT_TIMEOUT_MS = 10_000

type ApiFetchInit = Omit<RequestInit, 'body'> & {
  body?: unknown
  /* Attached as a bearer token when present; omitted (not merely falsy) so an
     unauthenticated call never sends an empty Authorization header. */
  token?: string | null
  /* A hanging request (a weak signal, a stalling captive portal) is
     otherwise indistinguishable from a slow but working one, and a caller
     showing a cached fallback on failure (useAuthSession's revalidation)
     needs to know it failed. Same idea as lib/health.ts's probe timeout. */
  timeoutMs?: number
}

/* JSON in, JSON out. A request body is any JSON-serialisable value, never a
   pre-encoded string, so a caller cannot forget the Content-Type header that
   goes with it. */
export async function apiFetch<T>(
  path: string,
  init: ApiFetchInit = {},
  fetchImpl: FetchImpl = fetch,
): Promise<T> {
  const { token, body, headers, timeoutMs = DEFAULT_TIMEOUT_MS, signal, ...rest } = init
  const requestHeaders = new Headers(headers)
  requestHeaders.set('Accept', 'application/json')
  if (body !== undefined) requestHeaders.set('Content-Type', 'application/json')
  if (token) requestHeaders.set('Authorization', `Bearer ${token}`)

  const controller = new AbortController()
  const onOuterAbort = () => controller.abort()
  if (signal) {
    if (signal.aborted) controller.abort()
    else signal.addEventListener('abort', onOuterAbort)
  }
  const timer = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const response = await fetchImpl(path, {
      ...rest,
      headers: requestHeaders,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    })

    /* Only an error response's body is allowed to fail to parse (a non-JSON
       error page from something in front of the API): ApiError still carries
       the status either way, with a null body standing in for whatever this
       could not read. A 2xx response's body is never swallowed the same
       way: login, me, and logout all promise a JSON contract, and turning a
       stalled or malformed success body into null would hand every caller
       a value that looks like data but silently isn't (performSignIn
       destructuring token out of a null, or toCurrentUser throwing on a
       property of it, rather than a clear parse failure). */
    if (!response.ok) {
      const parsed: unknown = await response.json().catch(() => null)
      throw new ApiError(response.status, parsed)
    }

    return (await response.json()) as T
  } finally {
    clearTimeout(timer)
    /* {once: true} alone only removes this once the outer signal actually
       fires abort; an outer signal that outlives many calls without ever
       aborting (a long-lived one a caller reuses) would otherwise keep
       every past call's listener attached to it forever. */
    signal?.removeEventListener('abort', onOuterAbort)
  }
}
