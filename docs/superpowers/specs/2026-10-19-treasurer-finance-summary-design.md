# Treasurer finance summary — design

Date: 2026-10-19
Status: draft, awaiting approval (builder check done)

## Why

The treasurer has no single place to see who has paid, who owes, and how much.
Today the only view is the Fee Payments queue (one proof at a time) and the Fee
Periods list (one bill at a time). Working out "Ahmad owes RM 300 across 6
months" means adding it up by hand.

At the same time, money handling is open to every admin. Only the treasurer and
super-admins should touch finance.

"Loan" in the original request means **unpaid monthly fees** — the debt the
system already tracks through `FeePeriod` + `PaymentAllocation`. There are no
real loans to members.

## Goals

1. A **Finance summary** page that shows, at a glance:
   - overall totals: outstanding, collected, credit held, payments waiting for review;
   - a "who owes" table: one row per active member, biggest debt first.
2. From the table the treasurer can **open a member's bills** and **send that
   member a reminder**.
3. **Export** the table to a file Excel opens.
4. Two new roles — **super-admin** and **treasurer** — and **all of Finance**
   (summary, payment review, bills) restricted to them.

## Non-goals

- Real member loans, instalment plans, or any new kind of debt.
- Lunch or activity charges (they are not billed today).
- Changing how payments allocate to bills (`FinanceService` stays as is).
- Charts, trends over time, or PDF statements.
- Any change to the member's own views (My Payments, My Bills) or the mobile app
  (it has no admin features).
- A general roles/permissions system. Two booleans are enough.

## Anti-goals (would be a failure even if it works)

- **The summary disagreeing with what a member sees.** Every balance must come
  from the same rule as `FinanceService::outstanding()` (per-bill unpaid
  remainder, over-paid bills clamp to zero, credit tracked separately). If a
  member's app says RM 150 and the summary says RM 120, the page has failed.
- **A plain admin able to reach finance** by URL, global search, a dashboard
  tile, or a tampered Livewire request.
- **A plain admin able to grant themselves treasurer.**
- **Slow page with many members** — no per-member N+1 queries.

## Constraints from the project

- Filament 5, Laravel 12, one panel (`AccountPanelProvider`) shared by members
  and admins; per-resource gating via `canAccess()` and `is_admin` checks.
- **No queue worker** in production. Anything Filament would queue by default
  (its `ExportAction` uses jobs and batches) must not be used as is; reminders
  must deliver inline (`BillReminder` is already not `ShouldQueue`).
- **Tests run on SQLite, prod on Postgres (Supabase).** The aggregation query
  must be portable and should be checked against Postgres before merging.
- **Livewire public props are tamperable** — the reminder action must re-load
  the member server-side and re-check the viewer's role.
- UI strings are translated: every new string goes in `lang/ms.json` and
  `lang/id.json` too.

## Decided

### Roles

- Two new boolean columns on `users`: `is_super_admin`, `is_treasurer`
  (default false). Both only take effect when `is_admin` is also true.
- One helper, `User::canManageFinance(): bool` = `is_admin && (is_super_admin || is_treasurer)`.
  Every finance check uses this helper, never the raw columns.
- On the user form, **Super-admin** and **Treasurer** toggles sit beside
  **Administrator**. They are visible and editable **only to super-admins**.
  The server must also ignore these fields when saved by a non-super-admin (do
  not rely on hiding the toggle).
- **Protecting finance accounts.** On a user who is a treasurer or super-admin,
  a plain admin may edit **only name and member category** (and birthday/custom
  fee as today). Password, email, `email_verified_at`, the Administrator switch,
  passkeys reset, disable, and delete are **super-admin only** for those
  accounts. Enforce server-side (strip the fields on save, `canDelete` per
  record), not only by hiding inputs.
- A super-admin cannot remove their own Administrator or Super-admin switch.
- Bootstrapping: an artisan command `app:make-super-admin {email}` sets
  `is_admin` and `is_super_admin` on that user. Run once after deploy.
- Users table: the existing role badge (`admin` / `claimed` / …) shows
  `super-admin` and `treasurer` where they apply, and the role filter gains
  those options.

### Finance lock

These switch from `is_admin` to `canManageFinance()`:

- `FeePeriodResource::canAccess()` — plain admins lose the Fee Periods page.
- `FeePaymentResource`: nav group/label/sort, `canEdit`, `canDelete`, the
  `getEloquentQuery()` scoping, `FeePaymentsTable` defaults, and
  `PaymentReviewActions`. A plain admin now gets exactly the **member view**:
  "My Payments", their own proofs only, can submit, cannot verify.
- `NeedsAttention` widget: the **Payments to verify** and **Unpaid bills**
  tiles show only to finance users; plain admins still see the lunch and
  waitlist tiles.
- Global search for fee periods/payments follows the same gating.

Member **credit** stays visible to plain admins on the Users pages (decided:
it's harmless). Unchanged for plain admins: users (except the protection above), lunches, activities, caterers, meals,
member categories, auto-emails, email budget.

**Rollout note:** on deploy, every current admin loses finance access until a
super-admin sets their switch. The deploy steps must include running
`app:make-super-admin` and setting the treasurer immediately.

### Finance summary page

A Filament page in `NavGroup::Finance`, first in the group, visible only when
`canManageFinance()`. Mockup agreed in chat; layout:

**Totals (four tiles, top)**

| Tile | Value | Sub-line |
|---|---|---|
| Outstanding | sum of every active member's outstanding | "N members owe" |
| Collected this month | sum of `amount_verified` of proofs verified this calendar month | all-time collected |
| Credit held | sum of every active member's credit (`User::credit` rule) | "paid ahead by N members" |
| Payments to verify | count of pending proofs | oldest age; links to Fee Payments queue |

Payments-to-verify is tinted when non-zero, grey when zero (same convention as
`NeedsAttention`).

**"Who owes" table**

- Rows: `User::enabled()` members — the same set that is billed and reminded.
  Unclaimed accounts still inside their invitation window are included; expired
  invitations count as disabled and are left out (see calls below).
- Columns: member name (with member category below), **owes**, unpaid months
  (count of bills not fully paid, with a "partial" marker when the oldest is
  partial), oldest unpaid month, credit.
- Default filter **Owing only** (owes > 0); a filter switches to **All active**.
- Default sort: owes, highest first. Searchable by name.
- Row actions:
  - **Bills** — opens the member's bills (Fee Periods list filtered to that member).
  - **Remind** — confirmation modal ("Send a reminder to Ahmad for MYR 300.00
    across 6 bills?"), then sends `BillReminder` inline to that one member,
    exactly as `SendBillReminders` builds it. Hidden when the member owes
    nothing. Success / failure toast.

**Export**

- Header button **Export** downloads the current table (respecting active filter
  and search) as **CSV**, UTF-8 with BOM so Excel opens it with correct
  characters. Columns: name, email, category, owes, unpaid months, oldest unpaid
  month, credit, paid this month (yes/no — this calendar month's bill has
  status `paid`, which includes free bills; "no" when partial, unpaid, or no bill). Filename
  `finance-summary-YYYY-MM-DD.csv`.
- Generated synchronously as a streamed download — not Filament's queued
  `ExportAction`.

### "Collected this month" needs a verified date

`payment_proofs` has no verified timestamp today. Add a nullable `verified_at`,
set it in `FinanceService::verifyAndAllocate()`, and backfill existing verified
proofs from `updated_at` in the migration.

## Left to the builder

- How the per-member outstanding/credit is computed in bulk (one grouped query
  or subquery-select on `users`), as long as it matches
  `FinanceService::outstanding()` and `User::credit` exactly and works on
  Postgres. A test should compare the page's number against those two methods
  for a member with partial, over-paid and paid bills.
- Whether the summary is a custom page with a table, or a table widget on a page.
- Exact wording, icons, and how "Bills" filters Fee Periods (existing filter or a
  new `user` filter).
- Whether the summary's tile code shares helpers with `NeedsAttention`.

## Calls I made (check these)

- Super-admin can't remove their own super-admin switch (prevents locking
  everyone out); otherwise any super-admin can grant/revoke both roles.
- Export is CSV, not .xlsx (opens in Excel, no new dependency, no queue).
- "Collected this month" uses the new `verified_at`, backfilled from `updated_at`.
- Payment proofs and bills from disabled members still count in "Collected"
  (money received is money received) but not in "Outstanding" or the table.
- "Active member" = `User::enabled()`, matching billing and reminders, so the
  summary owes-list is exactly who the reminder job would reach. Debt on
  disabled or expired-invitation accounts is not in Outstanding.
- "Paid this month" in the export = this month's bill status is `paid`.
- Plain admins' own dashboard bill widgets stay as today (hidden for admins).

## Testing

- Feature tests for: role gating on every finance resource/page/tile (plain
  admin, treasurer, super-admin, member); a plain admin cannot set role flags,
  or change a treasurer's password/email/admin switch, via a crafted form
  submission; summary numbers equal the `FinanceService` /
  `User::credit` values; remind sends one notification inline and refuses a
  member who owes nothing; export content and filtering; `app:make-super-admin`.
- Manual check of the summary against the prod-shaped Postgres database before merging.
