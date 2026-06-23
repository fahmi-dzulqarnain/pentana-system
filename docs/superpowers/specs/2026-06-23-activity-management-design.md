# Activity Management — Design Spec

**Date:** 2026-06-23
**Status:** Approved (pending spec review)

## Goal

Turn the bare admin-only `Activity` CRUD into a full event-operations feature: admins
publish activities, members register (with capacity + waitlist), everyone is notified,
reminders go out before the event, and admins check in who actually attended.

This deliberately reuses the **Lunch Planner** machinery (publish → notify → respond →
scheduled command → admin tally), since an activity RSVP flow is structurally identical.

## Scope

**In scope (chosen "full event ops"):**

- Optional per-activity capacity with an auto-promoting waitlist.
- Member register / cancel with live status (registered / waitlisted #N / spots left).
- Draft → published → cancelled lifecycle; publish & cancel notify members.
- Admin "ticks the list" attendance check-in.
- In-app (bell) notifications now; email wired but logged until SMTP is configured.
- Daily reminder ~1 day before, via a scheduled command.

**Out of scope (can add later):** fees/payments, category-targeted invites, self/QR
check-in, recurring activities, photo recaps.

**Eligibility:** every activated member account (users with `activated_at` set) can see
and register for every published activity. No targeting by member category.

## Data model

### `activities` (extend the existing table)

The existing columns stay: `title`, `description` (nullable text), `starts_at`
(datetime), `location` (nullable). Add via a new migration:

| Column | Type | Notes |
| --- | --- | --- |
| `status` | string, default `'draft'` | `draft` / `published` / `cancelled` |
| `capacity` | unsigned integer, nullable | `null` = unlimited |
| `registration_closes_at` | datetime, nullable | `null` ⇒ registration closes at `starts_at` |
| `ends_at` | datetime, nullable | optional end time (display only) |
| `reminder_sent_at` | timestamp, nullable | guards against double-reminding |

Casts: `starts_at`, `ends_at`, `registration_closes_at`, `reminder_sent_at` → `datetime`;
`capacity` → `integer`.

### `activity_registrations` (new — mirrors `lunch_responses`)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | id | |
| `activity_id` | FK → activities, cascade on delete | cast `integer` |
| `user_id` | FK → users, cascade on delete | cast `integer` |
| `status` | string | `registered` / `waitlisted` / `cancelled` |
| `registered_at` | timestamp | set when the row (re)joins the queue; orders the waitlist |
| `attended` | boolean, default `false` | the admin check-in flag; cast `boolean` |
| timestamps | | |

- **Unique** on (`activity_id`, `user_id`) — one row per member per activity; `status`
  flips between values rather than creating duplicate rows.
- **FK / migration ordering:** the registrations migration must sort *after*
  `2026_06_22_100003_create_activities_table` (new timestamps already do) so
  `migrate:fresh` succeeds on Postgres. Cast the integer FK columns to avoid the
  string-vs-int strict-comparison bug we hit with `meal_option_id`.

### Relationships & model helpers

- `Activity hasMany ActivityRegistration`; `ActivityRegistration belongsTo Activity, User`.
- `Activity` helpers (mirroring `Lunch`):
  - `registeredCount()` / `waitlistCount()` / `attendedCount()`
  - `spotsLeft()` → `null` when unlimited, else `max(0, capacity - registeredCount())`
  - `isFull()` → `capacity !== null && registeredCount() >= capacity`
  - `registrationClosesAt()` → `registration_closes_at ?? starts_at`
  - `isOpenForRegistration()` → `status === 'published' && now()->lt(registrationClosesAt())`
  - `isPast()` → `now()->gte(starts_at)`

## Domain rules & lifecycle

**Lifecycle:** `draft` → `published` → (becomes "past" once `starts_at` passes). Admin may
`cancel` from draft or published at any time before it starts.

**Register** (a member taking a spot):

1. Allowed only when `isOpenForRegistration()` and not already `registered`/`waitlisted`.
2. If `!isFull()` → `status = 'registered'`; else → `status = 'waitlisted'`.
3. `registered_at = now()` (back of the queue on re-register).

**Cancel** (a member giving up a spot):

1. Set their row `status = 'cancelled'`.
2. If they had been `registered` and a waitlist exists → **auto-promote** the earliest
   `waitlisted` row (min `registered_at`) to `registered` and send `WaitlistPromoted`.

**Capacity changes (admin edits):**

- Raising capacity promotes as many earliest-waitlisted rows as new seats allow (each gets
  `WaitlistPromoted`).
- Lowering capacity never kicks anyone already registered; `spotsLeft()` just floors at 0.

**Last-seat race:** register / cancel / promote run inside a DB transaction that locks the
activity row (`lockForUpdate`) so two simultaneous registrations can't both claim the final
seat. `lockForUpdate` is a no-op on SQLite (tests) but enforced on Postgres (prod) — per the
sqlite-vs-Postgres lesson, the *logic* is unit-tested and the lock is verified to exist.

**Check-in:** admin toggles `attended` per registrant, around/after the event.

All of this lives in an **`ActivityRegistrationService`** (TDD'd), the way
`LunchVotingService` owns the lunch rules. The service is the only writer of registration
state. **Security:** member-facing register/cancel act on `auth()->id()` only — never a
user id supplied by the client (Livewire public props are tamperable).

## Admin experience (extends `ActivityResource`, stays `is_admin`-only)

- **Form** gains: `capacity` (numeric, blank = unlimited), `ends_at`, `registration_closes_at`
  (helper: "defaults to the start time"), `location`. `status` is shown read-only (a badge);
  it is driven by the actions below, not edited directly. Datetime pickers use `native(false)`.
- **Publish** action — visible while `draft`, only if `starts_at` is in the future. Sets
  `status = 'published'` and sends `ActivityPublished` to all activated members. Mirrors the
  Lunch publish action.
- **Cancel** action — visible while `draft`/`published` and not past. Sets
  `status = 'cancelled'`; if it had been published, sends `ActivityCancelled` to all
  non-cancelled registrants.
- **Registrations relation manager** on the edit page:
  - Columns: member name, `status` (badge), `registered_at`, **`attended`** (toggle column = the check-in).
  - Header summary: `registered / capacity`, waitlist count, attended count.
  - Row action: remove a registrant (sets `cancelled`, triggers promotion).

## Member experience (account panel)

- **"Activities" page** — `App\Filament\Pages\MyActivities`, mirroring `MyLunch`:
  - **Upcoming**: published, not-past activities. Each shows date/time, location, spots left,
    the member's status ("Register" button, or "Registered" / "Waitlisted — #2" with a Cancel
    button). All guards re-checked server-side in the service.
  - **Past**: activities whose `starts_at` has passed where the member had a registration —
    shows an **Attended** badge when `attended` is true.
- **Dashboard widget** — the member's next upcoming *registered* activity (compact card);
  empty state links to the Activities page.

## Notifications

All are plain `Notification` classes (NOT `ShouldQueue`) with `via() = ['database', 'mail']`,
implementing `toDatabase()` (Filament bell message) and `toMail()` (MailMessage) — exactly
like `LunchPublished`. Delivered **inline** (no queue worker exists); email lands in the log
until SMTP is configured. The `notifications.data` column is already `json` (bell works on
Postgres).

| Notification | Trigger | Recipients |
| --- | --- | --- |
| `ActivityPublished` | Publish action | all activated members |
| `WaitlistPromoted` | A seat frees / capacity raised | the promoted member |
| `ActivityReminder` | Scheduled command, ~1 day before | members with `status = registered` |
| `ActivityCancelled` | Cancel action (if was published) | all non-cancelled registrants |

Each bell message links to the `MyActivities` page (`getUrl(panel: 'account')`).

## Scheduled command

`app:send-activity-reminders` (new), wired in `routes/console.php` via `Schedule::command(...)`
daily (e.g. `dailyAt('09:00')`), alongside the existing fee/lunch/birthday schedules:

- Find `published` activities with `reminder_sent_at` null and `starts_at` within the next
  ~24h (`now() < starts_at <= now()+1 day`).
- Notify every `registered` member, then stamp `reminder_sent_at = now()` so a re-run that
  day doesn't double-send (same guard pattern as `app:send-birthday-emails`).

## Error handling & edge cases

- Register/cancel on a draft, cancelled, past, or closed activity → rejected by the service
  (no-op + friendly message on the member page).
- Double register / double cancel → idempotent (no duplicate rows; status already set).
- Cancel by a non-registered member → no-op.
- Promotion when the waitlist is empty → nothing happens.
- Activity deleted → registrations cascade-delete.

## Testing strategy

- **Service (`ActivityRegistrationService`)**: seat fills; overflow → waitlist; cancel
  promotes the earliest waitlister and notifies them; raising capacity promotes in order;
  register blocked when draft/cancelled/past/closed; re-register is idempotent and re-queues.
- **Notifications** (`Notification::fake`): publish notifies all members; cancel notifies
  registrants; reminder command notifies only registered members, stamps `reminder_sent_at`,
  and a second same-day run sends nothing.
- **Access**: `ActivityResource` is admin-only; `MyActivities` requires an authenticated
  member; register/cancel use `auth()->id()`.
- **Filament**: publish action sets status + fires the notification; the attended toggle
  flips `attended`.
- Tests run on SQLite; capacity/locking logic is asserted at the service level and the
  `lockForUpdate` path is verified to exist (real enforcement is Postgres-only).

## Existing patterns to reuse (reference files)

- `app/Models/Lunch.php` — `status` column + count/`isOpenForVoting` helper shape.
- `app/Services/LunchVotingService.php` — service-owns-the-rules pattern.
- `app/Notifications/LunchPublished.php` — inline `database` + `mail` notification shape.
- `routes/console.php` — `Schedule::command(...)` registration.
- `app/Console/Commands/SendBirthdayEmails.php` — "find due, notify, stamp sent" command.
- `app/Filament/Pages/MyLunch.php` + `app/Filament/Resources/Lunches/*` — member page + admin
  publish/tally.
