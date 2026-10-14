# Email send cap — design

**Date:** 2026-10-14
**Status:** approved, ready for implementation planning

## Problem

PENTANA sends all member email through Resend's free tier: **100 emails/day, 3,000/month**.
Exceeding either limit means Resend rejects the message and it is lost — there is no retry,
and the only evidence is a log line nobody reads.

At ~150 members the daily cap is the binding constraint. Two senders exceed it in a single
burst:

- **Bill reminders** — 15th and last day of month, to every member with an outstanding
  balance. Up to 150 messages in one run.
- **Lunch and activity publishes** — `Notification::send(User::all(), …)`, so 150 messages
  from one admin click.

The monthly ceiling has headroom at the current cadence (weekly lunches ≈ 1,500/month
against 3,000) but no margin for a change in rhythm, so it must be visible rather than
assumed.

## Goal

No email is ever lost to a rate limit. Sends that do not fit today are deferred and
delivered as capacity frees, in an order that respects urgency. Admins can see the budget
and are warned before a limit is hit, not after.

## Non-goals

- Per-member email preferences or digesting
- Replacing Resend or changing tier
- Queueing the database and push channels — the in-app bell and APNs push are unlimited
  and must keep firing immediately
- Introducing a queue worker. This deployment deliberately has none — notifications are
  not `ShouldQueue` and nothing runs `queue:work`. The scheduler is the only background
  execution available.

## Constraints

- Laravel 12, Filament 5, PHP 8.4; PostgreSQL (Supabase) in production, SQLite in tests
- **No queue worker.** Notifications are delivered inline, synchronously
- `php artisan schedule:run` runs every minute — the only background execution available
- Day boundary is **UTC**, matching `APP_TIMEZONE` and Resend's quota reset

## Design

### Interception: a budgeted mail transport

A `BudgetedTransport` decorator wraps the transport `MAIL_MAILER` resolves to, registered
via `Mail::extend()`. Every outbound message passes through Laravel's mailer, so this single
chokepoint covers notifications, `Mail::raw`, password resets and anything Filament sends.

Senders are untouched. `SendBillReminders` keeps calling `$user->notify()` and never learns
a cap exists. Because only the mail transport is involved, the `database` and APNs channels
are unaffected and still deliver instantly.

Rejected alternatives:

- **`MessageSending` event listener** — cancelling a send by returning `false` reports
  success to the caller through a side channel; the semantics are easy to misread later.
- **A custom notification channel** — only covers notifications. Password resets and any
  future direct `Mail::` call would bypass the cap silently.
- **Laravel's database queue drained by the scheduler** — reverses the deliberate
  inline-delivery decision, delays bell and push along with email, and rate limiting
  without Redis is awkward.

### Data model

`email_budget` — one row per UTC date:

```
date        date, primary key
sent_count  integer, default 0
timestamps
```

`queued_emails` — the spool:

```
id          bigint
mime        longtext   raw RFC-5322, exactly as the transport would have sent it
recipients  json       for the admin view
subject     string     for the admin view
priority    smallint   default 50, lower sends first
attempts    smallint   default 0
last_error  text       nullable
failed_at   timestamp  nullable, set after 3 attempts
sent_at     timestamp  nullable
timestamps
```

**Raw MIME, never a re-render.** Queued messages store `$message->toString()` and are sent
back byte-for-byte. A bill reminder queued today says what it said today — draining must not
re-run Blade against a bill that has since been paid, or a lunch whose deadline has passed.

### Claiming a slot

`EmailBudget::claim()` opens a transaction, takes `lockForUpdate()` on today's row, and
grants only when **both** ceilings allow it:

- today's `sent_count` < 99
- the sum of `sent_count` for the current month < 3,000

Both live in one place because a monthly overrun cannot be fixed by waiting until tomorrow.
When the month is exhausted the system stops sending and shows red; mail accumulates until
the month rolls over.

`lockForUpdate()` is used rather than Postgres's `UPDATE … RETURNING` so the contended path
is the same code in both environments. `RETURNING` would be tidier but is not exercised by a
SQLite suite, and the guard against double-sending should not be the one thing tests cannot
reach. See *Testing* for the limitation this leaves.

### Send path

For each outbound message the transport does one of three things:

1. **Slot granted, send succeeds** — normal path, the spool is untouched.
2. **No slot** — the message is spooled and the call returns. The caller sees success, which
   is honest: the mail is accepted, just not delivered this minute.
3. **Slot granted, send throws** — the slot is released (Resend did not accept it, so it must
   not count), the message is spooled with `attempts = 1` and the error recorded, `report($e)`
   fires, and **the exception does not propagate**.

Case 3 is a deliberate behaviour change. Today a mail failure throws inside the request: an
unverified-domain `550` would take down lunch publishing entirely. Swallowing it degrades a
Resend outage to "mail is late" rather than "admins cannot publish". The failure stays
visible through the widget and the log instead of a 500 page.

### Draining

`app:drain-email-queue`, scheduled `everyMinute()->withoutOverlapping()`.

Each run takes up to **25** messages ordered by `priority` ascending then `created_at`
ascending, claiming a slot per message and sending the stored MIME verbatim. It stops early
when the budget refuses. The 25/run cap keeps the command short enough never to collide with
the next minute's tick; a full 99-message day drains in four minutes of wall clock.

On success `sent_at` is stamped. On failure `attempts` increments, `last_error` is recorded
and the slot is released; at 3 attempts `failed_at` is stamped and the message is retried no
further — a permanently bad recipient must not consume a daily slot forever.

### Priority

Carried on an `X-Pentana-Priority` header set in each notification's `toMail()` via
`withSymfonyMessage()`. Absent header defaults to 50.

| Priority | Senders | Rationale |
|---|---|---|
| 10 | lunch publish, activity publish, lunch vote nudge | deadline-bound, worthless if late |
| 20 | activity reminders | time-bound |
| 50 | default | |
| 80 | bill reminders | the 150-message spike, no deadline |

A lunch published on the 15th therefore jumps ahead of the bill backlog rather than arriving
after its own voting deadline.

### Admin visibility: `EmailBudgetStats`

A `StatsOverviewWidget` on the account panel dashboard, gated with a
`canView(): bool` returning `auth()->user()?->is_admin === true` — consistent with `MyBillsStats`,
`MyBillsTable` and `MyNextActivity`. There is no `super_admin` concept in this codebase and
this feature does not introduce one.

Three stats:

**Today** — `47 / 99`, description "52 remaining today".
Grey below 70%; **amber at ≥70%**; **red at ≥90%**, reading "cap reached — mail is queued" at
the cap. Reaching the cap is informational, not an error: queueing is the system working.

**This month** — `1,284 / 3,000`, description "43% used · projected 2,340".
Projection is `used ÷ days_elapsed × days_in_month`. Grey when the projection is under
3,000; **amber when projected to exceed**, reading "projected 3,400 — over limit"; **red once
actually over**. The projection warns while there is still time to act.

**Queued** — `51`, description "oldest 3 hours ago".
Grey while draining normally, including a large backlog immediately after a bill run.
**Red when the oldest pending message is over 24 hours old**, reading "oldest 2 days — not
draining".

That third red state is the one that matters. Being near a limit is normal and
self-correcting. A backlog older than a day means average inflow has exceeded 99/day and the
queue is compounding — the design's core assumption has broken. It must look alarming.

A backlog is deliberately **not** warned on by age alone, only by failure to drain. Colouring
a healthy 51-message queue amber would train admins to ignore the one state worth acting on.

## Testing

- Under budget, the message reaches the inner transport and nothing is spooled
- At budget, it is spooled and the inner transport is never called
- The 99th claim succeeds; the 100th is refused
- Drain respects priority then age, stops when the budget refuses, leaves the remainder queued
- A transport throw releases the slot, records the error, and does not propagate
- Three failures set `failed_at`; the message is not retried again
- Queued MIME is byte-identical when it finally sends
- Day rollover grants a fresh budget and the backlog drains
- Month at 3,000 refuses even with daily headroom remaining
- Widget thresholds flip grey → amber → red at the boundaries; `canView()` is false for
  non-admins
- End-to-end: `app:send-bill-reminders` with 150 members sends 99 and queues 51

### Known limitation

Tests run on SQLite, where `lockForUpdate()` is silently a no-op. The suite therefore
verifies the *logic* of the budget but not its behaviour under concurrency — precisely the
property the lock exists to provide. Tests pass whether or not the lock works.

Because a lunch publish fires 150 sends in one request while the drain may be running, this
must be **verified once manually against Supabase**: trigger a publish and a drain
simultaneously and confirm `sent_count` never exceeds 99. This mirrors the standing caveat
in `README.md` — DB-specific behaviour should be checked against Postgres rather than
trusted to the SQLite suite.

## Consequences

- A mail outage becomes "mail is late" rather than "admins cannot publish" — more robust,
  but failures are no longer loud at the call site and depend on the widget being seen
- Email is no longer guaranteed to be instantaneous. A member may receive a bill reminder the
  day after it was generated
- The spool grows without bound if average inflow exceeds 99/day. This is detected and shown,
  not prevented. If lunches ever move from weekly to daily, no spool design helps and the
  answer is a paid tier or not emailing every member on every publish
