import { afterEach, describe, expect, it } from 'vitest';
import { activities, billSummary, bills, lunches, paidBills } from './fixtures.js';
import { FakeApi, TOKEN, connect, envelope, text, type Harness } from './helpers.js';

let harness: Harness | undefined;
afterEach(async () => {
  await harness?.close();
  harness = undefined;
});

describe('get_bill_summary', () => {
  it('returns the summary and authenticates with the bearer token', async () => {
    const api = new FakeApi().on('GET', '/api/v1/bills/summary', envelope(billSummary));
    harness = await connect(api);
    const result = await harness.call('get_bill_summary');

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toEqual({ currency: 'MYR', ...billSummary });
    expect(text(result)).toContain('Outstanding: MYR 70.00 across 2 unpaid bills');
    expect(text(result)).toContain('Available credit: MYR 5.00');

    expect(api.calls).toHaveLength(1);
    expect(api.calls[0]?.headers.authorization).toBe(`Bearer ${TOKEN}`);
    expect(api.calls[0]?.headers.accept).toBe('application/json');
  });

  it('handles a member with no bills', async () => {
    const api = new FakeApi().on(
      'GET',
      '/api/v1/bills/summary',
      envelope({ total_outstanding: '0.00', available_credit: '0.00', unpaid_count: 0, bill_count: 0 }),
    );
    harness = await connect(api);
    const result = await harness.call('get_bill_summary');

    expect(result.isError).toBeFalsy();
    expect(text(result)).toContain('0 unpaid bills (0 bills in total)');
  });
});

describe('list_bills', () => {
  it('lists every bill', async () => {
    harness = await connect(new FakeApi().on('GET', '/api/v1/bills', envelope(bills)));
    const result = await harness.call('list_bills');

    expect(result.structuredContent).toMatchObject({ count: 3, outstanding_only: false, bills });
    expect(text(result)).toContain('2026-09 (bill 2): due MYR 50.00, paid MYR 30.00, outstanding MYR 20.00 [partial]');
  });

  it('filters to outstanding bills', async () => {
    harness = await connect(new FakeApi().on('GET', '/api/v1/bills', envelope(bills)));
    const result = await harness.call('list_bills', { outstanding_only: true });
    const content = result.structuredContent as { bills: { id: number }[]; count: number };

    expect(content.count).toBe(2);
    expect(content.bills.map((b) => b.id)).toEqual([3, 2]);
  });

  it('says so when nothing is outstanding', async () => {
    harness = await connect(new FakeApi().on('GET', '/api/v1/bills', envelope(paidBills)));
    const result = await harness.call('list_bills', { outstanding_only: true });

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toMatchObject({ count: 0, bills: [] });
    expect(text(result)).toBe('No outstanding bills. Everything is paid.');
  });

  it('handles a member with no bills at all', async () => {
    harness = await connect(new FakeApi().on('GET', '/api/v1/bills', envelope([])));
    const result = await harness.call('list_bills');

    expect(text(result)).toBe('No bills found for this member.');
    expect(result.structuredContent).toMatchObject({ count: 0, bills: [] });
  });

  it('rejects a non-boolean filter', async () => {
    const api = new FakeApi();
    harness = await connect(api);
    const result = await harness.call('list_bills', { outstanding_only: 'yes' });

    expect(result.isError).toBe(true);
    expect(api.calls).toHaveLength(0);
  });
});

describe('list_lunches', () => {
  it('lists lunches with menus, option ids, open state and the member vote', async () => {
    harness = await connect(new FakeApi().on('GET', '/api/v1/lunches', envelope(lunches)));
    const result = await harness.call('list_lunches');
    const content = result.structuredContent as {
      count: number;
      lunches: { lunch_id: number; is_open: boolean; my_vote: unknown; menus: unknown[] }[];
    };

    expect(content.count).toBe(3);
    const [closed, open, twoMenus] = content.lunches;
    expect(closed).toMatchObject({ lunch_id: 102, is_open: false, my_vote: { status: 'none' } });
    expect(open).toMatchObject({
      lunch_id: 101,
      is_open: true,
      deadline: '2026-10-25T17:00:00+08:00',
      my_vote: { status: 'attending', lunch_option_id: 1001, option_name: 'Chicken rice', menu_id: 11 },
      menus: [
        {
          menu_id: 11,
          options: [
            { lunch_option_id: 1001, name: 'Chicken rice' },
            { lunch_option_id: 1002, name: 'Vegetable curry' },
          ],
        },
      ],
    });
    expect(twoMenus).toMatchObject({ lunch_id: 103, my_vote: { status: 'not_attending' } });
    expect(twoMenus?.menus).toHaveLength(2);

    const out = text(result);
    expect(out).toContain('Lunch 101 on 2026-10-26 (Mon): OPEN for voting until 2026-10-25T17:00:00+08:00');
    expect(out).toContain('option 1002: Vegetable curry');
    expect(out).toContain('Your vote: Chicken rice (option 1001)');
    expect(out).toContain('Lunch 102 on 2026-10-23 (Fri): CLOSED');
    expect(out).toContain('Menu 22 - Sample Bistro: Pasta day');
    expect(out).toContain('Your vote: not attending');
  });

  it('resolves a legacy vote that only has a meal option', async () => {
    const legacy = { ...lunches[1], my_lunch_option_id: null, my_meal_option_id: 8 };
    harness = await connect(new FakeApi().on('GET', '/api/v1/lunches', envelope([legacy])));
    const result = await harness.call('list_lunches');

    expect(result.structuredContent).toMatchObject({
      lunches: [{ my_vote: { status: 'attending', lunch_option_id: 1002, option_name: 'Vegetable curry' } }],
    });
  });

  it('handles no upcoming lunches', async () => {
    harness = await connect(new FakeApi().on('GET', '/api/v1/lunches', envelope([])));
    const result = await harness.call('list_lunches');

    expect(result.isError).toBeFalsy();
    expect(text(result)).toBe('No published lunches in the next 14 days.');
    expect(result.structuredContent).toEqual({ count: 0, lunches: [] });
  });
});

describe('list_activities', () => {
  it('lists activities with questions, capacity and the member status', async () => {
    const waitlisted = { ...activities[1], id: 205, my_status: 'waitlisted', waitlist_position: 2 };
    harness = await connect(new FakeApi().on('GET', '/api/v1/activities', envelope([...activities, waitlisted])));
    const result = await harness.call('list_activities');
    const content = result.structuredContent as { count: number; activities: Record<string, unknown>[] };

    expect(content.count).toBe(5);
    expect(content.activities[0]).toMatchObject({
      activity_id: 201,
      description: 'Meet at the main gate.',
      spots_left: null,
      full: false,
      my_status: 'none',
      questions: [],
    });
    expect(content.activities[1]).toMatchObject({
      activity_id: 202,
      full: true,
      questions: [
        { key: 'q-size', type: 'select', required: true, options: ['S', 'M', 'L'] },
        { key: 'q-waiver', type: 'checkbox', required: true, options: [] },
        { key: 'q-notes', type: 'textarea', required: false, options: [] },
      ],
    });

    const out = text(result);
    expect(out).toContain('Activity 201: Morning walk - 2026-11-01T07:00:00+08:00 at Example Park');
    expect(out).toContain('Registration: open, unlimited seats. You: not registered.');
    expect(out).toContain('full (new registrations join the waitlist)');
    expect(out).toContain('[required] T-shirt size (key q-size; choose one of: S, M, L)');
    expect(out).toContain('[required] I accept the safety waiver (key q-waiver; checkbox, answer true/false)');
    expect(out).toContain('Registration: closed, 10 seats left');
    expect(out).toContain('You: registered.');
    expect(out).toContain('You: on the waitlist (position 2).');
    expect(out).not.toContain('<p>');
  });

  it('accepts questions without type or required flag (PHP defaults)', async () => {
    const bare = { ...activities[0], questions: [{ key: 'k1', label: 'Diet' }] };
    harness = await connect(new FakeApi().on('GET', '/api/v1/activities', envelope([bare])));
    const result = await harness.call('list_activities');

    expect(result.structuredContent).toMatchObject({
      activities: [{ questions: [{ key: 'k1', label: 'Diet', type: 'text', required: false, options: [] }] }],
    });
  });

  it('handles no upcoming activities', async () => {
    harness = await connect(new FakeApi().on('GET', '/api/v1/activities', envelope([])));
    const result = await harness.call('list_activities');

    expect(text(result)).toBe('No upcoming activities.');
    expect(result.structuredContent).toEqual({ count: 0, activities: [] });
  });
});
