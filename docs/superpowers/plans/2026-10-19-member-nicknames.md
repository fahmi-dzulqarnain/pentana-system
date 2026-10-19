# Member Nicknames Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Members can set an optional nickname, which is used to greet them on the web, in the apps and in emails, and which admins see above the full name on every admin screen that lists members.

**Architecture:** A nullable `users.nickname` column with a case-insensitive unique index. `User::display_name` (nickname, otherwise full name) is the single fallback rule, and `App\Support\Nickname` holds the validation rules shared by the web profile and the API. A reusable `MemberColumn` / `MemberEntry` helper renders the admin "bold nickname over grey full name" layout. The mobile apps get `nickname` and `display_name` from `UserResource` and update the nickname with `PATCH /api/v1/me` through the shared KMP `SessionManager`.

**Tech Stack:** Laravel 12, Filament 4, PHPUnit (SQLite in tests, Postgres/Supabase in prod), Kotlin Multiplatform (Ktor + MockEngine tests), SwiftUI, Jetpack Compose.

**Spec:** `docs/superpowers/specs/2026-10-19-member-nicknames-design.md`

**Repos:** Tasks 1–6 run in `pentana-system`. Tasks 7–9 run in `../pentana-mobile` and are committed there.

## Global Constraints

- Nickname is optional. Trim it; blank or whitespace-only input is stored as `null`.
- Max length is 30 characters.
- Unique among nicknames, ignoring case ("Fahmi" = "fahmi"). No check against full names.
- `display_name` = nickname if set, otherwise full name. The `name` field always stays the full name, including in the API.
- Member greetings without a nickname behave exactly as today. Mobile keeps the first word of the full name, and initials keep using the full name.
- Disabling a member clears their nickname. Re-enabling doesn't restore it.
- Invitations keep the full name. The member's own profile shows both names.
- Admin layout: nickname in bold with the full name in small grey text underneath. Without a nickname, show the full name on one line. Dropdown options use "Nickname (Full name)".
- Admins can view and clear a nickname, but can't type one.
- Every new web string needs translations in `lang/ms.json` and `lang/id.json` (English text is the key). The mobile apps are English-only today, so mobile strings stay in English *(plan call; the apps have no localisation yet)*.
- Every migration runs against the prod Supabase database (dev shares it), and `main` auto-deploys. Don't push `main` until the whole server side (Tasks 1–6) is ready.

## Review Focus

1. **Case-only rename of your own nickname** ("fahmi" → "Fahmi"). This must be allowed, so the uniqueness rule must ignore the member's own row. Test it in Task 1.
2. **A nickname that pads to empty or over-long** (`"   "`, or 30 letters plus spaces around them). Store `null` for the first and accept the second after trimming. Test it in Task 1.
3. **Two members racing for the same nickname.** The validation passes for both, so the database index must reject the second. Test it in Task 1 with a direct insert that hits the index.
4. **Postgres vs SQLite.** The `lower()` index and the `lower(nickname) = ?` lookup must behave the same on Postgres. Task 1 has a manual verification step against Postgres.
5. **Older app builds and members without a nickname.** The API keeps `name` as the full name, and the apps treat the new fields as optional. Tests cover this in Task 6 (API shape) and Task 7 (DTO decoding without the new fields).

> **Spec note:** the spec lists "lunch responses (who ordered what)" as an admin screen. Today the lunch admin only shows **counts** (`LunchForm` `orders_summary`) and never names members, so there's nothing to change there. No task covers it.

---

### Task 1: Nickname column, display name and validation rules

**Files:**
- Create: `database/migrations/2026_10_19_000000_add_nickname_to_users_table.php`
- Create: `app/Support/Nickname.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Nickname/NicknameModelTest.php`

**Interfaces:**
- Produces:
  - Column `users.nickname` (`string(30)`, nullable), plus unique index `users_nickname_lower_unique` on `lower(nickname)`.
  - `User::$display_name` (an `Attribute` accessor) → `string`, the nickname, otherwise `name`.
  - `User implements Filament\Models\Contracts\HasName`, with `getFilamentName(): string` returning `display_name`. This drives the web user menu and avatar.
  - `App\Support\Nickname::normalise(?string $value): ?string` trims and turns empty into `null`.
  - `App\Support\Nickname::rules(?User $ignore = null): array` returns the Laravel rules for the field: `nullable`, `string`, `max:30`, plus a closure rule that fails when another user (not `$ignore`) has `lower(nickname) = lower(value)`. The failure message is `__('That nickname is already taken.')`.
  - `nickname` is added to `$fillable`.

- [ ] **Step 1: Write the failing tests** in `NicknameModelTest` (PHPUnit class, `RefreshDatabase`, same style as `tests/Feature/Api/AuthTest.php`):
  - `test_display_name_falls_back_to_full_name`: with no nickname, `display_name === name`. With `nickname = 'Fahmi'`, it is `'Fahmi'`, and `getFilamentName()` returns `'Fahmi'`.
  - `test_normalise_trims_and_nulls_blank`: `normalise('  Fahmi ') === 'Fahmi'`, `normalise('   ') === null`, `normalise(null) === null`.
  - `test_rules_reject_case_insensitive_duplicate`: when user A has `'Fahmi'`, `Validator::make(['nickname' => 'fahmi'], ['nickname' => Nickname::rules($userB)])` fails with the message `'That nickname is already taken.'`.
  - `test_rules_allow_own_nickname_in_new_case`: when A has `'fahmi'`, validating `'Fahmi'` with `rules($userA)` passes.
  - `test_rules_reject_31_characters`: `str_repeat('a', 31)` fails and 30 passes. Normalise first: `'  '.str_repeat('a', 30).'  '` passes after normalising.
  - `test_database_index_rejects_case_insensitive_duplicate`: A has `'Fahmi'`. Setting B's nickname to `'FAHMI'` with `forceFill()->save()` throws `Illuminate\Database\UniqueConstraintViolationException`.
  - `test_many_users_may_have_no_nickname`: two users with `nickname = null` save fine.

- [ ] **Step 2: Run the tests and confirm they fail**
  Run: `php artisan test --filter=NicknameModelTest`
  Expected: FAIL (the column, class and accessor don't exist yet).

- [ ] **Step 3: Write the migration.** Add `string('nickname', 30)->nullable()->after('name')`. Then add the index with `DB::statement('CREATE UNIQUE INDEX users_nickname_lower_unique ON users (lower(nickname))')`, which works on both SQLite and Postgres. `down()` drops the index, then the column.

- [ ] **Step 4: Implement `Nickname` and the `User` changes** listed under Interfaces. In the closure rule, compare with `whereRaw('lower(nickname) = ?', [mb_strtolower($value)])` and `->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))`.

- [ ] **Step 5: Run the tests and confirm they pass**
  Run: `php artisan test --filter=NicknameModelTest`
  Expected: PASS

- [ ] **Step 6: Check the index on Postgres.** Don't use the shared prod database for this. Use a throwaway local Postgres (see memory `artisan-serve-ignores-env-overrides`: use a separate env file). Run the migration there, insert `'Fahmi'`, then insert `'fahmi'`. Expected: a unique violation on `users_nickname_lower_unique`. Roll back.

- [ ] **Step 7: Add the translation** for `That nickname is already taken.` to `lang/ms.json` and `lang/id.json`. Then commit:

```bash
git add database/migrations app/Support/Nickname.php app/Models/User.php tests/Feature/Nickname lang/ms.json lang/id.json
git commit -m "feat: nickname column, display name and validation"
```

### Task 2: Clear the nickname when a member is disabled

**Files:**
- Modify: `app/Services/MemberAccess.php` (`disable()`)
- Test: `tests/Feature/Nickname/NicknameDisableTest.php`

**Interfaces:**
- Consumes: the `users.nickname` column from Task 1.

- [ ] **Step 1: Write the failing tests**
  - `test_disabling_clears_nickname`: a member with `'Fahmi'` is passed to `app(MemberAccess::class)->disable($user)`. After `fresh()`, `nickname` is `null` and `disabled_at` is set.
  - `test_enabling_does_not_restore_nickname`: disable, then `enable()`. `nickname` stays `null`.
  - `test_disabled_members_nickname_is_free`: after A is disabled, `Nickname::rules($b)` accepts `'Fahmi'` for B.
- [ ] **Step 2: Run the tests and confirm they fail.** Run: `php artisan test --filter=NicknameDisableTest`. Expected: FAIL on the first test.
- [ ] **Step 3: Implement.** In `disable()`, use `forceFill(['disabled_at' => now(), 'nickname' => null])`.
- [ ] **Step 4: Run the tests and confirm they pass**, then run the existing disable tests too: `php artisan test --filter='NicknameDisableTest|Disable'`. Expected: PASS
- [ ] **Step 5: Commit** with `feat: clear nickname when a member is disabled`.

### Task 3: Member edits their nickname on the web profile

**Files:**
- Modify: `app/Filament/Auth/Profile.php`
- Modify: `lang/ms.json`, `lang/id.json`
- Test: `tests/Feature/Nickname/ProfileNicknameTest.php` (use the same Livewire testing approach as `tests/Feature/TimeFormatPreferenceTest.php`)

**Interfaces:**
- Consumes: `Nickname::normalise()` and `Nickname::rules(?User)` from Task 1.

- [ ] **Step 1: Write the failing tests**
  - `test_member_can_set_nickname`: set `data.nickname` to `'  Fahmi '` and save. The stored value is `'Fahmi'`.
  - `test_member_can_clear_nickname`: set it to `''`. The stored value is `null`.
  - `test_duplicate_nickname_is_rejected`: another user has `'fahmi'`. Setting `'Fahmi'` gives `assertHasFormErrors(['nickname'])`.
  - `test_full_name_field_is_still_shown`: the form still contains the `name` field.
- [ ] **Step 2: Run the tests and confirm they fail.** Run: `php artisan test --filter=ProfileNicknameTest`. Expected: FAIL
- [ ] **Step 3: Implement.** Add `TextInput::make('nickname')` directly after the name component, with:
  - label `__('Nickname')`, `->maxLength(30)`,
  - `->rules(fn (): array => Nickname::rules($this->getUser()))`,
  - `->dehydrateStateUsing(fn (?string $state) => Nickname::normalise($state))`,
  - `->helperText(__('What people call you. Shown instead of your full name.'))`.
- [ ] **Step 4: Add `ms` and `id` translations** for `Nickname` and the helper text.
- [ ] **Step 5: Run the tests and confirm they pass.** Run: `php artisan test --filter=ProfileNicknameTest`. Expected: PASS
- [ ] **Step 6: Commit** with `feat: members can set a nickname on their profile`.

### Task 4: Birthday emails greet by nickname

**Files:**
- Modify: `app/Notifications/BirthdayWish.php` (lines 25 and 30: `$notifiable->name` → `$notifiable->display_name`)
- Modify: `app/Console/Commands/SendBirthdayEmails.php:31-32` (`{name}` → `$user->display_name`, with `e()` kept on the body)
- Modify: `app/Filament/Resources/AutoEmails/Schemas/AutoEmailForm.php:37` (hint copy)
- Modify: `lang/ms.json`, `lang/id.json` (replace the old hint key with the new one)
- Test: add to the existing tests in `tests/Feature/Birthday/`

**Interfaces:**
- Consumes: `User::$display_name` from Task 1.

New hint copy (English key): `Use {name} where the member's name should appear. It uses their nickname if they have one. Leave blank to send the built-in birthday message, translated into each member's language.`

- [ ] **Step 1: Write the failing tests**
  - Built-in birthday message: for a member named `'Muhammad Fahmi bin Dzulqarnain'` with nickname `'Fahmi'`, the subject is `'Happy birthday, Fahmi! 🎂'`.
  - Custom template with subject `'Hi {name}'`: the sent subject is `'Hi Fahmi'`. Without a nickname, it's `'Hi Muhammad Fahmi bin Dzulqarnain'`.
  - Body escaping still holds: the nickname `'<b>Al</b>'` is rendered escaped in the body.
- [ ] **Step 2: Run the tests and confirm they fail.** Run: `php artisan test tests/Feature/Birthday`. Expected: the new tests FAIL.
- [ ] **Step 3: Make the changes** listed under Files. Invitations (`MemberInvitation`, `mail/invitation*.blade.php`) keep using `name` and must not change.
- [ ] **Step 4: Run the tests and confirm they pass.** Run: `php artisan test tests/Feature/Birthday tests/Feature/Onboarding`. Expected: PASS
- [ ] **Step 5: Commit** with `feat: birthday emails greet members by nickname`.

### Task 5: Admin screens show nickname over full name, search by nickname, clear action

**Files:**
- Create: `app/Filament/Support/MemberName.php`
- Modify: `app/Filament/Resources/Users/Tables/UsersTable.php` (name column, plus a new "Clear nickname" record action)
- Modify: `app/Filament/Resources/Users/Schemas/UserInfolist.php`
- Modify: `app/Filament/Resources/Users/Schemas/UserForm.php` (a read-only nickname field)
- Modify: `app/Filament/Resources/Activities/RelationManagers/RegistrationsRelationManager.php:42`
- Modify: `app/Filament/Resources/FeePayments/Tables/FeePaymentsTable.php:25`, `FeePaymentResource.php:30,35`, `Pages/ViewFeePayment.php:29`
- Modify: `app/Filament/Resources/FeePeriods/Tables/FeePeriodsTable.php:19,50`, `FeePeriodResource.php:34,39`, `Schemas/FeePeriodForm.php:24`
- Modify: `app/Filament/Pages/FinanceSummary.php:97-99`
- Modify: `lang/ms.json`, `lang/id.json`
- Test: `tests/Feature/Nickname/AdminNicknameTest.php`

**Interfaces:**
- Consumes: `users.nickname` and `User::$display_name` from Task 1.
- Produces, in `App\Filament\Support\MemberName`:
  - `column(string $relationship = null): TextColumn` builds a column for `user.name`, or for `name` when `$relationship` is null. When the member has a nickname, it shows the nickname in bold with `->description()` set to the full name. Without a nickname, it shows the full name with no description. It's searchable on both `name` and `nickname`.
  - `entry(string $relationship = null): TextEntry` is the same for infolists.
  - `optionLabel(User $user): string` returns `"Nickname (Full name)"` when a nickname is set, otherwise the full name.

Plan calls:
- **FinanceSummary:** the name column already uses `description()` for the member category. Make the description `"Full name · Category"` when a nickname is set, and keep `"Category"` alone otherwise.
- **Toast messages** in `UsersTable` and `FinanceSummary` (for example "Reminder sent to :name") keep the full name.
- **Global search:** add `user.nickname` to `getGloballySearchableAttributes()`. Record titles use `MemberName::optionLabel()`.
- **Clear nickname:** a record action shown only when `nickname !== null`. It asks for confirmation with the heading `__('Clear nickname for :name?')`, then sets `nickname = null`.

- [ ] **Step 1: Write the failing tests** (as an admin, using the Livewire table helpers as in `tests/Feature/FeePeriodsTableTest.php`):
  - `test_users_table_shows_nickname_with_full_name_beneath`: the table renders both `'Fahmi'` and `'Muhammad Fahmi bin Dzulqarnain'` for that row.
  - `test_users_table_search_matches_nickname`: `searchTable('fahmi')` finds the member whose full name doesn't contain "fahmi" (use a full name like `'Ahmad Zulkifli'` with nickname `'Fahmi'`) and leaves out someone else.
  - `test_fee_payments_search_matches_nickname`: the same check on `FeePaymentsTable`.
  - `test_registrations_show_nickname`: the activity registrations relation manager renders the nickname.
  - `test_fee_period_member_option_label`: `MemberName::optionLabel()` returns `'Fahmi (Ahmad Zulkifli)'` and `'Ahmad Zulkifli'` when there's no nickname.
  - `test_admin_can_clear_nickname`: calling the `clearNickname` action leaves `nickname` as `null`. The action is hidden for a member without a nickname.
  - `test_finance_summary_description_includes_full_name_when_nicknamed`.
- [ ] **Step 2: Run the tests and confirm they fail.** Run: `php artisan test --filter=AdminNicknameTest`. Expected: FAIL
- [ ] **Step 3: Implement `MemberName`.** Then swap each `TextColumn::make('user.name')` / `TextEntry::make('user.name')` / `TextColumn::make('name')` listed under Files for the helper. Keep each site's existing `label` and `sortable`; sorting stays on `name`. In `FeePeriodForm`, use `->getOptionLabelFromRecordUsing(fn (User $u) => MemberName::optionLabel($u))` and make the search match `nickname` too (for example `->searchable(['name', 'nickname'])`).
- [ ] **Step 4: Add the read-only nickname** to `UserForm` (`->disabled()->dehydrated(false)`) and the clear action to `UsersTable`, plus `ms` and `id` translations for `Clear nickname`, `Clear nickname for :name?` and `Nickname cleared`.
- [ ] **Step 5: Run the new tests and the related suites.** Run: `php artisan test --filter='AdminNicknameTest|FeePeriod|FeePayment|ViewFeePayment|Finance|Activity'`. Expected: PASS
- [ ] **Step 6: Check by eye** in the browser at desktop and phone width (start the app as in memory `artisan-serve-ignores-env-overrides`, never against prod). The Users table, a fee payment view and Finance Summary should each show the two-line layout.
- [ ] **Step 7: Commit** with `feat: show nicknames on admin screens and search by them`.

### Task 6: API: expose the nickname and let members update it

**Files:**
- Modify: `app/Http/Resources/Api/UserResource.php`
- Modify: `app/Http/Controllers/Api/V1/MeController.php` (add `update()`)
- Modify: `routes/api.php` (`Route::patch('me', [MeController::class, 'update'])` inside the `auth:sanctum` group)
- Test: `tests/Feature/Api/MeNicknameTest.php`

**Interfaces:**
- Consumes: `Nickname::normalise()`, `Nickname::rules()` and `User::$display_name` from Task 1.
- Produces: the `UserResource` JSON gains `nickname: string|null` and `display_name: string`. `PATCH /api/v1/me` takes `{ "nickname": string|null }` and returns `UserResource`. Invalid input returns a standard Laravel 422 with `errors.nickname`.

- [ ] **Step 1: Write the failing tests** (`Sanctum::actingAs`):
  - `test_me_includes_nickname_and_display_name`: with no nickname, `nickname` is `null`, `display_name` equals `name`, and `name` is still the full name.
  - `test_member_can_set_nickname`: `patchJson('/api/v1/me', ['nickname' => ' Fahmi '])` returns 200 with `data.nickname === 'Fahmi'` and `data.display_name === 'Fahmi'`.
  - `test_member_can_clear_nickname`: `['nickname' => null]` gives `nickname === null`.
  - `test_duplicate_is_422`: another user has `'fahmi'`, so `'Fahmi'` returns 422 with `assertJsonValidationErrors(['nickname'])`.
  - `test_too_long_is_422`: 31 characters returns 422.
  - `test_unauthenticated_is_401`.
  - `test_cannot_change_other_fields`: `['name' => 'X', 'nickname' => 'Y']` leaves `name` unchanged.
- [ ] **Step 2: Run the tests and confirm they fail.** Run: `php artisan test --filter=MeNicknameTest`. Expected: FAIL
- [ ] **Step 3: Implement.** In `update()`, normalise the input first, validate with `Nickname::rules($request->user())`, save only `nickname`, and return `new UserResource($user->fresh())`.
- [ ] **Step 4: Run the full suite.** Run: `php artisan test`. Expected: PASS, no regressions.
- [ ] **Step 5: Commit** with `feat: nickname in the member API and PATCH /me`.

### Task 7: Shared KMP: DTO fields, update call and greeting helpers (`pentana-mobile`)

**Files:**
- Modify: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/model/Dtos.kt` (`UserDto`)
- Modify: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/AuthRepository.kt`
- Modify: `shared/src/commonMain/kotlin/my/silentmode/pentana/shared/presentation/SessionManager.kt`
- Test: `shared/src/commonTest/kotlin/my/silentmode/pentana/shared/SessionManagerTest.kt` and `PentanaApiTest.kt`

**Interfaces:**
- Consumes: the Task 6 API contract.
- Produces:
  - In `UserDto`, add `val nickname: String? = null` and `@SerialName("display_name") val displayName: String? = null`. Defaults keep older servers decoding fine.
  - `val UserDto.greetingName: String` returns `nickname` if set, otherwise the first word of `name`. This is today's greeting rule.
  - `val UserDto.avatarName: String` returns `nickname ?: name`, the input for initials.
  - `AuthRepository.updateNickname(nickname: String?): UserDto` sends `PATCH /me` with a JSON body, unwraps `DataEnvelope`, and throws `ApiException` on failure.
  - `SessionManager.updateNickname(nickname: String?): String?` updates `state.user` on success and returns `null`. On failure, it returns a user-facing error. For a 422 that's the server's `serverMessage`, otherwise `"Couldn't save your nickname. Try again."`

- [ ] **Step 1: Write the failing tests**
  - `user_dto_decodes_without_nickname_fields`: JSON without `nickname` or `display_name` decodes, and `greetingName == "Muhammad"` for `"Muhammad Fahmi bin Dzulqarnain"`.
  - `greeting_and_avatar_use_nickname_when_set`.
  - `update_nickname_success_updates_user` (MockEngine answers PATCH `/me` with 200 and nickname `"Fahmi"`): `state.user.nickname == "Fahmi"` and the result is `null`.
  - `update_nickname_422_returns_server_message`: the user is unchanged and the result equals the server message `"That nickname is already taken."`.
  - Assert the request method is `PATCH` and the body is `{"nickname":"Fahmi"}`. For `null`, the body is `{"nickname":null}` (configure `explicitNulls` for this request if the client's Json omits nulls).
- [ ] **Step 2: Run the tests and confirm they fail.** Run: `./gradlew :shared:testDebugUnitTest`. Expected: FAIL
- [ ] **Step 3: Implement** as described under Interfaces, following the `withContext(Dispatchers.Main)` and `client.ensureSuccess` patterns already in `AuthRepository`.
- [ ] **Step 4: Run the tests and confirm they pass.** Run: `./gradlew :shared:testDebugUnitTest`. Expected: PASS
- [ ] **Step 5: Commit** in `pentana-mobile` with `feat(shared): nickname on UserDto and SessionManager.updateNickname`.

### Task 8: iOS: greeting, initials and profile editing (`pentana-mobile/iosApp`)

**Files:**
- Modify: `iosApp/iosApp/ContentView.swift` (lines 41–71 use `pentInitials(session.user?.avatarName)`, and line 83 uses `session.user?.greetingName ?? "there"`)
- Modify: `iosApp/iosApp/ProfileView.swift` (line 24 initials; below the full-name `Text` at line 26, add a nickname row)
- Modify: `iosApp/iosApp/SessionStore.swift` (wrap `updateNickname`)

**Interfaces:**
- Consumes: `greetingName`, `avatarName` and `SessionManager.updateNickname` from Task 7. With SKIE, the Kotlin extension properties appear as top-level functions or properties; see memory `skie-kmp-presentation-sharing` for how they surface.

UX (plan call, following the existing profile list style): keep the full name as the heading, and add a "Nickname" row underneath showing the current value or "Not set". Tapping it opens an edit sheet with a text field (30-character limit), Save and Cancel buttons, a "Clear" option when one is set, and an inline error when `updateNickname` returns a message.

- [ ] **Step 1: Implement** the changes listed above.
- [ ] **Step 2: Build and run** in the simulator (attach the panel first). Log in as a seeded test member, set "Fahmi", and confirm the greeting, initials and profile row update. Clear it and confirm the greeting returns to the first word of the full name. Try a nickname another test user already has and confirm the error appears.
- [ ] **Step 3: Commit** with `feat(ios): nickname greeting and profile editing`.

### Task 9: Android: greeting, initials and profile editing (`pentana-mobile/androidApp`)

**Files:**
- Modify: `androidApp/src/main/kotlin/my/silentmode/pentana/PentanaApp.kt:65,75` (`initials(user.avatarName)`, `HomeScreen(userName = user.greetingName, …)`)
- Modify: `androidApp/src/main/kotlin/my/silentmode/pentana/feature/home/HomeScreen.kt` if it splits `userName` itself. Remove that split so `greetingName` is the only rule.
- Modify: `androidApp/src/main/kotlin/my/silentmode/pentana/feature/profile/ProfileSheet.kt:49-52`

**Interfaces:**
- Consumes: the Task 7 helpers and `SessionManager.updateNickname`.

UX: the same as iOS. Keep the full name as the heading and add a nickname row with an edit dialog that has `OutlinedTextField` (30-character limit), Save, Cancel, Clear and an inline error.

- [ ] **Step 1: Implement** the changes listed above.
- [ ] **Step 2: Build and run** on the emulator against a local server. Never use prod (see memory `android-emulator-dev-server`). Run the same manual checks as Task 8.
- [ ] **Step 3: Commit** with `feat(android): nickname greeting and profile editing`.
