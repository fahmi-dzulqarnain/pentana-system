# Paid Receipt Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Issue a numbered, frozen PDF receipt whenever an admin verifies a payment, and deliver it by email attachment, a web download, an admin receipts list and an API endpoint.

**Architecture:** The receipt is a `payment_receipts` row created inside `FinanceService::verifyAndAllocate()`'s transaction. It holds a gapless per-year number from a locked `receipt_counters` row plus a snapshot of what that approval settled. A `ReceiptPdf` renderer turns the row into PDF bytes on demand from a Blade view via dompdf, and nothing is stored on disk. Deleting a proof voids its receipt; deleting a member leaves receipts valid.

**Tech Stack:** Laravel 12, Filament 5 (single `account` panel), PHP 8.4+, `barryvdh/laravel-dompdf`, PHPUnit on SQLite (prod is Postgres/Supabase).

**Spec:** `docs/superpowers/specs/2026-10-21-paid-receipt-design.md`

**Out of scope:** iOS/Android buttons in `pentana-mobile`. They get a separate plan once Task 7's API is live.

## Global Constraints

- Number format `PEN-{YYYY}-{NNNN}` (4-digit zero-padded, more digits if > 9999). The sequence restarts each calendar year of `verified_at` in `Asia/Kuala_Lumpur` (the app timezone).
- No gaps, no reuse: the number is issued inside the verify transaction; a rollback releases it.
- Receipts are only issued for proofs verified after launch. No backfill.
- Receipt text is always English, whatever the member's locale. Render it inside `App::withLocale('en', …)` and use English month names.
- Money is computed in integer cents, like `FinanceService::toCents()`.
- Invariant on every receipt: `sum(lines.amount) − credit_used + credit_added = amount_received`.
- Footer copy, exact: "This is a computer-generated receipt; no signature is required."
- Void copy, exact: watermark "VOID", "Voided on {d M Y}", footer "This receipt is no longer valid."
- Only the owner (`user_id`) or a user with `canManageFinance()` may download a receipt. Re-resolve the record server-side; never trust Livewire public props.
- PDF/email failures must never undo or block a verification (keep `notifyMember()`'s `rescue` behaviour).
- No queue: everything runs inline.
- No new system binaries on the server (dompdf is pure PHP). Target PDF size < 100 KB.

## Review Focus

- Credit-only payment (member has no unpaid bills): `lines` is empty, `credit_added = amount_received`, and the PDF still renders with just "Added to credit". Tested in Task 2.
- Amounts with cents (e.g. MYR 25.50 against a MYR 30 bill, then a top-up): the invariant must hold to the cent. Tested in Task 2.
- One approval settling a month from both old credit and the new payment: the result is one line per month, not one per allocation. Tested in Task 2 (old-credit case).
- A receipt whose member was deleted (`user_id` null) still renders from its snapshot name/email, and finance admins can download it. Tested in Tasks 3 and 4.
- Verification at 23:30 on 31 Dec in Malaysia time takes the year of the Malaysian date, not UTC's. Tested in Task 2.

---

### Task 1: Receipt storage, counter and model

**Files:**
- Create: `database/migrations/2026_10_21_000002_create_payment_receipts_table.php`
- Create: `app/Models/Payment/PaymentReceipt.php`
- Modify: `app/Models/Payment/PaymentProof.php` (add `receipt()` relation)
- Modify: `composer.json` / `composer.lock` (`composer require barryvdh/laravel-dompdf`)
- Test: `tests/Feature/Receipts/PaymentReceiptModelTest.php`

**Interfaces:**
- Produces: table `receipt_counters` (`year` unsigned smallint primary, `last_sequence` unsigned int default 0, timestamps).
- Produces: table `payment_receipts`: `id`, `number` string unique, `year` smallint, `sequence` int, unique(`year`,`sequence`), `user_id` nullable FK users `nullOnDelete`, `payment_proof_id` nullable FK payment_proofs `nullOnDelete` **unique**, `member_name` string, `member_email` string nullable, `amount_received` decimal(10,2), `credit_used` decimal(10,2) default 0, `credit_added` decimal(10,2) default 0, `lines` json, `verified_at` timestamp, `voided_at` timestamp nullable, timestamps.
- Produces: `PaymentReceipt` model with casts `lines => array`, `verified_at`/`voided_at => datetime`, money columns `decimal:2`; relations `user()`, `paymentProof()`; `isVoid(): bool`; `filename(): string` returning `"{$number}.pdf"`.
- Produces: `PaymentProof::receipt(): HasOne`.
- Each `lines` item is `['label' => string, 'month' => 'Y-m-d', 'amount' => '30.00']`.

- [ ] **Step 1: Write the failing test** `test_receipt_round_trips_its_snapshot`: create a `PaymentReceipt` with `number = 'PEN-2026-0001'`, two lines and `voided_at = null`. Assert `lines[1]['amount'] === '30.00'`, `isVoid() === false`, `filename() === 'PEN-2026-0001.pdf'`, and that `$proof->receipt->is($receipt)`.
- [ ] **Step 2: Write** `test_deleting_the_proof_row_keeps_the_receipt`: create the receipt, delete the proof row with `DB::table('payment_proofs')->delete()` (which skips the model hooks), and assert the receipt still exists with `payment_proof_id === null`. Add `test_two_receipts_cannot_share_a_proof`, which expects a `UniqueConstraintViolationException`.
- [ ] **Step 3: Run** `php artisan test --filter=PaymentReceiptModelTest`. Expected: FAIL (table/model missing).
- [ ] **Step 4: Implement** the migration, the model and the relation; run `composer require barryvdh/laravel-dompdf`.
- [ ] **Step 5: Run** `php artisan test --filter=PaymentReceiptModelTest`. Expected: PASS.
- [ ] **Step 6: Commit** `feat: payment receipt storage and counter`.

### Task 2: Issue the receipt on verification (numbering + snapshot)

**Files:**
- Create: `app/Services/ReceiptService.php`
- Modify: `app/Services/FinanceService.php:17-28` (`verifyAndAllocate`)
- Test: `tests/Feature/Receipts/IssueReceiptTest.php`

**Interfaces:**
- Consumes: `PaymentReceipt`, `receipt_counters` (Task 1).
- Produces: `ReceiptService::nextNumber(int $year): array{0:int,1:string}`, which returns `[$sequence, $number]`. It must be called inside a transaction: `insertOrIgnore` the year row, select it `lockForUpdate()`, increment `last_sequence`, and save.
- Produces: `ReceiptService::issueFor(PaymentProof $proof, int $allocationIdBefore): PaymentReceipt`.
- Produces: `ReceiptService::lineLabel(FeePeriod $period): string`, which gives `"Monthly fee: September 2026"` for `type = 'regular'` and `"Adjustment: September 2026"` for `'adjustment'`, then appends `" — {remarks}"` when remarks are filled. Other types use `Str::headline($type)`.
- `verifyAndAllocate()` new flow, inside its existing transaction:
  1. Re-select the proof with `lockForUpdate()`; if its `status !== 'pending'`, throw a `RuntimeException`.
  2. `$before = (int) PaymentAllocation::max('id')`.
  3. Update the proof and call `applyAvailableFunds()`, as now.
  4. Call `app(ReceiptService::class)->issueFor($payment->fresh(), $before)`.

Snapshot algorithm for `issueFor`:
- Take the new allocations: `id > $before` and the fee period belongs to `$proof->user_id`.
- Group them by `fee_period_id`, order by `month_date`, sum in cents, and build one line per period.
- `credit_used` is the cents of those new allocations whose `payment_proof_id != $proof->id`.
- `credit_added` is `amount_verified` minus all of this proof's own allocations, in cents.
- `year` is `$proof->verified_at->timezone(config('app.timezone'))->year`.
- `member_name` and `member_email` are snapshotted from the user.

- [ ] **Step 1: Write the failing tests.** Use the `PaymentVerificationTest` setup helpers: a `FeePeriod` with `amount_due`, and verify through `FinanceService::verifyAndAllocate()`.
  - `test_verifying_issues_the_first_number_of_the_year`: freeze the time at 2026-10-21 and assert `number === 'PEN-2026-0001'`; a second member's verify gives `PEN-2026-0002`.
  - `test_sequence_restarts_each_year`: verify at 2026-12-31 15:00 UTC (23:00 MYT), which gives `PEN-2026-0001`. Verify again at 2026-12-31 16:30 UTC (00:30 MYT on 1 Jan), which gives `PEN-2027-0001`.
  - `test_failed_verification_issues_no_number`: make `applyAvailableFunds` throw by binding a partial mock `FinanceService` and calling the real `verifyAndAllocate`. Catch the exception and assert there are 0 receipts and `receipt_counters` has no row or `last_sequence` is 0. The next successful verify gets `-0001`.
  - `test_old_credit_case_lists_what_the_approval_settled`: the member has an older verified proof of 20 with no bills (all credit), then a September bill of 30 is created with `FeePeriod::create`. This is like an admin adding it by hand; `GenerateMonthlyFees` would already have spent the credit. Then a new proof of 30 is verified. Assert `lines == [['label' => 'Monthly fee: September 2026', 'month' => '2026-09-01', 'amount' => '30.00']]`, `credit_used == '20.00'`, `credit_added == '20.00'`, `amount_received == '30.00'`.
  - `test_credit_only_payment`: no bills, verify 50. Assert `lines === []`, `credit_added == '50.00'`, `credit_used == '0.00'`.
  - `test_cents_keep_the_invariant`: the member has an Oct bill of 30. Verify 25.50, then add a Nov bill of 30 and verify 40.25. For each receipt assert `sum(lines) − credit_used + credit_added == amount_received` to the cent. Second receipt: lines Oct 4.50 and Nov 30.00, `credit_added` 5.75.
  - `test_snapshot_does_not_change_when_allocations_shift_later`: verify A (credit left over), store `lines`/`credit_*`, then add a bill and verify B. Assert A's receipt columns are unchanged.
  - `test_verifying_twice_is_refused`: call `verifyAndAllocate` on an already verified proof. Expect a `RuntimeException` and still exactly 1 receipt.
- [ ] **Step 2: Run** `php artisan test --filter=IssueReceiptTest`. Expected: FAIL.
- [ ] **Step 3: Implement** `ReceiptService` and the `verifyAndAllocate` changes as specified above.
- [ ] **Step 4: Run** `php artisan test --filter=IssueReceiptTest`, then `php artisan test --filter='PaymentVerification|FinanceService|PaymentDeletion'`. Expected: all PASS (the existing behaviour is unchanged).
- [ ] **Step 5: Commit** `feat: issue numbered receipt snapshot on payment verification`.

### Task 3: Void on proof delete; keep receipts when a member is deleted

**Files:**
- Modify: `app/Models/Payment/PaymentProof.php:22-26` (`booted`)
- Modify: `app/Services/ReceiptService.php` (add `voidFor`)
- Test: `tests/Feature/Receipts/VoidReceiptTest.php`

**Interfaces:**
- Produces: `ReceiptService::voidFor(PaymentProof $proof): void`. It sets `voided_at = now()` on `$proof->receipt` if it exists and isn't voided yet. Call it in the existing `deleting` closure before `releasePayment`.

- [ ] **Step 1: Write the failing tests.**
  - `test_deleting_a_verified_payment_voids_its_receipt`: verify, then `$proof->delete()`. Assert the receipt exists, `voided_at` is not null, `payment_proof_id` is null, and the number is unchanged; the next verify gets the next sequence (no reuse).
  - `test_deleting_a_member_keeps_their_receipts_valid`: verify, then `$member->delete()`. Assert the receipt exists, `voided_at === null`, `user_id === null`, and `member_name` is unchanged.
- [ ] **Step 2: Run** `php artisan test --filter=VoidReceiptTest`. Expected: the first test FAILS, and the second may already PASS thanks to Task 1's FKs. Keep the second as a regression pin.
- [ ] **Step 3: Implement** `voidFor` and the hook call.
- [ ] **Step 4: Run** `php artisan test --filter='VoidReceiptTest|PaymentDeletionTest'`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: void receipt when its payment is deleted`.

### Task 4: PDF renderer and layout

**Files:**
- Create: `app/Services/ReceiptPdf.php`
- Create: `resources/views/receipts/receipt.blade.php`
- Test: `tests/Feature/Receipts/ReceiptPdfTest.php`

**Interfaces:**
- Consumes: `PaymentReceipt` (Task 1).
- Produces: `ReceiptPdf::html(PaymentReceipt $receipt): string` (the rendered Blade, in English), plus `ReceiptPdf::render(PaymentReceipt $receipt): string`, which returns the PDF bytes from `Pdf::loadHTML($this->html($r))->setPaper('a4')->output()`.
- The layout is option A from the spec's "Agreed layout" section:
  - Logo: `public/images/pentana-mark.png`, embedded as a base64 data URI so dompdf needs no remote fetch.
  - Header: "PENTANA" and "Official Receipt", then the number, "Issued {created_at d M Y}" and a green PAID badge (hidden when voided).
  - "Received from": the name and email.
  - The table, then "Payment verified on {verified_at d M Y}.", then the footer.
  - Table rows: one per line; then "Less: existing credit applied" with `-{credit_used}` if > 0; then "Added to credit" if > 0; then a bold **Total received**.
  - Voided: a translucent rotated "VOID" watermark, "Voided on …", and the void footer line from Global Constraints.
  - Use DejaVu Sans (dompdf's bundled font), with no other web fonts.

- [ ] **Step 1: Write the failing tests.**
  - `test_html_shows_the_snapshot`: use the old-credit receipt from Task 2 (build the `PaymentReceipt` directly). Assert the HTML contains `PEN-2026-0007`, `Monthly fee: September 2026`, `Less: existing credit applied`, `-20.00`, `Added to credit`, `Total received`, `30.00`, the exact footer copy, `PAID`, and not `VOID`.
  - `test_html_is_english_for_a_malay_member`: `App::setLocale('ms')`, build a receipt with an October line, and assert the HTML contains `October 2026`, `Issued` and `Received from`, and does not contain `Oktober`. Afterwards, `App::getLocale()` is still `ms`.
  - `test_voided_html`: set `voided_at` and assert it contains `VOID`, `Voided on`, `This receipt is no longer valid.` and does not contain `>PAID<`.
  - `test_receipt_for_a_deleted_member_still_renders`: `user_id = null`; assert `render()` starts with `%PDF` and the HTML contains the snapshot name.
  - `test_pdf_is_small`: assert `strlen(render()) < 100_000`.
- [ ] **Step 2: Run** `php artisan test --filter=ReceiptPdfTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the renderer and the Blade view.
- [ ] **Step 4: Run** `php artisan test --filter=ReceiptPdfTest`. Expected: PASS.
- [ ] **Step 5: Visual check.** Run `php artisan tinker --execute` to write a normal and a voided sample to `storage/app/receipt-sample.pdf` and `receipt-void-sample.pdf`, open both, and compare them with the mockup. Delete the samples afterwards.
- [ ] **Step 6: Commit** `feat: render payment receipt PDF`.

### Task 5: Attach the receipt to the "Payment verified" email

**Files:**
- Modify: `app/Notifications/PaymentVerified.php` (`toMail`)
- Test: `tests/Feature/PaymentVerifiedNotificationTest.php` (add cases)

**Interfaces:**
- Consumes: `PaymentProof::receipt()`, `ReceiptPdf::render()`, `PaymentReceipt::filename()`.
- `toMail()`: when `$this->payment->receipt` exists, call `->attachData($pdf, $receipt->filename(), ['mime' => 'application/pdf'])`. Wrap the rendering in `rescue(fn () => …, null, report: true)` and skip the attachment when it returns null.

- [ ] **Step 1: Write the failing tests.**
  - `test_verified_email_attaches_the_receipt`: verify through `FinanceService`, build `(new PaymentVerified($proof->fresh()))->toMail($member)`, and assert `rawAttachments` has one entry named `PEN-2026-0001.pdf` with mime `application/pdf`.
  - `test_email_still_builds_when_rendering_fails`: bind a `ReceiptPdf` mock whose `render` throws. Assert `toMail` returns a message with no attachments and the subject "Your payment has been verified".
  - `test_no_attachment_without_a_receipt`: a proof verified before launch (status `verified`, no receipt row) gets no attachments.
- [ ] **Step 2: Run** `php artisan test --filter=PaymentVerifiedNotificationTest`. Expected: the new cases FAIL.
- [ ] **Step 3: Implement** the attachment.
- [ ] **Step 4: Run** `php artisan test --filter='PaymentVerifiedNotificationTest|Email'`. Expected: PASS (the budget/spool tests still pass with the attachment).
- [ ] **Step 5: Commit** `feat: attach receipt PDF to payment verified email`.

### Task 6: Web downloads: payment page action and admin receipts list

**Files:**
- Modify: `app/Filament/Resources/FeePayments/Pages/ViewFeePayment.php` (header action)
- Create: `app/Filament/Resources/PaymentReceipts/PaymentReceiptResource.php` (+ `Pages/ListPaymentReceipts.php`, `Tables/PaymentReceiptsTable.php`), following the `FeePayments` layout and `NavGroup` conventions
- Create: `app/Filament/Resources/PaymentReceipts/Actions/DownloadReceiptAction.php`
- Test: `tests/Feature/Receipts/ReceiptDownloadTest.php`

**Interfaces:**
- Consumes: `ReceiptPdf::render()`, `PaymentReceipt::filename()`.
- Produces: `DownloadReceiptAction::make(Closure $receipt): Action`, named `downloadReceipt`, labelled "Download receipt" with the icon `heroicon-o-arrow-down-tray`. Its action returns `response()->streamDownload(fn () => print(app(ReceiptPdf::class)->render($r)), $r->filename(), ['Content-Type' => 'application/pdf'])`. It is visible only when the receipt is not null and `auth()->user()` owns it or `canManageFinance()`.
- On `ViewFeePayment`, use `DownloadReceiptAction::make(fn (PaymentProof $record) => $record->receipt)` as a header action. Resolving the record through `ViewRecord` already uses `FeePaymentResource::getEloquentQuery()`, which scopes members to their own proofs.
- `PaymentReceiptResource`: read-only (no create/edit/delete) and finance-only (`canViewAny` = `canManageFinance()`). Columns: number, member_name, amount_received (MYR), verified_at, and status (Valid/Void badge). It has a `voided` ternary filter, the row action `DownloadReceiptAction::make(fn (PaymentReceipt $r) => $r)`, and is sorted by `year desc, sequence desc`.

- [ ] **Step 1: Write the failing tests** (on the `account` panel, like `PaymentVerificationTest`).
  - `test_member_downloads_their_receipt`: Livewire `ViewFeePayment` as the owner. `assertActionVisible('downloadReceipt')`, then `callAction('downloadReceipt')->assertFileDownloaded('PEN-2026-0001.pdf')`.
  - `test_member_cannot_open_another_members_payment`: a GET to the other member's view URL returns 404.
  - `test_no_action_without_a_receipt`: `assertActionHidden('downloadReceipt')` on a pending proof.
  - `test_treasurer_downloads_any_receipt`: as `User::factory()->treasurer()`, on the member's payment, the action is visible and the file downloads.
  - `test_receipts_list_is_finance_only`: a member gets 403 on `PaymentReceiptResource::getUrl('index')`; a treasurer gets 200 and sees a voided receipt and one with `user_id = null`, and the download action works from the table (`callTableAction('downloadReceipt', $receipt)->assertFileDownloaded(...)`).
- [ ] **Step 2: Run** `php artisan test --filter=ReceiptDownloadTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the action, the header action and the resource.
- [ ] **Step 4: Run** `php artisan test --filter='ReceiptDownloadTest|ViewFeePaymentTest|PanelAccessTest|FinanceAccessTest'`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: download receipts from payment page and admin receipts list`.

### Task 7: API endpoint for the mobile apps

**Files:**
- Modify: `routes/api.php:50-51`
- Modify: `app/Http/Controllers/Api/V1/PaymentProofController.php` (add `receipt`)
- Modify: `app/Http/Resources/Api/PaymentProofResource.php`
- Test: `tests/Feature/Api/PaymentProofsTest.php` (add cases)

**Interfaces:**
- Produces: `GET /api/v1/payment-proofs/{paymentProof}/receipt` → `PaymentProofController::receipt(Request $request, PaymentProof $paymentProof): Response`. It returns 404 unless `$paymentProof->user_id === $request->user()->id` and a receipt exists. Otherwise it returns a 200 with the PDF bytes, `Content-Type: application/pdf` and `Content-Disposition: attachment; filename="PEN-2026-0001.pdf"`.
- Produces: `PaymentProofResource` adds `receipt_number` (string|null) and `has_receipt` (bool). Eager-load `receipt` in `index`.
- Mobile follow-up contract: these two fields plus this endpoint.

- [ ] **Step 1: Write the failing tests**, following the existing `PaymentProofsTest` auth setup.
  - `test_index_exposes_receipt_number`: assert the verified proof has `receipt_number = 'PEN-2026-0001'` and `has_receipt = true`; the pending one has `null` / `false`.
  - `test_owner_downloads_receipt_pdf`: 200, content type `application/pdf`, body starts with `%PDF`.
  - `test_other_member_gets_404`.
  - `test_proof_without_receipt_gets_404`.
- [ ] **Step 2: Run** `php artisan test --filter=PaymentProofsTest`. Expected: the new cases FAIL.
- [ ] **Step 3: Implement** the route, controller method and resource fields.
- [ ] **Step 4: Run** `php artisan test --filter='Api'`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: receipt fields and PDF endpoint in payment proofs API`.

### Task 8: Full suite, Postgres lock check, PR

**Files:** none new (scratch script outside the repo)

- [ ] **Step 1: Run** `php artisan test`. Expected: all green.
- [ ] **Step 2: Postgres concurrency check.** Do NOT run this against the shared prod Supabase. Start a throwaway local Postgres (`docker run --rm -e POSTGRES_PASSWORD=x -p 55432:5432 postgres:16`) and run the migrations against it with a separate env file. Then fire two `nextNumber(2026)` calls from two parallel `php artisan tinker` processes, each holding the transaction with `sleep(2)`. Expected: sequences 1 and 2, with the second process blocking until the first commits. If the check can't run, say so in the PR.
- [ ] **Step 3: Visual check** of the normal and voided PDFs from Task 4, still matching the mockup.
- [ ] **Step 4: Push** `feat/paid-receipt` and open a PR that lists the tests and the Postgres result. The user merges it.
