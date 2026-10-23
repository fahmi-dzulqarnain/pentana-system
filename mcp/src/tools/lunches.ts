import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import type { Lunch } from '../schemas.js';
import { READ_ONLY, confirmField, errorResult, failure, plural, textResult, type ToolContext } from './shared.js';

const voteOutput = z.object({
  status: z.enum(['none', 'attending', 'not_attending']).describe('none = the member has not answered yet'),
  lunch_option_id: z.number().int().nullable(),
  option_name: z.string().nullable(),
  menu_id: z.number().int().nullable(),
});
type Vote = z.infer<typeof voteOutput>;

const lunchOutput = z.object({
  lunch_id: z.number().int(),
  date: z.string().describe('YYYY-MM-DD'),
  deadline: z.string().nullable().describe('ISO 8601; voting closes at this instant'),
  is_open: z.boolean().describe('Whether the member can still vote or change their vote'),
  menus: z.array(
    z.object({
      menu_id: z.number().int(),
      caterer: z.string().nullable(),
      menu: z.string().nullable(),
      options: z.array(z.object({ lunch_option_id: z.number().int(), name: z.string().nullable() })),
    }),
  ),
  my_vote: voteOutput,
});
type LunchOutput = z.infer<typeof lunchOutput>;

const listOutput = { count: z.number().int(), lunches: z.array(lunchOutput) };
const voteResultOutput = { lunch: lunchOutput, previous_vote: voteOutput, changed: z.boolean() };

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function weekday(date: string): string {
  const d = new Date(`${date}T00:00:00Z`);
  return Number.isNaN(d.getTime()) ? '' : ` (${WEEKDAYS[d.getUTCDay()] ?? ''})`;
}

function allOptions(lunch: Lunch) {
  return lunch.menus.flatMap((menu) => menu.options.map((option) => ({ ...option, menu_id: menu.id })));
}

/** The member's current answer, resolved to an option name where possible. */
export function myVote(lunch: Lunch): Vote {
  if (!lunch.responded) return { status: 'none', lunch_option_id: null, option_name: null, menu_id: null };

  const options = allOptions(lunch);
  if (lunch.my_lunch_option_id !== null) {
    const match = options.find((o) => o.lunch_option_id === lunch.my_lunch_option_id);
    return {
      status: 'attending',
      lunch_option_id: lunch.my_lunch_option_id,
      option_name: match?.name ?? null,
      menu_id: match?.menu_id ?? null,
    };
  }
  if (lunch.my_meal_option_id !== null) {
    // A pick saved before multi-menu days only has the meal option.
    const match = options.find((o) => o.meal_option_id === lunch.my_meal_option_id);
    return {
      status: 'attending',
      lunch_option_id: match?.lunch_option_id ?? null,
      option_name: match?.name ?? null,
      menu_id: match?.menu_id ?? null,
    };
  }
  return { status: 'not_attending', lunch_option_id: null, option_name: null, menu_id: null };
}

export function toLunchOutput(lunch: Lunch): LunchOutput {
  return {
    lunch_id: lunch.id,
    date: lunch.date,
    deadline: lunch.deadline,
    is_open: lunch.is_open,
    menus: lunch.menus.map((menu) => ({
      menu_id: menu.id,
      caterer: menu.caterer,
      menu: menu.menu,
      options: menu.options.map((o) => ({ lunch_option_id: o.lunch_option_id, name: o.name })),
    })),
    my_vote: myVote(lunch),
  };
}

function describeVote(vote: Vote): string {
  switch (vote.status) {
    case 'none':
      return 'not answered yet';
    case 'not_attending':
      return 'not attending';
    case 'attending': {
      const name = vote.option_name ?? 'an option no longer offered';
      return vote.lunch_option_id === null ? name : `${name} (option ${vote.lunch_option_id})`;
    }
  }
}

function formatLunch(lunch: LunchOutput): string {
  const state = lunch.is_open
    ? `OPEN for voting until ${lunch.deadline ?? 'unknown'}`
    : `CLOSED${lunch.deadline ? ` (deadline was ${lunch.deadline})` : ''}`;
  const lines = [`- Lunch ${lunch.lunch_id} on ${lunch.date}${weekday(lunch.date)}: ${state}`];
  if (lunch.menus.length === 0) lines.push('  No menu yet.');
  for (const menu of lunch.menus) {
    const title = [menu.caterer, menu.menu].filter(Boolean).join(': ') || 'Menu';
    lines.push(`  Menu ${menu.menu_id} - ${title}`);
    if (menu.options.length === 0) lines.push('    (no options)');
    for (const option of menu.options) {
      lines.push(`    * option ${option.lunch_option_id}: ${option.name ?? '(unnamed)'}`);
    }
  }
  lines.push(`  Your vote: ${describeVote(lunch.my_vote)}`);
  return lines.join('\n');
}

export function registerLunchReadTools(server: McpServer, { client, logger }: ToolContext): void {
  server.registerTool(
    'list_lunches',
    {
      title: 'List lunches',
      description:
        'Published lunches from today to 14 days ahead. For each: date, voting deadline, whether voting is open, ' +
        'the menus with their options (each option has the lunch_option_id used for voting), and the ' +
        "member's current vote. A member picks one option per lunch, or says they are not attending.",
      outputSchema: listOutput,
      annotations: READ_ONLY,
    },
    async () => {
      try {
        const lunches = (await client.listLunches()).map(toLunchOutput);
        const text =
          lunches.length === 0
            ? 'No published lunches in the next 14 days.'
            : `${plural(lunches.length, 'lunch', 'lunches')} in the next 14 days:\n${lunches.map(formatLunch).join('\n')}`;
        return textResult(text, { count: lunches.length, lunches });
      } catch (error) {
        return failure(error, logger, 'list_lunches');
      }
    },
  );
}

export function registerLunchWriteTools(server: McpServer, { client, logger }: ToolContext): void {
  server.registerTool(
    'vote_lunch',
    {
      title: 'Vote for a lunch option',
      description:
        "Set the member's pick for one lunch, replacing any earlier pick. Use lunch_id and a lunch_option_id " +
        'from list_lunches; pass lunch_option_id null to answer "not attending". Only works while the lunch is open. ' +
        'Before calling, tell the member the date and the option name and get their explicit yes; then set confirm: true.',
      inputSchema: {
        lunch_id: z.number().int().positive().describe('lunch_id from list_lunches'),
        lunch_option_id: z
          .number()
          .int()
          .positive()
          .nullable()
          .describe('lunch_option_id of one of that lunch\'s options, or null for "not attending"'),
        confirm: confirmField,
      },
      outputSchema: voteResultOutput,
      annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ lunch_id, lunch_option_id }) => {
      try {
        // Pre-check against a fresh list so obvious mistakes never reach the write endpoint.
        const lunch = (await client.listLunches()).find((l) => l.id === lunch_id);
        if (!lunch) {
          return errorResult(
            `Lunch ${lunch_id} is not among the published lunches for the next 14 days. ` +
              'Call list_lunches and use one of the lunch_id values it returns. Nothing was changed.',
          );
        }
        if (!lunch.is_open) {
          return errorResult(
            `Lunch ${lunch_id} on ${lunch.date} is closed for voting` +
              `${lunch.deadline ? ` (the deadline was ${lunch.deadline})` : ''}. Nothing was changed.`,
          );
        }
        if (lunch_option_id !== null) {
          const options = allOptions(lunch);
          if (!options.some((o) => o.lunch_option_id === lunch_option_id)) {
            const valid = options.map((o) => `${o.lunch_option_id} (${o.name ?? 'unnamed'})`).join(', ');
            return errorResult(
              `Option ${lunch_option_id} does not belong to lunch ${lunch_id} on ${lunch.date}. ` +
                (valid ? `Valid lunch_option_id values: ${valid}.` : 'This lunch has no options to pick.') +
                ' Nothing was changed.',
            );
          }
        }

        const previous = myVote(lunch);
        const updated = toLunchOutput(await client.respondToLunch(lunch_id, lunch_option_id));
        const changed =
          previous.status !== updated.my_vote.status || previous.lunch_option_id !== updated.my_vote.lunch_option_id;
        logger.info(`vote_lunch: lunch ${lunch_id} -> ${lunch_option_id === null ? 'not attending' : `option ${lunch_option_id}`}`);

        const text =
          `Saved. Your answer for lunch ${lunch_id} on ${updated.date}${weekday(updated.date)} is now: ` +
          `${describeVote(updated.my_vote)}.` +
          (changed ? ` (Before: ${describeVote(previous)}.)` : ' (Unchanged.)') +
          (updated.deadline ? ` You can change it until ${updated.deadline}.` : '');
        return textResult(text, { lunch: updated, previous_vote: previous, changed });
      } catch (error) {
        return failure(error, logger, 'vote_lunch');
      }
    },
  );
}
