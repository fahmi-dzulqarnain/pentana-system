# Activity Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Full event-ops Activity Management — admins publish activities; members register with capacity + auto-waitlist; everyone is notified; reminders fire a day before; admins check in attendees.

**Architecture:** Reuse the Lunch Planner shape. An `ActivityRegistrationService` owns all registration/waitlist state (like `LunchVotingService`). Plain inline notifications (`database` + `mail`, no `ShouldQueue`) like `LunchPublished`. A daily scheduled command like `app:send-birthday-emails`. A member Filament `Page` like `MyLunch`. Admin stays on the existing `ActivityResource` (admin-only).

**Tech Stack:** Laravel 12, Filament 5, Livewire 4, PostgreSQL (Supabase prod) / SQLite (tests), PHPUnit, Pint, Carbon.

**Conventions / gotchas (apply throughout):**
- No queue worker → notifications are NOT `ShouldQueue`; delivered inline.
- SQLite tests vs Postgres prod → cast integer FK columns; `lockForUpdate` is a no-op on SQLite (test the *logic*, the lock protects prod only).
- Livewire public props are tamperable → member actions resolve the member from `auth()->user()`, never a client-passed user id (record id params are fine).
- Member-facing pages use nav group `'Welfare & Social'` (like `MyLunch`); admin `ActivityResource` keeps group `'Activity'`.
- `User::isActivated()` (returns `activated_at !== null`) identifies real member accounts.

---

### Task ACT-1: Data model (schema, models, factories)

**Files:**
- Create: `database/migrations/2026_06_25_000001_add_event_fields_to_activities_table.php`
- Create: `database/migrations/2026_06_25_000002_create_activity_registrations_table.php`
- Modify: `app/Models/Activity.php`
- Create: `app/Models/ActivityRegistration.php`
- Create: `database/factories/ActivityFactory.php`, `database/factories/ActivityRegistrationFactory.php`
- Test: `tests/Feature/Activity/ActivityModelTest.php`

**Build:**
- Migration 1 — add to `activities`: `status` string default `'draft'`; `capacity` unsignedInteger nullable; `registration_closes_at` dateTime nullable; `ends_at` dateTime nullable; `reminder_sent_at` timestamp nullable.
- Migration 2 — `activity_registrations`: `id`; `foreignId('activity_id')->constrained()->cascadeOnDelete()`; `foreignId('user_id')->constrained()->cascadeOnDelete()`; `string('status')` (`registered`/`waitlisted`/`cancelled`); `timestamp('registered_at')->nullable()`; `boolean('attended')->default(false)`; timestamps; `unique(['activity_id','user_id'])`.
- `Activity` model: add `HasFactory`; `$fillable` += `status, capacity, registration_closes_at, ends_at, reminder_sent_at`; casts `starts_at/ends_at/registration_closes_at/reminder_sent_at => datetime`, `capacity => integer`; `registrations(): HasMany`. Helper methods:
  - `registeredCount(): int` / `waitlistCount(): int` / `attendedCount(): int` (count `registrations` by status / attended).
  - `registrationClosesAt(): Carbon` → `registration_closes_at ?? starts_at`.
  - `spotsLeft(): ?int` → `capacity === null ? null : max(0, capacity - registeredCount())`.
  - `isFull(): bool` → `capacity !== null && registeredCount() >= capacity`.
  - `isPast(): bool` → `now()->gte($this->starts_at)`.
  - `isOpenForRegistration(): bool` → `status === 'published' && now()->lt($this->registrationClosesAt())`.
- `ActivityRegistration` model: `HasFactory`; `$fillable = ['activity_id','user_id','status','registered_at','attended']`; casts `registered_at => datetime`, `attended => boolean`, `activity_id => integer`, `user_id => integer`; `activity(): BelongsTo`, `user(): BelongsTo`.
- Factories: `ActivityFactory` (title, paragraph description, `starts_at` future, nullable location/capacity, status default `draft`); `ActivityRegistrationFactory` (status `registered`, `registered_at` now, attended false).

**Tests** (TDD — write failing first):
- `spotsLeft` null when capacity null; `= capacity - registeredCount` and floors at 0 when over.
- `isFull` true only when capacity set and registered ≥ capacity (waitlisted/cancelled don't count).
- `isOpenForRegistration`: false when draft; true when published & before close; false once `registrationClosesAt` passed; `registration_closes_at` overrides `starts_at`.
- `isPast` flips at `starts_at`.

**Commit:** `feat(activity): event fields + registrations schema, models, factories`

---

### Task ACT-2: ActivityRegistrationService (TDD — core logic)

**Files:**
- Create: `app/Services/ActivityRegistrationService.php`
- Test: `tests/Feature/Activity/ActivityRegistrationServiceTest.php`

**Build** — the only writer of registration state. Methods:
- `register(Activity $activity, User $user): ActivityRegistration`
  - Guard: throw/return early if `!$activity->isOpenForRegistration()`. (Define a small `RegistrationException` or return the existing row unchanged for idempotency — prefer: if already `registered`/`waitlisted`, return it unchanged.)
  - Wrap in `DB::transaction`; re-fetch `Activity::lockForUpdate()` inside to serialize the seat decision.
  - New status = `isFull()` ? `waitlisted` : `registered`. `updateOrCreate(['activity_id','user_id'], ['status'=>…, 'registered_at'=>now()])`.
- `cancel(Activity $activity, User $user): void`
  - In a locked transaction: if the user's row is `registered`, set `cancelled` then **promote**; if `waitlisted`, just set `cancelled`; if none/`cancelled`, no-op.
- `promoteWaitlist(Activity $activity): void` (internal, called inside the lock)
  - While `!$activity->isFull()` and a `waitlisted` row exists: take earliest by `registered_at`, set `registered`, dispatch `WaitlistPromoted` (added in ACT-3 — leave a `// notify` seam or add after ACT-3; sequence ACT-3 before wiring the notification call, or stub and fill). Stop when full or none left.
- `reconcileWaitlist(Activity $activity): void` — public entry used after capacity edits; just calls the promotion loop in a locked transaction.

**Tests** (use real models, `RefreshDatabase`):
- register into a published activity with free seats → `registered`.
- register when full → `waitlisted`; `registered_at` ordered.
- cancel a registered member → their row `cancelled` AND earliest waitlisted promoted to `registered`.
- cancel a waitlisted member → no promotion.
- register blocked when draft / past / closed (row not created / unchanged).
- double register & double cancel are idempotent (no dup rows, unique respected).
- re-register after cancel → status back to registered/waitlisted, `registered_at` refreshed (back of queue).
- `reconcileWaitlist` after raising capacity promotes the right number in order.

**Commit:** `feat(activity): registration + waitlist service (capacity, auto-promote, locking)`

---

### Task ACT-3: Notifications (TDD)

**Files:**
- Create: `app/Notifications/ActivityPublished.php`, `WaitlistPromoted.php`, `ActivityReminder.php`, `ActivityCancelled.php`
- Modify: `app/Services/ActivityRegistrationService.php` (dispatch `WaitlistPromoted` in `promoteWaitlist`)
- Test: `tests/Feature/Activity/ActivityNotificationTest.php`

**Build** — copy the `LunchPublished` shape exactly: plain `Notification` (no `ShouldQueue`), `via() = ['database','mail']`, `toMail()` (MailMessage with subject/greeting/lines/action), `toDatabase()` (Filament `Notification::make()->...->getDatabaseMessage()`). Constructor takes the `Activity` (+ nothing else). Bell `Action` + mail `action()` link to `MyActivities::getUrl(panel: 'account')` (page created in ACT-5; until then link to `'/account'` and switch in ACT-5, OR build ACT-5 first — implementer may reorder, but keep the import correct at the end).
- `ActivityPublished`: "New activity: {title}", body date · location.
- `WaitlistPromoted`: "You're in: {title}", body "A spot opened up…".
- `ActivityReminder`: "Reminder: {title}", body when/where.
- `ActivityCancelled`: "Activity cancelled: {title}", body date.
- In `promoteWaitlist`, after promoting a row: `$promoted->user->notify(new WaitlistPromoted($activity))`.

**Tests** (`Notification::fake`):
- promoting via `cancel()` sends `WaitlistPromoted` to exactly the promoted user.
- each notification exposes `database` + `mail` channels and renders a non-empty bell title (sanity).

**Commit:** `feat(activity): published/promoted/reminder/cancelled notifications (inline)`

---

### Task ACT-4: Admin resource — form, publish/cancel actions, registrations + check-in

**Files:**
- Modify: `app/Filament/Resources/Activities/Schemas/ActivityForm.php`
- Modify: `app/Filament/Resources/Activities/Tables/ActivitiesTable.php`
- Modify: `app/Filament/Resources/Activities/ActivityResource.php` (register relation manager)
- Create: `app/Filament/Resources/Activities/RelationManagers/RegistrationsRelationManager.php`
- Modify: `app/Filament/Resources/Activities/Pages/EditActivity.php` (header actions: Publish, Cancel)
- Test: `tests/Feature/Activity/ActivityAdminTest.php`

**Build:**
- Form: `title` (required), `description` (Textarea), `starts_at` + `ends_at` + `registration_closes_at` (DateTimePicker `native(false)`, `seconds(false)`; close helper "defaults to the start time"), `location`, `capacity` (TextInput `numeric()` `minValue(1)`, helper "blank = unlimited"). Show `status` as a read-only `Placeholder`/badge (driven by actions).
- Table: columns title, `starts_at` (dateTime), `status` (badge), registered count (`registeredCount()` via `state`), capacity. Filter by status.
- `EditActivity` header actions:
  - **Publish** — visible when `status==='draft'` and `starts_at` future. Sets `status='published'`; notifies all activated members: `User::whereNotNull('activated_at')->each->notify(new ActivityPublished($record))` (chunk for safety). Success notification.
  - **Cancel** — visible when `status` in `['draft','published']` and `!isPast()`. Confirmation required. Sets `status='cancelled'`; if it had been `published`, notify non-cancelled registrants with `ActivityCancelled`.
- `RegistrationsRelationManager` (relationship `registrations`): columns `user.name`, `status` (badge), `registered_at`, **`attended`** as `ToggleColumn` (the check-in). Header text/summary: registered/capacity, waitlist, attended counts. Row action: **Remove** → `ActivityRegistrationService::cancel($activity, $row->user)` (triggers promotion). No create form (members self-register); optionally an "Add member" action calling `register()`.

**Tests** (Filament testing + `Notification::fake`):
- Publish action sets status published and sends `ActivityPublished` to activated members (not to unactivated).
- Publish hidden once published / for past activities.
- Cancel sets cancelled and notifies registrants (only when previously published).
- Toggling `attended` persists on the registration.
- Resource access: admin yes, non-admin `canAccess()` false.

**Commit:** `feat(activity): admin publish/cancel + registrations check-in panel`

---

### Task ACT-5: Member "Activities" page (register / cancel)

**Files:**
- Create: `app/Filament/Pages/MyActivities.php`
- Create: `resources/views/filament/pages/my-activities.blade.php`
- Modify the four notifications to link to `MyActivities::getUrl(panel: 'account')` (if ACT-3 stubbed `/account`).
- Test: `tests/Feature/Activity/MyActivitiesPageTest.php`

**Build** — mirror `MyLunch` (extends `Filament\Pages\Page`, nav group `'Welfare & Social'`, icon e.g. `Heroicon::Sparkles`, label "My Activities", view `filament.pages.my-activities`):
- `upcomingActivities(): Collection` — `published`, `starts_at >= now`, not cancelled, eager-load the current user's registration.
- `pastActivities(): Collection` — `starts_at < now` (any status) where the current user has a non-cancelled registration; expose whether `attended`.
- `register(int $activityId): void` — load Activity; `app(ActivityRegistrationService::class)->register($activity, auth()->user())`; Filament success/warn notification reflecting registered vs waitlisted; guard closed/past via the service.
- `cancel(int $activityId): void` — `->cancel($activity, auth()->user())`; success notification.
- **Security:** always `auth()->user()`; never trust a passed user id. Re-check `isOpenForRegistration()` server-side (the service does).
- Blade: Upcoming cards (date/time, location, spots-left or "Full — waitlist", member status badge "Registered" / "Waitlisted — #N", Register/Cancel buttons with `wire:loading`); Past section with **Attended** badge. Waitlist position = count of waitlisted rows with earlier `registered_at` + 1.

**Tests** (Livewire/Filament page test, acting as a member):
- register on an open activity → row `registered`, success.
- register when full → `waitlisted`.
- cancel → row cancelled (and promotion happens if applicable).
- register on draft/past/closed → rejected (no row / unchanged), warn message.
- page lists only published upcoming + the member's past; requires auth.

**Commit:** `feat(activity): member Activities page with register/cancel + waitlist status`

---

### Task ACT-6: Dashboard widget (next registered activity)

**Files:**
- Create: `app/Filament/Widgets/MyNextActivity.php` (+ view if needed)
- Modify: `app/Providers/Filament/AccountPanelProvider.php` (register the widget) — confirm how `MyBills*`/lunch widgets are registered and follow it.
- Test: `tests/Feature/Activity/MyNextActivityWidgetTest.php`

**Build:** a compact widget showing the member's next upcoming `registered` activity (`starts_at` asc, future), with date/location and a link to `MyActivities`. Empty state ("No upcoming activities") links to the page. Follow the existing account-panel widget registration pattern.

**Tests:** shows the soonest upcoming registered activity for the user; ignores waitlisted/cancelled/past/other-users'; empty state when none.

**Commit:** `feat(activity): dashboard widget for next registered activity`

---

### Task ACT-7: Reminder command + schedule

**Files:**
- Create: `app/Console/Commands/SendActivityReminders.php`
- Modify: `routes/console.php` (add `Schedule::command('app:send-activity-reminders')->dailyAt('09:00');`)
- Test: `tests/Feature/Activity/SendActivityRemindersTest.php`

**Build** (mirror `SendBirthdayEmails`): signature `app:send-activity-reminders`. Find `Activity::where('status','published')->whereNull('reminder_sent_at')->whereBetween('starts_at',[now(), now()->addDay()])`. For each: notify every `registered` member (`$activity->registrations()->where('status','registered')->with('user')` → `$reg->user->notify(new ActivityReminder($activity))`), then `update(['reminder_sent_at'=>now()])`. Inline (no queue).

**Tests** (`Notification::fake`):
- notifies only `registered` members of an activity starting within 24h; not waitlisted/cancelled.
- stamps `reminder_sent_at`; a second run the same day sends nothing.
- ignores draft, already-reminded, and far-future activities.

**Commit:** `feat(activity): daily reminder command (~1 day before) + schedule`

---

### Task ACT-8: Verify suite, migrate Supabase, smoke, finalize

**Steps:**
- [ ] `vendor/bin/pint --dirty` then full `php artisan test` — all green.
- [ ] Review the two new migrations; **confirm with the user** before running against Supabase (live prod creds). Then `php artisan migrate` (additive only). Per the sqlite/Postgres lesson, sanity-check the registrations table + a register/cancel cycle on Postgres.
- [ ] Smoke: `/account` (My Activities page + widget render), admin Activity resource (create → publish → registrations panel → toggle attended → cancel). `curl` 200s + a manual browser pass by the user.
- [ ] Final code-review subagent over the whole feature.
- [ ] Use `superpowers:finishing-a-development-branch` (work is on `main`).

**Commit:** (any fix-ups) `chore(activity): verify suite + finalize`
