import { readFileSync } from 'node:fs';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { PentanaClient, type FetchLike } from './api.js';
import type { Config } from './config.js';
import { silentLogger, type Logger } from './log.js';
import { registerActivityReadTools, registerActivityWriteTools } from './tools/activities.js';
import { registerBillTools } from './tools/bills.js';
import { registerLunchReadTools, registerLunchWriteTools } from './tools/lunches.js';
import type { ToolContext } from './tools/shared.js';

export const SERVER_NAME = 'pentana-mcp';

export function packageVersion(): string {
  try {
    const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version?: unknown };
    return typeof pkg.version === 'string' ? pkg.version : '0.0.0';
  } catch {
    return '0.0.0';
  }
}

const BASE_INSTRUCTIONS =
  'You are helping one PENTANA club member with their own account, through the PENTANA member API. ' +
  'Amounts are in MYR. Dates and deadlines come from the server as ISO 8601 with a timezone offset.';

const WRITE_INSTRUCTIONS =
  ' Write tools (vote_lunch, register_activity, cancel_registration) change real data. Before any of them: ' +
  'look up current data with the matching list tool, tell the member exactly what you are about to do ' +
  '(which lunch and option, which activity and which answers), wait for an explicit yes, and only then call ' +
  'the tool with confirm: true. Never invent answers to registration questions. If a tool returns an error ' +
  'from PENTANA, show the member that message as it is.';

const READ_ONLY_INSTRUCTIONS =
  ' This server is read-only: it cannot vote, register or cancel. Tell the member to do that in the PENTANA app.';

export interface ServerOptions {
  config: Config;
  /** Injected in tests; defaults to the global fetch. */
  fetch?: FetchLike;
  logger?: Logger;
}

/** Build the MCP server. Write tools exist only when config.allowWrites is true. */
export function createPentanaServer({ config, fetch, logger = silentLogger }: ServerOptions): McpServer {
  const server = new McpServer(
    { name: SERVER_NAME, version: packageVersion() },
    { instructions: BASE_INSTRUCTIONS + (config.allowWrites ? WRITE_INSTRUCTIONS : READ_ONLY_INSTRUCTIONS) },
  );
  const ctx: ToolContext = {
    client: new PentanaClient({ apiUrl: config.apiUrl, token: config.token, timeoutMs: config.timeoutMs, fetch }),
    logger,
  };

  registerBillTools(server, ctx);
  registerLunchReadTools(server, ctx);
  registerActivityReadTools(server, ctx);

  if (config.allowWrites) {
    registerLunchWriteTools(server, ctx);
    registerActivityWriteTools(server, ctx);
  }

  return server;
}
