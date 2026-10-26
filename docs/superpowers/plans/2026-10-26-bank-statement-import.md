# Bank Statement Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every member gets a fixed payment reference code, and the treasurer uploads a CIMB BizChannel statement PDF that auto-verifies pending proofs it can tie to a bank line with certainty.

**Architecture:** `ReferenceCode` encodes/decodes `PTN` + 4-digit user id + Damm digit (derived from the id, no column). `pdftotext -layout` feeds `CimbStatementParser`, which refuses any statement whose balances or totals don't reconcile. `StatementImporter` stores credit lines (deduplicated by a unique key) and hands every unmatched line to `StatementMatcher`, which auto-verifies through the existing `FinanceService::verifyAndAllocate()`. A treasurer-only Filament resource shows imports and their lines; the proof page shows possible bank matches.

**Tech Stack:** Laravel 12, Filament 5, Livewire, PHPUnit (SQLite in tests, Postgres in prod), poppler-utils `pdftotext`.

**Spec:** `docs/superpowers/specs/2026-10-26-bank-statement-import-design.md`

## Global Constraints

- Code format: `PTN` + user id zero-padded to 4 digits + 1 Damm check digit, no hyphen. User 142 → `PTN01428`. `ReferenceCode::for()` throws `InvalidArgumentException` for ids > 9999. (Plan decision: the spec's "widens above 9999" is dropped — YAGNI at ~108 members, and a fixed 5-digit run keeps extraction unambiguous.)
- The code is **derived from the id**, not stored (plan decision replacing the spec's `users.payment_reference` column: the id never changes and is already unique). Exposed as the `payment_reference` accessor.
- Extraction is case-insensitive and allows whitespace between `PTN` and the digits and between digits (`ptn 0142 8`). Invalid check digit, unknown user, or two different valid codes in one line → no code.
- Import access: `User::canImportBankStatements()` = `is_admin && is_treasurer`. Manual verify keeps `canManageFinance()`.
- Windows: pending proof `created_at >= txn_date − 7 days` (start of day); confirmable verified proof `verified_at` within `[txn_date, txn_date + 30 days]`. Constants `StatementMatcher::PROOF_LOOKBACK_DAYS = 7`, `CONFIRM_WINDOW_DAYS = 30`.
- Amounts compare in integer cents via `FinanceService::toCents()`.
- Only credit lines are stored. The uploaded PDF is deleted after parsing, success or failure.
- Auto-verify admin note (stored data, English, not translated): `Verified from bank statement (CIMB ref {bank_ref_no})`.
- No queue worker: member notifications go through `MemberNotifier` (deferred, per-recipient `try`/`report`). Tests call `$this->withoutDefer()`.
- Every new `__()` key goes into `lang/ms.json` and `lang/id.json` (`TranslationCompletenessTest` enforces it).
- Portable SQL only (SQLite tests / Postgres prod).
- Never commit a real statement. The only fixture is the synthetic `tests/Fixtures/bank-statements/cimb-synthetic.txt` (already in the repo, committed with this plan).

## Review Focus

1. **A typo'd code that happens to be another member's valid code** → must not verify. Damm rejects every single-digit change and adjacent swap; test in Task 1.
2. **Same statement uploaded twice, or two overlapping statements** → no proof verified twice, no duplicate lines. Tests in Task 5.
3. **A proof verified by hand between matching and auto-verify** (`PaymentNotPendingException`) → line falls back to `needs_review`, import completes. Test in Task 4.
4. **Proof deleted after auto-verify** → line unlinked and back to `needs_review`. Test in Task 3.
5. **Statement that parses but doesn't reconcile** (a dropped row, a misread amount, a scanned PDF) → whole import refused, nothing stored, file deleted. Tests in Task 2 and Task 5.

---

### Task 1: Reference code and where members see it

**Files:**
- Create: `app/Support/ReferenceCode.php`
- Modify: `app/Models/User.php` (accessor, `canImportBankStatements()`)
- Modify: `app/Http/Resources/Api/UserResource.php`
- Modify: `app/Filament/Resources/FeePayments/Schemas/FeePaymentForm.php`
- Modify: `app/Notifications/BillReminder.php`
- Modify: `lang/ms.json`, `lang/id.json`
- Test: `tests/Unit/ReferenceCodeTest.php`, `tests/Feature/Finance/PaymentReferenceTest.php`

**Interfaces:**
- Produces:
  - `ReferenceCode::for(int $userId): string` — `PTN01428` for 142.
  - `ReferenceCode::isValid(string $code): bool`
  - `ReferenceCode::extractUserId(string $text): ?int` — the single user id whose valid code appears in `$text`; `null` for none, invalid, or two different codes. Does **not** check the user exists (the matcher does).
  - `User::$payment_reference` accessor (string), `User::canImportBankStatements(): bool`.

- [ ] **Step 1: Write the failing unit tests**

```php
public function test_code_is_prefix_padded_id_and_luhn_digit(): void
{
    $this->assertSame('PTN01428', ReferenceCode::for(142));
    $this->assertSame('PTN00013', ReferenceCode::for(1));
    $this->expectException(InvalidArgumentException::class);
    ReferenceCode::for(10000);
}

public function test_every_single_digit_typo_and_adjacent_swap_is_invalid(): void
{
    foreach ([1, 142, 907, 9999] as $id) {
        $digits = substr(ReferenceCode::for($id), 3);
        for ($i = 0; $i < 5; $i++) {
            foreach (range(0, 9) as $d) {
                if ((string) $d === $digits[$i]) continue;
                $typo = substr_replace($digits, (string) $d, $i, 1);
                $this->assertFalse(ReferenceCode::isValid('PTN'.$typo), "PTN$typo");
            }
            if ($i < 4 && $digits[$i] !== $digits[$i + 1]) {
                $swap = $digits; [$swap[$i], $swap[$i + 1]] = [$swap[$i + 1], $swap[$i]];
                $this->assertFalse(ReferenceCode::isValid('PTN'.$swap), "PTN$swap");
            }
        }
    }
}

public function test_extracts_the_user_id_from_free_text(): void
{
    $this->assertSame(142, ReferenceCode::extractUserId('CLUB FEE ptn 0142 8 sept'));
    $this->assertSame(142, ReferenceCode::extractUserId('PTN01428 2024'));
    $this->assertSame(142, ReferenceCode::extractUserId('PTN01428 again PTN01428'));
    $this->assertNull(ReferenceCode::extractUserId('PTN01421'));              // bad check digit
    $this->assertNull(ReferenceCode::extractUserId('PTN01428 PTN00013'));     // two members
    $this->assertNull(ReferenceCode::extractUserId('Pentana fee Aug Sep'));
}
```

- [ ] **Step 2: Run** `php artisan test tests/Unit/ReferenceCodeTest.php` — Expected: FAIL, class not found.

- [ ] **Step 3: Implement `App\Support\ReferenceCode`**

Damm check digit over the 4-digit payload (standard 10x10 quasigroup table; check digit = the interim digit after processing the 4 payload digits, so a full 5-digit code is valid when processing all 5 digits gives 0). Extraction regex: `/PTN\s*((?:\d\s*){4}\d)(?!\d)/i`, strip whitespace from the capture, keep valid ones, return the id if exactly one distinct id remains.

- [ ] **Step 4: Run the unit tests** — Expected: PASS.

- [ ] **Step 5: Write the failing feature tests** in `PaymentReferenceTest`:
  - `test_api_user_exposes_payment_reference`: `GET` the existing authenticated user endpoint (find it in `routes/api.php`) as a member → `assertJsonPath('data.payment_reference', ReferenceCode::for($member->id))` (match the existing response envelope).
  - `test_submit_payment_form_shows_the_member_code`: `Livewire::test(CreateFeePayment::class)` as a member → `assertSee($member->payment_reference)`.
  - `test_bill_reminder_mail_tells_the_member_their_code`: render `(new BillReminder(...))->toMail($member)` as the existing BillReminder tests do → `assertStringContainsString($member->payment_reference, ...)` on the rendered lines.
  - `test_only_admin_treasurers_can_import`: `treasurer()` → true; `superAdmin()` without `is_treasurer` → false; `admin()` → false; plain member with `is_treasurer => true` but not admin → false.

- [ ] **Step 6: Implement**
  - `User`: `paymentReference(): Attribute` returning `ReferenceCode::for($this->id)`; `canImportBankStatements()`.
  - `UserResource`: `'payment_reference' => $this->payment_reference`.
  - `FeePaymentForm`: the section description becomes `__('Use reference :code when you transfer, then enter the total amount and upload your bank receipt.', ['code' => Auth::user()->payment_reference])`. Copy button is not needed (Filament `TextEntry` isn't available in a form; keep it as text).
  - `BillReminder::toMail()`: add `->line(__('Use reference :code when you transfer.', ['code' => $notifiable->payment_reference]))` before the "Once you have transferred" line.
  - The member's profile page (find the panel's profile page class) shows a read-only `__('Payment reference')` line with the code.
  - All keys into `lang/ms.json` and `lang/id.json` with `:code` kept.

- [ ] **Step 7: Run** `php artisan test tests/Feature/Finance/PaymentReferenceTest.php tests/Feature/Localisation tests/Feature/SendBillRemindersTest.php` — Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Support/ReferenceCode.php app/Models/User.php app/Http/Resources/Api/UserResource.php app/Filament/Resources/FeePayments/Schemas/FeePaymentForm.php app/Notifications/BillReminder.php lang/ms.json lang/id.json tests/Unit/ReferenceCodeTest.php tests/Feature/Finance/PaymentReferenceTest.php
git commit -m "feat(finance): per-member payment reference code"
```

---

### Task 2: CIMB statement parser

**Files:**
- Create: `app/Services/BankStatements/StatementTextExtractor.php` (interface), `PdftotextExtractor.php`, `CimbStatementParser.php`, `ParsedStatement.php`, `StatementCredit.php`, `StatementParseException.php` (all in `app/Services/BankStatements/`)
- Modify: `app/Providers/AppServiceProvider.php` (bind interface → `PdftotextExtractor`)
- Test: `tests/Unit/CimbStatementParserTest.php`; fixture `tests/Fixtures/bank-statements/cimb-synthetic.txt` (exists)

**Interfaces:**
- Produces:
  - `interface StatementTextExtractor { public function extract(string $pdfPath): string; }` — throws `StatementParseException` when the tool fails or returns no text.
  - `CimbStatementParser::parse(string $text): ParsedStatement` — throws `StatementParseException` with a member-safe message.
  - `final readonly class ParsedStatement { public Carbon $from; public Carbon $to; /** @var list<StatementCredit> */ public array $credits; }`
  - `final readonly class StatementCredit { public Carbon $date; public string $type; public string $bankRefNo; public string $amount; /* '15.00' */ public string $referenceText; }`

The real layout (pages separated by `\f`, **newest transaction first**, balance is the balance after that row):

- Row: `^\d{2}/\d{2}/\d{4}` then, split on runs of 2+ spaces: type, ref no, one amount, balance. Amounts use `,` thousands separators.
- Lines after a row, up to the next row or a non-table line, are continuation: tokens starting at a column ≥ the row's ref-no column are ref-no continuation (append, no space: `2001000000000000` + `186`); the rest is reference text (joined with single spaces).
- A page break can split a transaction: the first continuation lines after the next page's `(RM)` header line belong to the previous page's last row. Everything between the top of a page and that header line is ignored.
- `Opening Balance` on page 1; the summary block (`No of Withdrawal … Total Deposits`, then a line with 2 counts and 2 totals) on the last page.

Reconciliation, any failure throws `StatementParseException`:
- Walk rows oldest→newest from the opening balance: each row's amount is a credit if `prev + amount == balance`, a debit if `prev − amount == balance`, otherwise fail ("Balance does not reconcile on dd/mm/yyyy").
- Count of debits/credits and their sums must equal the summary line.
- No `Statement of Account` / `CIMB` header, no opening balance, no summary, or zero rows → fail ("Not a CIMB BizChannel statement" / "No transactions found").

- [ ] **Step 1: Write the failing tests**

```php
private function fixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/bank-statements/cimb-synthetic.txt'));
}

public function test_parses_only_credits_with_reference_text(): void
{
    $s = (new CimbStatementParser)->parse($this->fixture());

    $this->assertSame('2024-09-01', $s->from->toDateString());
    $this->assertSame('2024-09-28', $s->to->toDateString());
    $this->assertSame(
        ['1050.00', '15.00', '15.00', '15.00', '105.00'],
        array_map(fn ($c) => $c->amount, $s->credits),
    );
    $ibg = $s->credits[2];
    $this->assertSame('IBG CREDIT', $ibg->type);
    $this->assertSame('2001000000000000186', $ibg->bankRefNo);
    $this->assertSame('CLUB FEE ptn 0142 8 sept', $ibg->referenceText);
    $this->assertSame('PTN01428 Yuran Jul-Jan', $s->credits[4]->referenceText);
}

public function test_continuation_after_a_page_break_stays_with_its_row(): void
{
    // TR IBG "Caterer" continues as "September payment" on page 2; it is a debit, so
    // it is dropped — but the first page-2 credit must not inherit that text.
    $s = (new CimbStatementParser)->parse($this->fixture());
    $this->assertSame('PENTANA lunch September', $s->credits[1]->referenceText);
}

public function test_refuses_a_statement_whose_balances_do_not_reconcile(): void
{
    $broken = str_replace('105.00         1,105.00', '150.00         1,105.00', $this->fixture());
    $this->expectException(StatementParseException::class);
    (new CimbStatementParser)->parse($broken);
}

public function test_refuses_a_dropped_row(): void
{
    $dropped = preg_replace('/^03\/09\/2024.*\n.*Fund Transfer\n/m', '', $this->fixture());
    $this->expectException(StatementParseException::class);
    (new CimbStatementParser)->parse($dropped);
}

public function test_refuses_text_that_is_not_a_cimb_statement(): void
{
    $this->expectException(StatementParseException::class);
    (new CimbStatementParser)->parse("Some other bank\n01/09/2024  X  1  2.00  3.00\n");
}
```

Credits are returned in statement order (newest first), amounts normalised to two decimals without separators.

- [ ] **Step 2: Run** `php artisan test tests/Unit/CimbStatementParserTest.php` — Expected: FAIL, class not found.

- [ ] **Step 3: Implement the parser and DTOs** per the rules above. `PdftotextExtractor` runs `Process::run(['pdftotext', '-layout', $pdfPath, '-'])` (Laravel `Process` facade, array form — no shell); non-zero exit or blank output → `StatementParseException(__('This PDF has no readable text. Upload the statement downloaded from BizChannel, not a scan.'))`. Parser messages also go through `__()`.

- [ ] **Step 4: Add an extractor test**: `Process::fake(['*' => Process::result('')])` → `extract()` throws `StatementParseException`.

- [ ] **Step 5: Run the tests** — Expected: PASS. Then run the real file locally, outside the test suite, and confirm it reconciles (do not commit output):

```bash
php artisan tinker --execute="dump(count(app(App\Services\BankStatements\CimbStatementParser::class)->parse(app(App\Services\BankStatements\StatementTextExtractor::class)->extract(getenv('HOME').'/Downloads/01Sep24_to_30Sep24.pdf'))->credits));"
```

Expected: `34`.

- [ ] **Step 6: Translations** for the parser messages into `lang/ms.json` and `lang/id.json`; run `php artisan test tests/Feature/Localisation` — PASS.

- [ ] **Step 7: Commit** — `git commit -m "feat(finance): parse CIMB BizChannel statements with balance reconciliation"`

---

### Task 3: Schema and models

**Files:**
- Create: `database/migrations/2026_10_27_000001_create_bank_statement_tables.php`
- Create: `app/Enums/StatementLineOutcome.php` (follow the existing enum folder; check `app/Enums` first)
- Create: `app/Models/Payment/BankStatementImport.php`, `app/Models/Payment/BankStatementLine.php`, factories for both
- Modify: `app/Models/Payment/PaymentProof.php` (`bankStatementLine(): HasOne`, deleting hook)
- Test: `tests/Feature/Finance/BankStatementLineTest.php`

**Interfaces:**
- Produces:
  - `enum StatementLineOutcome: string { AutoVerified = 'auto_verified'; Confirmed = 'confirmed'; NeedsReview = 'needs_review'; NoProof = 'no_proof'; NoCode = 'no_code'; }` with `label(): string` (translated).
  - `bank_statement_imports`: id, `uploaded_by` (FK users, nullOnDelete), `file_sha256` string(64) unique, `statement_from` date, `statement_to` date, `lines_new` / `lines_duplicate` / `auto_verified` / `confirmed` / `unmatched` unsigned ints default 0, timestamps.
  - `bank_statement_lines`: id, `bank_statement_import_id` (FK, cascadeOnDelete), `txn_date` date, `type` string, `bank_ref_no` string, `amount` decimal(10,2), `reference_text` text, `user_id` nullable FK nullOnDelete, `payment_proof_id` nullable **unique** FK nullOnDelete, `outcome` string, timestamps; unique `(txn_date, bank_ref_no, amount)`.
  - `BankStatementLine` casts `txn_date` date, `outcome` enum; relations `import()`, `user()`, `paymentProof()`; scope `unmatched()` = `whereNull('payment_proof_id')`.
  - `PaymentProof::bankStatementLine(): HasOne`.

- [ ] **Step 1: Write the failing tests**
  - `test_a_line_is_unique_by_date_ref_and_amount`: inserting the same `(txn_date, bank_ref_no, amount)` twice via `BankStatementLine::insertOrIgnore` leaves one row.
  - `test_deleting_an_auto_verified_proof_puts_its_line_back_in_review`: line linked to a verified proof with outcome `AutoVerified` → `$proof->delete()` → line `payment_proof_id` null, outcome `NeedsReview`.
  - `test_a_proof_can_be_linked_to_only_one_line`: second line with the same `payment_proof_id` → `QueryException`.

- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** migration, enum, models, factories; in `PaymentProof::booted()` deleting hook, before `releasePayment`, reset the linked line (`payment_proof_id` null, outcome `NeedsReview`). Outcome labels into both lang files.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Finance/BankStatementLineTest.php tests/Feature/PaymentDeletionTest.php tests/Feature/Localisation` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(finance): bank statement import and line tables"`

---

### Task 4: Matcher

**Files:**
- Create: `app/Services/BankStatements/StatementMatcher.php`, `MatchResult.php`
- Modify: `app/Services/MemberNotifier.php` (per-recipient variant)
- Test: `tests/Feature/Finance/StatementMatcherTest.php`

**Interfaces:**
- Consumes: `ReferenceCode::extractUserId()`, `FinanceService::verifyAndAllocate()`, `FinanceService::toCents()`, `BankStatementLine`, `StatementLineOutcome`.
- Produces:
  - `StatementMatcher::match(Collection $lines): MatchResult` — `$lines` are unmatched `BankStatementLine`s; sets `user_id` and `outcome` on each, links proofs.
  - `final readonly class MatchResult { public int $autoVerified; public int $confirmed; public int $unmatched; /** @var list<int> */ public array $verifiedProofIds; }`
  - `MemberNotifier::sendEachAfterResponse(Collection $notifications): void` — `Collection<int, array{0: User, 1: Notification}>`, same deferred, per-recipient `try`/`report` loop as `sendAfterResponse()`.

Rules, in order, per line:
1. `user_id = ReferenceCode::extractUserId($line->reference_text)`, kept only if that user exists. None → `NoCode`.
2. Candidates: that user's `pending` proofs with `toCents(amount_claimed) == toCents(line.amount)` and `created_at >= txn_date->startOfDay()->subDays(PROOF_LOOKBACK_DAYS)`.
3. Compute candidates for **all** lines first. Auto-verify when the line has exactly one candidate and no other line in this batch lists that proof. Auto-verify = in one `DB::transaction`: `verifyAndAllocate($proof, (float) $line->amount)`; `$proof->update(['admin_note' => "Verified from bank statement (CIMB ref {$line->bank_ref_no})"])`; link the line with `BankStatementLine::whereKey($line->id)->whereNull('payment_proof_id')->update([...])` and throw to roll back if it updated 0 rows. On `PaymentNotPendingException` → `NeedsReview`.
4. No candidates: exactly one `verified` proof of that user with equal amount (`amount_verified`), no linked line, `verified_at` between `txn_date` start of day and `+ CONFIRM_WINDOW_DAYS` end of day → link, `Confirmed`.
5. Otherwise: the user has any `pending` proof → `NeedsReview`; else `NoProof`.

After the loop, queue one `PaymentVerified` per auto-verified proof through `sendEachAfterResponse()`.

- [ ] **Step 1: Write the failing tests** (each builds lines with `reference_text` from `ReferenceCode::for($member->id)`; `$this->withoutDefer()`; `Notification::fake()`):
  - `test_unique_exact_match_is_verified_allocated_receipted_and_notified` → proof `verified`, `amount_verified` = line amount, line `AutoVerified` + linked, a `PaymentReceipt` exists, `admin_note` contains the bank ref, `Notification::assertSentTo($member, PaymentVerified::class)`.
  - `test_multi_month_transfer_spreads_over_bills`: three unpaid RM35 periods, proof and line RM105 → all three periods `paid`.
  - `test_no_code_and_invalid_code_are_left_alone` → `NoCode`, proof still `pending`.
  - `test_amount_mismatch_needs_review` (proof RM105, line RM100) → `NeedsReview`, proof `pending`.
  - `test_two_pending_proofs_of_the_same_amount_need_review`.
  - `test_two_lines_competing_for_one_proof_both_need_review`.
  - `test_proof_older_than_the_lookback_is_not_a_candidate` (proof `created_at` = txn − 8 days) → `NeedsReview`; at txn − 7 days → `AutoVerified`.
  - `test_already_verified_proof_is_confirmed_not_reverified` → `Confirmed`, linked, `verifyAndAllocate` not called again (allocation count unchanged), no notification.
  - `test_code_without_any_proof_is_no_proof`.
  - `test_code_of_a_deleted_user_is_no_code`.
  - `test_proof_verified_by_hand_mid_match_falls_back_to_review`: bind a `FinanceService` partial mock whose `verifyAndAllocate` throws `PaymentNotPendingException` → `NeedsReview`, `MatchResult::autoVerified === 0`, no exception escapes.
  - `test_one_failing_notification_does_not_stop_the_others` (two members, first member's notify throws via a channel that throws, mirror the existing `MemberNotifierTest` setup) → second member notified.

- [ ] **Step 2: Run** `php artisan test tests/Feature/Finance/StatementMatcherTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** `StatementMatcher`, `MatchResult`, `MemberNotifier::sendEachAfterResponse()`.
- [ ] **Step 4: Run the matcher tests plus** `tests/Feature/MemberNotifierTest.php tests/Feature/FinanceServiceTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(finance): match statement lines to pending proofs"`

---

### Task 5: Importer

**Files:**
- Create: `app/Services/BankStatements/StatementImporter.php`
- Test: `tests/Feature/Finance/StatementImporterTest.php`

**Interfaces:**
- Consumes: `StatementTextExtractor`, `CimbStatementParser`, `StatementMatcher`.
- Produces: `StatementImporter::import(string $pdfPath, User $uploadedBy): BankStatementImport` — throws `StatementParseException` (message shown to the treasurer) or `DuplicateStatementException` (new, same folder) when the file hash was imported before.

Flow: hash the file → refuse a known hash → extract + parse (any exception: nothing stored) → `DB::transaction`: create the import, `insertOrIgnore` one row per credit (outcome `NeedsReview` placeholder, `user_id` null), counting new vs duplicate → match **all** `BankStatementLine::unmatched()` lines (this import's and earlier ones) → store counts → return. The file is deleted in a `finally`, on every path.

- [ ] **Step 1: Write the failing tests** (bind a fake `StatementTextExtractor` returning the fixture text, so tests don't need poppler; copy a dummy file into a temp path per test):
  - `test_imports_credits_and_records_counts` → 5 lines, `lines_new = 5`, `statement_from/to` = 2024-09-01 / 2024-09-28, `uploaded_by` set, temp file gone.
  - `test_same_file_twice_is_refused` → `DuplicateStatementException`, still one import.
  - `test_overlapping_statement_adds_only_new_lines`: second fixture variant (different bytes, one extra newer credit row and the balance/summary adjusted — build it in the test by string-replacing the top row and summary) → `lines_duplicate = 5`, `lines_new = 1`, no proof verified twice.
  - `test_unmatched_line_from_an_earlier_import_matches_later`: import once (member has no proof yet → `NoProof`), member submits the proof, import an overlapping statement → the old line is `AutoVerified`.
  - `test_unreconciled_statement_stores_nothing_and_deletes_the_file` → `StatementParseException`, zero imports, zero lines, file gone.

- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test tests/Feature/Finance/StatementImporterTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(finance): import bank statements and retry unmatched lines"`

---

### Task 6: Treasurer screens

**Files:**
- Create: `app/Filament/Resources/BankStatements/BankStatementImportResource.php`, `Pages/ListBankStatementImports.php`, `Pages/ViewBankStatementImport.php`, `RelationManagers/LinesRelationManager.php`
- Modify: `lang/ms.json`, `lang/id.json`
- Test: `tests/Feature/Finance/BankStatementScreensTest.php`

**Interfaces:**
- Consumes: `StatementImporter::import()`, `User::canImportBankStatements()`, `StatementLineOutcome::label()`.
- Produces: resource in `NavGroup::Finance`, label `__('Bank statements')`, `canViewAny()` / `canView()` = `canImportBankStatements()`, no create/edit/delete pages.

- List columns: statement range, uploaded by (`MemberName`), auto-verified, confirmed, unmatched, imported at. Header action **Import statement**: one `FileUpload` (`acceptedFileTypes(['application/pdf'])`, `maxSize(5120)`, disk `local`, directory `bank-statements`), calls the importer with the stored path, then redirects to the view page. `StatementParseException` / `DuplicateStatementException` → danger notification with the message, `$action->halt()`. Success → notification `__(':verified verified, :confirmed confirmed, :unmatched to review', [...])`.
- Lines relation manager: date, type, amount (MYR), reference text, member (`MemberName`), outcome badge, link to the proof when linked. `SelectFilter` on outcome.

- [ ] **Step 1: Write the failing tests**
  - `test_treasurer_imports_a_statement_from_the_list_page` (fake extractor as in Task 5; `Livewire::test(ListBankStatementImports::class)->callAction('importStatement', data: ['file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf')])`) → one import, redirect to its view page.
  - `test_unreconciled_statement_shows_the_reason` → danger notification with the parser message, no import.
  - `test_super_admin_without_treasurer_cannot_open_the_resource` → list page 403 and nav item hidden; same for `admin()`; member 403.
  - `test_lines_can_be_filtered_by_outcome` → filter `no_code` shows only those lines.

- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** the resource, pages and relation manager; strings into both lang files.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Finance/BankStatementScreensTest.php tests/Feature/Localisation tests/Feature/Filament` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(finance): treasurer bank statement import screens"`

---

### Task 7: Possible bank match on the proof page

**Files:**
- Modify: `app/Filament/Resources/FeePayments/Pages/ViewFeePayment.php`
- Modify: `lang/ms.json`, `lang/id.json`
- Test: `tests/Feature/Finance/PossibleBankMatchTest.php`

**Interfaces:**
- Consumes: `BankStatementLine`, `StatementLineOutcome`.
- Produces: `BankStatementLine::possibleMatchesFor(PaymentProof $proof): Collection` (static, on the model) — unmatched lines of `$proof->user_id` with outcome `NeedsReview` or `NoProof` and `txn_date >= $proof->created_at − 30 days`, newest first.

- A `Section` `__('Possible bank match')`, visible only when the proof is `pending`, the viewer `canManageFinance()`, and there are matches; one line each: `06/09/2026 · MYR 105.00 · CIMB ref 800000001 · <reference text>`. Display only — the admin still verifies with the existing action.

- [ ] **Step 1: Write the failing tests**
  - `test_pending_proof_shows_a_coded_line_with_a_different_amount` → `assertSee('Possible bank match')`, `assertSee('800000001')`.
  - `test_other_members_lines_are_not_shown`.
  - `test_member_viewing_their_own_proof_does_not_see_it`.
  - `test_verified_proof_does_not_show_it`.

- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** the test file, `tests/Feature/ViewFeePaymentTest.php`, `tests/Feature/Localisation` — PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(finance): show possible bank matches on pending proofs"`

---

### Task 8: Deployment notes and Postgres check

**Files:**
- Modify: `docs/deployment-internal-server.md`

- [ ] **Step 1:** Add a "Bank statement import" subsection: `sudo apt-get install -y poppler-utils`, check with `pdftotext -v` as `www-data`, and that the `bank-statements` directory on the `local` disk must be writable by php-fpm (same as the existing storage note).
- [ ] **Step 2:** Run the full suite: `php artisan test` — Expected: all PASS.
- [ ] **Step 3:** Run the migrations and the matcher/importer tests against a **local** Postgres (never the shared Supabase, which is prod): `DB_CONNECTION=pgsql` with a throwaway database, `php artisan test tests/Feature/Finance/StatementMatcherTest.php tests/Feature/Finance/StatementImporterTest.php tests/Feature/Finance/BankStatementLineTest.php`. Expected: PASS — this is what proves the unique constraints and `insertOrIgnore` behave the same as on SQLite. If no local Postgres is available, say so in the PR instead of skipping silently.
- [ ] **Step 4: Commit** — `git commit -m "docs: poppler-utils for bank statement import"`

## Rollout (from the spec, not code)

Ship Task 1 first if you want members using codes before the import exists; Tasks 2–8 can follow in a later deploy. On the first real statement the treasurer spot-checks every auto-verified line.

## Changes after final review

- **Matching windows (C1):** pending candidates are now two-sided (7 days before to 14 days after
  the credit); the confirm window gained 3 days of slack before the credit; a credit with both a
  pending candidate and a confirmable verified proof is ambiguous and left for review.
- **Temporary upload (I2):** the upload uses `storeFiles(false)`; the importer reads Livewire's
  temporary file and the action deletes it in `finally`. No `bank-statements/` directory, no
  form-state path validation.
- **Per-line isolation (I3):** each line is matched in its own try/catch (reported, left
  `needs_review`, run continues); notifications for committed auto-verifies go out from a
  `finally`; a private `LineAlreadyLinkedException` replaces the reused
  `RecordsNotFoundException` rollback signal.
- **Occurrence key (I4):** `bank_statement_lines.occurrence` joins the unique key, so identical
  same-day credits stay separate lines; the view page shows "Already imported".
- **Live counts, NoCode terminal (I5):** stored `auto_verified`/`confirmed`/`unmatched` columns
  dropped in favour of live per-outcome counts per import; earlier `no_code` lines are not retried.
- **Minors:** `ReferenceCode` doc example and `\z` anchor fixed; the rejection email repeats the
  member's reference; spec updated to match.
