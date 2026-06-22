# PENTANA Fixes — Design Spec

Date: 2026-06-22
Scope: Fix credit consumption (#1), custom-fee billing (#2), role-based access (#6),
plus the related small correctness fixes (#3 member_note, #5 migration typo).

## Background

PENTANA is a Laravel 12 + Filament 4 membership-dues system. Members belong to a
`MemberCategory` (with a `default_fee`) and may have a `custom_fee` override. A monthly
cron generates a `FeePeriod` (bill) per user. Members submit a `PaymentProof`; an admin
verifies the actual amount, and `FinanceService` allocates it FIFO (oldest unpaid month
first) via `PaymentAllocation` ledger rows, with any surplus stored as `credit_balance`.

Three problems motivated this work:
- **#1** `credit_balance` is write-only — surplus is added but never spent.
- **#2** `GenerateMonthlyFees` bills `category.default_fee ?? 100`, ignoring `custom_fee`
  (the `active_fee` accessor) and using an inconsistent fallback.
- **#6** No roles — every authenticated user has full admin power over all resources.

## Decisions (from brainstorming)

- **Credit model: derive from proofs.** Drop the scalar `credit_balance`. A verified
  proof's unallocated remainder (`amount_verified − Σ its allocations`) *is* the user's
  credit, so every allocation always links to a real proof. No manual credit grants
  without a proof (accepted trade-off).
- **Access model: shared, role-scoped panel.** Members and admins share `/account`;
  resources are gated and scoped by an `is_admin` flag.

## A. Schema changes (new migrations)

1. `add_is_admin_to_users` — `is_admin` boolean, default `false`.
2. `add_member_note_to_payment_proofs` — `member_note` text, nullable (fixes #3).
3. `drop_credit_balance_from_users` — remove scalar wallet; credit is derived. Reversible.
4. In-place fix of `member_category` → `member_categories` typo in the existing
   `create_member_category_table` migration `down()` (#5; does not touch live data).

## B. Credit engine — FinanceService rewrite (#1)

- **`applyAvailableFunds(User $user): void`** — unified nested-FIFO allocator. Walk
  outstanding periods oldest-first; for each, draw from verified proofs that still have
  remainder, oldest proof first; create a `PaymentAllocation` per draw; update period
  status (`paid`/`partial`/`unpaid`). Leftover proof remainder = credit, implicitly.
- **`verifyAndAllocate(PaymentProof, float)`** — mark verified, then `applyAvailableFunds`.
- **`User::getCreditAttribute(): float`** — sum of verified proofs' unallocated remainders.
  Add `User::paymentProofs(): HasMany`.
- All amounts rounded to 2 dp at each step to avoid float drift.

## C. Billing fix (#2)

`GenerateMonthlyFees` bills `$user->active_fee` (honors `custom_fee`), drops the
`?? 100.00` fallback, and calls `applyAvailableFunds($user)` after creating each bill so
accumulated credit auto-pays it.

## D. Role-scoped access (#6)

`User implements FilamentUser`; `canAccessPanel()` returns true for all authenticated
users. Per-resource gating:

| Resource                  | Member                                   | Admin         |
|---------------------------|------------------------------------------|---------------|
| Users, MemberCategories   | hidden (`canAccess` = is_admin)          | full          |
| FeePeriods (their bills)  | read-only, own only                      | full          |
| FeePayments (proofs)      | create + view own only, **no verify**    | full + verify |

Mechanism: resource `canAccess()`/`canCreate()`/`canEdit()`/`canDelete()` return
`is_admin` where appropriate; `getEloquentQuery()` scopes to `user_id` for members; the
**Verify** action is `visible(is_admin && status === pending)`. `UserForm`'s editable
`credit_balance` field becomes a read-only "Available credit" display; add an `is_admin`
toggle.

## E. Testing (TDD, written first)

Run on an **isolated sqlite connection** — never the live Supabase DB. Verify
`phpunit.xml`/`config/database.php` accordingly.

- Allocation: exact single month, partial, multi-month FIFO, surplus→credit (remainder),
  credit auto-applied to next generated bill, `custom_fee` billing amount.
- Access: member cannot see Users; member query scoped to own records; non-admin cannot
  verify a payment.

## Out of scope

- #4 reject action, #8 full integer-cents refactor (only rounding here),
  admin-creating-payments-on-behalf.
- #7 is ops, not code: live Supabase credentials + `APP_DEBUG=true` in `.env` — treat as
  exposed; not modified here.

## Risks / notes

- No git repo initialized — spec is written but not committed.
- Dropping `credit_balance` runs against live Supabase; migrations are NOT to be run
  against Supabase without explicit approval. Tests use sqlite.
