import { describe, expect, it } from 'vitest';
import { ConfigError, DEFAULT_TIMEOUT_MS, isInsecureRemote, loadConfig, parseAllowWrites } from '../src/config.js';
import { createStderrLogger, redact } from '../src/log.js';

describe('loadConfig', () => {
  it('reads and normalises the environment', () => {
    expect(
      loadConfig({ PENTANA_API_URL: 'https://pentana.example.com/', PENTANA_TOKEN: ' 1|abc ' }),
    ).toEqual({ apiUrl: 'https://pentana.example.com', token: '1|abc', allowWrites: false, timeoutMs: DEFAULT_TIMEOUT_MS });
  });

  it('keeps a sub-path base URL', () => {
    expect(loadConfig({ PENTANA_API_URL: 'https://example.com/pentana/', PENTANA_TOKEN: 't' }).apiUrl).toBe(
      'https://example.com/pentana',
    );
  });

  it('fails fast with a clear message when the URL or token is missing', () => {
    expect(() => loadConfig({ PENTANA_TOKEN: 't' })).toThrow(/PENTANA_API_URL is not set/);
    expect(() => loadConfig({ PENTANA_API_URL: 'http://localhost:8000' })).toThrow(/PENTANA_TOKEN is not set.*pentana-mcp login/);
    expect(() => loadConfig({ PENTANA_API_URL: 'http://localhost:8000', PENTANA_TOKEN: '  ' })).toThrow(ConfigError);
  });

  it('rejects bad URLs', () => {
    expect(() => loadConfig({ PENTANA_API_URL: 'localhost:8000', PENTANA_TOKEN: 't' })).toThrow(/http:\/\/ or https:\/\//);
    expect(() => loadConfig({ PENTANA_API_URL: 'not a url', PENTANA_TOKEN: 't' })).toThrow(/not a valid URL/);
    expect(() => loadConfig({ PENTANA_API_URL: 'https://u:p@example.com', PENTANA_TOKEN: 't' })).toThrow(/credentials/);
    expect(() => loadConfig({ PENTANA_API_URL: 'https://example.com/?x=1', PENTANA_TOKEN: 't' })).toThrow(/query/);
  });

  it('validates the timeout', () => {
    const base = { PENTANA_API_URL: 'http://localhost:8000', PENTANA_TOKEN: 't' };
    expect(loadConfig({ ...base, PENTANA_MCP_TIMEOUT_MS: '5000' }).timeoutMs).toBe(5000);
    expect(() => loadConfig({ ...base, PENTANA_MCP_TIMEOUT_MS: 'soon' })).toThrow(/PENTANA_MCP_TIMEOUT_MS/);
  });
});

describe('parseAllowWrites', () => {
  it('is off unless explicitly enabled', () => {
    expect(parseAllowWrites(undefined)).toBe(false);
    expect(parseAllowWrites('')).toBe(false);
    expect(parseAllowWrites('false')).toBe(false);
    expect(parseAllowWrites('ture')).toBe(false);
    expect(parseAllowWrites('true')).toBe(true);
    expect(parseAllowWrites('TRUE')).toBe(true);
    expect(parseAllowWrites('1')).toBe(true);
  });
});

describe('isInsecureRemote', () => {
  it('flags plain http only for remote hosts', () => {
    expect(isInsecureRemote('http://localhost:8000')).toBe(false);
    expect(isInsecureRemote('http://127.0.0.1:8000')).toBe(false);
    expect(isInsecureRemote('http://pentana.localhost')).toBe(false);
    expect(isInsecureRemote('https://pentana.example.com')).toBe(false);
    expect(isInsecureRemote('http://pentana.example.com')).toBe(true);
  });
});

describe('logger', () => {
  it('redacts secrets and writes one line per message', () => {
    const lines: string[] = [];
    const logger = createStderrLogger(['s3cret'], (line) => lines.push(line));
    logger.warn('token s3cret leaked twice: s3cret');

    expect(lines).toEqual(['[pentana-mcp] warn: token [REDACTED] leaked twice: [REDACTED]\n']);
    expect(redact('abc', [''])).toBe('abc');
  });
});
