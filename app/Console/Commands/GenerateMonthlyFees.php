<?php

namespace App\Console\Commands;

use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Console\Command;

class GenerateMonthlyFees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-monthly-fees';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate this month\'s fee bill for every member and apply any credit they hold';

    /**
     * Execute the console command.
     */
    public function handle(FinanceService $finance)
    {
        $currentMonth = now()->startOfMonth();

        // Disabled members (invitation expired, never claimed) never joined, so they are not billed.
        foreach (User::enabled()->get() as $user) {
            // active_fee honours a per-member custom_fee override, then the
            // category default, then 0 (a free month).
            FeePeriod::firstOrCreate(
                ['user_id' => $user->id, 'month_date' => $currentMonth],
                ['amount_due' => $user->active_fee, 'status' => 'unpaid']
            );

            // Spend any credit the member is holding against their outstanding bills.
            $finance->applyAvailableFunds($user);
        }
    }
}
