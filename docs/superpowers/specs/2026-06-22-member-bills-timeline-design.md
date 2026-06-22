# Member Bills → Dashboard Timeline — Design Spec

Date: 2026-06-22
Scope: Remove the Fee Periods page from members; show their bills as a timeline
widget on the dashboard. Admins keep the full Fee Periods resource.

## Decisions (from brainstorming)

- **Members: timeline only.** Fee Periods resource becomes admin-only; members see
  their bills exclusively through a new dashboard widget.
- **Widget visibility: members only.** Admins manage bills via the resource, so the
  widget is hidden from them (`canView() => !is_admin`).
- **Order: newest bill first**, with an outstanding + available-credit summary header.

## 1. FeePeriodResource → admin-only

Replace the member-scoping (`getEloquentQuery`) and the redundant per-action
`canCreate/canEdit/canDelete` overrides with a single `canAccess() => is_admin`
(matching UserResource / MemberCategoryResource). This hides nav + blocks routes for
members; admins keep full CRUD via Filament defaults. Remove the now-unused
`Builder` / `Model` imports.

## 2. Dashboard widget: MyBillsTimeline

`app/Filament/Widgets/MyBillsTimeline.php` (custom `Filament\Widgets\Widget`) +
`resources/views/filament/widgets/my-bills-timeline.blade.php`. Auto-discovered by the
panel's existing `discoverWidgets()`.

- `canView(): bool` → `! is_admin` (members only).
- `$columnSpan = 'full'`, `$sort = -3` (top of dashboard).
- `getBills()` → current user's FeePeriods, `withSum('allocations','amount_allocated')`,
  `orderByDesc('month_date')`, memoized per render (no N+1).
- `getOutstanding()` → sum of `max(0, amount_due - paid)` across bills.
- `getCredit()` → `$user->credit`.
- View: summary header (Outstanding, Available credit) + vertical timeline. Each row:
  month (`F Y`), amount due, status badge (Paid / Partial / Unpaid / Free), and
  paid/remaining. Empty state: "No bills yet."
- Status → badge mapping: paid+free → "Free"/gray, paid → "Paid"/success,
  partial → "Partial"/warning, else → "Unpaid"/danger.

## 3. Tests (TDD, sqlite in-memory)

- Access: members **cannot** access `FeePeriodResource` (`canAccess()` false); admins can.
- Widget visibility: `canView()` true for members, false for admins.
- Widget data: renders the member's own bills (month/amount) and not another member's.
- Summary: `getOutstanding()` / `getCredit()` reflect real allocations (via FinanceService).

## Out of scope

- FilamentInfoWidget promo on the dashboard (offered separately).
- FeePayments resource (members still submit proofs there, unchanged).
