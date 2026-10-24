/**
 * Made-up API payloads shaped exactly like the Laravel resources (BillResource, LunchResource,
 * ActivityResource). No real people or data.
 */

export const billSummary = {
  total_outstanding: '70.00',
  available_credit: '5.00',
  unpaid_count: 2,
  bill_count: 3,
};

export const bills = [
  { id: 3, month: '2026-10', amount_due: '50.00', amount_paid: '0.00', outstanding: '50.00', status: 'unpaid' },
  { id: 2, month: '2026-09', amount_due: '50.00', amount_paid: '30.00', outstanding: '20.00', status: 'partial' },
  { id: 1, month: '2026-08', amount_due: '50.00', amount_paid: '50.00', outstanding: '0.00', status: 'paid' },
];

export const paidBills = [bills[2]];

/** Lunch 101: open, single menu, member picked option 1001. */
export const lunchOpen = {
  id: 101,
  date: '2026-10-26',
  caterer: 'Example Kitchen',
  menu: 'Monday set',
  deadline: '2026-10-25T17:00:00+08:00',
  is_open: true,
  options: [
    { meal_option_id: 7, name: 'Chicken rice' },
    { meal_option_id: 8, name: 'Vegetable curry' },
  ],
  menus: [
    {
      id: 11,
      caterer: 'Example Kitchen',
      menu: 'Monday set',
      options: [
        { lunch_option_id: 1001, meal_option_id: 7, name: 'Chicken rice' },
        { lunch_option_id: 1002, meal_option_id: 8, name: 'Vegetable curry' },
      ],
    },
  ],
  responded: true,
  my_meal_option_id: 7,
  my_lunch_option_id: 1001,
};

/** Lunch 102: deadline passed. */
export const lunchClosed = {
  id: 102,
  date: '2026-10-23',
  caterer: 'Example Kitchen',
  menu: 'Friday set',
  deadline: '2026-10-22T17:00:00+08:00',
  is_open: false,
  options: [{ meal_option_id: 9, name: 'Fried noodles' }],
  menus: [
    {
      id: 12,
      caterer: 'Example Kitchen',
      menu: 'Friday set',
      options: [{ lunch_option_id: 1201, meal_option_id: 9, name: 'Fried noodles' }],
    },
  ],
  responded: false,
  my_meal_option_id: null,
  my_lunch_option_id: null,
};

/** Lunch 103: open, two menus (legacy `options` is empty), member said not attending. */
export const lunchTwoMenus = {
  id: 103,
  date: '2026-10-28',
  caterer: 'Example Kitchen · Sample Bistro',
  menu: 'Several menus today. Update the app to order.',
  deadline: '2026-10-27T17:00:00+08:00',
  is_open: true,
  options: [],
  menus: [
    {
      id: 21,
      caterer: 'Example Kitchen',
      menu: 'Wednesday set',
      options: [{ lunch_option_id: 2001, meal_option_id: 7, name: 'Chicken rice' }],
    },
    {
      id: 22,
      caterer: 'Sample Bistro',
      menu: 'Pasta day',
      options: [{ lunch_option_id: 2101, meal_option_id: 10, name: 'Tomato pasta' }],
    },
  ],
  responded: true,
  my_meal_option_id: null,
  my_lunch_option_id: null,
};

export const lunches = [lunchClosed, lunchOpen, lunchTwoMenus];

/** Activity 201: open, unlimited, no questions, not registered. */
export const activitySimple = {
  id: 201,
  title: 'Morning walk',
  description: '<p>Meet at the <strong>main gate</strong>.</p>',
  starts_at: '2026-11-01T07:00:00+08:00',
  location: 'Example Park',
  spots_left: null,
  is_open: true,
  my_status: 'none',
  waitlist_position: null,
  questions: [],
};

/** Activity 202: open, full, three questions, not registered. */
export const activityWithQuestions = {
  id: 202,
  title: 'Futsal night',
  description: null,
  starts_at: '2026-11-05T20:00:00+08:00',
  location: 'Sample Arena',
  spots_left: 0,
  is_open: true,
  my_status: 'none',
  waitlist_position: null,
  questions: [
    { key: 'q-size', label: 'T-shirt size', type: 'select', required: true, options: ['S', 'M', 'L'] },
    { key: 'q-waiver', label: 'I accept the safety waiver', type: 'checkbox', required: true },
    { key: 'q-notes', label: 'Anything we should know?', type: 'textarea', required: false },
  ],
};

/** Activity 203: registration closed, not registered. */
export const activityClosed = {
  id: 203,
  title: 'Annual dinner',
  description: 'Formal dinner.',
  starts_at: '2026-11-10T19:30:00+08:00',
  location: null,
  spots_left: 10,
  is_open: false,
  my_status: 'none',
  waitlist_position: null,
  questions: [],
};

/** Activity 204: member registered. */
export const activityRegistered = {
  id: 204,
  title: 'Board games',
  description: null,
  starts_at: '2026-11-12T20:00:00+08:00',
  location: 'Clubhouse',
  spots_left: 3,
  is_open: true,
  my_status: 'registered',
  waitlist_position: null,
  questions: [],
};

export const activities = [activitySimple, activityWithQuestions, activityClosed, activityRegistered];
