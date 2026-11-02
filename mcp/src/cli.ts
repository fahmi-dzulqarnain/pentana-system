/**
 * Command line entry: `pentana-mcp [serve|login|logout|help|version]`.
 * Everything takes its I/O as parameters so the commands can be tested without a terminal.
 */
import { createInterface } from 'node:readline/promises';
import { Writable, type Readable } from 'node:stream';
import { fileURLToPath } from 'node:url';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { PentanaApiError, PentanaClient, login, type FetchLike } from './api.js';
import { ConfigError, DEFAULT_TIMEOUT_MS, isInsecureRemote, loadConfig, parseApiUrl } from './config.js';
import { createStderrLogger } from './log.js';
import { SERVER_NAME, createPentanaServer, packageVersion } from './server.js';

type Env = Record<string, string | undefined>;

export interface CliIO {
  env: Env;
  stdin: Readable & { isTTY?: boolean };
  /** Command results (login prints the token here). Never used while serving: stdout is the MCP channel. */
  stdout: Writable;
  /** Prompts, warnings and logs. */
  stderr: Writable;
  fetch?: FetchLike;
}

const HELP = `Usage: pentana-mcp [command]

Commands:
  serve     Run the MCP server over stdio (default)
  login     Sign in with a member's email and password and print a token
  logout    Revoke the token in PENTANA_TOKEN
  help      Show this help
  version   Show the version

Environment:
  PENTANA_API_URL           Base URL of the PENTANA app, e.g. http://localhost:8000
  PENTANA_TOKEN             Member API token (from \`pentana-mcp login\`)
  PENTANA_MCP_ALLOW_WRITES  "true" to enable vote_lunch, register_activity and
                            cancel_registration (default: read-only)
  PENTANA_MCP_TIMEOUT_MS    Request timeout in milliseconds (default: ${DEFAULT_TIMEOUT_MS})
`;

export async function main(argv: readonly string[], io: CliIO): Promise<number> {
  const command = argv[0] ?? 'serve';
  try {
    switch (command) {
      case 'serve':
        return await serve(io);
      case 'login':
        return await runLogin(io);
      case 'logout':
        return await runLogout(io);
      case 'help':
      case '--help':
      case '-h':
        io.stdout.write(HELP);
        return 0;
      case 'version':
      case '--version':
      case '-v':
        io.stdout.write(`${packageVersion()}\n`);
        return 0;
      default:
        io.stderr.write(`Unknown command "${command}".\n\n${HELP}`);
        return 2;
    }
  } catch (error) {
    if (error instanceof ConfigError || error instanceof PentanaApiError) {
      io.stderr.write(`pentana-mcp: ${error.message}\n`);
      return 1;
    }
    throw error;
  }
}

async function serve(io: CliIO): Promise<number> {
  const config = loadConfig(io.env); // fails fast on missing URL or token
  const logger = createStderrLogger([config.token], (line) => io.stderr.write(line));
  if (isInsecureRemote(config.apiUrl)) {
    logger.warn(`PENTANA_API_URL uses plain http to a remote host (${config.apiUrl}); the token is sent unencrypted.`);
  }

  const server = createPentanaServer({ config, fetch: io.fetch, logger });
  await server.connect(new StdioServerTransport());
  logger.info(
    `${SERVER_NAME} ${packageVersion()} serving ${config.apiUrl} over stdio ` +
      `(writes ${config.allowWrites ? 'ENABLED' : 'disabled'})`,
  );

  const shutdown = () => {
    void server.close().finally(() => process.exit(0));
  };
  process.once('SIGINT', shutdown);
  process.once('SIGTERM', shutdown);
  return 0;
}

class PromptEnded extends Error {
  override name = 'PromptEnded';

  constructor(readonly cancelled: boolean) {
    super(cancelled ? 'Cancelled.' : 'Input ended before all answers were given.');
  }
}

/**
 * Line prompts on stderr; echo is switched off for the password. Lines are read through the
 * readline async iterator, which buffers them, so piped input (not a TTY) works too.
 */
function createPrompter(io: CliIO) {
  let muted = false;
  const echo = new Writable({
    write(chunk: Buffer | string, _encoding, callback) {
      if (!muted) io.stderr.write(chunk);
      callback();
    },
  });
  const rl = createInterface({ input: io.stdin, output: echo, terminal: io.stdin.isTTY === true });
  let cancelled = false;
  rl.on('SIGINT', () => {
    cancelled = true;
    rl.close();
  });
  const lines = rl[Symbol.asyncIterator]();

  const next = async (): Promise<string> => {
    const line = await lines.next();
    if (line.done === true) {
      throw new PromptEnded(cancelled);
    }
    return line.value;
  };

  return {
    ask(question: string): Promise<string> {
      rl.setPrompt(question);
      rl.prompt();
      return next();
    },
    async askHidden(question: string): Promise<string> {
      rl.setPrompt(question);
      rl.prompt();
      muted = true;
      try {
        return await next();
      } finally {
        muted = false;
        io.stderr.write('\n');
      }
    },
    close: () => {
      rl.close();
    },
  };
}

function shellQuote(value: string): string {
  return /^[\w@%+=:,./-]+$/.test(value) ? value : `'${value.replace(/'/g, `'\\''`)}'`;
}

export function configSnippets(apiUrl: string, token: string, entryPath: string): string {
  const desktop = {
    mcpServers: {
      pentana: {
        command: 'node',
        args: [entryPath],
        env: { PENTANA_API_URL: apiUrl, PENTANA_TOKEN: token, PENTANA_MCP_ALLOW_WRITES: 'false' },
      },
    },
  };
  const code = [
    'claude mcp add pentana --scope user',
    `-e ${shellQuote(`PENTANA_API_URL=${apiUrl}`)}`,
    `-e ${shellQuote(`PENTANA_TOKEN=${token}`)}`,
    '-e PENTANA_MCP_ALLOW_WRITES=false',
    `-- node ${shellQuote(entryPath)}`,
  ].join(' ');

  return [
    'Claude Desktop - add to claude_desktop_config.json:',
    JSON.stringify(desktop, null, 2),
    '',
    'Claude Code:',
    code,
    '',
    'Set PENTANA_MCP_ALLOW_WRITES to "true" only if the assistant may vote and register for you',
    '(it still has to ask you before every change).',
  ].join('\n');
}

async function runLogin(io: CliIO): Promise<number> {
  const prompter = createPrompter(io);
  let apiUrl: string;
  let email: string;
  let password: string;
  try {
    const defaultUrl = io.env.PENTANA_API_URL?.trim() || 'http://localhost:8000';
    const urlAnswer = (await prompter.ask(`PENTANA URL [${defaultUrl}]: `)).trim();
    apiUrl = parseApiUrl(urlAnswer || defaultUrl);
    email = (await prompter.ask('Email: ')).trim();
    password = await prompter.askHidden('Password: ');
  } catch (error) {
    if (error instanceof PromptEnded) {
      io.stderr.write(`\npentana-mcp: ${error.message}\n`);
      return error.cancelled ? 130 : 1;
    }
    throw error;
  } finally {
    prompter.close();
  }

  if (!email || !password) {
    io.stderr.write('pentana-mcp: email and password are required.\n');
    return 1;
  }
  if (isInsecureRemote(apiUrl)) {
    io.stderr.write(`Warning: ${apiUrl} is plain http to a remote host; your password is sent unencrypted.\n`);
  }

  const result = await login({ apiUrl, timeoutMs: DEFAULT_TIMEOUT_MS, fetch: io.fetch }, email, password);
  const who = result.user.display_name ?? result.user.name;
  const entryPath = fileURLToPath(new URL('./index.js', import.meta.url));

  io.stderr.write(`Signed in as ${who} (${result.user.email}). The token below is shown only once.\n\n`);
  io.stdout.write(
    [
      'Token:',
      result.token,
      '',
      configSnippets(apiUrl, result.token, entryPath),
      '',
      'Nothing was saved to disk. To revoke this token later: PENTANA_API_URL=... PENTANA_TOKEN=... pentana-mcp logout',
      '',
    ].join('\n'),
  );
  return 0;
}

async function runLogout(io: CliIO): Promise<number> {
  const config = loadConfig(io.env);
  const client = new PentanaClient({ apiUrl: config.apiUrl, token: config.token, timeoutMs: config.timeoutMs, fetch: io.fetch });
  try {
    await client.logout();
  } catch (error) {
    if (error instanceof PentanaApiError && error.kind === 'unauthorized') {
      io.stderr.write('pentana-mcp: the token is already invalid or revoked; nothing to do.\n');
      return 1;
    }
    throw error;
  }
  io.stdout.write(`Token revoked. Remove PENTANA_TOKEN from your MCP client config.\n`);
  return 0;
}
