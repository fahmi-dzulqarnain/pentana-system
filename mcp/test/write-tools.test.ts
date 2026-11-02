import { afterEach, describe, expect, it } from 'vitest';
import {
  activities,
  activityRegistered,
  activitySimple,
  activityWithQuestions,
  lunchOpen,
  lunchTwoMenus,
  lunches,
} from './fixtures.js';
import { FakeApi, TOKEN, connect, envelope, text, type Harness } from './helpers.js';

let harness: Harness | undefined;
afterEach(async () => {
  await harness?.close();
  harness = undefined;
});

const writes = { allowWrites: true };

function lunchApi() {
  return new FakeApi().on('GET', '/api/v1/lunches', envelope(lunches));
}

function activityApi() {
  return new FakeApi().on('GET', '/api/v1/activities', envelope(activities));
}

describe('confirm gate', () => {
  it.each([
    ['vote_lunch', { lunch_id: 101, lunch_option_id: 1002 }],
    ['register_activity', { activity_id: 201 }],
    ['cancel_registration', { activity_id: 204 }],
  ])('%s without confirm is rejected before any request', async (tool, args) => {
    const api = new FakeApi();
    harness = await connect(api, writes);

    for (const confirm of [undefined, false, 'yes']) {
      const result = await harness.call(tool, confirm === undefined ? args : { ...args, confirm });
      expect(result.isError).toBe(true);
      expect(text(result)).toContain('ask them to confirm');
    }
    expect(api.calls).toHaveLength(0);
  });
});

describe('vote_lunch', () => {
  it('votes after checking a fresh lunch list', async () => {
    const updated = { ...lunchOpen, my_lunch_option_id: 1002, my_meal_option_id: 8 };
    const api = lunchApi().on('POST', '/api/v1/lunches/101/respond', envelope(updated));
    harness = await connect(api, writes);
    const result = await harness.call('vote_lunch', { lunch_id: 101, lunch_option_id: 1002, confirm: true });

    expect(result.isError).toBeFalsy();
    expect(api.calls.map((c) => `${c.method} ${c.path}`)).toEqual([
      'GET /api/v1/lunches',
      'POST /api/v1/lunches/101/respond',
    ]);
    const post = api.posts()[0];
    expect(post?.body).toEqual({ lunch_option_id: 1002 });
    expect(post?.headers.authorization).toBe(`Bearer ${TOKEN}`);
    expect(post?.headers['content-type']).toBe('application/json');

    expect(text(result)).toContain('is now: Vegetable curry (option 1002). (Before: Chicken rice (option 1001).)');
    expect(result.structuredContent).toMatchObject({
      changed: true,
      previous_vote: { status: 'attending', lunch_option_id: 1001 },
      lunch: { lunch_id: 101, my_vote: { status: 'attending', lunch_option_id: 1002 } },
    });
  });

  it('votes for an option on the second menu of a multi-menu day', async () => {
    const updated = { ...lunchTwoMenus, my_lunch_option_id: 2101, my_meal_option_id: 10 };
    const api = lunchApi().on('POST', '/api/v1/lunches/103/respond', envelope(updated));
    harness = await connect(api, writes);
    const result = await harness.call('vote_lunch', { lunch_id: 103, lunch_option_id: 2101, confirm: true });

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toMatchObject({ lunch: { my_vote: { option_name: 'Tomato pasta', menu_id: 22 } } });
  });

  it('sends null for "not attending"', async () => {
    const updated = { ...lunchOpen, my_lunch_option_id: null, my_meal_option_id: null };
    const api = lunchApi().on('POST', '/api/v1/lunches/101/respond', envelope(updated));
    harness = await connect(api, writes);
    const result = await harness.call('vote_lunch', { lunch_id: 101, lunch_option_id: null, confirm: true });

    expect(api.posts()[0]?.body).toEqual({ lunch_option_id: null });
    expect(text(result)).toContain('is now: not attending');
  });

  it('refuses a closed lunch without calling POST', async () => {
    const api = lunchApi();
    harness = await connect(api, writes);
    const result = await harness.call('vote_lunch', { lunch_id: 102, lunch_option_id: 1201, confirm: true });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('Lunch 102 on 2026-10-23 is closed for voting');
    expect(api.posts()).toHaveLength(0);
  });

  it('refuses an option that belongs to a different lunch without calling POST', async () => {
    const api = lunchApi();
    harness = await connect(api, writes);
    const result = await harness.call('vote_lunch', { lunch_id: 101, lunch_option_id: 2001, confirm: true });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('Option 2001 does not belong to lunch 101');
    expect(text(result)).toContain('1001 (Chicken rice), 1002 (Vegetable curry)');
    expect(api.posts()).toHaveLength(0);
  });

  it('refuses a lunch that is not in the list without calling POST', async () => {
    const api = lunchApi();
    harness = await connect(api, writes);
    const result = await harness.call('vote_lunch', { lunch_id: 999, lunch_option_id: 1001, confirm: true });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('Lunch 999 is not among the published lunches');
    expect(api.posts()).toHaveLength(0);
  });

  it('relays the API 422 message verbatim when the API still refuses', async () => {
    // The deadline passes between the pre-check and the POST: the API is the final authority.
    const api = lunchApi().on('POST', '/api/v1/lunches/101/respond', {
      status: 422,
      body: { message: 'This lunch is not open for ordering.' },
    });
    harness = await connect(api, writes);
    const result = await harness.call('vote_lunch', { lunch_id: 101, lunch_option_id: 1002, confirm: true });

    expect(result.isError).toBe(true);
    expect(text(result)).toBe('PENTANA refused the request: This lunch is not open for ordering.');
  });
});

describe('register_activity', () => {
  it('registers for an activity without questions', async () => {
    const api = activityApi().on(
      'POST',
      '/api/v1/activities/201/register',
      envelope({ ...activitySimple, my_status: 'registered' }),
    );
    harness = await connect(api, writes);
    const result = await harness.call('register_activity', { activity_id: 201, confirm: true });

    expect(result.isError).toBeFalsy();
    expect(api.posts()[0]?.body).toEqual({ answers: {} });
    expect(text(result)).toBe('Registered for "Morning walk" on 2026-11-01T07:00:00+08:00.');
    expect(result.structuredContent).toMatchObject({ outcome: 'registered', activity: { activity_id: 201 } });
  });

  it('sends normalised answers and reports a waitlist place', async () => {
    const api = activityApi().on(
      'POST',
      '/api/v1/activities/202/register',
      envelope({ ...activityWithQuestions, my_status: 'waitlisted', waitlist_position: 3 }),
    );
    harness = await connect(api, writes);
    const result = await harness.call('register_activity', {
      activity_id: 202,
      answers: { 'q-size': ' M ', 'q-waiver': 'yes' },
      confirm: true,
    });

    expect(result.isError).toBeFalsy();
    expect(api.posts()[0]?.body).toEqual({ answers: { 'q-size': 'M', 'q-waiver': true } });
    expect(text(result)).toContain('"Futsal night" is full, so you joined the waitlist at position 3.');
    expect(result.structuredContent).toMatchObject({ outcome: 'waitlisted' });
  });

  it('refuses a missing required answer without calling POST', async () => {
    const api = activityApi();
    harness = await connect(api, writes);
    const result = await harness.call('register_activity', {
      activity_id: 202,
      answers: { 'q-waiver': true },
      confirm: true,
    });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('"T-shirt size" (key q-size) is required. Choices: S, M, L.');
    expect(api.posts()).toHaveLength(0);
  });

  it('refuses an unticked required checkbox without calling POST', async () => {
    const api = activityApi();
    harness = await connect(api, writes);
    const result = await harness.call('register_activity', {
      activity_id: 202,
      answers: { 'q-size': 'L', 'q-waiver': false },
      confirm: true,
    });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('"I accept the safety waiver" (key q-waiver) must be accepted');
    expect(api.posts()).toHaveLength(0);
  });

  it('refuses a registration when no answers are given at all', async () => {
    const api = activityApi();
    harness = await connect(api, writes);
    const result = await harness.call('register_activity', { activity_id: 202, confirm: true });

    expect(result.isError).toBe(true);
    expect(text(result)).toContain('q-size');
    expect(text(result)).toContain('q-waiver');
    expect(text(result)).not.toContain('q-notes');
    expect(api.posts()).toHaveLength(0);
  });

  it('does not register again when already registered', async () => {
    const api = activityApi();
    harness = await connect(api, writes);
    const result = await harness.call('register_activity', { activity_id: activityRegistered.id, confirm: true });

    expect(result.isError).toBeFalsy();
    expect(text(result)).toContain('already registered');
    expect(result.structuredContent).toMatchObject({ outcome: 'already_registered' });
    expect(api.posts()).toHaveLength(0);
  });

  it('refuses a closed activity and an unknown activity without calling POST', async () => {
    const api = activityApi();
    harness = await connect(api, writes);

    const closed = await harness.call('register_activity', { activity_id: 203, confirm: true });
    expect(closed.isError).toBe(true);
    expect(text(closed)).toContain('Registration is closed for "Annual dinner"');

    const unknown = await harness.call('register_activity', { activity_id: 999, confirm: true });
    expect(unknown.isError).toBe(true);
    expect(text(unknown)).toContain('Activity 999 is not among the upcoming published activities');

    expect(api.posts()).toHaveLength(0);
  });

  it('relays a Laravel validation 422 message verbatim', async () => {
    const message = 'The answers.q-size field is required.';
    const api = activityApi().on('POST', '/api/v1/activities/202/register', {
      status: 422,
      body: { message, errors: { 'answers.q-size': [message] } },
    });
    harness = await connect(api, writes);
    const result = await harness.call('register_activity', {
      activity_id: 202,
      answers: { 'q-size': 'S', 'q-waiver': true },
      confirm: true,
    });

    expect(result.isError).toBe(true);
    expect(text(result)).toBe(`PENTANA refused the request: ${message}`);
  });
});

describe('cancel_registration', () => {
  it('cancels a registration', async () => {
    const api = activityApi().on(
      'POST',
      '/api/v1/activities/204/cancel',
      envelope({ ...activityRegistered, my_status: 'none', spots_left: 4 }),
    );
    harness = await connect(api, writes);
    const result = await harness.call('cancel_registration', { activity_id: 204, confirm: true });

    expect(result.isError).toBeFalsy();
    expect(api.posts().map((c) => c.path)).toEqual(['/api/v1/activities/204/cancel']);
    expect(text(result)).toBe('Cancelled your registration for "Board games".');
    expect(result.structuredContent).toMatchObject({
      outcome: 'cancelled',
      previous_status: 'registered',
      activity: { my_status: 'none', spots_left: 4 },
    });
  });

  it('does nothing when the member is not registered', async () => {
    const api = activityApi();
    harness = await connect(api, writes);
    const result = await harness.call('cancel_registration', { activity_id: 201, confirm: true });

    expect(result.isError).toBeFalsy();
    expect(text(result)).toContain('nothing to cancel');
    expect(result.structuredContent).toMatchObject({ outcome: 'not_registered' });
    expect(api.posts()).toHaveLength(0);
  });
});
