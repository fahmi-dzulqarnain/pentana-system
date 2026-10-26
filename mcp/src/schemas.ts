/**
 * Runtime schemas for the PENTANA member API (/api/v1), mirroring the Laravel resources in
 * app/Http/Resources/Api. Responses are parsed with these so a contract drift surfaces as a clear
 * error instead of a confusing tool result. Unknown fields are dropped (zod's default).
 */
import { z } from 'zod';

/** Money is a fixed-2dp string ("70.00") on the API, to avoid float rounding. */
const money = z.string();
const id = z.number().int();

/** BillResource */
export const billSchema = z.object({
  id,
  month: z.string(), // "YYYY-MM"
  amount_due: money,
  amount_paid: money,
  outstanding: money,
  status: z.string(), // unpaid | partial | paid
});
export type Bill = z.infer<typeof billSchema>;

/** BillController@summary */
export const billSummarySchema = z.object({
  total_outstanding: money,
  available_credit: money,
  unpaid_count: z.number().int(),
  bill_count: z.number().int(),
});
export type BillSummary = z.infer<typeof billSummarySchema>;

/** LunchResource.menus[].options[] */
export const lunchMenuOptionSchema = z.object({
  lunch_option_id: id,
  meal_option_id: id.nullable(),
  name: z.string().nullable(),
});
export type LunchMenuOption = z.infer<typeof lunchMenuOptionSchema>;

/** LunchResource.menus[] */
export const lunchMenuSchema = z.object({
  id,
  caterer: z.string().nullable(),
  menu: z.string().nullable(),
  options: z.array(lunchMenuOptionSchema),
});
export type LunchMenu = z.infer<typeof lunchMenuSchema>;

/** LunchResource. `caterer`, `menu` and `options` are the legacy single-menu fields. */
export const lunchSchema = z.object({
  id,
  date: z.string(), // "YYYY-MM-DD"
  caterer: z.string().nullable(),
  menu: z.string().nullable(),
  deadline: z.string().nullable(), // ISO 8601
  is_open: z.boolean(),
  options: z.array(z.object({ meal_option_id: id.nullable(), name: z.string().nullable() })),
  menus: z.array(lunchMenuSchema),
  responded: z.boolean(),
  my_meal_option_id: id.nullable(),
  my_lunch_option_id: id.nullable(),
});
export type Lunch = z.infer<typeof lunchSchema>;

/**
 * One entry of Activity.registration_fields (exposed as ActivityResource.questions).
 * Defaults mirror the PHP side: a missing type is "text", a missing required flag is false.
 */
export const activityQuestionSchema = z.object({
  key: z.string(),
  label: z.string().nullish().transform((v) => v ?? ''),
  type: z.string().nullish().transform((v) => v ?? 'text'), // text | textarea | select | checkbox
  required: z.boolean().nullish().transform((v) => v ?? false),
  options: z.array(z.string()).nullish().transform((v) => v ?? []),
});
export type ActivityQuestion = z.infer<typeof activityQuestionSchema>;

/** ActivityResource */
export const activitySchema = z.object({
  id,
  title: z.string(),
  description: z.string().nullable(), // may contain rich-text HTML
  starts_at: z.string().nullable(),
  location: z.string().nullable(),
  spots_left: z.number().int().nullable(), // null = unlimited capacity
  is_open: z.boolean(),
  my_status: z.string(), // registered | waitlisted | none
  waitlist_position: z.number().int().nullable(),
  questions: z.array(activityQuestionSchema),
});
export type Activity = z.infer<typeof activitySchema>;

/** UserResource (only the fields this server uses). */
export const userSchema = z.object({
  id,
  name: z.string(),
  display_name: z.string().nullish(),
  email: z.string(),
});
export type User = z.infer<typeof userSchema>;

/** AuthController@login */
export const loginResponseSchema = z.object({
  token: z.string().min(1),
  user: userSchema,
});
export type LoginResponse = z.infer<typeof loginResponseSchema>;

/** Laravel JSON resources are wrapped as { "data": ... }. */
export const envelope = <T extends z.ZodType>(schema: T) => z.object({ data: schema });
