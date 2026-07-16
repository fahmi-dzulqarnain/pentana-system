<?php

namespace App\Services;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    /**
     * Verify a submitted payment proof, then (re)allocate the member's funds.
     */
    public function verifyAndAllocate(PaymentProof $payment, float $verifiedAmount): void
    {
        DB::transaction(function () use ($payment, $verifiedAmount) {
            $payment->update([
                'amount_verified' => $verifiedAmount,
                'status' => 'verified',
            ]);

            $this->applyAvailableFunds($payment->user);
        });
    }

    /**
     * Allocate every verified-but-unspent ringgit the member has to their
     * outstanding bills — oldest bill first, drawing from the oldest proof
     * first. Whatever stays unallocated remains the member's credit, held
     * implicitly as the proof's remainder (see User::getCreditAttribute()).
     */
    public function applyAvailableFunds(User $user): void
    {
        DB::transaction(function () use ($user) {
            $periods = FeePeriod::where('user_id', $user->id)
                ->where('status', '!=', 'paid')
                ->orderBy('month_date')
                ->get();

            $proofs = PaymentProof::where('user_id', $user->id)
                ->where('status', 'verified')
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

                $paid = $this->toCents($period->allocations()->sum('amount_allocated'));
                $due = $this->toCents($period->amount_due);
                $period->update([
                    'status' => $paid >= $due ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                ]);
            }
        });
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

    private function toCents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
