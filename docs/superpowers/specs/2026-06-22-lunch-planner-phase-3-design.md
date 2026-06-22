# Lunch Planner — Phase 3: Voting & Notifications

**Status:** Approved 2026-06-22
**Builds on:** Phase 1 (catalog) and Phase 2 (planning calendar), both merged to `main`.
**Prior specs:** `2026-06-22-lunch-planner-design.md`, `2026-06-22-lunch-planner-phase-2-planning-design.md`.

## Goal

Members cast a lunch vote — a meal option or "not attending" — for each published lunch
from inside their panel. Publishing a lunch alerts everyone (in-app bell now, real email
once SMTP is wired). A scheduled command locks lunches past their deadline. The Menu
Calendar's order counts (Avg. Orders/Day, per-day count) come alive from the real votes.

## Decisions (locked)

- **Voting UI:** a dedicated member **card-list page** ("My Lunch"), not a table+modal.
- **Alerts on publish:** in-app Filament **database notification** *and* the stubbed
  (logged) email — so members are actually alerted before SMTP exists.
- **Who votes / is counted:** **all panel users** (admins included). "Not attending" is the
  default until a user opts in.
- **Estimated Cost stays "planned"** (default counts). Avg. Orders/Day and per-day counts
  reflect **actual votes**. Planned budget and actual headcount are kept distinct.
- **"My Lunch" is visible to admins too** (since all users vote); labelled to distinguish it
  from the admin "Lunch Planner".
- **Not-attending is computed on read** — no response row is materialized at the deadline.

## Data model

### `LunchResponse` — `lunch_responses` table

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `lunch_id` | FK → `lunches`, `cascadeOnDelete` | |
| `user_id` | FK → `users`, `cascadeOnDelete` | |
| `meal_option_id` | FK → `meal_options`, **nullable**, `nullOnDelete` | `NULL` = explicitly "not attending" |
| `created_at`, `updated_at` | timestamps | `updated_at` = when the choice was last set |

- `unique(['lunch_id', 'user_id'])` — one response per user per lunch.
- Casts: none beyond defaults (`meal_option_id` stays int|null).
- Relationships: `lunch()` belongsTo, `user()` belongsTo, `mealOption()` belongsTo (nullable).

**Three states for a (lunch, user):**
- row with `meal_option_id` set → **attending**, ordering that option.
- row with `meal_option_id = NULL` → **explicitly not attending**.
- no row → **hasn't responded**; counts as not-attending (default-on-read).

## Voting rules

A new `Lunch::isOpenForVoting(): bool` — true when `status === 'published'` **and**
`now() <= deadline` (deadline is required on a published lunch). False for draft, closed,
or past-deadline.

A `LunchVotingService` (so logic is testable without Livewire):

```php
// Upsert the current user's response. Returns the LunchResponse.
// Throws a RuntimeException if the lunch is not open for voting, or if $mealOptionId is
// non-null and is not an ENABLED option on this lunch.
public function respond(Lunch $lunch, User $user, ?int $mealOptionId): LunchResponse
```

- `$mealOptionId === null` → records "not attending".
- A non-null `$mealOptionId` must match a `LunchOption` on this lunch with `enabled = true`;
  otherwise the call is rejected (guards against tampering / stale options).
- Uses `updateOrCreate(['lunch_id','user_id'], ['meal_option_id' => ...])`.
- Refuses when `! $lunch->isOpenForVoting()`.

## Member voting page — "My Lunch"

`app/Filament/Pages/MyLunch.php` + `resources/views/filament/pages/my-lunch.blade.php`.

- Navigation: group **"Welfare & Social"**, label **"My Lunch"**, icon `Heroicon::Ticket`
  (a lunch-ticket metaphor; distinct from Meals' `Cake` so admins don't see two cakes),
  visible to **all** authenticated users (`canAccess(): true`).
- Lists **upcoming published lunches** — `status = published`, `date >= today`, within the
  next 14 days — ordered by `date` ascending. (Closed/past lunches drop off the page.)
- One **card per lunch**:
  - Date heading ("Mon, 23 Jun"), menu name (`catererMeal->name`) + caterer name.
  - Deadline line: "Order by 10:00, Mon 23 Jun" while open; "Voting closed" once locked.
  - A button per **enabled** option (label = `MealOption->name`) plus a **Not attending**
    button. The user's current choice is visually highlighted.
  - When `! isOpenForVoting()`: buttons are replaced by a read-only line showing the user's
    choice (or "Not attending").
- Livewire actions on the page:
  - `choose(int $lunchId, int $mealOptionId)` and `notAttending(int $lunchId)` → resolve the
    `Lunch`, call `LunchVotingService::respond(...)` for `auth()->user()`, flash a short
    confirmation. Both re-guard server-side (open + enabled option) — never trust the click.
- Styling: inline styles + a `<style>` block with `.dark` overrides, matching the calendar
  page (Filament's precompiled CSS does not carry arbitrary Tailwind/`dark:` utilities).
  Reuse `x-filament::section` / `badge` where they help.

## Publish notification

`app/Notifications/LunchPublished.php`:

- Implements `ShouldQueue`; `via()` returns `['database', 'mail']`.
- `toMail`: subject "Lunch menu for {date}", body with the menu name + deadline + a CTA
  button/link to the My Lunch page. Mail driver is `log` → written to the log for now.
- `toDatabase` / `toArray`: `title`, `body`, and an action `url` to the My Lunch page; renders
  in Filament's notification bell.

Wiring:
- Add Laravel's **notifications table** migration (`notifications` — uuid id, notifiable
  morph, type, data json, read_at, timestamps).
- Enable `->databaseNotifications()` on `AccountPanelProvider`.
- Fire from the **existing Publish action** (in `LunchResource` / `EditLunch`) **only on a
  genuine `draft → published` transition**, sending to `User::all()` via
  `Notification::send($users, new LunchPublished($lunch))`. `User` already uses `Notifiable`.

## Deadline auto-close command

`app/Console/Commands/CloseLunchOrders.php`, signature `app:close-lunch-orders`:

- Sets `status = 'closed'` on every lunch where `status = 'published'` and `deadline < now()`.
- Leaves draft, already-closed, and future-deadline published lunches untouched.
- No row materialization (not-attending is computed on read); closing simply locks voting and
  finalizes the counts the admin sees.
- Registered in `routes/console.php`:
  `Schedule::command('app:close-lunch-orders')->everyFifteenMinutes();`
  (Production needs a cron running `php artisan schedule:run`; the command is tested directly.)

## Order counts & tally

On `Lunch`:
- `attendingCount(): int` — responses with a non-null `meal_option_id`.
- `optionCounts(): array<int,int>` — `meal_option_id => count` over attending responses.
- `notAttendingCount(): int` — responses with `meal_option_id = NULL` (explicit declines).

On `LunchPlanningStats` (extends the Phase-2 service):
- `avgOrdersPerDay(Carbon $month): float` — total attending responses across the month's
  **published/closed** lunches ÷ the number of those lunches. Returns `0.0` when there are
  none (avoids dragging the average down with un-opened drafts).

Menu Calendar (`LunchCalendar.php` + blade):
- The **Avg. Orders/Day** card shows the real `avgOrdersPerDay` value (was `—`).
- Each menu card on a published/closed lunch shows a small **"N ordered"** badge
  (`attendingCount`). Draft lunches show no count.
- **Estimated Cost is unchanged** — still planned (`default_count × price`).

Admin tally:
- A read-only **"Current orders"** section on the Lunch **edit** page showing per-option
  counts (option name → count), total attending, and not-attending. Implemented with
  Filament placeholder/text components fed by `optionCounts()` / `attendingCount()` /
  `notAttendingCount()`.

## Access summary (unchanged except the new page)

- **Admins:** Lunch Planner (manage), Menu Calendar, Caterers/Meals/Meal Options, Email Auto
  Sender — **plus** "My Lunch" (they vote too).
- **Members:** "My Lunch" only, within Welfare & Social.

## Testing (TDD)

- **LunchResponse:** persists; `unique(lunch_id,user_id)` enforced; nullable `meal_option_id`;
  relationships resolve.
- **`Lunch::isOpenForVoting`:** true only for published + not-past-deadline; false for draft,
  closed, and past-deadline.
- **`LunchVotingService::respond`:** creates a row; updates an existing row (no duplicate);
  records not-attending on `null`; **rejects a non-enabled / unrelated option**; **refuses
  when the lunch is not open**.
- **My Lunch page (`Livewire::test`):** lists only upcoming published lunches; `choose`
  records the current user's option; `notAttending` records null; another user's vote is not
  affected; a past-deadline lunch's actions are guarded.
- **Publish notification (`Notification::fake`):** publishing a draft sends `LunchPublished`
  to **all** users on both `database` and `mail`; a re-publish / already-published edit does
  not re-send.
- **Close command:** closes only past-deadline published lunches; leaves draft / future /
  already-closed alone.
- **Tally math:** `attendingCount`, `optionCounts`, `notAttendingCount`, and
  `avgOrdersPerDay` (including the empty-month `0.0` case).

## Files

**Create**
- `database/migrations/<ts>_create_lunch_responses_table.php`
- `database/migrations/<ts>_create_notifications_table.php`
- `app/Models/LunchResponse.php`
- `app/Services/LunchVotingService.php`
- `app/Filament/Pages/MyLunch.php`
- `resources/views/filament/pages/my-lunch.blade.php`
- `app/Notifications/LunchPublished.php`
- `app/Console/Commands/CloseLunchOrders.php`
- Tests: `tests/Feature/Lunch/LunchResponseTest.php`,
  `LunchVotingServiceTest.php`, `MyLunchPageTest.php`,
  `LunchPublishNotificationTest.php`, `CloseLunchOrdersTest.php`,
  `LunchOrderStatsTest.php` (paths follow the existing Phase-2 test layout).

**Modify**
- `app/Models/Lunch.php` — `isOpenForVoting()`, `responses()` hasMany, `attendingCount()`,
  `optionCounts()`, `notAttendingCount()`.
- `app/Services/LunchPlanningStats.php` — `avgOrdersPerDay()`.
- `app/Providers/Filament/AccountPanelProvider.php` — `->databaseNotifications()`.
- `app/Filament/Resources/Lunches/...` (publish action) — dispatch `LunchPublished`.
- `app/Filament/Resources/Lunches/.../EditLunch.php` (or schema) — "Current orders" tally.
- `app/Filament/Pages/LunchCalendar.php` + `resources/views/filament/pages/lunch-calendar.blade.php`
  — real Avg. Orders/Day + per-day "N ordered" badge.
- `routes/console.php` — schedule `app:close-lunch-orders`.

## Out of scope (later)

Real SMTP delivery; vote-reminder emails before the deadline; per-member dietary defaults;
recurring lunch templates; the Week view. Email content and channel wiring are built now so
SMTP is a config change, not a code change.
