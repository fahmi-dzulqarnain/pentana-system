<?php

namespace App\Services\BankStatements;

use App\Enums\StatementLineOutcome;
use App\Exceptions\PaymentNotPendingException;
use App\Models\Payment\BankStatementLine;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Notifications\PaymentVerified;
use App\Services\FinanceService;
use App\Services\MemberNotifier;
use App\Support\ReferenceCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Decides, for each stored bank credit, whether it verifies a member's pending proof.
 * Only a certain match is acted on: one code, one proof of exactly that amount, and no
 * other line in the batch after the same proof. Anything less is left for the treasurer,
 * because a wrong auto-verify marks the wrong member's money as received.
 */
class StatementMatcher
{
    /** A pending proof counts for a credit only if uploaded from 7 days before to 14 days after it. */
    public const PROOF_LOOKBACK_DAYS = 7;

    public const PROOF_LOOKAHEAD_DAYS = 14;

    /** A verified proof is confirmed by a credit only if verified from 3 days before to 30 days after it. */
    public const CONFIRM_SLACK_DAYS = 3;

    public const CONFIRM_WINDOW_DAYS = 30;

    public function __construct(
        private FinanceService $finance,
        private MemberNotifier $notifier,
    ) {}

    /** @param  Collection<int, BankStatementLine>  $lines  lines not yet tied to a proof */
    public function match(Collection $lines): MatchResult
    {
        $userIds = $this->members($lines);

        // Every line's candidates first: a proof two lines could claim is verified by neither.
        // A member whose lookup failed gets no auto-verify at all this run: their claims are unknown.
        $unknownClaims = [];
        $candidates = $lines->mapWithKeys(function (BankStatementLine $line) use ($userIds, &$unknownClaims) {
            $userId = $userIds[$line->id];

            try {
                return [$line->id => $userId === null ? collect() : $this->candidates($line, $userId)];
            } catch (Throwable $e) {
                $this->reportFailure($line, $e);
                $unknownClaims[$userId] = true;

                return [$line->id => null];
            }
        });
        $claims = $candidates->filter()->flatMap(fn (Collection $proofs) => $proofs->pluck('id'))->countBy();

        $autoVerified = $confirmed = $unmatched = 0;
        $verifiedProofIds = [];

        try {
            foreach ($lines as $line) {
                $userId = $userIds[$line->id];

                try {
                    $outcome = $candidates[$line->id] === null || isset($unknownClaims[$userId])
                        ? $this->leaveForReview($line, $userId)
                        : $this->decide($line, $userId, $candidates[$line->id], $claims);
                } catch (Throwable $e) {
                    // One bad line must not stop the others; it stays with the treasurer.
                    $this->reportFailure($line, $e);
                    $outcome = $this->fallBackToReview($line, $userId);
                }

                if ($outcome === false) {
                    continue; // Linked by a concurrent import meanwhile; that run owns the line now.
                }

                if ($outcome === StatementLineOutcome::AutoVerified) {
                    $autoVerified++;
                    $verifiedProofIds[] = $line->payment_proof_id;
                } elseif ($outcome === StatementLineOutcome::Confirmed) {
                    $confirmed++;
                } else {
                    $unmatched++;
                }
            }
        } finally {
            // Every collected id is a committed verification, so its member hears about it even if
            // the run itself fails later. Per member, so one bad channel skips only that member.
            $this->notifier->sendEachAfterResponse(
                PaymentProof::with('user')->whereKey($verifiedProofIds)->get()
                    ->map(fn (PaymentProof $proof) => [$proof->user, new PaymentVerified($proof)])
            );
        }

        return new MatchResult($autoVerified, $confirmed, $unmatched, $verifiedProofIds);
    }

    /**
     * @param  Collection<int, PaymentProof>  $mine
     * @param  Collection<int, int>  $claims  how many lines claim each proof id
     */
    private function decide(BankStatementLine $line, ?int $userId, Collection $mine, Collection $claims): StatementLineOutcome|false
    {
        if ($userId === null) {
            return $this->settle($line, StatementLineOutcome::NoCode, null) ? StatementLineOutcome::NoCode : false;
        }

        // A credit that could be both a new payment and one already verified by hand is
        // ambiguous: it may be the old payment, so it must not verify the newer proof.
        $verified = $this->confirmable($line, $userId);
        $outcome = null;

        if ($mine->count() === 1 && $claims[$mine->first()->id] === 1 && $verified->isEmpty()) {
            $outcome = $this->autoVerify($line, $userId, $mine->first());
        } elseif ($mine->isEmpty() && $verified->count() === 1) {
            $outcome = $this->confirm($line, $userId, $verified->first());
        }

        return $outcome ?? $this->leaveForReview($line, $userId);
    }

    /**
     * Wrapped so the line is named in the log, and so exceptions Laravel never reports on its
     * own (ModelNotFoundException and friends, normally a 404) still reach the log here.
     */
    private function reportFailure(BankStatementLine $line, Throwable $e): void
    {
        report(new RuntimeException("Bank statement line {$line->id} could not be matched: {$e->getMessage()}", 0, $e));
    }

    /** After a failure: NeedsReview if the line is still unlinked. Counted as unmatched even if that write fails too. */
    private function fallBackToReview(BankStatementLine $line, ?int $userId): StatementLineOutcome|false
    {
        try {
            return $this->settle($line, StatementLineOutcome::NeedsReview, $userId) ? StatementLineOutcome::NeedsReview : false;
        } catch (Throwable $e) {
            report($e);

            return StatementLineOutcome::NeedsReview;
        }
    }

    /**
     * The member each line's code names, or null. extractUserId() trusts the check digit
     * only, so a code of a since-deleted member counts as no code.
     *
     * @return Collection<int, int|null> keyed by line id
     */
    private function members(Collection $lines): Collection
    {
        $codes = $lines->mapWithKeys(fn (BankStatementLine $line) => [
            $line->id => ReferenceCode::extractUserId((string) $line->reference_text),
        ]);
        $existing = User::whereKey($codes->filter()->unique()->values())->pluck('id')->flip();

        return $codes->map(fn (?int $id) => $id !== null && $existing->has($id) ? $id : null);
    }

    /** @return Collection<int, PaymentProof> */
    private function candidates(BankStatementLine $line, int $userId): Collection
    {
        $day = $line->txn_date->toImmutable();

        // Two-sided: an old credit must never claim a proof uploaded for a later payment.
        // Compared in PHP, in cents: decimal columns come back as strings on Postgres and SQLite alike.
        return PaymentProof::where('user_id', $userId)
            ->where('status', 'pending')
            ->whereBetween('created_at', [
                $day->startOfDay()->subDays(self::PROOF_LOOKBACK_DAYS),
                $day->endOfDay()->addDays(self::PROOF_LOOKAHEAD_DAYS),
            ])
            ->get()
            ->filter(fn (PaymentProof $proof) => FinanceService::toCents($proof->amount_claimed) === FinanceService::toCents($line->amount))
            ->values();
    }

    private function autoVerify(BankStatementLine $line, int $userId, PaymentProof $proof): StatementLineOutcome|false
    {
        try {
            DB::transaction(function () use ($line, $userId, $proof) {
                // Takes the member lock, so the line row below is locked second, as on a manual verify.
                $this->finance->verifyAndAllocate($proof, (float) $line->amount);
                $proof->update(['admin_note' => "Verified from bank statement (CIMB ref {$line->bank_ref_no})"]);

                if (! $this->settle($line, StatementLineOutcome::AutoVerified, $userId, $proof->id)) {
                    throw new LineAlreadyLinkedException("Bank statement line {$line->id} is already linked.");
                }
            });
        } catch (PaymentNotPendingException) {
            // Verified or rejected by hand since the candidates were read.
            return $this->settle($line, StatementLineOutcome::NeedsReview, $userId) ? StatementLineOutcome::NeedsReview : false;
        } catch (LineAlreadyLinkedException) {
            return false;
        }

        return StatementLineOutcome::AutoVerified;
    }

    /**
     * Verified, still unlinked proofs of exactly this amount that this credit may already
     * have paid for. Exactly one is confirmed; any at all makes the credit ambiguous.
     *
     * @return Collection<int, PaymentProof>
     */
    private function confirmable(BankStatementLine $line, int $userId): Collection
    {
        $day = $line->txn_date->toImmutable();

        return PaymentProof::where('user_id', $userId)
            ->where('status', 'verified')
            ->whereDoesntHave('bankStatementLine')
            ->whereBetween('verified_at', [
                $day->startOfDay()->subDays(self::CONFIRM_SLACK_DAYS),
                $day->endOfDay()->addDays(self::CONFIRM_WINDOW_DAYS),
            ])
            ->get()
            ->filter(fn (PaymentProof $proof) => FinanceService::toCents($proof->amount_verified) === FinanceService::toCents($line->amount))
            ->values();
    }

    private function confirm(BankStatementLine $line, int $userId, PaymentProof $proof): StatementLineOutcome|false|null
    {
        try {
            // Own transaction (a savepoint when nested), so a lost race leaves Postgres usable.
            $linked = DB::transaction(fn () => $this->settle($line, StatementLineOutcome::Confirmed, $userId, $proof->id));
        } catch (UniqueConstraintViolationException) {
            return null; // Another line took this proof meanwhile.
        }

        return $linked ? StatementLineOutcome::Confirmed : false;
    }

    private function leaveForReview(BankStatementLine $line, int $userId): StatementLineOutcome|false
    {
        $outcome = PaymentProof::where('user_id', $userId)->where('status', 'pending')->exists()
            ? StatementLineOutcome::NeedsReview
            : StatementLineOutcome::NoProof;

        return $this->settle($line, $outcome, $userId) ? $outcome : false;
    }

    /**
     * Writes the line's outcome only while it is still unlinked, so a concurrent import's
     * match is never overwritten. False when another run linked it first.
     */
    private function settle(BankStatementLine $line, StatementLineOutcome $outcome, ?int $userId, ?int $proofId = null): bool
    {
        $attributes = ['user_id' => $userId, 'outcome' => $outcome->value, 'payment_proof_id' => $proofId];

        if (BankStatementLine::whereKey($line->id)->whereNull('payment_proof_id')->update($attributes) === 0) {
            return false;
        }

        $line->forceFill($attributes)->syncOriginal();

        return true;
    }
}
