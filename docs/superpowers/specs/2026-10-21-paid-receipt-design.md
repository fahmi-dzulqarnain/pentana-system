# Paid receipt for verified payments — design

Date: 2026-10-21 · Status: draft for review

## Intent

Members pay their monthly fees by bank transfer and upload proof; an admin verifies it
(`PaymentReviewActions::verify()` → `FinanceService::verifyAndAllocate()`). Today the member
only gets a "Payment verified" email/bell/push. They have no document that proves PENTANA
received their money.

We want an **official, auto-generated receipt** the member can keep as proof of payment. It
must behave like a real receipt: one unique number, issued once, never changing afterwards.

## Goals

- Every payment verified from launch onwards gets exactly one receipt with a gapless yearly
  number: `PEN-2026-0001`, `PEN-2026-0002`, … (sequence restarts each calendar year, based on
  the verification date in the app timezone).
- The receipt content is **frozen at verification time**: member name and email, amount
  received, months covered with the amount applied to each, the amount that went to credit,
  and the verification date.
- The PDF is **rendered on demand** from that frozen record. No PDF file is stored, and every
  download of the same receipt is identical.
- Delivery:
  - attached as a PDF to the existing `PaymentVerified` email;
  - a **Download receipt** action on the payment's view page for the member and for finance
    admins (one shared panel and resource, gated by role);
  - an authenticated API endpoint so the iOS and Android apps can download and show/share it;
  - the existing push notification (APN) keeps linking to the payment, where the download
    now appears.
- If an admin deletes a verified payment, its receipt is **voided, not deleted**: the record
  and number stay, the PDF shows a VOID watermark and the void date, and the number is never
  reused.

## Non-goals

- No backfill: payments verified before launch get no receipt. Inventing numbers and month
  splits after the fact would not be a true snapshot.
- No tax invoice / e-invoice (LHDN MyInvois), no SST fields.
- No issuer address, registration number or settings page. The header is just the PENTANA
  name and logo.
- No signature or verifier name. A footer line says it's computer-generated.
- No translations. The receipt is always in English, whatever the member's locale.
- No payment gateway. Payments stay manual by design.

## Anti-goals (would make this a failure even if it "works")

- A receipt whose months or amounts change after it was issued.
- Duplicate or reused receipt numbers, or gaps caused by failed/rolled-back verifications.
- One member being able to download another member's receipt (via a web URL or the API).
- A PDF-rendering or email failure undoing or blocking the verification itself. The
  verification already "never fails because of notification" (`notifyMember()` uses
  `rescue`), and receipts must keep that property.

## Agreed layout (option A, "Classic itemised")

A4 portrait, single page:

- **Header:** logo + "PENTANA" + "Official Receipt" on the left. On the right, the receipt
  number, "Issued <date>" and a green **PAID** badge.
- **Received from:** member name, email.
- **Table** (Description | Amount (MYR)): one row per month **this approval settled**, as
  "Monthly fee: September 2026", with the full amount applied to that month in this
  approval. Then, when they apply: "Less: existing credit applied" (negative), and "Added
  to credit". Then **Total received**. Example: MYR 20 old credit plus a MYR 30 payment for a
  MYR 30 September bill reads "September 2026: 30.00 · Less: existing credit applied:
  −20.00 · Added to credit: 20.00 · Total received: 30.00". *(User decision 2026-10-21: show
  what the approval settled, not only this payment's own ringgit.)*
- "Payment verified on <date>."
- **Footer:** "This is a computer-generated receipt; no signature is required."
- **Voided:** the same document with a large diagonal translucent red **VOID** watermark,
  "Voided on <date>", and a footer line "This receipt is no longer valid." The PAID badge is
  hidden.

Mockup: `.superpowers/brainstorm/32485-1792586569/content/receipt-layout.html` (not
committed). Row labels use the fee period's month. If a period's `type` is not the regular
monthly fee, use its type/remarks for the label (builder's call on exact wording).

## Constraints from the existing system

- **Allocations shift over time.** `FinanceService::applyAvailableFunds()` re-spreads every
  verified ringgit across unpaid bills whenever a payment is verified or deleted. So the
  months must be **copied** into the receipt, not read live from `payment_allocations`.
- **Snapshot point:** inside the same DB transaction as `verifyAndAllocate()`. Note the
  member's id set of `payment_allocations` *before* `applyAvailableFunds()`, then after it,
  take **every allocation that run created** for the member, whichever proof it drew from.
  The allocator uses the oldest proof first, so older credit is often spent before the new
  payment. Group those new allocations by fee period → `lines`. `credit_used` = the
  part of them drawn from *other* proofs. `credit_added` = `amount_verified` − this
  proof's own allocations. Use integer cents like `FinanceService` does.
  (The member's later bills may draw on this proof's leftover credit. Those settlements belong
  to that later approval, not to this receipt.)
- **Only `pending` proofs can be verified** (`reviewable()`), and only once, so there is one
  receipt per proof.
- **Deletion path:** `PaymentProof::booted()` releases funds on `deleting`. The receipt must
  outlive the proof: `payment_proof_id` is nullable with `nullOnDelete`, and voiding happens
  in that same `deleting` hook (or alongside it), setting `voided_at`.
- **Gapless numbering on Postgres (prod) and SQLite (tests).** Issue the number inside the
  verify transaction, so a rollback releases it. Use a per-year counter row taken with
  `lockForUpdate()` (a `receipt_counters` table keyed by year is my call), plus a unique index
  on `(year, sequence)` and on `number` as a backstop. SQLite ignores row locks, so the
  concurrency guarantee must be checked against Postgres (see memory: SQLite tests vs
  Postgres prod).
- **No queue worker.** Everything runs inline: snapshot on verify, render PDF when the email
  is built or a download is requested.
- **Email budget / spool.** `BudgetedTransport` stores over-budget mail as raw MIME in
  `queued_emails`, so the attachment survives the spool. Each spooled row gets bigger by the
  PDF size, so keep the PDF small (target < 100 KB; no embedded web fonts beyond what's
  needed).
- **Member deletion.** `payment_proofs.user_id` is `cascadeOnDelete` at the DB level, so
  deleting a member removes their proofs **without** running the `PaymentProof` deleting
  hook. *(User decision: the member's receipts stay valid, because the money really was
  received.)* So `payment_receipts.user_id` is `nullOnDelete` and `payment_proof_id` is
  `nullOnDelete`. The receipt is *not* voided, and it keeps rendering from its snapshot name and
  email. Only an explicit proof delete (which runs the Eloquent hook) voids. A test should
  pin both paths.
- **Concurrent verify.** Lock the proof row (`lockForUpdate`) at the start of the verify
  transaction and re-check `status = pending`. Also add a unique index on
  `payment_receipts.payment_proof_id`, so two admins can't issue two receipts.
- **Authorization:** members may only fetch their own receipts. Admins with
  `canManageFinance()` may fetch any. Never trust a Livewire public property for the record.
  Re-resolve it on the server (see memory: Livewire public props are tamperable).
- **Filament panels don't load `app.js`.** The download should be a plain HTTP response
  (an action returning a streamed download, or a signed/authenticated route), not custom JS.

## Data model (my call, builder may refine)

`payment_receipts`:

| column | notes |
|---|---|
| id | |
| number | string, unique, e.g. `PEN-2026-0042` |
| year, sequence | ints, unique together |
| user_id | nullable FK users, `nullOnDelete` (see "Member deletion" below) |
| payment_proof_id | nullable FK, `nullOnDelete` |
| member_name, member_email | snapshot |
| amount_received | decimal(10,2) = `amount_verified` |
| credit_used | decimal(10,2), ≥ 0: existing credit (from older proofs) this approval spent |
| credit_added | decimal(10,2), ≥ 0: part of this payment left over as credit |
| lines | json: `[{ "label": "Monthly fee: September 2026", "month": "2026-09-01", "amount": "30.00" }]` ordered by month |
| verified_at | snapshot of the proof's `verified_at` |
| voided_at | nullable timestamp |
| timestamps | `created_at` = issued at |

Invariant: sum(lines.amount) − credit_used + credit_added = amount_received.

## Surfaces

1. **Service:** a `ReceiptService` (or methods on `FinanceService`) with `issueFor(PaymentProof)`
   (called inside `verifyAndAllocate`'s transaction) and `voidFor(PaymentProof)`. Plus a
   renderer that turns a `PaymentReceipt` into PDF bytes from a Blade view.
2. **PDF library:** none is installed yet. `barryvdh/laravel-dompdf` is my call (pure PHP,
   no headless Chrome on the server). The builder may pick another if it renders the layout
   better, as long as it needs no extra system binaries on the server.
3. **Email:** `PaymentVerified::toMail()` attaches `PEN-2026-0042.pdf` when the proof has a
   receipt. If rendering throws, send the email without the attachment and log it. Don't
   fail the notification.
4. **Web:** there is one Filament panel (`account`); admins and members share
   `FeePaymentResource`, gated by `canManageFinance()`. Add a "Download receipt" header action
   on `ViewFeePayment`, visible when the proof has a receipt (owner or finance admin). Admins
   also need a finance-only list of all receipts (voided ones, and ones whose payment or
   member is gone) with download. A simple resource or page is enough; the builder decides.
   Members can't see a voided receipt, because its payment is gone.
   **No notification is sent when a receipt is voided** (user decision); the member's
   emailed PAID copy simply stays out there.
5. **API:** `GET /api/v1/payment-proofs/{proof}/receipt` returns `application/pdf` (owner
   only, 404 otherwise). `PaymentProofResource` gains `receipt_number` (nullable) and
   `has_receipt` so the apps know when to show the button.
6. **Mobile (separate change in `pentana-mobile`):** on a verified payment in Bills
   (`iosApp/iosApp/BillsView.swift`, Android `feature/bills`, shared `BillsRepository` /
   `Dtos.kt`), add a **Receipt** button that downloads the PDF with the auth token, then
   opens the system share/preview sheet (iOS QuickLook / share sheet, Android
   `ACTION_VIEW` via FileProvider). This ships after the API is live.

## Testing expectations

- Feature tests: old-credit case (the example in the layout section) gives the right lines
  and invariant, member deletion keeps receipts valid, number issued on verify, gapless across verifies, year rollover, a rollback
  issues no number, snapshot unchanged after later allocations shift, void on delete keeps
  the number, owner-only access (web + API), admin access, email has the attachment,
  render failure still sends the email.
- One manual check against Postgres (Supabase) for the counter lock. Dev shares the prod
  database, so do it on a throwaway schema or local Postgres, not prod.
- Visual check of the rendered PDF (normal + voided) before merge.

## Left to the builder

PDF library choice (within the constraints above), exact Blade/CSS, file layout, the exact
names of classes and routes, how admins list voided receipts, and how the mobile apps preview
the PDF. Anything marked "my call" above was decided by Claude, not by the user.
