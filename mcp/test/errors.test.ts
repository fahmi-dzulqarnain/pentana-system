import { afterEach, describe, expect, it } from 'vitest';
import { lunches } from './fixtures.js';
import { FakeApi, TOKEN, connect, envelope, text, type Harness } from './helpers.js';

let harness: Harness | undefined;
afterEach(async () => {
  await harness?.close();
  harness = undefined;
});

describe('API failures', () => {
  it.each(['get_bill_summary', 'list_bills', 'list_lunches', 'list_activities'])(
    '%s maps 401 to a clear re-login message',
    async (tool) => {
      const api = new FakeApi();
      for (const path of ['bills/summary', 'bills', 'lunches', 'activities']) {
        api.on('GET', `/api/v1/${path}`, { status: 401, body: { message: 'Unauthenticated.' } });
      }
      harness = await connect(api);
      const result = await harness.call(tool);

      expect(result.isError).toBe(true);
      expect(text(result)).toContain('token is invalid or has been revoked');
      expect(text(result)).toContain('pentana-mcp login');
      expect(result.structuredContent).toBeUndefined();
    },
  );

  it('maps 401 on a write the same way', async () => {
    const api = new FakeApi()
      .on('GET', '/api/v1/lunches', envelope(lunches))
      .on('POST', '/api/v1/lunches/101/respond', { status: 401, body: { message: 'Unauthenticated.' } });
    harness = await connect(api, { allowWrites: true });
    const result = await harness.call('vote_lunch', { lunch_id: 101, lunch_option_id: 1002, confirm: true });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('pentana-mcp login');
  });

  it('reports a network failure without leaking the token', async () => {
    const api = new FakeApi().on('GET', '/api/v1/bills', () => {
      throw new TypeError('fetch failed', { cause: new Error(`connect ECONNREFUSED (${TOKEN})`) });
    });
    harness = await connect(api);
    const result = await harness.call('list_bills');

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('Could not reach the PENTANA API at https://pentana.example.com');
    expect(text(result)).toContain('ECONNREFUSED ([REDACTED])');
  });

  it('times out a hanging request', async () => {
    const api = new FakeApi().on('GET', '/api/v1/lunches', (_call, init) => {
      return new Promise((_resolve, reject) => {
        init.signal?.addEventListener('abort', () => {
          reject(init.signal?.reason as Error);
        });
      });
    });
    harness = await connect(api, { timeoutMs: 50 });
    const result = await harness.call('list_lunches');

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('did not answer within 50 ms');
  });

  it('does not follow redirects (the token never goes to another host)', async () => {
    let redirectMode: RequestInit['redirect'];
    const api = new FakeApi().on('GET', '/api/v1/bills', (_call, init) => {
      redirectMode = init.redirect;
      return { status: 302, raw: '', headers: { Location: 'https://elsewhere.example.com/login' } };
    });
    harness = await connect(api);
    const result = await harness.call('list_bills');

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('redirect (HTTP 302)');
    expect(redirectMode).toBe('manual');
    expect(api.calls).toHaveLength(1);
  });

  it('reports a non-JSON answer (wrong base URL)', async () => {
    const api = new FakeApi().on('GET', '/api/v1/bills', { status: 200, raw: '<html>Welcome</html>' });
    harness = await connect(api);
    const result = await harness.call('list_bills');

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('did not return JSON');
  });

  it('reports a response that does not match the API contract', async () => {
    const api = new FakeApi().on('GET', '/api/v1/bills', envelope([{ id: 'x' }]));
    harness = await connect(api);
    const result = await harness.call('list_bills');

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('Unexpected response shape from GET /api/v1/bills');
  });

  it('reports server errors and rate limiting', async () => {
    const api = new FakeApi()
      .on('GET', '/api/v1/bills', { status: 500, body: { message: 'Server Error' } })
      .on('GET', '/api/v1/lunches', { status: 429, body: { message: 'Too Many Attempts.' } });
    harness = await connect(api);

    expect(text(await harness.call('list_bills'))).toBe('The PENTANA API returned HTTP 500 for GET /api/v1/bills.');
    expect(text(await harness.call('list_lunches'))).toContain('Too many requests');
  });
});
