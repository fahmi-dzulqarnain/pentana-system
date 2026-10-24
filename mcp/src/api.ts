/**
 * Typed client for the PENTANA member API (/api/v1). This is the only place that talks HTTP.
 *
 * - Every request carries `Accept: application/json` so Laravel answers errors in JSON
 *   (otherwise an expired token would be redirected to the web login page).
 * - Redirects are never followed, so the bearer token cannot be forwarded to another host.
 * - Every request has a timeout (AbortSignal.timeout).
 * - Failures become a PentanaApiError with a message that is safe to show to the assistant:
 *   it never contains the token.
 */
import { z } from 'zod';
import { redact } from './log.js';
import {
  activitySchema,
  billSchema,
  billSummarySchema,
  envelope,
  loginResponseSchema,
  lunchSchema,
  type Activity,
  type Bill,
  type BillSummary,
  type LoginResponse,
  type Lunch,
} from './schemas.js';

export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

export type ApiErrorKind =
  | 'unauthorized' // 401 on an authenticated call: token invalid or revoked
  | 'bad_credentials' // 401 on login
  | 'forbidden' // 403
  | 'not_found' // 404
  | 'validation' // 422: the API's own business-rule or validation message
  | 'rate_limited' // 429
  | 'http' // any other non-2xx status
  | 'timeout'
  | 'network'
  | 'invalid_response'; // 2xx but not the JSON shape we expect

export class PentanaApiError extends Error {
  override name = 'PentanaApiError';

  constructor(
    readonly kind: ApiErrorKind,
    message: string,
    readonly status?: number,
    /** Laravel validation errors ({ field: [messages] }) when the API sent them. */
    readonly fieldErrors?: Record<string, string[]>,
  ) {
    super(message);
  }
}

export const UNAUTHORIZED_MESSAGE =
  'The PENTANA token is invalid or has been revoked. Run `pentana-mcp login` to get a new token, ' +
  'update PENTANA_TOKEN in the MCP client config, and restart the client.';

export const LOGIN_DEVICE_NAME = 'PENTANA MCP';

interface RequestOptions {
  method: 'GET' | 'POST';
  path: string;
  body?: unknown;
  /** Bearer token; omitted only for login. */
  token?: string;
}

interface Transport {
  apiUrl: string;
  timeoutMs: number;
  fetch: FetchLike;
}

const errorBodySchema = z.object({
  message: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
});

async function request<T extends z.ZodType>(
  transport: Transport,
  options: RequestOptions,
  schema: T,
): Promise<z.infer<T>> {
  try {
    return await send(transport, options, schema);
  } catch (error) {
    // Defence in depth: error text can include foreign strings (socket errors, server messages).
    if (error instanceof PentanaApiError && options.token !== undefined) {
      error.message = redact(error.message, [options.token]);
    }
    throw error;
  }
}

async function send<T extends z.ZodType>(
  transport: Transport,
  options: RequestOptions,
  schema: T,
): Promise<z.infer<T>> {
  const url = `${transport.apiUrl}/api/v1/${options.path}`;
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'User-Agent': 'pentana-mcp',
  };
  if (options.token !== undefined) headers.Authorization = `Bearer ${options.token}`;
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';

  let response: Response;
  try {
    response = await transport.fetch(url, {
      method: options.method,
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      redirect: 'manual',
      signal: AbortSignal.timeout(transport.timeoutMs),
    });
  } catch (error) {
    throw toTransportError(error, transport);
  }

  const text = await readBody(response, transport);
  const json = parseJson(text);

  if (response.status >= 200 && response.status < 300) {
    if (json === undefined) {
      throw new PentanaApiError(
        'invalid_response',
        `The PENTANA API at ${transport.apiUrl} did not return JSON for ${options.method} /api/v1/${options.path}. ` +
          'Check that PENTANA_API_URL points at the PENTANA app.',
        response.status,
      );
    }
    const parsed = schema.safeParse(json);
    if (!parsed.success) {
      throw new PentanaApiError(
        'invalid_response',
        `Unexpected response shape from ${options.method} /api/v1/${options.path}: ` +
          z.prettifyError(parsed.error).replace(/\s+/g, ' '),
        response.status,
      );
    }
    return parsed.data;
  }

  throw toHttpError(response.status, json, options, transport);
}

async function readBody(response: Response, transport: Transport): Promise<string> {
  try {
    return await response.text();
  } catch (error) {
    throw toTransportError(error, transport);
  }
}

function parseJson(text: string): unknown {
  if (text.trim() === '') return undefined;
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return undefined;
  }
}

function toTransportError(error: unknown, transport: Transport): PentanaApiError {
  const name = error instanceof Error ? error.name : '';
  if (name === 'TimeoutError' || name === 'AbortError') {
    return new PentanaApiError(
      'timeout',
      `The PENTANA API at ${transport.apiUrl} did not answer within ${transport.timeoutMs} ms. Try again later.`,
    );
  }
  const cause =
    error instanceof Error && error.cause instanceof Error ? error.cause.message : error instanceof Error ? error.message : '';
  return new PentanaApiError(
    'network',
    `Could not reach the PENTANA API at ${transport.apiUrl}${cause ? ` (${cause})` : ''}. ` +
      'Check PENTANA_API_URL and that the server is running.',
  );
}

function toHttpError(status: number, json: unknown, options: RequestOptions, transport: Transport): PentanaApiError {
  const body = errorBodySchema.safeParse(json);
  const apiMessage = body.success ? body.data.message : undefined;
  const fieldErrors = body.success ? body.data.errors : undefined;

  if (status >= 300 && status < 400) {
    return new PentanaApiError(
      'http',
      `The PENTANA API at ${transport.apiUrl} answered with a redirect (HTTP ${status}); redirects are not followed. ` +
        'Check PENTANA_API_URL (for example http vs https, or a missing path).',
      status,
    );
  }
  switch (status) {
    case 401:
      return options.token === undefined
        ? new PentanaApiError('bad_credentials', apiMessage ?? 'The provided credentials are incorrect.', status)
        : new PentanaApiError('unauthorized', UNAUTHORIZED_MESSAGE, status);
    case 403:
      return new PentanaApiError('forbidden', apiMessage ?? 'This member is not allowed to do that.', status);
    case 404:
      // Laravel's model-binding message names internal classes; say it plainly instead.
      return new PentanaApiError('not_found', `Not found: /api/v1/${options.path}`, status);
    case 422:
      return new PentanaApiError(
        'validation',
        apiMessage ?? 'The PENTANA API rejected the request (HTTP 422).',
        status,
        fieldErrors,
      );
    case 429:
      return new PentanaApiError('rate_limited', 'Too many requests to the PENTANA API. Wait a minute and try again.', status);
    default:
      return new PentanaApiError('http', `The PENTANA API returned HTTP ${status} for ${options.method} /api/v1/${options.path}.`, status);
  }
}

export interface ClientOptions {
  apiUrl: string;
  token: string;
  timeoutMs: number;
  fetch?: FetchLike;
}

/** Answers to an activity's registration questions, keyed by question key. */
export type RegistrationAnswers = Record<string, string | boolean>;

/** The member API, acting as the member who owns the token. */
export class PentanaClient {
  private readonly transport: Transport;
  private readonly token: string;

  constructor(options: ClientOptions) {
    this.transport = {
      apiUrl: options.apiUrl,
      timeoutMs: options.timeoutMs,
      fetch: options.fetch ?? ((input, init) => fetch(input, init)),
    };
    this.token = options.token;
  }

  private get<T extends z.ZodType>(path: string, schema: T) {
    return request(this.transport, { method: 'GET', path, token: this.token }, schema);
  }

  private post<T extends z.ZodType>(path: string, body: unknown, schema: T) {
    return request(this.transport, { method: 'POST', path, body, token: this.token }, schema);
  }

  async getBillSummary(): Promise<BillSummary> {
    return (await this.get('bills/summary', envelope(billSummarySchema))).data;
  }

  async listBills(): Promise<Bill[]> {
    return (await this.get('bills', envelope(z.array(billSchema)))).data;
  }

  /** Published lunches from today to today + 14 days, with the member's own vote. */
  async listLunches(): Promise<Lunch[]> {
    return (await this.get('lunches', envelope(z.array(lunchSchema)))).data;
  }

  /** Vote for a lunch option, or pass null for "not attending". Returns the updated lunch. */
  async respondToLunch(lunchId: number, lunchOptionId: number | null): Promise<Lunch> {
    const path = `lunches/${encodeURIComponent(String(lunchId))}/respond`;
    return (await this.post(path, { lunch_option_id: lunchOptionId }, envelope(lunchSchema))).data;
  }

  /** Published activities that have not started yet. */
  async listActivities(): Promise<Activity[]> {
    return (await this.get('activities', envelope(z.array(activitySchema)))).data;
  }

  async registerForActivity(activityId: number, answers: RegistrationAnswers): Promise<Activity> {
    const path = `activities/${encodeURIComponent(String(activityId))}/register`;
    return (await this.post(path, { answers }, envelope(activitySchema))).data;
  }

  async cancelRegistration(activityId: number): Promise<Activity> {
    const path = `activities/${encodeURIComponent(String(activityId))}/cancel`;
    return (await this.post(path, {}, envelope(activitySchema))).data;
  }

  /** Revoke the token this client uses. */
  async logout(): Promise<void> {
    await this.post('logout', {}, z.object({ message: z.string().optional() }));
  }
}

/** POST /api/v1/login. Returns the new token and the member. */
export async function login(
  options: { apiUrl: string; timeoutMs: number; fetch?: FetchLike },
  email: string,
  password: string,
): Promise<LoginResponse> {
  const transport: Transport = {
    apiUrl: options.apiUrl,
    timeoutMs: options.timeoutMs,
    fetch: options.fetch ?? ((input, init) => fetch(input, init)),
  };
  return request(
    transport,
    { method: 'POST', path: 'login', body: { email, password, device_name: LOGIN_DEVICE_NAME } },
    loginResponseSchema,
  );
}
