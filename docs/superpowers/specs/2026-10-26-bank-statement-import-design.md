# Bank statement import with member reference codes — design

Date: 2026-10-26 · Status: draft for review

## Intent

Members pay their fees by bank transfer, then upload a proof. A finance admin opens each proof,
checks the bank account by hand and verifies it
(`PaymentReviewActions::verify()` → `FinanceService::verifyAndAllocate()`). Most of that checking
is mechanical: "did RM105 from this member arrive around this date?"

We want the treasurer to **upload the monthly CIMB BizChannel statement PDF** and have the system
verify the proofs it can tie to a bank line with certainty. Everything it can't tie with
certainty stays in the existing manual queue, as today.

What makes certainty possible is a **fixed reference code per member** (e.g. `PTN01428`) that the
member types into the transfer's reference field. Today's references are free text ("CLUB FEE",
"Feb24 - Sept24", sometimes empty), and amount + date alone collides: on 06/09 there were two
separate RM15 transfers, and RM105 appeared four times in one month.

## Goals

- Every member has one permanent reference code, shown wherever they are told how to pay.
- The treasurer uploads a CIMB BizChannel statement PDF and sees what happened to every credit
  line: auto-verified, confirmed an already-verified proof, needs review, or no code.
- A pending proof is auto-verified only when the match is unique and exact (rules below). It goes
  through the same `verifyAndAllocate()` path, so allocation, receipt and member notification are
  identical to a manual verify.
- Re-uploading a statement, or uploading two overlapping statements, never verifies anything twice.
- Credit lines that matched nothing are kept and retried on every later import, so a member who
  uploads their proof after the statement was imported still gets matched next time.

## Non-goals

- Members still upload a proof. A coded transfer without a proof creates nothing; it shows as
  "code found, no pending proof" for the treasurer.
- No other banks or statement formats. Only CIMB BizChannel (the JasperReports-generated text PDF).
  Anything else is refused with a clear message.
- No OCR, no AI. Scanned or image PDFs are refused.
- No bank API, no scheduled fetching. The treasurer uploads the file by hand.
- No lunch-payment reconciliation. Lunch transfers into the same account are simply ignored
  (they carry no fee code).
- No per-payment codes. One code per member, forever.
- Mobile app UI changes are out of scope here. The API exposes the code so the apps can show it
  in a follow-up.

## Anti-goals (would make this a failure even if it "works")

- Auto-verifying a proof against the wrong bank line, or the wrong member's money. When in doubt,
  leave it manual.
- One bank line verifying two proofs, or one proof being verified by two lines.
- An import that silently skips lines because the parser misread the PDF.
- A notification or receipt failure undoing or blocking a verification (same rule as today:
  `notifyMember()` uses `rescue`).
- Keeping the full statement around. It holds other people's names and account details that
  have nothing to do with fees.

## Decisions

| Question | Decision |
| --- | --- |
| Does a proof stay required? | Yes. The statement confirms a proof; it never replaces one. |
| Code type | Fixed per member, assigned once. |
| Code matches but amount differs from `amount_claimed` | Manual. The proof shows the suggested bank line; the admin decides. |
| Who can import | Treasurer only: `is_admin && is_treasurer`. Super admins who are not treasurer cannot. Manual verify keeps using `canManageFinance()`. |

## Reference code

Format: `PTN` + 4-digit member number + 1 check digit, e.g. `PTN01428` (member 142, check digit 8).

- **No hyphen or spaces.** Some banking apps strip punctuation from the DuitNow reference field,
  and a plain alphanumeric code survives every app.
- **Check digit (Damm mod 10 over the 4 digits).** A one-digit typo or swapped pair produces an
  invalid code instead of another member's valid code. Invalid codes are treated as "no code".
- The member number is the user id, zero-padded. Above 9999 members the number widens; the regex
  allows 4–6 digits.
- Stored in a new unique column `users.payment_reference`, filled for every existing member by a
  migration and for new members on creation. It never changes.
- Matching is case-insensitive and tolerant of whitespace between characters (`ptn 0142 8`),
  because people retype it.

Where members see it:

- Web: the "Submit payment" page (above the bank details, with a copy button) and the member's
  profile.
- Fee reminder and payment-related emails: one line, "Use reference PTN01428 when you transfer."
- API: `payment_reference` on the authenticated user resource, for the iOS and Android apps.

## Parsing the statement

`pdftotext -layout` (poppler-utils) on the uploaded file, then line rules:

- A transaction starts with a `dd/mm/yyyy` date, followed by the transaction type, CIMB's ref no,
  the amount in either the debit or credit column, and the running balance.
- The lines below it, up to the next date, are the payer-entered reference text.
- Credits are kept; debits and fees (`TR IBG`, `OTHER TRANSFER FEE`, …) are dropped after the
  balance check.
- **Balance check:** each row's balance must equal the previous balance ± its amount, across all
  pages. If any row fails, the whole import is refused and nothing is saved. This is what catches
  a misread or skipped line.
- Not a text PDF, header not recognised, or zero transactions → refused with a message that says
  which.

The server needs `poppler-utils` (`sudo apt-get install -y poppler-utils`); add it to
`docs/deployment-internal-server.md`. The parser sits behind a small interface, so a pure-PHP
fallback (`smalot/pdfparser`) can replace it if installing packages on the server is a problem.

## Data

`bank_statement_imports`: id, uploaded_by, file_sha256 (unique: the same file twice is refused
up front), statement_from, statement_to, counts per outcome, created_at. The PDF itself is
deleted after parsing.

`bank_statement_lines`: id, import_id, txn_date, bank_ref_no, amount, reference_text,
extracted_code (nullable), user_id (nullable, the code's owner), payment_proof_id (nullable),
outcome, timestamps. **Unique on (txn_date, bank_ref_no, amount)**, so an overlapping statement
re-imports the same line as a no-op.

`payment_proofs.bank_statement_line_id` (nullable, unique): the line that confirmed this proof.

Only credit lines are stored. Debits, balances and the other account-holder details never reach
the database.

## Matching rules

For each stored credit line without a proof (new lines plus unmatched lines from earlier imports):

1. **Extract the code.** Exactly one valid code in the reference text → that member. None, an
   invalid check digit, or two different codes → outcome `no_code`. Stop.
2. **Find candidate proofs:** that member's `pending` proofs whose `amount_claimed` equals the line
   amount to the cent, uploaded no earlier than 7 days before the transfer date.
3. **Exactly one candidate, and that proof is not also the only candidate of another line in this
   run** → auto-verify (below), outcome `auto_verified`.
4. **No pending candidate, but a `verified` proof of the same member and amount with no linked
   line, verified within 30 days of the transfer** → link it, outcome `confirmed`. Nothing changes
   for the member; it just closes the loop for the treasurer.
5. **Anything else** (several candidates, amount differs from every pending proof, no proof yet)
   → outcome `needs_review` or `no_proof`. Nothing is verified.

The 7-day and 30-day windows are constants, easy to adjust after the first real month.

## Auto-verify

Same transaction and lock as a manual verify:

- `verifyAndAllocate($proof, $line->amount)`, then link `bank_statement_line_id` and set
  `admin_note` to "Verified from bank statement (CIMB ref …)".
- Lock order: member lock first (as today), then the line row. A proof that stopped being pending
  between matching and verifying throws `PaymentNotPendingException`; the line drops back to
  `needs_review` and the import continues.
- The member is notified exactly like a manual verify, outside the transaction, through the same
  rescued `notifyMember()` (extract it from `PaymentReviewActions` into a small shared class).
- Delivery is inline: there is no queue worker. One statement auto-verifies maybe 20–40 proofs;
  emails past the daily send cap spill to the spool as they do today.

## Treasurer screens

- **Finance → Bank statements**: list of imports (date range, uploaded by, counts) and an
  **Import statement** action, visible to the treasurer only, that takes one PDF.
- After an import, its page lists every stored credit line with the outcome, member, amount and
  reference text, filterable by outcome. Lines in `needs_review` / `no_proof` link to the member's
  pending proofs.
- **Proof view page** (for anyone who can verify): if a stored line carries this member's code but
  wasn't matched, show it as "Possible bank match: 06/09/2026, RM105.00, ref …" beside the proof.
  The admin still verifies by hand.

## Edge cases

- **One transfer covering several months** (RM105 = 7 × RM15): matched to the proof by amount,
  then the existing allocation spreads it oldest bill first. No special handling.
- **Proof deleted after auto-verify:** the existing delete path voids the receipt and releases the
  allocations; the line's `payment_proof_id` is cleared and its outcome goes back to
  `needs_review`, so the money still shows as received.
- **Member pays with someone else's code** (a parent paying for a child): that is the intended
  payer, so it is correct.
- **Member forgets the code:** `no_code`, manual verify as today. Expect this for the first months.
- **Same proof amount twice in a month by one member** (two RM15 proofs, two RM15 lines): rule 3
  fails on uniqueness, both go to review. Rare; acceptable.

## Testing

- Parser unit tests on **synthetic** `pdftotext` output fixtures (made-up names and accounts,
  same layout), including multi-line references, a page break mid-transaction, debits, and a
  deliberately broken balance that must refuse the import. The real statement is never committed.
- Matching feature tests: each rule above, overlapping re-import, the race with a manual verify,
  delete after auto-verify, treasurer-only access (super admin without treasurer is refused).
- Check-digit tests: every single-digit typo and adjacent swap of a valid code is invalid.
- One run against Postgres for the unique constraints and locks, since the suite runs on SQLite.

## Rollout

1. Ship codes first and show them to members. Imports would find almost nothing before members
   start using codes.
2. One or two months later, ship the import. On the first real statement, the treasurer still
   spot-checks the auto-verified lines before trusting it.
