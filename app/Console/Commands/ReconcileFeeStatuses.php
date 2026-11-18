<?php

namespace App\Console\Commands;

use App\Models\Payment\FeePeriod;
use App\Services\FinanceService;
use Illuminate\Console\Command;

/**
 * One-off repair for bills whose amount was edited before amount edits re-derived
 * the status: finds bills whose stored status, or allocated total, no longer fits
 * their amount_due and re-balances them.
 */
class ReconcileFeeStatuses extends Command
{
    protected $signature = 'app:reconcile-fee-statuses {--dry-run : List the bills that would change, without changing them}';

    protected $description = 'Re-derive bill statuses (and move surplus payments) for bills that no longer match their amount due';

    public function handle(FinanceService $finance): int
    {
        $stale = FeePeriod::with('user')
            ->withSum('allocations', 'amount_allocated')
            ->orderBy('user_id')
            ->orderBy('month_date')
            ->get()
            ->filter(function (FeePeriod $bill) {
                $paid = FinanceService::toCents($bill->allocations_sum_amount_allocated);
                $due = FinanceService::toCents($bill->amount_due);
                $expected = $paid >= $due ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

                return $paid > $due || ($due > 0 && $bill->status !== $expected);
            });

        foreach ($stale as $bill) {
            $this->line(sprintf(
                '%s  %s  due %s  paid %s  status %s',
                $bill->user?->name ?? "user #{$bill->user_id}",
                $bill->month_date->format('Y-m'),
                $bill->amount_due,
                number_format((float) $bill->allocations_sum_amount_allocated, 2),
                $bill->status,
            ));

            if (! $this->option('dry-run')) {
                $finance->reconcileBill($bill->fresh());
            }
        }

        $this->info(($this->option('dry-run') ? 'Would reconcile ' : 'Reconciled ').$stale->count().' bill(s).');

        return self::SUCCESS;
    }
}
