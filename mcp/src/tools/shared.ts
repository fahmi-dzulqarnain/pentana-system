import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import { PentanaApiError, type PentanaClient } from '../api.js';
import type { Logger } from '../log.js';

/** What every tool module needs. */
export interface ToolContext {
  client: PentanaClient;
  logger: Logger;
}

export const CURRENCY = 'MYR';

export const CONFIRM_REQUIRED_MESSAGE =
  'confirm must be true. Do not set it on your own: first tell the member exactly what will happen, ' +
  'ask them to confirm, and only after they say yes call this tool again with confirm: true.';

/**
 * Every write tool takes `confirm: true`. Anything else (missing, false, "yes") fails input
 * validation before the handler runs, so no request reaches the API.
 */
export const confirmField = z
  .literal(true, { error: CONFIRM_REQUIRED_MESSAGE })
  .describe('Must be true. Set it only after the member has explicitly confirmed this exact action in the conversation.');

export const READ_ONLY = { readOnlyHint: true, openWorldHint: false } as const;

export function textResult(text: string, structured: Record<string, unknown>): CallToolResult {
  return { content: [{ type: 'text', text }], structuredContent: structured };
}

export function errorResult(text: string): CallToolResult {
  return { content: [{ type: 'text', text }], isError: true };
}

/** Turn any failure into a tool error the assistant can act on. Never includes the token. */
export function failure(error: unknown, logger: Logger, tool: string): CallToolResult {
  if (error instanceof PentanaApiError) {
    logger.warn(`${tool}: ${error.kind}${error.status ? ` (HTTP ${error.status})` : ''}`);
    if (error.kind === 'validation') {
      return errorResult(`PENTANA refused the request: ${error.message}`);
    }
    return errorResult(error.message);
  }
  logger.error(`${tool}: unexpected error: ${error instanceof Error ? (error.stack ?? error.message) : String(error)}`);
  return errorResult('Unexpected error inside pentana-mcp. Details were written to the server log.');
}

/** Rich-text HTML from the admin editor -> one line of plain text. */
export function toPlainText(html: string | null): string | null {
  if (html === null) return null;
  const text = html
    .replace(/<(br|\/p|\/div|\/li|\/h\d)\s*\/?>/gi, ' ')
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
  return text === '' ? null : text;
}

export function truncate(text: string, max: number): string {
  return text.length <= max ? text : `${text.slice(0, max - 1).trimEnd()}…`;
}

export function plural(count: number, one: string, many = `${one}s`): string {
  return `${count} ${count === 1 ? one : many}`;
}
