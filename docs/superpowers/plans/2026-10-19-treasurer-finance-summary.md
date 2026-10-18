# Treasurer Finance Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the treasurer a Finance summary page (totals, who owes, remind, CSV export) and restrict all of Finance to new treasurer / super-admin roles.

**Architecture:** Two booleans on `users` plus `User::canManageFinance()` replace `is_admin` in every finance gate. A `FinanceSummary` service builds one aggregated query over `users` (outstanding, unpaid count, oldest unpaid, credit) that the page table, the totals tiles and the CSV export all share, so the three can never disagree. The page is a Filament page in `NavGroup::Finance`.

**Tech Stack:** Laravel 12, Filament 5, Livewire, PHPUnit 11 (class-style tests, `RefreshDatabase`, SQLite in tests / Postgres in prod).

**Spec:** `docs/superpowers/specs/2026-10-19-treasurer-finance-summary-design.md`

## Global Constraints

- No queue worker in production: nothing may dispatch a job; Filament's queued `ExportAction` is not used. Reminders send inline.
- Every finance check calls `User::canManageFinance()` — never `is_super_admin` / `is_treasurer` directly (role badge/filter on Users excepted).
- Balances must equal `FinanceService::outstanding()` (per-bill remainder clamped at 0) and `User::getCreditAttribute()` exactly.
- Queries must run on Postgres as well as SQLite: no SQLite-only functions; cast aggregate results with `(float)` (Postgres returns numerics as strings).
- Livewire input is untrusted: actions re-load the member by id and re-check `canManageFinance()` server-side.
- Every new UI string goes through `__()` and gets an entry in `lang/ms.json` and `lang/id.json`.
- Money is displayed as `MYR` with 2 decimals, matching existing tables (`->money('MYR')`).

## Review Focus

1. Member with one over-paid bill and one unpaid bill — owes only the unpaid remainder (clamp, no offset). Test in Task 5.
2. Pending and rejected proofs — count toward neither credit, collected, nor allocations. Test in Task 5.
3. Plain admin mounting the summary page or calling its Remind action directly — 403 / action not run. Test in Task 6.
4. Names with commas, quotes or non-ASCII (e.g. `Lim, "Jay" 林`) — CSV stays one row per member and opens correctly in Excel. Test in Task 7.
5. Member with credit but nothing owed — absent under "Owing only", present with credit under "All active". Test in Task 6.

---

### Task 1: Roles on the user model

**Files:**
- Create: `database/migrations/2026_10_19_000001_add_finance_roles_to_users_table.php`
- Create: `app/Console/Commands/MakeSuperAdmin.php`
- Modify: `app/Models/User.php` (fillable, casts, helpers)
- Modify: `database/factories/UserFactory.php` (states)
- Test: `tests/Feature/Finance/FinanceRolesTest.php`

**Interfaces:**
- Produces: columns `users.is_super_admin`, `users.is_treasurer` (boolean, default false); `User::canManageFinance(): bool`; `User::isSuperAdmin(): bool` (`is_admin && is_super_admin`); factory states `UserFactory::treasurer()` and `UserFactory::superAdmin()` (both also set `is_admin => true`); command `app:make-super-admin {email}`.

- [ ] **Step 1: Write the failing tests** in `FinanceRolesTest`:
  - `test_only_admins_with_a_finance_role_can_manage_finance`: member → false; `admin()` → false; `treasurer()` → true; `superAdmin()` → true; `['is_admin' => false, 'is_treasurer' => true]` → false.
  - `test_make_super_admin_command_promotes_the_user`: run `app:make-super-admin alice@example.com` → success; user has `is_admin` and `is_super_admin` true.
  - `test_make_super_admin_command_fails_for_unknown_email`: exit code failure, output contains `No user`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Finance/FinanceRolesTest.php` — expected FAIL (method/command missing).
- [ ] **Step 3: Implement** migration (two `boolean(...)->default(false)` after `is_admin`), add both to `$fillable` and `casts()` as boolean, the two helpers, the two factory states, and the command (look up by email, `forceFill` + save, print `:name is now a super-admin.`; `self::FAILURE` with `No user with email :email.` otherwise).
- [ ] **Step 4: Run** the test file — expected PASS.
- [ ] **Step 5: Commit** `feat: treasurer and super-admin roles`.

### Task 2: Role management on the Users resource

**Files:**
- Create: `app/Filament/Resources/Users/UserRoleGuard.php`
- Modify: `app/Filament/Resources/Users/Schemas/UserForm.php` (two toggles; disable protected fields)
- Modify: `app/Filament/Resources/Users/Pages/CreateUser.php`, `EditUser.php` (strip via guard; DeleteAction visibility)
- Modify: `app/Filament/Resources/Users/UserResource.php` (`canDelete`)
- Modify: `app/Filament/Resources/Users/Tables/UsersTable.php` (badge states, filter options; `DeleteBulkAction` skips protected)
- Test: `tests/Feature/Finance/UserRoleManagementTest.php`

**Interfaces:**
- Consumes: Task 1 helpers and factory states.
- Produces: `UserRoleGuard::sanitize(array $data, User $actor, ?User $record): array` — removes keys the actor may not change; `UserRoleGuard::isProtected(User $record): bool` (`is_super_admin || is_treasurer`).

Rules `sanitize` enforces (from the spec):
- Non-super-admin actor: always drop `is_super_admin`, `is_treasurer`.
- Non-super-admin actor editing a protected record: also drop `password`, `email`, `email_verified_at`, `is_admin`.
- Super-admin actor editing themself: drop `is_admin` and `is_super_admin` if they would become false.

- [ ] **Step 1: Write the failing tests** (Livewire `EditUser` / `CreateUser`, acting as the given user):
  - `test_super_admin_sees_and_can_set_role_toggles`: fill `is_treasurer => true` → saved.
  - `test_plain_admin_cannot_grant_roles_even_by_crafted_payload`: plain admin `->set('data.is_treasurer', true)->call('save')` → record still false; same on create.
  - `test_plain_admin_cannot_change_a_treasurers_password_email_or_admin_switch`: set `data.email`, `data.password`, `data.is_admin=false`, `data.name='New'` → only name changed; password hash unchanged.
  - `test_plain_admin_cannot_delete_a_treasurer`: `UserResource::canDelete($treasurer)` false for plain admin, true for super-admin; plain-admin bulk delete of `[$treasurer, $member]` deletes only `$member`.
  - `test_super_admin_cannot_remove_own_super_admin_or_admin_switch`.
  - `test_users_table_shows_treasurer_and_super_admin_badges_and_filters`: filter `account_status=treasurer` lists only treasurers.
- [ ] **Step 2: Run** — expected FAIL.
- [ ] **Step 3: Implement.** Toggles labelled `Super-admin` and `Treasurer` with helper text `Can manage finance: payments, bills and the finance summary.` (treasurer) / `Full access, including granting roles.` (super-admin); `->visible(fn () => auth()->user()?->isSuperAdmin())`. Protected-record fields `->disabled()` for non-super-admins (cosmetic; `sanitize` is the real gate, called from `mutateFormDataBeforeSave` / `mutateFormDataBeforeCreate`). Badge order: `super-admin`, `treasurer`, `admin`, then existing states; filter options gain the same two.
- [ ] **Step 4: Run** the test file plus `tests/Feature/Filament/UsersTableActionsTest.php` — expected PASS.
- [ ] **Step 5: Commit** `feat: only super-admins grant finance roles; protect finance accounts`.

### Task 3: Lock Finance to treasurer and super-admin

**Files:**
- Modify: `app/Filament/Resources/FeePeriods/FeePeriodResource.php` (`canAccess`)
- Modify: `app/Filament/Resources/FeePayments/FeePaymentResource.php` (nav group/label/sort, `canEdit`, `canDelete`, `getEloquentQuery`)
- Modify: `app/Filament/Resources/FeePayments/Tables/FeePaymentsTable.php:21` (`$isAdmin` → `canManageFinance`)
- Modify: `app/Filament/Resources/FeePayments/Actions/PaymentReviewActions.php:101`
- Modify: `app/Filament/Widgets/NeedsAttention.php` (`paymentsToVerify` + `unpaidBills` only for finance users)
- Modify existing tests that exercise finance as `admin()`: `FeePeriodsTableTest`, `FeePeriodFormTest`, `ViewFeePaymentTest`, `PaymentVerificationTest`, `PaymentDeletionTest`, `PaymentRejectedNotificationTest`, `PaymentVerifiedNotificationTest`, `Filament/NavigationAndDashboardTest` → `treasurer()` where they act on finance.
- Test: `tests/Feature/Finance/FinanceAccessTest.php`

**Interfaces:**
- Consumes: `User::canManageFinance()`.

- [ ] **Step 1: Write the failing tests:**
  - `test_plain_admin_cannot_open_fee_periods`: GET `FeePeriodResource::getUrl('index')` → 403; treasurer → 200.
  - `test_plain_admin_sees_fee_payments_as_my_payments`: as plain admin, `ListFeePayments` shows own proof, not another member's; nav label `My Payments`.
  - `test_plain_admin_cannot_verify_a_payment`: verify action hidden on another member's pending proof, and edit page GET → 403.
  - `test_needs_attention_hides_finance_tiles_from_plain_admins`: plain admin dashboard does not see `Payments to verify` / `Unpaid bills this month`, does see `Next lunch`; treasurer sees all four.
  - `test_global_search_hides_finance_records_from_plain_admins`.
- [ ] **Step 2: Run** — expected FAIL.
- [ ] **Step 3: Implement** the swaps listed under Files. In `NeedsAttention::getStats()` build the array conditionally; keep tile order for finance users unchanged. Update `$columns` so 2 tiles don't leave gaps (`['md' => 2]` for plain admins).
- [ ] **Step 4: Run** the full suite `php artisan test` — expected PASS (existing finance tests now using `treasurer()`).
- [ ] **Step 5: Commit** `feat: restrict finance to treasurer and super-admin`.

### Task 4: Record when a payment was verified

**Files:**
- Create: `database/migrations/2026_10_19_000002_add_verified_at_to_payment_proofs_table.php`
- Modify: `app/Models/Payment/PaymentProof.php` (fillable, `verified_at` datetime cast)
- Modify: `app/Services/FinanceService.php` (`verifyAndAllocate` sets `verified_at => now()`)
- Test: `tests/Feature/FinanceServiceTest.php` (add)

- [ ] **Step 1: Write the failing tests:** `test_verifying_records_verified_at` (freeze time; `verified_at` equals now); `test_migration_backfills_verified_at_from_updated_at` (insert a verified proof with `verified_at` null, run the migration's backfill closure/method, assert equals `updated_at`; pending proof stays null).
- [ ] **Step 2: Run** — expected FAIL.
- [ ] **Step 3: Implement.** Migration: nullable `timestamp('verified_at')`, then `DB::table('payment_proofs')->where('status','verified')->whereNull('verified_at')->update(['verified_at' => DB::raw('updated_at')])`.
- [ ] **Step 4: Run** — expected PASS.
- [ ] **Step 5: Commit** `feat: record when a payment proof was verified`.

### Task 5: FinanceSummary service (the numbers)

**Files:**
- Create: `app/Services/FinanceSummary.php`
- Test: `tests/Feature/Finance/FinanceSummaryTest.php`

**Interfaces:**
- Consumes: `verified_at` (Task 4), `User::scopeEnabled`.
- Produces:
  - `FinanceSummary::members(): Builder` — `User::query()->enabled()` with selected aliases `owes`, `unpaid_count`, `oldest_unpaid` (date or null), `credit`, `paid_this_month` (bool: this month's bill has status `paid`), eager-loading `memberCategory`. Includes members owing nothing.
  - `FinanceSummary::totals(): array{outstanding: float, owing_members: int, collected_this_month: float, collected_all_time: float, credit: float, credit_members: int, pending_count: int, oldest_pending_at: ?Carbon}`.

Per-member expressions (correlated subqueries, portable SQL):
- `owes` = `SUM(CASE WHEN fp.amount_due - COALESCE(a.paid,0) > 0 THEN fp.amount_due - COALESCE(a.paid,0) ELSE 0 END)` over the member's `fee_periods`, `a.paid` = allocation sum per bill.
- `unpaid_count` / `oldest_unpaid` = count / `MIN(month_date)` of the same bills where remainder > 0.
- `credit` = `SUM(amount_verified)` of verified proofs − `SUM(amount_allocated)` of allocations on verified proofs.
- Totals: outstanding/credit sum over `members()`; collected = `SUM(amount_verified)` of verified proofs (all users, including disabled) for `verified_at` in the current month / all time; pending from `status = 'pending'`.

- [ ] **Step 1: Write the failing tests:**
  - `test_owes_matches_finance_service_for_mixed_bills`: member with bills 100 unpaid, 50 partial (20 paid), 30 over-paid (create a `PaymentAllocation` of 40 on the 30 bill directly) → `owes` = `FinanceService::outstanding(FeePeriod::forMember($id)->get())` = 130.00; `unpaid_count` 2; `oldest_unpaid` the 100 bill's month.
  - `test_credit_matches_user_credit_attribute`: proof verified 200 against 150 of bills → `credit` 50.00 == `$user->credit`.
  - `test_pending_and_rejected_proofs_count_nowhere`: pending 80 + rejected 60 → `credit` 0, `collected_all_time` 0, `pending_count` 1.
  - `test_disabled_members_are_excluded_from_members_and_outstanding_but_counted_in_collected`.
  - `test_totals`: three members → exact `outstanding`, `owing_members`, `collected_this_month` (one proof verified last month excluded), `credit_members`, `oldest_pending_at`.
  - `test_paid_this_month_flag`: paid bill → true; partial → false; no bill → false.
- [ ] **Step 2: Run** — expected FAIL.
- [ ] **Step 3: Implement** with `selectSub` per alias on `User::query()`; cast in callers, not SQL.
- [ ] **Step 4: Run** — expected PASS.
- [ ] **Step 5: Commit** `feat: finance summary query`.

### Task 6: Finance summary page

**Files:**
- Create: `app/Filament/Pages/FinanceSummary.php` + `resources/views/filament/pages/finance-summary.blade.php` (if the page needs a view; otherwise header widgets + `HasTable`)
- Create: `app/Filament/Widgets/FinanceTotals.php` (StatsOverviewWidget, not registered on the dashboard)
- Modify: `app/Filament/Resources/FeePeriods/Tables/FeePeriodsTable.php` (add `SelectFilter::make('user_id')` relationship `user`/`name`, searchable)
- Test: `tests/Feature/Finance/FinanceSummaryPageTest.php`

**Interfaces:**
- Consumes: `FinanceSummary::members()`, `FinanceSummary::totals()`, `BillReminder(float $outstanding, int $unpaidCount)`.
- Produces: page route `FinanceSummary::getUrl()`; table filter name `scope` with values `owing` (default) / `all`.

Page spec (from the spec + agreed mockup): title `Finance summary`, nav sort 0 in Finance, `canAccess()` = `canManageFinance()`. Tiles in order: Outstanding (`N members owe`, danger colour when > 0), Collected this month (`MYR x all time`), Credit held (`Paid ahead by N members`), Payments to verify (`oldest :age` / `All checked`, warning when > 0, links to `FeePaymentResource` index). Table columns: name (+ category description), owes (money, sortable, default desc), unpaid months (with `partial` badge when the oldest unpaid bill is partial), oldest unpaid (`F Y`), credit (money). Searchable by name.
Row actions: `Bills` → `FeePeriodResource::getUrl('index', ['filters' => ['user_id' => ['value' => $id]]])`; `Remind` visible when `owes > 0`, confirmation `Send a reminder to :name for MYR :amount across :count bills?`, action re-loads `User::enabled()->findOrFail($id)`, aborts 403 unless `auth()->user()->canManageFinance()`, recomputes with `FinanceService::outstanding`, sends `BillReminder` via `notify()` in try/catch (`report()` + warning toast `The member could not be notified.`), success toast `Reminder sent to :name`.

- [ ] **Step 1: Write the failing tests:**
  - `test_only_finance_users_can_open_the_page`: member 403, plain admin 403, treasurer 200, super-admin 200; page absent from plain admin nav.
  - `test_owing_only_by_default_sorted_by_amount`: members owing 50 and 300 and one owing 0 with credit 20 → rows 300, 50; filter `scope=all` adds the credit-only member showing `20.00`.
  - `test_tiles_show_totals`: assertSee formatted values from a known dataset.
  - `test_remind_sends_one_inline_reminder_with_current_balance`: `Notification::fake()`; call action → `BillReminder` with outstanding 300.0, unpaidCount 6 to that member only.
  - `test_remind_is_refused_for_plain_admin_calling_the_action_directly`: acting as plain admin, `Livewire::test(FinanceSummary::class)` → forbidden; `Notification::assertNothingSent()`.
  - `test_bills_action_links_to_the_members_fee_periods`: `ListFeePeriods` with `filters.user_id.value` shows only that member's bills.
- [ ] **Step 2: Run** — expected FAIL.
- [ ] **Step 3: Implement** the page, widget and filter.
- [ ] **Step 4: Run** the file — expected PASS.
- [ ] **Step 5: Commit** `feat: finance summary page with reminders`.

### Task 7: CSV export

**Files:**
- Create: `app/Services/FinanceSummaryCsv.php`
- Modify: `app/Filament/Pages/FinanceSummary.php` (header action `Export`)
- Test: `tests/Feature/Finance/FinanceSummaryExportTest.php`

**Interfaces:**
- Consumes: the page table's filtered + searched query (`$this->getFilteredSortedTableQuery()`), built on `FinanceSummary::members()`.
- Produces: `FinanceSummaryCsv::stream(Builder $members, CarbonInterface $date): StreamedResponse`.

Format: UTF-8 BOM (`"\xEF\xBB\xBF"`), `fputcsv`, header row `Name, Email, Category, Owes (MYR), Unpaid months, Oldest unpaid, Credit (MYR), Paid this month` (translated), amounts as `0.00`, oldest as `Y-m`, paid this month `Yes`/`No`; filename `finance-summary-YYYY-MM-DD.csv`. Synchronous `response()->streamDownload` — no `ExportAction`, no jobs.

- [ ] **Step 1: Write the failing tests:**
  - `test_export_respects_filter_and_search`: default filter excludes the zero-owing member; search `Ahmad` returns one data row.
  - `test_export_quotes_awkward_names_and_starts_with_bom`: member `Lim, "Jay" 林` → content starts with BOM, `str_getcsv` on line 2 gives name exactly `Lim, "Jay" 林`, exactly 2 lines + trailing newline.
  - `test_export_filename_and_columns`: `Content-Disposition` contains `finance-summary-2026-10-19.csv` (frozen date); header row matches; `Bus::fake()` asserts nothing dispatched.
- [ ] **Step 2: Run** — expected FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — expected PASS.
- [ ] **Step 5: Commit** `feat: export finance summary as CSV`.

### Task 8: Translations, Postgres check, deploy notes

**Files:**
- Modify: `lang/ms.json`, `lang/id.json`
- Modify: `docs/deployment-internal-server.md` (post-deploy steps)
- Test: existing `tests/Feature/Localisation/*` (they check every `__()` key has translations)

- [ ] **Step 1: Run** `php artisan test tests/Feature/Localisation` — expected FAIL listing the new keys.
- [ ] **Step 2: Add** Malay and Indonesian strings for every new key.
- [ ] **Step 3: Run** the full suite `php artisan test` — expected PASS.
- [ ] **Step 4: Postgres check.** Following the `artisan-serve-ignores-env-overrides` memory, run the `FinanceSummary::members()` and `totals()` queries against the prod-shaped Supabase database read-only (tinker with `toSql()` + `get()`, no writes) and spot-check three members against `FinanceService::outstanding()` and `->credit`. Expected: identical values, no SQL errors.
- [ ] **Step 5: Document deploy steps:** `php artisan migrate`, `php artisan app:make-super-admin <email>`, then set the treasurer's switch on before announcing (current admins lose finance access until then).
- [ ] **Step 6: Commit** `chore: finance summary translations and deploy notes`.
