# Several Menus on One Lunch Day — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A lunch day can hold several menus (from different caterers or the same caterer); each member picks one meal across them, or "Not attending" once for the day.

**Architecture:** `lunches` stays one row per day (deadline, status, nudge). A new `lunch_menus` table holds each day's caterer + menu; `lunch_options` hang off a menu; `lunch_responses` stays unique per `(lunch_id, user_id)` and gains `lunch_option_id`. The API adds `menus` / `lunch_option_id` while legacy fields degrade on several-menu days so old apps show an "update the app" message.

**Tech Stack:** Laravel 12 + Filament 4 + Livewire (PHP 8.4), PHPUnit on SQLite `:memory:`, prod Postgres (Supabase). Mobile: Kotlin Multiplatform shared module (Ktor, kotlinx.serialization), Android Compose, iOS SwiftUI via SKIE.

**Spec:** `docs/superpowers/specs/2026-10-16-multi-menu-lunch-days-design.md`

## Global Constraints

- Work on a feature branch. **Pushing to `main` auto-deploys to production within minutes** (`deploy/auto-deploy.sh`), including migrations against the live Supabase DB. Do not push `main` or run `php artisan migrate` against `.env`'s DB without the user's explicit go-ahead (local dev shares the prod Supabase).
- Every new UI / notification / API message string goes through `__()` with entries in **both** `lang/ms.json` and `lang/id.json`. The localisation tests fail deploys that miss one.
- Integer FK columns read on Postgres come back as strings: add `'integer'` casts for every new FK compared with `===` (`lunch_menu_id`, `lunch_option_id`).
- Migrations must sort after the tables their FKs reference (Postgres checks FKs at `CREATE TABLE`). New migration filenames are dated `2026_10_16_…`.
- No queue worker: notifications go out inline / via `MemberNotifier::sendAfterResponse()`, never `ShouldQueue`.
- The day is the unit: one deadline, one status, one "Lunch published", and one vote nudge per lunch day, whatever the menu count.
- Not attending is `lunch_responses.meal_option_id = null`. Count methods keep reading `meal_option_id`.
- Several-menus legacy copy (exact English key): `Several menus today — update the app to order`.
- Backend test command: `php artisan test`. Mobile: `./gradlew :shared:allTests`, `./gradlew :androidApp:assembleDebug`, `xcodebuild` per `pentana-mobile/README.md`.

## Review Focus

1. **The same meal option on two menus the same day** (e.g. "Regular" from both caterers). The pick, highlight, count and legacy resolution must name the right menu. Tested in Tasks 2, 5 and 6.
2. **Switching a pick from menu A to menu B.** There must still be exactly one response row and no leftover count on A. Tested in Task 2.
3. **Old app on a several-menu day.** It must not be able to record a meal (the respond endpoint returns 422), but "Not attending" still works. Tested in Task 6.
4. **Editing a lunch after the deadline has passed but before the close command has run** (status still `published`). It must be treated as locked. Tested in Task 4.
5. **Migrating a response whose meal option is no longer on its lunch** (an orphan). The row is kept, `lunch_option_id` stays null, and the count doesn't change. Tested in Task 1.

---

### Task 1: Schema, models, factories, data migration

**Files:**
- Create: `database/migrations/2026_10_16_000001_create_lunch_menus_table.php`
- Create: `database/migrations/2026_10_16_000002_move_lunch_caterer_menu_to_lunch_menus.php`
- Create: `app/Models/LunchMenu.php`
- Create: `database/factories/LunchMenuFactory.php`
- Modify: `app/Models/Lunch.php`, `app/Models/LunchOption.php`, `app/Models/LunchResponse.php`
- Modify: `database/factories/LunchFactory.php`, `LunchOptionFactory.php`, `LunchResponseFactory.php`
- Modify: `app/Services/LunchPlanningService.php`, `database/seeders/DemoContentSeeder.php`
- Modify: every test listed by `grep -rln "caterer_meal_id\|caterer_id\|catererMeal" tests` (switch them to the factory state below)
- Test: `tests/Feature/Lunch/LunchMenusMigrationTest.php`, `tests/Feature/Lunch/LunchPlanningTest.php`

**Interfaces:**
- Produces:
  - Table `lunch_menus(id, lunch_id → lunches cascade, caterer_id → caterers, caterer_meal_id → caterer_meals, sort uint default 0, timestamps)`, unique `(lunch_id, caterer_meal_id)`.
  - `lunch_options.lunch_menu_id` → `lunch_menus` cascade, NOT NULL after backfill. Unique changes from `(lunch_id, meal_option_id)` to `(lunch_menu_id, meal_option_id)`; `lunch_id` stays.
  - `lunch_responses.lunch_option_id` nullable → `lunch_options`, `cascadeOnDelete`. This is a backstop: if an option disappears outside `LunchMenuChanges`, the pick becomes "not responded". With `nullOnDelete` it would be left as an orphan that still counts as attending.
  - `lunches.caterer_id` and `lunches.caterer_meal_id` dropped.
  - `LunchMenu`: fillable `lunch_id, caterer_id, caterer_meal_id, sort`. Relations `lunch(): BelongsTo`, `caterer(): BelongsTo`, `catererMeal(): BelongsTo`, `options(): HasMany<LunchOption>`. Method `label(): string` returns `"{caterer} — {menu}"`, and `estimatedCost(): float` is today's `Lunch::estimatedCost` logic, scoped to this menu's options and prices.
  - `Lunch::menus(): HasMany` ordered by `sort, id`. `Lunch::estimatedCost()` sums `menus->estimatedCost()`. `Lunch::caterer()` and `Lunch::catererMeal()` are removed.
  - `Lunch::hasSeveralMenus(): bool`, `Lunch::isLocked(): bool` (`status === 'closed' || (deadline && now()->gt(deadline))`).
  - `LunchOption`: add `lunch_menu_id` (fillable + integer cast) and `lunchMenu(): BelongsTo`.
  - `LunchResponse`: add `lunch_option_id` (fillable + integer cast) and `lunchOption(): BelongsTo`.
  - `LunchFactory::withMenu(?CatererMeal $meal = null, array $mealOptions = []): static` — afterCreating creates a `LunchMenu` (from `$meal`, or a fresh `CatererMeal`), plus one enabled `LunchOption` per given `MealOption`.
  - `LunchOptionFactory`: `lunch_menu_id` defaults to the lunch's first menu, creating one if there isn't one, so existing `LunchOption::factory()->create(['lunch_id' => …])` calls keep working.
  - `LunchPlanningService::syncOptionsFromMenu(LunchMenu $menu): void`, which creates options keyed `(lunch_menu_id, meal_option_id)` and fills `lunch_id`.

- [ ] **Step 1: Write the failing migration test** `LunchMenusMigrationTest`. Roll back the two new migrations (`$this->artisan('migrate:rollback', ['--step' => 2])`), then insert the old shape:
  - a lunch with `caterer_id` + `caterer_meal_id`;
  - two `lunch_options` (meal options A, B);
  - three responses: one on A, one not attending, and one orphan on meal option C, which isn't an option on that lunch.

  Run `migrate`, then assert:
  - `test_existing_lunch_becomes_a_one_menu_day`: one `lunch_menus` row with the old caterer/menu, and both options point to it.
  - `test_responses_get_their_lunch_option_id`: the A response's `lunch_option_id` is A's option id, and the not-attending row is null on both columns.
  - `test_orphan_response_is_kept_and_still_counts`: the C row still has `meal_option_id = C`, `lunch_option_id` is null, and `Lunch::attendingCount() === 2`.
- [ ] **Step 2: Run** `php artisan test --filter=LunchMenusMigrationTest`. Expected: FAIL (migration missing).
- [ ] **Step 3: Write both migrations.** Migration 2's steps:
  1. add the nullable columns;
  2. insert one `lunch_menus` row per lunch, in plain query-builder SQL (no models);
  3. backfill `lunch_options.lunch_menu_id` and `lunch_responses.lunch_option_id` by joining on `(lunch_id, meal_option_id)`;
  4. make `lunch_menu_id` NOT NULL;
  5. swap the unique index;
  6. drop the two `lunches` columns, dropping their FKs first.

  `down()` reverses these steps, taking each lunch's first menu. It must work on both SQLite and Postgres: use `Schema` calls and plain updates, with no driver-specific SQL.
- [ ] **Step 4: Add the models, relations and factory state** listed in Interfaces, and update `syncOptionsFromMenu` and `DemoContentSeeder`.
- [ ] **Step 5: Convert the existing tests** that pass `caterer_id`/`caterer_meal_id` to `Lunch::factory()` so they use `->withMenu($meal, [...])`. Change code that reads `$lunch->catererMeal` to `$lunch->menus`. This only touches the model and the factories. The admin/portal/API code is fixed in later tasks, so some of those tests stay red until then: list them in the commit message.
- [ ] **Step 6: Add to `LunchPlanningTest`:**
  - `test_estimated_cost_sums_every_menu_at_its_own_prices`: two menus. Menu 1's option costs 10 with default count 3; menu 2's option costs 12 with default count 2. Assert `estimatedCost() === 54.0`.
  - `test_same_meal_option_can_sit_on_two_menus_of_one_day`: no unique violation.
- [ ] **Step 7: Run** `php artisan test --filter='LunchMenusMigrationTest|LunchPlanningTest'`. Expected: PASS.
- [ ] **Step 8: Commit** `feat(lunch): lunch days hold a list of menus`.

### Task 2: Voting picks one option across the day's menus

**Files:**
- Modify: `app/Services/LunchVotingService.php`
- Test: `tests/Feature/Lunch/LunchVotingServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models.
- Produces: `LunchVotingService::respond(Lunch $lunch, User $user, ?int $lunchOptionId): LunchResponse`. A non-null id must be an enabled `lunch_options` row with `lunch_id === $lunch->id`, otherwise it throws `RuntimeException('That option is not available for this lunch.')`. The not-open message stays the same. It does an `updateOrCreate` on `(lunch_id, user_id)` with `lunch_option_id`, and `meal_option_id` comes from that option (null/null = not attending).
- Produces: `LunchVotingService::resolveLegacyMealOption(Lunch $lunch, int $mealOptionId): int` returns the single enabled `lunch_option_id` with that `meal_option_id` on the lunch. If there are zero or several matches, it throws `RuntimeException('Please update the app to order for this day.')`.

- [ ] **Step 1: Write failing tests:**
  - `test_switching_to_another_menu_replaces_the_pick`: pick on menu A, then on menu B. Exactly 1 response row, and `optionCounts()` shows only B.
  - `test_same_meal_option_on_two_menus_records_the_chosen_menu`: both menus offer "Regular". Picking menu B's option stores B's `lunch_option_id`.
  - `test_option_from_another_lunch_is_rejected`.
  - `test_not_attending_clears_the_option`: both columns are null.
  - `test_legacy_meal_option_resolves_on_a_one_menu_day`.
  - `test_legacy_meal_option_is_rejected_when_ambiguous`: throws with the update message.
- [ ] **Step 2: Run** `php artisan test --filter=LunchVotingServiceTest`. Expected: FAIL.
- [ ] **Step 3: Implement** both methods. Update every caller (`MyLunch`, `LunchController`) to pass a `lunch_option_id`. The full caller rework happens in Tasks 5 and 6; here, just make it compile.
- [ ] **Step 4: Run** the filter again. Expected: PASS.
- [ ] **Step 5: Add the two new messages** to `lang/ms.json` and `lang/id.json`, then commit `feat(lunch): one pick per day across menus`.

### Task 3: Admin lunch form with a repeater of menus

**Files:**
- Modify: `app/Filament/Resources/Lunches/Schemas/LunchForm.php`, `Pages/CreateLunch.php`
- Modify: `app/Filament/Resources/Lunches/Tables/LunchesTable.php`
- Test: `tests/Feature/Lunch/LunchResourceTest.php`

**Interfaces:**
- Consumes: `LunchPlanningService::syncOptionsFromMenu(LunchMenu)`.
- Produces: form state path `data.menus` (a Filament `Repeater::make('menus')->relationship()`). Each item has `caterer_id` (live), `caterer_meal_id` (filtered by caterer), and a nested `options` repeater (relationship, hidden on create, `enabled` toggle + `default_count`, as today). `minItems(1)`. `caterer_meal_id` is `distinct()` across items. The table column `menus_label` shows each menu's `label()` joined with `, `.

- [ ] **Step 1: Write failing tests:**
  - `test_admin_can_create_a_day_with_two_caterers`: two items from different caterers. Assert 2 `lunch_menus` rows, with options synced for each.
  - `test_admin_can_create_a_day_with_two_menus_from_one_caterer`.
  - `test_same_menu_twice_is_rejected`: form error on `menus`.
  - `test_a_day_needs_at_least_one_menu`.
- [ ] **Step 2: Run** `php artisan test --filter=LunchResourceTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the repeater and table column. In `CreateLunch::afterCreate`, sync options for each created menu.
- [ ] **Step 4: Run** the filter again. Expected: PASS. Run the localisation tests too (`php artisan test tests/Feature/Localisation`) and add any new labels to `ms.json`/`id.json`.
- [ ] **Step 5: Commit** `feat(lunch): admin adds several menus to a day`.

### Task 4: Editing menus — carry-over, dropped picks, member notice, lock

**Files:**
- Create: `app/Services/LunchMenuChanges.php`
- Create: `app/Notifications/LunchPickRemoved.php`
- Modify: `app/Filament/Resources/Lunches/Pages/EditLunch.php`, `Schemas/LunchForm.php`
- Test: `tests/Feature/Lunch/ChangeLunchMenuTest.php`

**Interfaces:**
- Consumes: Task 1 models, `MemberNotifier::sendAfterResponse(Collection $users, Notification $n)`.
- Produces:
  - `LunchMenuChanges::droppedResponses(Lunch $lunch, array $menusState): Collection<int, LunchResponse>` is a dry run over the submitted repeater state. It returns the responses whose menu is removed, or whose menu is swapped to one without the same `meal_option_id`. It powers both the confirmation count and the pre-save capture. **It must run before save**, because the relationship repeater deletes removed `lunch_menus` rows (and, by cascade, their options) during the save itself.
  - `LunchMenuChanges::apply(Lunch $lunch, Collection $dropped): Collection<int, User>` runs after save. It deletes the `$dropped` responses and rebuilds the options of swapped/new menus through `syncOptionsFromMenu`. It re-points each carried response's `lunch_option_id` to the new option with the same `meal_option_id` on the same `lunch_menus` row (a swapped menu keeps its row id; only `caterer_meal_id` changes). It then deletes the options that are no longer on the menu, and returns the users of `$dropped`.
  - `EditLunch` captures `$dropped` in `beforeSave()`, from `$this->data['menus']`, and passes it to `apply()` in `afterSave()`.
  - `LunchPickRemoved(Lunch $lunch)` uses the same channels/shape as `LunchPublished`. Exact copy keys:
    - title: `Your lunch pick for :date was removed`
    - body: `The menu changed. Please choose again by :deadline.`
- Behaviour:
  - Save asks for confirmation when `droppedResponses()->count() > 0`, using the key `:count members' picks will be removed and they will be asked to choose again.` (Filament `requiresConfirmation` on the save action, with `modalDescription` showing the count).
  - After `apply`, it notifies the returned users through `MemberNotifier`, but only when `status === 'published'`.
  - When `$lunch->isLocked()`, the `menus` repeater and its nested options are `disabled()` and not dehydrated, and `EditLunch` refuses menu changes server-side. Livewire state is tamperable, so re-check `isLocked()` in `afterSave`/`mutateFormDataBeforeSave` and drop `menus` from the data.

- [ ] **Step 1: Write failing tests** in `ChangeLunchMenuTest`. Keep the existing re-save regression test, adapted to `data.menus`. Add:
  - `test_swapping_a_menu_carries_picks_with_the_same_meal_option`.
  - `test_picks_that_cannot_carry_over_are_removed_and_members_notified`: use `Notification::fake()` and assert `LunchPickRemoved` is sent to exactly the affected user.
  - `test_removing_a_menu_removes_its_picks_only`: picks on the other menu are untouched.
  - `test_adding_a_menu_keeps_existing_picks`.
  - `test_draft_lunch_changes_send_no_notice`.
  - `test_menus_are_locked_after_the_deadline_even_before_close_runs`: status `published`, deadline 1 minute ago. A tampered `data.menus` doesn't change the DB.
  - `test_menus_are_locked_when_closed`.
  - `test_dropped_responses_match_the_confirmation`: `droppedResponses()` returns the 2 picks a state drops, and the modal description shows `2`.
- [ ] **Step 2: Run** `php artisan test --filter=ChangeLunchMenuTest`. Expected: FAIL.
- [ ] **Step 3: Implement** `LunchMenuChanges`, `LunchPickRemoved`, and the `EditLunch` wiring. Replace today's `wasChanged('caterer_meal_id')` block, keeping its `unsetRelation` + `fillForm()` reload.
- [ ] **Step 4: Run** the filter again plus `tests/Feature/Localisation`. Expected: PASS. Add the copy keys to `ms.json`/`id.json`.
- [ ] **Step 5: Commit** `feat(lunch): menu edits keep picks where they can and tell members when they can't`.

### Task 5: Admin orders summary, calendar, stats, portal My Lunch

**Files:**
- Modify: `app/Filament/Resources/Lunches/Schemas/LunchForm.php` (`orders_summary` placeholder)
- Modify: `app/Filament/Pages/LunchCalendar.php`, `resources/views/filament/pages/lunch-calendar.blade.php`
- Modify: `app/Services/LunchPlanningStats.php`
- Modify: `app/Filament/Pages/MyLunch.php`, `resources/views/filament/pages/my-lunch.blade.php`
- Test: `tests/Feature/Lunch/LunchOrderStatsTest.php`, `LunchCalendarTest.php`, `MyLunchPageTest.php`

**Interfaces:**
- Consumes: `LunchMenu::label()`, `LunchMenu::estimatedCost()`, `LunchVotingService::respond(…, ?int $lunchOptionId)`.
- Produces: `Lunch::optionCountsByLunchOption(): array<int,int>` (lunch_option_id ⇒ count). `MyLunch::choose(int $lunchId, int $lunchOptionId)` (renamed parameter; `wire:click` passes `$option->id`).
- Behaviour:
  - **Orders summary:** one line per menu, `"{label}: Option A: n · Option B: n"`, then the existing not attending / total line.
  - **Calendar cell (desktop + agenda):** one block per menu (title = menu name, caterer under it), each with its enabled options. The day's `:count ordered` appears once.
  - **Stats:** `daysPlanned` still counts lunch rows (days), cost uses the new `estimatedCost`, and eager loads become `menus.catererMeal.options`, `options`.
  - **My Lunch card:** the date, then a section per menu (heading = `label()`, image = that menu's `catererMeal->image` if it has one) with its option buttons, then one "Not attending". The active state is `$mine->lunch_option_id === $option->id`. The closed state names the chosen option and its menu.

- [ ] **Step 1: Write failing tests:**
  - `test_orders_summary_groups_counts_per_menu`: two menus that both offer "Regular". The counts land under the right menu.
  - `test_calendar_cell_lists_every_menu_of_the_day`: both menu names render, and the count renders once.
  - `test_my_lunch_shows_each_menu_and_highlights_the_pick_on_the_right_menu`: the same meal option on both menus; pick menu B's. Only B's button has `ml-btn--active`.
  - `test_my_lunch_choosing_on_menu_b_after_a_keeps_one_response`.
- [ ] **Step 2: Run** `php artisan test --filter='LunchOrderStatsTest|LunchCalendarTest|MyLunchPageTest'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the same filter plus `tests/Feature/Localisation` and `tests/Feature/Filament/PhoneLayoutsTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(lunch): calendar, summary and My Lunch show every menu of the day`.

### Task 6: API and dashboard with legacy fallback

**Files:**
- Modify: `app/Http/Resources/Api/LunchResource.php`, `app/Http/Controllers/Api/V1/LunchController.php`, `app/Http/Controllers/Api/V1/DashboardController.php`
- Test: `tests/Feature/Api/LunchApiTest.php`, `tests/Feature/Api/DashboardApiTest.php`

**Interfaces:**
- Consumes: `respond(…, ?int $lunchOptionId)`, `resolveLegacyMealOption()`, `Lunch::hasSeveralMenus()`.
- Produces (JSON per lunch, additive):
  - `menus: [{id, caterer, menu, options: [{lunch_option_id, meal_option_id, name}]}]`
  - `my_lunch_option_id: int|null`
  - **One-menu day:** legacy `caterer`, `menu`, `options[{meal_option_id,name}]` and `my_meal_option_id` are unchanged from today.
  - **Several menus:** `options: []`, `menu: __('Several menus today — update the app to order')`, `caterer`: the caterer names, distinct, joined with ` · `, `my_meal_option_id` as stored.
  - **Respond body:** `lunch_option_id` (nullable int) or legacy `meal_option_id`. If both are absent or null, the member is not attending. If legacy is present, resolve it through `resolveLegacyMealOption`. A `RuntimeException` gives a 422 with `{message}`.
  - **Dashboard `next_lunch`:** `caterer`/`menu` follow the same one-menu/several rule, plus `menus: [{caterer, menu}]`.
  - Eager loads: `menus.caterer`, `menus.catererMeal`, `menus.options.mealOption`, and `responses` filtered to the caller.

- [ ] **Step 1: Write failing tests:**
  - `test_one_menu_day_keeps_the_legacy_shape`: the existing assertions stay unchanged, and `menus.0.options.0.lunch_option_id` is present.
  - `test_several_menu_day_hides_legacy_options_and_shows_update_message`.
  - `test_respond_with_lunch_option_id`.
  - `test_legacy_respond_works_on_one_menu_day`.
  - `test_legacy_respond_is_422_on_ambiguous_several_menu_day`.
  - `test_legacy_not_attending_works_on_several_menu_day`.
  - `test_dashboard_next_lunch_lists_menus`.
  - Run one several-menu assertion under `ms` too, so the update message comes back translated (follow the existing localisation test pattern for API locale).
- [ ] **Step 2: Run** `php artisan test --filter='LunchApiTest|DashboardApiTest'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter again. Expected: PASS.
- [ ] **Step 5: Commit** `feat(api): lunch menus in the API with an update prompt for old apps`.

### Task 7: Notifications name every menu

**Files:**
- Modify: `app/Notifications/LunchPublished.php`, `app/Notifications/LunchVoteNudge.php`
- Test: `tests/Feature/Lunch/LunchPublishNotificationTest.php`, `SendLunchVoteNudgesTest.php`, `tests/Feature/Localisation/NotificationsLocalisedTest.php`

**Interfaces:**
- Consumes: `Lunch::menus`.
- Produces: a private helper in each notification, `menusLine(): string`, which joins the menu names (`catererMeal->name`) with ` · `. It is used where `catererMeal?->name` was. Keep the existing translation keys, since `:menu` just receives the joined string.

- [ ] **Step 1: Write failing tests:**
  - `test_publishing_a_two_menu_day_sends_one_notification_naming_both_menus`: `Notification::fake()`, exactly one `LunchPublished` per user, and the database title contains both names.
  - `test_nudge_is_sent_once_per_day_with_two_menus`.
- [ ] **Step 2: Run** `php artisan test --filter='LunchPublishNotificationTest|SendLunchVoteNudgesTest|NotificationsLocalisedTest'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the filter again, then the **full suite** with `php artisan test`. Expected: all PASS, with none of the reds listed in Task 1 remaining.
- [ ] **Step 5: Commit** `feat(lunch): lunch notifications name every menu of the day`.

### Task 8: Mobile shared module (`pentana-mobile`)

**Files (in `/Users/fahmi/Silentmode/pentana-mobile`, own feature branch):**
- Modify: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/model/LunchDtos.kt`, `model/DashboardDtos.kt`
- Modify: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/LunchRepository.kt`, `presentation/LunchStore.kt`
- Test: `shared/src/commonTest/kotlin/my/silentmode/pentana/shared/LunchStoreTest.kt`, `PentanaApiTest.kt`

**Interfaces:**
- Consumes: the Task 6 JSON.
- Produces:
  - `LunchMenuOptionDto(@SerialName("lunch_option_id") lunchOptionId: Long, @SerialName("meal_option_id") mealOptionId: Long, name: String? = null)`
  - `LunchMenuDto(id: Long, caterer: String? = null, menu: String? = null, options: List<LunchMenuOptionDto> = emptyList())`
  - `LunchDto` gains `menus: List<LunchMenuDto> = emptyList()` and `@SerialName("my_lunch_option_id") myLunchOptionId: Long? = null`. The legacy fields stay.
  - `RespondRequest(@SerialName("lunch_option_id") lunchOptionId: Long?)`. The serializer must send `"lunch_option_id": null` explicitly for not attending (check `explicitNulls`).
  - `LunchRepository.respond(lunchId: Long, lunchOptionId: Long?)` and `chooseOption(lunchId: Long, lunchOptionId: Long)`.
  - `LunchStore.choose(lunchId: Long, lunchOptionId: Long)`.
  - The dashboard next-lunch DTO gains `menus: List<NextLunchMenuDto> = emptyList()`, where `NextLunchMenuDto(caterer: String?, menu: String?)`.

- [ ] **Step 1: Write failing tests:**
  - `PentanaApiTest`: decode a two-menu payload (from the Task 6 shape) into `menus` + `myLunchOptionId`, and check that `respond` posts `{"lunch_option_id":81}` and `{"lunch_option_id":null}`.
  - `LunchStoreTest`: `choose` passes the `lunchOptionId` through and replaces the card.
- [ ] **Step 2: Run** `./gradlew :shared:testDebugUnitTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `./gradlew :shared:allTests`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(shared): lunch menus and lunch_option_id`.

### Task 9: Android and iOS lunch screens

**Files (`pentana-mobile`):**
- Modify: `androidApp/src/main/kotlin/my/silentmode/pentana/feature/lunch/LunchScreen.kt`, `androidApp/src/main/kotlin/my/silentmode/pentana/feature/home/HomeScreen.kt`
- Modify: `iosApp/iosApp/LunchView.swift`, `iosApp/iosApp/HomeView.swift`

**Interfaces:**
- Consumes: Task 8 DTOs and `LunchStore.choose(lunchId, lunchOptionId)`.
- Behaviour:
  - **Day card:** a section per `menus` entry (heading "Caterer — Menu"), each with its option chips. The chosen chip is `lunch.responded && lunch.myLunchOptionId == option.lunchOptionId`. One "Not attending" per day.
  - **Fallback:** when `menus` is empty (an older server), render the legacy `options` as today.
  - **Home card:** shows the menus joined with ` · `.

- [ ] **Step 1: Build** with `./gradlew :androidApp:assembleDebug` and the README's `xcodebuild build …` command. Expected: both succeed.
- [ ] **Step 2: Check it on screen** against a local backend with a two-menu day where both menus share an option. Use the Android emulator via `10.0.2.2` with multi-worker `artisan serve`, and the iOS Simulator. Pick on menu B, then switch to menu A, then pick Not attending, and check each time that exactly one chip is highlighted.
- [ ] **Step 3: Commit** `feat(app): lunch day shows every menu`.

### Task 10: Production readiness (user-gated)

- [ ] **Step 1:** Ask the user before running a **read-only** orphan count against Supabase. The count is the responses whose `(lunch_id, meal_option_id)` matches no `lunch_options` row and whose `meal_option_id` is not null. Report the number.
- [ ] **Step 2:** Ask the user before running the migration on Supabase, or before merging to `main`, which auto-deploys and migrates. Afterwards, check that every lunch has exactly one `lunch_menus` row and that `lunch_options.lunch_menu_id` has no nulls.
- [ ] **Step 3:** The mobile release (TestFlight / Play) is the user's step. Old app versions keep working on one-menu days. Two-menu days show the update message until members update.
