/**
 * Runtime configuration, read once from the environment.
 *
 * PENTANA_API_URL           base URL of the PENTANA app, e.g. http://localhost:8000
 * PENTANA_TOKEN             a member's Sanctum token (from `pentana-mcp login`)
 * PENTANA_MCP_ALLOW_WRITES  "true" to expose the write tools (default: off)
 * PENTANA_MCP_TIMEOUT_MS    per-request timeout in milliseconds (default: 15000)
 */

export interface Config {
  /** Base URL without a trailing slash. */
  apiUrl: string;
  token: string;
  allowWrites: boolean;
  timeoutMs: number;
}

export const DEFAULT_TIMEOUT_MS = 15_000;

export class ConfigError extends Error {
  override name = 'ConfigError';
}

type Env = Record<string, string | undefined>;

/** Validate and normalise a base URL ("http://host:8000/" -> "http://host:8000"). */
export function parseApiUrl(raw: string | undefined): string {
  const value = raw?.trim();
  if (!value) {
    throw new ConfigError(
      'PENTANA_API_URL is not set. Set it to the base URL of the PENTANA app, e.g. http://localhost:8000',
    );
  }
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    throw new ConfigError(`PENTANA_API_URL is not a valid URL: "${value}"`);
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new ConfigError(`PENTANA_API_URL must start with http:// or https:// (got "${value}")`);
  }
  if (url.username || url.password) {
    throw new ConfigError('PENTANA_API_URL must not contain credentials.');
  }
  if (url.search || url.hash) {
    throw new ConfigError('PENTANA_API_URL must not contain a query string or fragment.');
  }
  return url.toString().replace(/\/+$/, '');
}

/** Only an explicit opt-in enables writes; anything else (unset, "false", typos) keeps them off. */
export function parseAllowWrites(raw: string | undefined): boolean {
  return ['true', '1', 'yes'].includes((raw ?? '').trim().toLowerCase());
}

export function parseTimeout(raw: string | undefined): number {
  if (raw === undefined || raw.trim() === '') return DEFAULT_TIMEOUT_MS;
  const ms = Number(raw);
  if (!Number.isInteger(ms) || ms < 1000 || ms > 120_000) {
    throw new ConfigError('PENTANA_MCP_TIMEOUT_MS must be an integer between 1000 and 120000.');
  }
  return ms;
}

/** Full config for serving and logout: URL and token are both required. */
export function loadConfig(env: Env = process.env): Config {
  const apiUrl = parseApiUrl(env.PENTANA_API_URL);
  const token = env.PENTANA_TOKEN?.trim();
  if (!token) {
    throw new ConfigError(
      'PENTANA_TOKEN is not set. Run `pentana-mcp login` to get a member token, then set PENTANA_TOKEN.',
    );
  }
  return {
    apiUrl,
    token,
    allowWrites: parseAllowWrites(env.PENTANA_MCP_ALLOW_WRITES),
    timeoutMs: parseTimeout(env.PENTANA_MCP_TIMEOUT_MS),
  };
}

/** True when the URL sends traffic in clear text to somewhere other than this machine. */
export function isInsecureRemote(apiUrl: string): boolean {
  const url = new URL(apiUrl);
  if (url.protocol === 'https:') return false;
  return !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname) && !url.hostname.endsWith('.localhost');
}
