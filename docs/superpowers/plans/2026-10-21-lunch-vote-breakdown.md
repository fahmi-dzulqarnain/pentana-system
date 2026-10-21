# Lunch Vote Breakdown Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins see every member's lunch pick by name on the lunch edit page, can change picks by clicking a name, can send a "Remind now" to non-voters, and can print the list.

**Architecture:** A read-only `LunchVoteBreakdown` service builds one data structure that both the edit page and the print page render, so they never disagree. Mutations go through services (`LunchVotingService::setPickAsAdmin`, `LunchReminderService::remindNonResponders`) that are called from a new Livewire component (`App\Livewire\Admin\LunchVotes`). That component is embedded in the existing lunch form in place of the counts-only `orders_summary` placeholder. The print page is a plain admin-only route and Blade view.

**Tech Stack:** Laravel 12, Filament 5 (schemas `Livewire::make`), Livewire, PHPUnit on SQLite (prod is Supabase Postgres).

**Spec:** `docs/superpowers/specs/2026-10-21-lunch-vote-breakdown-design.md`

## Global Constraints

- Admin-only everywhere: `is_admin` true. A non-admin gets 403. A guest is sent to the account panel login.
- Admin pick changes are allowed only when `status === 'published'`, including after the deadline. Draft and closed lunches are read-only.
- "Remind now" is available only while `$lunch->isOpenForVoting()` and there is at least 1 non-responder. It must never set `vote_nudge_sent_at`.
- "Not responded" = `User::enabled()` members with no `lunch_responses` row for the lunch. Disabled members who did respond stay in their group with a "disabled" tag and count in the totals.
- Option groups: every **enabled** option on each menu (count 0 shows "Nobody"), plus any **disabled** option only if someone picked it.
- Names are sorted alphabetically (case-insensitive) within each group. Show `users.name`. Nicknames are not built yet. If `User` has gained a nickname accessor by the time you build this, use the same display the other admin lists use.
- No queue worker: notifications are delivered through `App\Services\MemberNotifier::sendAfterResponse()` (deferred and isolated per recipient).
- All new user-facing strings go through `__()` and get entries in `lang/ms.json` and `lang/id.json`.
- Livewire public props are tamperable. Every mutating Livewire method re-checks `auth()->user()->is_admin` and re-resolves the lunch, member and option server-side from validated ids.
- **Never run `php artisan migrate` or `artisan serve` against `.env`. It is the live production Supabase.** Tests use SQLite. Migrations reach prod through the normal deploy. For manual browser checks, follow the throwaway env-file recipe in Task 6.
- Integer FK columns need an `'integer'` cast (Postgres PDO returns strings).

## Review Focus

1. **Tampered ids in the Livewire calls:** a member id, option id or lunch id from another lunch must be rejected without saving. Covered in Task 4 (`test_tampered_option_from_another_lunch_is_rejected`).
2. **A member who responded and was later disabled:** they still appear in their option with the tag and count in totals, and are excluded from "Not responded". Covered in Task 1 (`test_disabled_responder_stays_in_group_with_flag`).
3. **A pick on an option the committee later disabled:** it still shows under that option and isn't hidden. Covered in Task 1 (`test_disabled_option_shows_only_when_picked`).
4. **Admin sets the member's current pick:** no save and no notification. Covered in Task 2 (`test_setting_same_pick_is_a_no_op`).
5. **A member edits after the deadline while an admin can:** the member path must still refuse. Covered in Task 2 (`test_member_respond_still_refused_after_deadline`).

---

## File Map

- Create `app/Services/LunchVoteBreakdown.php`: builds the grouped breakdown (Task 1).
- Modify `app/Models/Lunch.php`: `nonResponders()` query, new casts and relation (Tasks 1 and 3).
- Modify `app/Console/Commands/SendLunchVoteNudges.php`: use `Lunch::nonResponders()` (Task 1).
- Modify `app/Services/LunchVotingService.php`: add `setPickAsAdmin()` (Task 2).
- Create `app/Notifications/LunchPickSetByAdmin.php` (Task 2).
- Create `database/migrations/2026_10_21_000001_add_manual_reminder_to_lunches_table.php` (Task 3).
- Create `app/Services/LunchReminderService.php` (Task 3).
- Create `app/Livewire/Admin/LunchVotes.php` and `resources/views/livewire/admin/lunch-votes.blade.php` (Task 4).
- Modify `app/Filament/Resources/Lunches/Schemas/LunchForm.php`: replace the `orders_summary` placeholder (Task 4).
- Create `app/Http/Controllers/LunchPrintController.php` and `resources/views/lunches/print.blade.php`. Modify `routes/web.php` and `bootstrap/app.php` (Task 5).
- Modify `lang/ms.json` and `lang/id.json` (every task that adds strings; checked in Task 6).
- Tests live under `tests/Feature/Lunch/`.

---

### Task 1: Breakdown data and the shared non-responder query

**Files:**
- Create: `app/Services/LunchVoteBreakdown.php`
- Modify: `app/Models/Lunch.php`, `app/Console/Commands/SendLunchVoteNudges.php:25`
- Test: `tests/Feature/Lunch/LunchVoteBreakdownTest.php`

**Interfaces:**
- Produces: `Lunch::nonResponders(): Illuminate\Database\Eloquent\Builder` (a `User` query: `User::enabled()->whereNotIn('id', <this lunch's response user_ids>)`).
- Produces: `LunchVoteBreakdown::for(Lunch $lunch): array` with this shape (Tasks 4 and 5 render it):

```php
[
  'menus' => [[
     'meal' => 'Nasi Lemak', 'caterer' => 'Kak Ros' /* or null */,
     'options' => [[
        'lunch_option_id' => 12, 'name' => 'Chicken', 'enabled' => true,
        'members' => [['id' => 3, 'name' => 'Ali', 'disabled' => false], /* … sorted */],
     ], /* … in $menu->options order */],
  ], /* … in $lunch->menus order */],
  'not_attending' => [/* members */],
  'not_responded' => [/* members, enabled only */],
  'counts' => ['ordering' => 9, 'not_attending' => 2, 'not_responded' => 4],
]
```

- [ ] **Step 1: Write the failing tests** in `LunchVoteBreakdownTest`. Build data with `Lunch::factory()->withMenu(CatererMeal::factory()->for(Caterer::factory()->create(['name' => 'Kak Ros']))->create(['name' => 'Nasi Lemak']), [$chicken, $fish])` and `LunchResponse::create([...])`.
  - `test_groups_members_by_menu_and_option_sorted_by_name`: responses Zara→Chicken, ali→Chicken, Hafiz→Fish. Assert `menus[0]['meal'] === 'Nasi Lemak'`, `menus[0]['caterer'] === 'Kak Ros'`, Chicken members' names `=== ['ali', 'Zara']`, Fish `=== ['Hafiz']`.
  - `test_enabled_option_with_no_picks_is_listed_empty`: an option nobody picked appears with `members === []`.
  - `test_disabled_option_shows_only_when_picked`: two disabled options, one picked by someone. Only the picked one appears, with `enabled === false`.
  - `test_not_attending_and_not_responded`: one response with null option goes to `not_attending`. An enabled user with no response goes to `not_responded`. A disabled user (`disabled_at` set) with no response is absent from `not_responded`.
  - `test_disabled_responder_stays_in_group_with_flag`: a user picks Chicken, then gets `disabled_at = now()`. They're still in Chicken with `disabled === true`, `counts['ordering']` includes them, and they're not in `not_responded`.
  - `test_counts`: assert `counts` equals the group sizes.
  - `test_non_responders_query_matches_the_nudge_command`: `Lunch::nonResponders()->pluck('id')` equals the ids `SendLunchVoteNudges` nudges (use `Notification::fake()` and run `app:send-lunch-vote-nudges` on a lunch closing in 12h).

- [ ] **Step 2: Run** `php artisan test tests/Feature/Lunch/LunchVoteBreakdownTest.php`. Expected: FAIL (class `LunchVoteBreakdown` not found).

- [ ] **Step 3: Implement** `Lunch::nonResponders()` and `LunchVoteBreakdown::for()`. Eager-load `menus.caterer`, `menus.catererMeal`, `menus.options.mealOption`, `responses.user`. Work out "disabled" for each responder by querying `User::enabled()->whereIn('id', $responderIds)->pluck('id')` once. Don't put the scope's logic in PHP. Sort with `sortBy(fn ($m) => mb_strtolower($m['name']))`. Change `SendLunchVoteNudges` to use `$lunch->nonResponders()->get()`.

- [ ] **Step 4: Run** `php artisan test tests/Feature/Lunch`. Expected: PASS, including the existing `SendLunchVoteNudgesTest`.

- [ ] **Step 5: Commit** `feat: lunch vote breakdown data for admins`.

---

### Task 2: Admin sets a member's pick, and the member is notified

**Files:**
- Modify: `app/Services/LunchVotingService.php`
- Create: `app/Notifications/LunchPickSetByAdmin.php`
- Test: `tests/Feature/Lunch/AdminSetPickTest.php`

**Interfaces:**
- Produces: `LunchVotingService::setPickAsAdmin(Lunch $lunch, User $member, ?int $lunchOptionId): bool`. Returns `true` if it saved, `false` if the pick was unchanged. Throws `RuntimeException` if `$lunch->status !== 'published'`, or if the option isn't an **enabled** option on this lunch. On save, it calls `app(MemberNotifier::class)->sendAfterResponse(collect([$member]), new LunchPickSetByAdmin($lunch, $option))`.
- Produces: `LunchPickSetByAdmin::__construct(public Lunch $lunch, public ?LunchOption $option)`. A null option means not attending.

- [ ] **Step 1: Write the failing tests.** Use `Notification::fake()` and `$this->withoutDefer()`.
  - `test_admin_can_set_pick_after_deadline`: published lunch with `deadline` in the past. Set Chicken. A `LunchResponse` exists with that `lunch_option_id` and `meal_option_id`, and `LunchPickSetByAdmin` was sent to the member.
  - `test_admin_can_mark_not_attending_for_a_non_responder`: null option creates a response with null ids.
  - `test_refused_on_draft_and_closed`: both throw `RuntimeException`, and nothing is saved or sent.
  - `test_disabled_or_foreign_option_is_refused`: an option on another lunch, or one with `enabled = false`, throws.
  - `test_setting_same_pick_is_a_no_op`: returns `false`, and `Notification::assertNothingSent()`.
  - `test_member_respond_still_refused_after_deadline`: `respond()` on the same past-deadline lunch still throws.
  - `test_notification_copy`: render it without fakes (`Mail::fake()` only). Database title is `"Your lunch pick for Fri, 24 Oct was changed"`. Body is `"An admin set it to Nasi Lemak · Chicken."`. For null, the body is `"An admin marked you as not attending."`.

- [ ] **Step 2: Run** `php artisan test tests/Feature/Lunch/AdminSetPickTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement.** Share the option lookup with `respond()` through a private `enabledOption(Lunch, ?int): ?LunchOption`. Copy the shape of `LunchPickRemoved` for `LunchPickSetByAdmin` (database, mail and APNs via `PushesViaApn`; the `MyLunch` link; the `'lunch'` mail tone; priority header `'10'`). Exact strings:
  - title `__('Your lunch pick for :date was changed', ['date' => $lunch->date->translatedFormat('D, j M')])`
  - body `__('An admin set it to :pick.', ['pick' => $option->lunchMenu->catererMeal->name.' · '.$option->mealOption->name])` or `__('An admin marked you as not attending.')`

  Add the three strings to `lang/ms.json` and `lang/id.json`.

- [ ] **Step 4: Run** `php artisan test tests/Feature/Lunch tests/Feature/Localisation`. Expected: PASS.

- [ ] **Step 5: Commit** `feat: admins can set a member's lunch pick`.

---

### Task 3: Manual "Remind now"

**Files:**
- Create: `database/migrations/2026_10_21_000001_add_manual_reminder_to_lunches_table.php`
- Create: `app/Services/LunchReminderService.php`
- Modify: `app/Models/Lunch.php`
- Test: `tests/Feature/Lunch/ManualLunchReminderTest.php`

**Interfaces:**
- Consumes: `Lunch::nonResponders()` (Task 1).
- Produces: columns `lunches.manual_reminder_sent_at` (nullable timestamp) and `lunches.manual_reminder_sent_by` (nullable `foreignId` → `users`, `nullOnDelete`). Lunch casts: `'manual_reminder_sent_at' => 'datetime'`, `'manual_reminder_sent_by' => 'integer'`. Relation `Lunch::manualReminderSender(): BelongsTo`.
- Produces: `LunchReminderService::remindNonResponders(Lunch $lunch, User $admin): int`. Returns the number of members reminded. Throws `RuntimeException` when `! $lunch->isOpenForVoting()`. Returns 0 without stamping when there are no non-responders. Otherwise it sends `LunchVoteNudge` through `MemberNotifier::sendAfterResponse` and stamps both columns with `forceFill`.

- [ ] **Step 1: Write the failing tests.**
  - `test_reminds_only_enabled_non_responders_and_stamps`: 2 non-responders, 1 responder, 1 disabled. Returns `2`, sends `LunchVoteNudge` to exactly those 2, sets `manual_reminder_sent_at` and `manual_reminder_sent_by === $admin->id`.
  - `test_does_not_touch_the_automatic_nudge_stamp`: `vote_nudge_sent_at` stays null, and running `app:send-lunch-vote-nudges` afterwards still nudges.
  - `test_can_be_sent_repeatedly`: a second call sends again and updates the stamp.
  - `test_refused_after_deadline_or_when_not_published`: throws, and nothing is sent.

- [ ] **Step 2: Run** `php artisan test tests/Feature/Lunch/ManualLunchReminderTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement** the migration (the timestamp sorts after the existing `users`/`lunches` tables, as Postgres FK ordering needs), the casts and relation, and the service.

- [ ] **Step 4: Run** `php artisan test tests/Feature/Lunch`. Expected: PASS.

- [ ] **Step 5: Commit** `feat: manual lunch reminder for non-voters`.

---

### Task 4: Breakdown on the lunch edit page (Livewire component)

**Files:**
- Create: `app/Livewire/Admin/LunchVotes.php`, `resources/views/livewire/admin/lunch-votes.blade.php`
- Modify: `app/Filament/Resources/Lunches/Schemas/LunchForm.php:85-111` (replace the `orders_summary` `Placeholder`)
- Test: `tests/Feature/Lunch/LunchVotesComponentTest.php`

**Interfaces:**
- Consumes: `LunchVoteBreakdown::for()`, `LunchVotingService::setPickAsAdmin()`, `LunchReminderService::remindNonResponders()`, route `lunches.print` (Task 5; use `route('lunches.print', $lunch)`. If Task 5 isn't merged yet, add the route stub first).
- Produces: Livewire component `LunchVotes` with `#[Locked] public int $lunchId`, plus methods `setPick(int $memberId, ?int $lunchOptionId): void` and `remind(): void`.

Embed it in `LunchForm` with `Filament\Schemas\Components\Livewire::make(LunchVotes::class, fn (?Lunch $record) => ['lunchId' => $record?->id])->visibleOn('edit')->columnSpanFull()`, keyed so it re-renders after a form save.

The view follows the approved mockup:
- Summary line: `Ordering :n · Not attending :n · Not responded :n` and the deadline.
- **Print list** link (`target="_blank"`).
- **Remind :count non-voters** button. Shown only if the lunch is open for voting and the count is above 0. Uses `wire:confirm` with "Send the last-call reminder to :count members who haven't responded?". Below it: "Last reminder sent: :time by :name" when stamped.
- Per menu `Meal · Caterer`, per option `Name (n)`, then the names. "Nobody" when empty.
- "Not attending (n)" and "Not responded (n)" groups.
- A "disabled" badge next to disabled members.

When `status === 'published'`, each name is an `<x-filament::dropdown>` listing every **enabled** option as `Meal · Option`, plus "Not attending". Each item calls `wire:click="setPick(id, optionId|null)"`. On other statuses, names are plain text. After an action, send a Filament success or danger notification ("Pick saved." / "Reminder sent to :count members.") and re-render.

- [ ] **Step 1: Write the failing tests** with `Livewire::actingAs($admin)->test(LunchVotes::class, ['lunchId' => $lunch->id])`.
  - `test_renders_names_grouped`: `assertSeeInOrder(['Nasi Lemak', 'Chicken', 'Ali', 'Fish', 'Hafiz', 'Not attending', 'Ahmad', 'Not responded', 'Ben'])`.
  - `test_admin_sets_pick_by_name`: call `setPick($ben->id, $chicken->id)`. A response is saved and the view now shows Ben under Chicken.
  - `test_tampered_option_from_another_lunch_is_rejected`: the option id belongs to another lunch. Nothing is saved, and a danger notification is shown.
  - `test_tampered_lunch_id_is_rejected`: `->set('lunchId', $other->id)` throws (Locked).
  - `test_non_admin_cannot_set_or_remind`: as a member, `setPick` and `remind` are forbidden (403), and nothing changes.
  - `test_names_not_clickable_when_closed`: on a closed lunch, `assertDontSeeHtml('wire:click="setPick')`.
  - `test_remind_button_hidden_after_deadline`: `assertDontSee('non-voters')`.
  - `test_remind_sends_and_shows_last_sent`: call `remind()`. `LunchVoteNudge` is sent, and the view shows "Last reminder sent".
  - `test_edit_page_embeds_component`: the existing `EditLunch` Livewire test renders `assertSeeLivewire(LunchVotes::class)`.

- [ ] **Step 2: Run** `php artisan test tests/Feature/Lunch/LunchVotesComponentTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement** the component, the view and the `LunchForm` swap. In every method: `abort_unless(auth()->user()?->is_admin, 403)`, re-fetch with `Lunch::findOrFail($this->lunchId)` and `User::findOrFail($memberId)`, and catch `RuntimeException` → danger notification. Add the new strings to `lang/ms.json` and `lang/id.json`.

- [ ] **Step 4: Run** `php artisan test tests/Feature/Lunch`. Expected: PASS (`LunchOrderStatsTest` asserts on the old "Current orders" placeholder and its per-menu counts: keep the "Current orders" label on the new section and move those count assertions to the component, rather than deleting their coverage).

- [ ] **Step 5: Commit** `feat: lunch vote breakdown on the admin edit page`.

---

### Task 5: Printable list

**Files:**
- Create: `app/Http/Controllers/LunchPrintController.php`, `resources/views/lunches/print.blade.php`
- Modify: `routes/web.php`, `bootstrap/app.php`
- Test: `tests/Feature/Lunch/LunchPrintTest.php`

**Interfaces:**
- Consumes: `LunchVoteBreakdown::for()`.
- Produces: route `GET /lunches/{lunch}/print`, name `lunches.print`, middleware `auth`, invokable `LunchPrintController`.

In `bootstrap/app.php`, add `$middleware->redirectGuestsTo(fn () => Filament::getPanel('account')->getLoginUrl())`.

The view is a standalone HTML document (no panel layout, no Vite), with inline print CSS and `@media print`. It contains:
- `Lunch: <l, j F>` and `:ordering ordering · :not_attending not attending · :not_responded not responded · printed <time>`
- per menu `Meal (Caterer)`, per option `Option: n`, then each name with `☐`
- `Not attending: n` names, then `Not responded: n` names, plus "(disabled)" after disabled members

It works for every status.

- [ ] **Step 1: Write the failing tests.**
  - `test_admin_sees_grouped_print_with_tick_boxes`: 200, `assertSeeInOrder(['Nasi Lemak (Kak Ros)', 'Chicken: 2', '☐', 'Ali', 'Not attending: 1', 'Not responded: 1'])`.
  - `test_member_gets_403`.
  - `test_guest_is_redirected_to_account_login`: `assertRedirect(Filament::getPanel('account')->getLoginUrl())`.
  - `test_works_on_a_closed_lunch`: 200.

- [ ] **Step 2: Run** `php artisan test tests/Feature/Lunch/LunchPrintTest.php`. Expected: FAIL.

- [ ] **Step 3: Implement** the controller (`abort_unless($request->user()->is_admin, 403)`), the route, the redirect and the view. Translate the new strings in `ms.json` and `id.json`.

- [ ] **Step 4: Run** `php artisan test tests/Feature/Lunch`. Expected: PASS.

- [ ] **Step 5: Commit** `feat: printable lunch vote list`.

---

### Task 6: Full verification

- [ ] **Step 1:** `php artisan test`. Expected: all pass. `vendor/bin/pint --test`. Expected: passed.
- [ ] **Step 2:** Translation check: every new `__()` key appears in both `lang/ms.json` and `lang/id.json`. Run `php artisan test tests/Feature/Localisation`.
- [ ] **Step 3:** Manual browser check on a throwaway local backend. **Never use `.env` (prod Supabase).** Write `.env.votecheck` with `DB_CONNECTION=sqlite`, `DB_DATABASE=<scratchpad>/votecheck.sqlite`, `CACHE_STORE=array`, `SESSION_DRIVER=file`, `MAIL_MAILER=log`, and no APNs keys. Confirm with `APP_ENV=votecheck php artisan tinker --execute="echo config('database.default');"` that it prints `sqlite`. Then run `APP_ENV=votecheck php artisan migrate:fresh --seed` and serve with `APP_ENV=votecheck php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` from `public/`. As an admin, check the edit page breakdown, change a pick by clicking a name, use Remind now, and open Print list (including the print preview). Delete `.env.votecheck` and the sqlite file afterwards.
- [ ] **Step 4:** Note for deploy: the migration in Task 3 runs on Supabase at deploy. It is a nullable timestamp plus a nullable FK to `users`, and is safe on Postgres.
