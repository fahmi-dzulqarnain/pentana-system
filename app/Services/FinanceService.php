<?php

namespace App\Services;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinanceService
{
    /**
     * Verify a submitted payment proof, (re)allocate the member's funds, and issue
     * the receipt. All in one transaction, so a failure anywhere releases the number.
     */
    public function verifyAndAllocate(PaymentProof $payment, float $verifiedAmount): void
    {
        DB::transaction(function () use ($payment, $verifiedAmount) {
            // The lock makes a double-click or a second admin wait, then see it is no longer pending.
            $status = PaymentProof::whereKey($payment->id)->lockForUpdate()->value('status');
            if ($status !== 'pending') {
                throw new RuntimeException("Payment proof {$payment->id} is not pending.");
            }

            $allocationIdBefore = (int) PaymentAllocation::max('id');

            $payment->update([
                'amount_verified' => $verifiedAmount,
                'status' => 'verified',
                'verified_at' => now(),
            ]);

            $this->applyAvailableFunds($payment->user);

            app(ReceiptService::class)->issueFor($payment->fresh(), $allocationIdBefore);
        });
    }

    /**
     * Allocate every verified-but-unspent ringgit the member has to their
     * outstanding bills - oldest bill first, drawing from the oldest proof
     * first. Whatever stays unallocated remains the member's credit, held
     * implicitly as the proof's remainder (see User::getCreditAttribute()).
     *
     * $exceptProofId leaves out a payment that is about to be deleted.
     */
    public function applyAvailableFunds(User $user, ?int $exceptProofId = null): void
    {
        DB::transaction(function () use ($user, $exceptProofId) {
            $periods = FeePeriod::where('user_id', $user->id)
                ->where('status', '!=', 'paid')
                ->orderBy('month_date')
                ->get();

            $proofs = PaymentProof::where('user_id', $user->id)
                ->where('status', 'verified')
                ->when($exceptProofId, fn ($query) => $query->whereKeyNot($exceptProofId))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            // Track each proof's unallocated funds in integer cents to avoid float drift.
            $remaining = [];
            foreach ($proofs as $proof) {
                $remaining[$proof->id] = $this->toCents($proof->amount_verified)
                    - $this->toCents($proof->allocations()->sum('amount_allocated'));
            }

            foreach ($periods as $period) {
                $needed = $this->toCents($period->amount_due)
                    - $this->toCents($period->allocations()->sum('amount_allocated'));

                foreach ($proofs as $proof) {
                    if ($needed <= 0) {
                        break;
                    }
                    if ($remaining[$proof->id] <= 0) {
                        continue;
                    }

                    $take = min($needed, $remaining[$proof->id]);

                    PaymentAllocation::create([
                        'payment_proof_id' => $proof->id,
                        'fee_period_id' => $period->id,
                        'amount_allocated' => $take / 100,
                    ]);

                    $remaining[$proof->id] -= $take;
                    $needed -= $take;
                }

                $this->refreshStatus($period);
            }
        });
    }

    /**
     * Undo a payment before it is deleted: drop its allocations, re-derive the status of
     * every bill it had paid, then let the member's other verified payments cover them.
     * Without this the bills kept saying "paid" with nothing behind them.
     */
    public function releasePayment(PaymentProof $payment): void
    {
        DB::transaction(function () use ($payment) {
            $periodIds = $payment->allocations()->pluck('fee_period_id');
            $payment->allocations()->delete();

            FeePeriod::whereKey($periodIds)->get()->each(fn (FeePeriod $period) => $this->refreshStatus($period));

            if ($payment->user) {
                $this->applyAvailableFunds($payment->user, exceptProofId: $payment->id);
            }
        });
    }

    private function refreshStatus(FeePeriod $period): void
    {
        $paid = $this->toCents($period->allocations()->sum('amount_allocated'));
        $due = $this->toCents($period->amount_due);
        $period->update([
            'status' => $paid >= $due ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    /**
     * Sum of unpaid remainders across a member's bills, as loaded by
     * FeePeriod::forMember() (relies on allocations_sum_amount_allocated).
     * An over-paid bill clamps to zero rather than offsetting other bills.
     */
    public static function outstanding(Collection $bills): float
    {
        return round($bills->sum(
            fn (FeePeriod $bill) => max(0, (float) $bill->amount_due - (float) $bill->allocations_sum_amount_allocated)
        ), 2);
    }

    public static function toCents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
