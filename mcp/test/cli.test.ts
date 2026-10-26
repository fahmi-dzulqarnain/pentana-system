import { PassThrough, Writable } from 'node:stream';
import { describe, expect, it } from 'vitest';
import { main, type CliIO } from '../src/cli.js';
import { FakeApi, TOKEN } from './helpers.js';

function sink() {
  let data = '';
  const stream = new Writable({
    write(chunk: Buffer, _enc, cb) {
      data += chunk.toString();
      cb();
    },
  });
  return { stream, text: () => data };
}

function io(env: Record<string, string>, input: string, api?: FakeApi) {
  const stdin = new PassThrough();
  stdin.end(input);
  const stdout = sink();
  const stderr = sink();
  const cliIO: CliIO = { env, stdin, stdout: stdout.stream, stderr: stderr.stream, fetch: api?.fetch };
  return { cliIO, stdout, stderr };
}

const user = { id: 1, name: 'Member One', display_name: 'Member One', email: 'member@example.com' };

describe('pentana-mcp login', () => {
  it('posts the credentials with the MCP device name and prints the token and config snippets', async () => {
    const api = new FakeApi().on('POST', '/api/v1/login', { status: 200, body: { token: TOKEN, user } });
    const { cliIO, stdout, stderr } = io({}, 'https://pentana.example.com/\nmember@example.com\nsecret-password\n', api);

    expect(await main(['login'], cliIO)).toBe(0);

    expect(api.calls).toHaveLength(1);
    expect(api.calls[0]?.body).toEqual({
      email: 'member@example.com',
      password: 'secret-password',
      device_name: 'PENTANA MCP',
    });
    expect(api.calls[0]?.headers.authorization).toBeUndefined();

    const out = stdout.text();
    expect(out).toContain(`Token:\n${TOKEN}\n`);
    expect(out).toContain('"PENTANA_API_URL": "https://pentana.example.com"');
    expect(out).toContain(`"PENTANA_TOKEN": "${TOKEN}"`);
    expect(out).toContain(`claude mcp add pentana --scope user -e PENTANA_API_URL=https://pentana.example.com -e 'PENTANA_TOKEN=${TOKEN}'`);
    expect(stderr.text()).toContain('Signed in as Member One (member@example.com)');
    expect(stderr.text()).not.toContain('secret-password');
  });

  it('uses PENTANA_API_URL as the default URL', async () => {
    const api = new FakeApi().on('POST', '/api/v1/login', { status: 200, body: { token: TOKEN, user } });
    const { cliIO, stderr } = io({ PENTANA_API_URL: 'http://localhost:8000' }, '\nmember@example.com\npw\n', api);

    expect(await main(['login'], cliIO)).toBe(0);
    expect(stderr.text()).toContain('PENTANA URL [http://localhost:8000]: ');
  });

  it('relays wrong credentials', async () => {
    const api = new FakeApi().on('POST', '/api/v1/login', {
      status: 401,
      body: { message: 'The provided credentials are incorrect.' },
    });
    const { cliIO, stdout, stderr } = io({}, 'https://pentana.example.com\nmember@example.com\nwrong\n', api);

    expect(await main(['login'], cliIO)).toBe(1);
    expect(stderr.text()).toContain('pentana-mcp: The provided credentials are incorrect.');
    expect(stdout.text()).toBe('');
  });

  it('stops when input ends early', async () => {
    const api = new FakeApi();
    const { cliIO, stderr } = io({}, 'https://pentana.example.com\n', api);

    expect(await main(['login'], cliIO)).toBe(1);
    expect(stderr.text()).toContain('Input ended before all answers were given.');
    expect(api.calls).toHaveLength(0);
  });
});

describe('pentana-mcp logout', () => {
  const env = { PENTANA_API_URL: 'https://pentana.example.com', PENTANA_TOKEN: TOKEN };

  it('revokes the token', async () => {
    const api = new FakeApi().on('POST', '/api/v1/logout', { status: 200, body: { message: 'Logged out.' } });
    const { cliIO, stdout } = io(env, '', api);

    expect(await main(['logout'], cliIO)).toBe(0);
    expect(api.calls[0]?.headers.authorization).toBe(`Bearer ${TOKEN}`);
    expect(stdout.text()).toContain('Token revoked.');
  });

  it('says when the token is already invalid', async () => {
    const api = new FakeApi().on('POST', '/api/v1/logout', { status: 401, body: { message: 'Unauthenticated.' } });
    const { cliIO, stderr } = io(env, '', api);

    expect(await main(['logout'], cliIO)).toBe(1);
    expect(stderr.text()).toContain('already invalid or revoked');
  });
});

describe('serve and misc', () => {
  it('fails fast when the token is missing', async () => {
    const { cliIO, stdout, stderr } = io({ PENTANA_API_URL: 'http://localhost:8000' }, '');

    expect(await main([], cliIO)).toBe(1);
    expect(stderr.text()).toContain('PENTANA_TOKEN is not set');
    expect(stdout.text()).toBe('');
  });

  it('fails fast when the URL is missing', async () => {
    const { cliIO, stderr } = io({ PENTANA_TOKEN: TOKEN }, '');

    expect(await main(['serve'], cliIO)).toBe(1);
    expect(stderr.text()).toContain('PENTANA_API_URL is not set');
    expect(stderr.text()).not.toContain(TOKEN);
  });

  it('prints help and rejects unknown commands', async () => {
    const help = io({}, '');
    expect(await main(['--help'], help.cliIO)).toBe(0);
    expect(help.stdout.text()).toContain('Usage: pentana-mcp');

    const unknown = io({}, '');
    expect(await main(['frobnicate'], unknown.cliIO)).toBe(2);
    expect(unknown.stderr.text()).toContain('Unknown command "frobnicate"');
  });
});
