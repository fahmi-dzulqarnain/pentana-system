# Smart Reminders & Nudges — Design

**Date:** 2026-07-16
**Status:** Approved

## Goal

Two new member reminders that reuse the existing notification infrastructure
(scheduled console command → Notification with mail + Filament database bell +
APNs push via the `PushesViaApn` concern, delivered synchronously because this
deployment runs no queue worker):

1. **Outstanding-bill reminders** — nudge members who owe dues, twice a month.
2. **Lunch-voting nudges** — nudge members who haven't responded to a lunch
   whose voting deadline is closing within the next 24 hours.

The publish-time lunch touch already exists (`LunchPublished` notifies all
users when an admin publishes a lunch), so it is out of scope. Activity
reminders also already exist (`app:send-activity-reminders`).

## 1. Outstanding-bill reminders

**Schedule:** the 15th and the last day of each month, 09:00 —
`Schedule::command('app:send-bill-reminders')->monthlyOn(15, '09:00')` plus
`->lastDayOfMonth('09:00')` (two schedule entries for one command).

**Command:** `app:send-bill-reminders` (`App\Console\Commands\SendBillReminders`)

- Finds every member (non-admin user) whose outstanding balance is > 0.
- Sends one `BillReminder` notification per member.
- No sent-stamp needed: the schedule is date-based and each run is a distinct
  intentional touch. A member with an unpaid bill on both dates gets both —
  that is the chosen "twice a month" pressure.

**Notification:** `App\Notifications\BillReminder`

- Channels: `withApn($notifiable, ['database', 'mail'])`, synchronous.
- Content: total outstanding (`MYR x,xxx.xx`), count of unpaid bills, and a
  link to the member's bills (the account panel dashboard, where
  `MyBillsStats` / `MyBillsTable` live).
- Constructor receives the precomputed outstanding amount + unpaid count so
  the notification stays a dumb renderer.

**Audience note:** unclaimed members (no password yet) are included — they
still owe dues and mail still reaches them; push/bell simply won't fire until
they claim their account.

## 2. Lunch-voting nudges

**Schedule:** daily at 09:00 —
`Schedule::command('app:send-lunch-vote-nudges')->dailyAt('09:00')`.

**Command:** `app:send-lunch-vote-nudges` (`App\Console\Commands\SendLunchVoteNudges`)

- Finds lunches with `status = 'published'`, `vote_nudge_sent_at` null, and
  `deadline` between now and now + 1 day (same window/guard shape as
  `SendActivityReminders`; matches the votability rule on the `Lunch` model).
- For each, notifies every user who has **no** `LunchResponse` for that lunch
  (same all-users audience as `LunchPublished`, minus responders).
- Stamps `vote_nudge_sent_at = now()` so re-runs never double-send.

**Migration:** add nullable `timestamp vote_nudge_sent_at` to `lunches`.

**Notification:** `App\Notifications\LunchVoteNudge`

- Channels: `withApn($notifiable, ['database', 'mail'])`, synchronous.
- Content: meal name, deadline ("Voting closes at g:i A, D j M"), link to the
  *My Lunch* page. Mirrors `LunchPublished`'s wording/structure.

## Bundled refactor: shared outstanding-balance helper

The outstanding-balance math currently lives inside `MyBillsStats`
(`max(0, amount_due − allocations_sum_amount_allocated)` summed over
`FeePeriod::forMember(...)`). Extract it so the widget and the new command
share one definition:

- Add `FinanceService::outstandingFor(User $user): float` (and
  `unpaidBillCountFor(User $user): int`, or return a small value object with
  both) using the same `FeePeriod::forMember()` query.
- `MyBillsStats` delegates to it; `SendBillReminders` uses it.
- No behavior change intended for the widget.

## Error handling

- Per-member send failures (e.g. a bad APNs token) must not abort the run:
  wrap each `notify()` in try/catch, `report()` the exception, continue.
  (APNs channel failures otherwise throw and would skip remaining members.)
- Commands print a summary count like the existing commands.

## Testing

PHPUnit feature tests mirroring the existing command tests, using
`Notification::fake()`:

- **Bill reminders:** member with outstanding balance gets notified; settled
  member and admin do not; amount/count passed to the notification are
  correct (partial allocation case).
- **Lunch nudges:** non-responder notified, responder not; lunch outside the
  24h window not nudged; stamped lunch not re-nudged; stamp is written.
- **FinanceService helper:** outstanding math matches previous widget
  behavior (partial payments, overpayment clamped to 0).

Note the standing caveat: tests run on SQLite, production is Postgres —
date-window queries should use portable Eloquent builders (as
`SendActivityReminders` does).

## Out of scope

- Per-member opt-out preferences.
- Escalation tiers / overdue aging.
- Folding the nudge into the every-15-minutes `app:close-lunch-orders`
  command (rejected: mixes responsibilities and puts notification fan-out in
  a high-frequency job with no queue worker).
