import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import type { Bill } from '../schemas.js';
import { CURRENCY, READ_ONLY, failure, plural, textResult, type ToolContext } from './shared.js';

const billOutput = z.object({
  id: z.number().int(),
  month: z.string().describe('YYYY-MM'),
  amount_due: z.string().describe('Fixed 2dp decimal string'),
  amount_paid: z.string(),
  outstanding: z.string(),
  status: z.string().describe('unpaid | partial | paid'),
});

const summaryOutput = {
  currency: z.literal(CURRENCY),
  total_outstanding: z.string().describe('Fixed 2dp decimal string'),
  available_credit: z.string().describe('Fixed 2dp decimal string'),
  unpaid_count: z.number().int(),
  bill_count: z.number().int(),
};

const listOutput = {
  currency: z.literal(CURRENCY),
  outstanding_only: z.boolean(),
  count: z.number().int(),
  bills: z.array(billOutput),
};

/** A bill still needs money while it is not marked paid or has a positive balance. */
export function isOutstanding(bill: Bill): boolean {
  return bill.status !== 'paid' || Number(bill.outstanding) > 0;
}

export function registerBillTools(server: McpServer, { client, logger }: ToolContext): void {
  server.registerTool(
    'get_bill_summary',
    {
      title: 'Bill summary',
      description:
        "The member's dues at a glance: total outstanding, available credit, and how many bills are unpaid. " +
        `Amounts are ${CURRENCY}, as fixed 2-decimal strings.`,
      outputSchema: summaryOutput,
      annotations: READ_ONLY,
    },
    async () => {
      try {
        const s = await client.getBillSummary();
        const text =
          `Outstanding: ${CURRENCY} ${s.total_outstanding} across ${plural(s.unpaid_count, 'unpaid bill')} ` +
          `(${plural(s.bill_count, 'bill')} in total). Available credit: ${CURRENCY} ${s.available_credit}.`;
        return textResult(text, { currency: CURRENCY, ...s });
      } catch (error) {
        return failure(error, logger, 'get_bill_summary');
      }
    },
  );

  server.registerTool(
    'list_bills',
    {
      title: 'List bills',
      description:
        "The member's monthly bills, newest month first, with amount due, paid and outstanding " +
        `(${CURRENCY}, fixed 2-decimal strings) and status (unpaid, partial, paid). ` +
        'Set outstanding_only to list only bills that still need payment.',
      inputSchema: {
        outstanding_only: z
          .boolean()
          .optional()
          .describe('Only bills that are not fully paid. Default false.'),
      },
      outputSchema: listOutput,
      annotations: READ_ONLY,
    },
    async ({ outstanding_only }) => {
      try {
        const all = await client.listBills();
        const onlyOutstanding = outstanding_only ?? false;
        const bills = onlyOutstanding ? all.filter(isOutstanding) : all;

        let text: string;
        if (bills.length === 0) {
          text = onlyOutstanding ? 'No outstanding bills. Everything is paid.' : 'No bills found for this member.';
        } else {
          const lines = bills.map(
            (b) =>
              `- ${b.month} (bill ${b.id}): due ${CURRENCY} ${b.amount_due}, paid ${CURRENCY} ${b.amount_paid}, ` +
              `outstanding ${CURRENCY} ${b.outstanding} [${b.status}]`,
          );
          text = `${plural(bills.length, onlyOutstanding ? 'outstanding bill' : 'bill')}:\n${lines.join('\n')}`;
        }
        return textResult(text, { currency: CURRENCY, outstanding_only: onlyOutstanding, count: bills.length, bills });
      } catch (error) {
        return failure(error, logger, 'list_bills');
      }
    },
  );
}
