import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import type { Activity } from '../schemas.js';
import { checkAnswers } from './answers.js';
import {
  READ_ONLY,
  confirmField,
  errorResult,
  failure,
  plural,
  textResult,
  toPlainText,
  truncate,
  type ToolContext,
} from './shared.js';

const questionOutput = z.object({
  key: z.string().describe('Use as the key in register_activity answers'),
  label: z.string(),
  type: z.string().describe('text | textarea | select | checkbox'),
  required: z.boolean(),
  options: z.array(z.string()).describe('Choices for a select question; empty otherwise'),
});

const activityOutput = z.object({
  activity_id: z.number().int(),
  title: z.string(),
  description: z.string().nullable().describe('Plain text (HTML removed)'),
  starts_at: z.string().nullable().describe('ISO 8601'),
  location: z.string().nullable(),
  is_open: z.boolean().describe('Whether registration is open'),
  spots_left: z.number().int().nullable().describe('Free seats; null = unlimited capacity'),
  full: z.boolean().describe('No seats left: a new registration joins the waitlist'),
  my_status: z.string().describe('registered | waitlisted | none'),
  waitlist_position: z.number().int().nullable(),
  questions: z.array(questionOutput),
});
type ActivityOutput = z.infer<typeof activityOutput>;

const listOutput = { count: z.number().int(), activities: z.array(activityOutput) };
const registerOutput = {
  activity: activityOutput,
  outcome: z.enum(['registered', 'waitlisted', 'already_registered', 'already_waitlisted', 'unknown']),
};
const cancelOutput = {
  activity: activityOutput,
  outcome: z.enum(['cancelled', 'not_registered']),
  previous_status: z.string(),
};

export function toActivityOutput(a: Activity): ActivityOutput {
  return {
    activity_id: a.id,
    title: a.title,
    description: toPlainText(a.description),
    starts_at: a.starts_at,
    location: a.location,
    is_open: a.is_open,
    spots_left: a.spots_left,
    full: a.spots_left === 0,
    my_status: a.my_status,
    waitlist_position: a.waitlist_position,
    questions: a.questions.map((q) => ({
      key: q.key,
      label: q.label,
      type: q.type,
      required: q.required,
      options: q.options,
    })),
  };
}

function describeStatus(a: ActivityOutput): string {
  switch (a.my_status) {
    case 'registered':
      return 'registered';
    case 'waitlisted':
      return a.waitlist_position === null ? 'on the waitlist' : `on the waitlist (position ${a.waitlist_position})`;
    case 'none':
      return 'not registered';
    default:
      return a.my_status;
  }
}

function formatActivity(a: ActivityOutput): string {
  const where = a.location ? ` at ${a.location}` : '';
  const seats =
    a.spots_left === null
      ? 'unlimited seats'
      : a.full
        ? 'full (new registrations join the waitlist)'
        : `${plural(a.spots_left, 'seat')} left`;
  const lines = [
    `- Activity ${a.activity_id}: ${a.title} - ${a.starts_at ?? 'date to be announced'}${where}`,
    `  Registration: ${a.is_open ? 'open' : 'closed'}, ${seats}. You: ${describeStatus(a)}.`,
  ];
  if (a.description) lines.push(`  ${truncate(a.description, 280)}`);
  if (a.questions.length > 0) {
    lines.push('  Registration questions:');
    for (const q of a.questions) {
      const kind =
        q.type === 'checkbox'
          ? 'checkbox, answer true/false'
          : q.type === 'select' && q.options.length > 0
            ? `choose one of: ${q.options.join(', ')}`
            : q.type;
      lines.push(`    * [${q.required ? 'required' : 'optional'}] ${q.label || '(no label)'} (key ${q.key}; ${kind})`);
    }
  }
  return lines.join('\n');
}

async function findActivity(ctx: ToolContext, activityId: number): Promise<Activity | undefined> {
  return (await ctx.client.listActivities()).find((a) => a.id === activityId);
}

function notListed(activityId: number): ReturnType<typeof errorResult> {
  return errorResult(
    `Activity ${activityId} is not among the upcoming published activities. ` +
      'Call list_activities and use one of the activity_id values it returns. Nothing was changed.',
  );
}

export function registerActivityReadTools(server: McpServer, { client, logger }: ToolContext): void {
  server.registerTool(
    'list_activities',
    {
      title: 'List activities',
      description:
        'Upcoming published activities: title, start time, location, description, whether registration is open, ' +
        "free seats (null = unlimited; 0 = full, so a new registration joins the waitlist), the member's status " +
        '(registered, waitlisted with position, or none), and the registration questions to answer when registering.',
      outputSchema: listOutput,
      annotations: READ_ONLY,
    },
    async () => {
      try {
        const activities = (await client.listActivities()).map(toActivityOutput);
        const text =
          activities.length === 0
            ? 'No upcoming activities.'
            : `${plural(activities.length, 'upcoming activity', 'upcoming activities')}:\n` +
              activities.map(formatActivity).join('\n');
        return textResult(text, { count: activities.length, activities });
      } catch (error) {
        return failure(error, logger, 'list_activities');
      }
    },
  );
}

export function registerActivityWriteTools(server: McpServer, ctx: ToolContext): void {
  const { client, logger } = ctx;

  server.registerTool(
    'register_activity',
    {
      title: 'Register for an activity',
      description:
        'Register the member for an upcoming activity. If it is full, the member joins the waitlist. ' +
        'answers maps each question key from list_activities to the answer: text for text/textarea/select ' +
        '(select must be one of its choices), true/false for checkbox. Required checkboxes must be true. ' +
        'Never invent answers: ask the member. Before calling, tell the member the activity and their answers ' +
        'and get an explicit yes; then set confirm: true.',
      inputSchema: {
        activity_id: z.number().int().positive().describe('activity_id from list_activities'),
        answers: z
          .record(z.string(), z.union([z.string().max(2000), z.boolean()]))
          .optional()
          .describe('Answers keyed by question key. Omit when the activity has no questions.'),
        confirm: confirmField,
      },
      outputSchema: registerOutput,
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
    },
    async ({ activity_id, answers }) => {
      try {
        const activity = await findActivity(ctx, activity_id);
        if (!activity) return notListed(activity_id);

        if (activity.my_status === 'registered' || activity.my_status === 'waitlisted') {
          const current = toActivityOutput(activity);
          return textResult(`You are already ${describeStatus(current)} for "${activity.title}". Nothing was changed.`, {
            activity: current,
            outcome: activity.my_status === 'registered' ? 'already_registered' : 'already_waitlisted',
          });
        }
        if (!activity.is_open) {
          return errorResult(`Registration is closed for "${activity.title}". Nothing was changed.`);
        }
        const check = checkAnswers(activity.questions, answers ?? {});
        if (!check.ok) {
          return errorResult(
            `Cannot register for "${activity.title}" yet. Nothing was sent:\n` +
              check.problems.map((p) => `- ${p}`).join('\n'),
          );
        }

        const updated = toActivityOutput(await client.registerForActivity(activity_id, check.answers));
        const outcome =
          updated.my_status === 'registered' || updated.my_status === 'waitlisted' ? updated.my_status : 'unknown';
        logger.info(`register_activity: activity ${activity_id} -> ${outcome}`);

        const text =
          outcome === 'registered'
            ? `Registered for "${updated.title}" on ${updated.starts_at ?? 'a date to be announced'}.`
            : outcome === 'waitlisted'
              ? `"${updated.title}" is full, so you joined the waitlist` +
                (updated.waitlist_position === null ? '.' : ` at position ${updated.waitlist_position}.`) +
                ' You will be moved in automatically if a seat frees up.'
              : `The registration request for "${updated.title}" was sent; current status: ${updated.my_status}.`;
        return textResult(text, { activity: updated, outcome });
      } catch (error) {
        return failure(error, logger, 'register_activity');
      }
    },
  );

  server.registerTool(
    'cancel_registration',
    {
      title: 'Cancel an activity registration',
      description:
        "Cancel the member's registration (or waitlist place) for an upcoming activity. A freed seat goes to the " +
        'next person on the waitlist, so the member may not get it back. Before calling, tell the member which ' +
        'activity and get an explicit yes; then set confirm: true.',
      inputSchema: {
        activity_id: z.number().int().positive().describe('activity_id from list_activities'),
        confirm: confirmField,
      },
      outputSchema: cancelOutput,
      annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
    },
    async ({ activity_id }) => {
      try {
        const activity = await findActivity(ctx, activity_id);
        if (!activity) return notListed(activity_id);

        if (activity.my_status === 'none') {
          return textResult(`You are not registered for "${activity.title}", so there is nothing to cancel.`, {
            activity: toActivityOutput(activity),
            outcome: 'not_registered',
            previous_status: activity.my_status,
          });
        }

        const updated = toActivityOutput(await client.cancelRegistration(activity_id));
        logger.info(`cancel_registration: activity ${activity_id} (was ${activity.my_status})`);
        return textResult(
          `Cancelled your ${activity.my_status === 'waitlisted' ? 'waitlist place' : 'registration'} for "${updated.title}".`,
          { activity: updated, outcome: 'cancelled', previous_status: activity.my_status },
        );
      } catch (error) {
        return failure(error, logger, 'cancel_registration');
      }
    },
  );
}
