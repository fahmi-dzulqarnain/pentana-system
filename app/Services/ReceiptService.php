<?php

namespace App\Services;

use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentProof;
use App\Models\Payment\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceiptService
{
    /**
     * Snapshot what this approval settled. applyAvailableFunds() spends the oldest
     * credit first, so the new allocations (id > $allocationIdBefore) can draw on
     * older proofs too: that part is credit_used, and whatever of this proof stays
     * unallocated is credit_added. Must run inside the verify transaction.
     */
    public function issueFor(PaymentProof $proof, int $allocationIdBefore): PaymentReceipt
    {
        $year = $proof->verified_at->timezone(config('app.timezone'))->year;
        [$sequence, $number] = $this->nextNumber($year);

        $allocations = PaymentAllocation::with('feePeriod')
            ->where('id', '>', $allocationIdBefore)
            ->whereHas('feePeriod', fn ($query) => $query->where('user_id', $proof->user_id))
            ->get();

        $lines = $allocations->groupBy('fee_period_id')
            ->sortBy(fn ($group) => $group->first()->feePeriod->month_date)
            ->map(fn ($group) => [
                'label' => $this->lineLabel($group->first()->feePeriod),
                'month' => $group->first()->feePeriod->month_date->format('Y-m-d'),
                'amount' => $this->money($group->sum(fn ($allocation) => FinanceService::toCents($allocation->amount_allocated))),
            ])
            ->values()
            ->all();

        $creditUsed = $allocations->where('payment_proof_id', '!=', $proof->id)
            ->sum(fn ($allocation) => FinanceService::toCents($allocation->amount_allocated));
        $creditAdded = FinanceService::toCents($proof->amount_verified)
            - FinanceService::toCents($proof->allocations()->sum('amount_allocated'));

        return PaymentReceipt::create([
            'number' => $number,
            'year' => $year,
            'sequence' => $sequence,
            'user_id' => $proof->user_id,
            'payment_proof_id' => $proof->id,
            'member_name' => $proof->user->name,
            'member_email' => $proof->user->email,
            'amount_received' => $this->money(FinanceService::toCents($proof->amount_verified)),
            'credit_used' => $this->money($creditUsed),
            'credit_added' => $this->money($creditAdded),
            'lines' => $lines,
            'verified_at' => $proof->verified_at,
        ]);
    }

    /**
     * The payment is being deleted, so the receipt no longer stands. Keep the row
     * (and its number, which is never reused) and mark it void; the FK nulls
     * payment_proof_id once the proof row is gone.
     */
    public function voidFor(PaymentProof $proof): void
    {
        $receipt = $proof->receipt;

        if ($receipt && ! $receipt->isVoid()) {
            $receipt->forceFill(['voided_at' => now()])->save();
        }
    }

    /**
     * Take the next number for the year. Called inside the verify transaction, so the
     * row lock serialises concurrent approvals and a rollback hands the number back.
     *
     * @return array{0: int, 1: string}
     */
    public function nextNumber(int $year): array
    {
        DB::table('receipt_counters')->insertOrIgnore([
            'year' => $year,
            'last_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('receipt_counters')->where('year', $year)->lockForUpdate()->value('last_sequence') + 1;

        DB::table('receipt_counters')->where('year', $year)->update([
            'last_sequence' => $sequence,
            'updated_at' => now(),
        ]);

        return [$sequence, sprintf('PEN-%d-%04d', $year, $sequence)];
    }

    // Always English: format() ignores the app locale.
    public function lineLabel(FeePeriod $period): string
    {
        $kind = match ($period->type) {
            'regular' => 'Monthly fee',
            'adjustment' => 'Adjustment',
            default => Str::headline($period->type),
        };

        $label = "{$kind}: {$period->month_date->format('F Y')}";

        // Regular months' remarks are admin notes; only adjustments and levies need explaining.
        return $period->type !== 'regular' && filled($period->remarks) ? "{$label} — {$period->remarks}" : $label;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
