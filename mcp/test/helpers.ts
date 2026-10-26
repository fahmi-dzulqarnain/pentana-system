import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { expect } from 'vitest';
import type { FetchLike } from '../src/api.js';
import type { Config } from '../src/config.js';
import { createPentanaServer } from '../src/server.js';

export const TOKEN = '42|tEsTtOkEnDoNotLeAk0123456789abcdef';
export const API_URL = 'https://pentana.example.com';

export interface RecordedCall {
  method: string;
  path: string;
  headers: Record<string, string>;
  body: unknown;
}

type Reply = { status: number; body?: unknown; raw?: string; headers?: Record<string, string> };
type Handler = (call: RecordedCall, init: RequestInit) => Reply | Promise<Reply>;

/** A fake PENTANA API: register replies per "METHOD /path", then inspect `calls`. */
export class FakeApi {
  readonly calls: RecordedCall[] = [];
  private readonly routes = new Map<string, Handler>();

  on(method: string, path: string, reply: Reply | Handler): this {
    this.routes.set(`${method} ${path}`, typeof reply === 'function' ? reply : () => reply);
    return this;
  }

  posts(): RecordedCall[] {
    return this.calls.filter((c) => c.method === 'POST');
  }

  readonly fetch: FetchLike = async (input, init) => {
    const url = new URL(input);
    const headers = Object.fromEntries(new Headers(init.headers).entries());
    const call: RecordedCall = {
      method: init.method ?? 'GET',
      path: url.pathname,
      headers,
      body: typeof init.body === 'string' ? (JSON.parse(init.body) as unknown) : undefined,
    };
    this.calls.push(call);
    const handler = this.routes.get(`${call.method} ${call.path}`);
    const reply = handler ? await handler(call, init) : { status: 404, body: { message: 'Not Found' } };
    const text = reply.raw ?? (reply.body === undefined ? '' : JSON.stringify(reply.body));
    return new Response(reply.status === 204 ? null : text, {
      status: reply.status,
      headers: { 'Content-Type': 'application/json', ...reply.headers },
    });
  };
}

export function testConfig(overrides: Partial<Config> = {}): Config {
  return { apiUrl: API_URL, token: TOKEN, allowWrites: false, timeoutMs: 2000, ...overrides };
}

export interface Harness {
  client: Client;
  call(name: string, args?: Record<string, unknown>): Promise<CallToolResult>;
  close(): Promise<void>;
}

/** Real MCP client <-> real server over the SDK's in-memory transport. */
export async function connect(api: FakeApi, overrides: Partial<Config> = {}): Promise<Harness> {
  const server = createPentanaServer({ config: testConfig(overrides), fetch: api.fetch });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  await server.connect(serverTransport);
  const client = new Client({ name: 'pentana-mcp-test', version: '0.0.0' });
  await client.connect(clientTransport);

  return {
    client,
    async call(name, args = {}) {
      const result = (await client.callTool({ name, arguments: args })) as CallToolResult;
      // Every single tool output in the suite is checked for the token.
      expect(JSON.stringify(result)).not.toContain(TOKEN);
      return result;
    },
    async close() {
      await client.close();
      await server.close();
    },
  };
}

export function text(result: CallToolResult): string {
  return result.content
    .map((part) => (part.type === 'text' ? part.text : ''))
    .join('\n');
}

export const envelope = (data: unknown) => ({ status: 200, body: { data } });
