/**
 * Minimal stderr logger. stdout carries the MCP JSON-RPC stream, so nothing may ever be written there.
 * Every line passes through `redact`, which removes the configured secrets (the member token)
 * even if one somehow ends up inside an error message.
 */

export interface Logger {
  info(message: string): void;
  warn(message: string): void;
  error(message: string): void;
}

export function redact(text: string, secrets: readonly string[]): string {
  let out = text;
  for (const secret of secrets) {
    if (secret.length > 0) out = out.split(secret).join('[REDACTED]');
  }
  return out;
}

export function createStderrLogger(
  secrets: readonly string[] = [],
  write: (line: string) => void = (line) => process.stderr.write(line),
): Logger {
  const emit = (level: string, message: string) => {
    write(`[pentana-mcp] ${level}: ${redact(message, secrets)}\n`);
  };
  return {
    info: (message) => {
      emit('info', message);
    },
    warn: (message) => {
      emit('warn', message);
    },
    error: (message) => {
      emit('error', message);
    },
  };
}

export const silentLogger: Logger = {
  info: () => undefined,
  warn: () => undefined,
  error: () => undefined,
};
