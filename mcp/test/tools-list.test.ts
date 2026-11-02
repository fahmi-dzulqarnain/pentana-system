import { afterEach, describe, expect, it } from 'vitest';
import { FakeApi, connect, text, type Harness } from './helpers.js';

const READ_TOOLS = ['get_bill_summary', 'list_activities', 'list_bills', 'list_lunches'];
const WRITE_TOOLS = ['cancel_registration', 'register_activity', 'vote_lunch'];

let harness: Harness | undefined;
afterEach(async () => {
  await harness?.close();
  harness = undefined;
});

describe('tool registration', () => {
  it('exposes only read tools when writes are disabled', async () => {
    harness = await connect(new FakeApi(), { allowWrites: false });
    const { tools } = await harness.client.listTools();

    expect(tools.map((t) => t.name).sort()).toEqual(READ_TOOLS);
    for (const tool of tools) {
      expect(tool.annotations?.readOnlyHint).toBe(true);
      expect(tool.outputSchema).toBeDefined();
    }
    expect(harness.client.getInstructions()).toContain('read-only');
  });

  it('a write tool cannot be called when writes are disabled', async () => {
    const api = new FakeApi();
    harness = await connect(api, { allowWrites: false });
    const result = await harness.call('vote_lunch', { lunch_id: 101, lunch_option_id: 1001, confirm: true });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('Tool vote_lunch not found');
    expect(api.calls).toHaveLength(0);
  });

  it('adds the write tools, with confirm required and write annotations, when writes are enabled', async () => {
    harness = await connect(new FakeApi(), { allowWrites: true });
    const { tools } = await harness.client.listTools();
    const byName = new Map(tools.map((t) => [t.name, t]));

    expect([...byName.keys()].sort()).toEqual([...READ_TOOLS, ...WRITE_TOOLS].sort());

    for (const name of WRITE_TOOLS) {
      const tool = byName.get(name);
      expect(tool?.annotations?.readOnlyHint).toBe(false);
      expect(tool?.annotations?.idempotentHint).toBe(true);
      expect(tool?.inputSchema.required).toContain('confirm');
      expect(tool?.inputSchema.properties?.confirm).toMatchObject({ const: true });
      expect(tool?.outputSchema).toBeDefined();
    }
    expect(byName.get('vote_lunch')?.annotations?.destructiveHint).toBe(true);
    expect(byName.get('cancel_registration')?.annotations?.destructiveHint).toBe(true);
    expect(byName.get('register_activity')?.annotations?.destructiveHint).toBe(false);
    expect(harness.client.getInstructions()).toContain('explicit yes');
  });
});
