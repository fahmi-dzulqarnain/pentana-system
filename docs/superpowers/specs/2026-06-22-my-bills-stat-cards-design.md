# "My Bills" Stat Cards — Design Spec

Date: 2026-06-22
Scope: Replace the plain "My Bills" summary with Filament stat cards (the
StatsOverviewWidget look from the reference image), keeping a per-bill list below.

## Decision (from brainstorming)

- **Cards + bills list** (not cards-only): a stat-card row on top, the per-bill list beneath.
- Two members-only dashboard widgets.

## 1. MyBillsStats (new)

`app/Filament/Widgets/MyBillsStats.php` extending `Filament\Widgets\StatsOverviewWidget`.
- `canView() => !is_admin` (members only); `$sort = -4` (above the list); `$heading = 'My Bills'`.
- Three `Filament\Widgets\StatsOverviewWidget\Stat` cards (value + description + trend icon/color):
  1. **Outstanding** — `MYR x.xx`; >0 → "N bill(s) due" (danger, exclamation-circle); 0 → "You're all settled" (success, check-circle).
  2. **Available credit** — `MYR x.xx`; "Applied to your next bill" (gray, wallet).
  3. **Paid to date** — `MYR x.xx`; "Across N bill(s)" (success, banknotes).
- Helpers: `getOutstanding()`, `getCredit()`, `getPaidToDate()` (moved/added here).

## 2. MyBillsTimeline (existing, slimmed)

- `$heading`/section title → "Bill history"; `$sort = -3`.
- Remove the Outstanding/Available-credit summary header (now in the cards).
- Keep the per-bill timeline list + "No bills yet" empty state.
- Remove `getOutstanding()`/`getCredit()` (relocated to MyBillsStats).

## 3. Shared query

`FeePeriod::scopeForMember(int $userId)` → `where('user_id')`, `withSum('allocations','amount_allocated')`, `orderByDesc('month_date')`. Both widgets call `FeePeriod::forMember(auth()->id())->get()`.

## Tests (TDD, sqlite in-memory)

- `MyBillsStatsTest`: members-only `canView`; `getOutstanding`/`getCredit`/`getPaidToDate`
  correct against real allocations (via FinanceService); renders for a member (assertSee values).
- `MyBillsTimelineTest`: keep visibility + own-bills-list tests; remove the outstanding/credit
  assertion (relocated to the stats test).

## Out of scope

- Admin dashboard (admins use resources; both widgets are members-only).
