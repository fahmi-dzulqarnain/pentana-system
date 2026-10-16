<?php

namespace App\Console\Commands;

use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Services\FinanceService;
use Illuminate\Console\Command;
use Throwable;

class SendBillReminders extends Command
{
    protected $signature = 'app:send-bill-reminders';

    protected $description = 'Remind members with an outstanding balance about their unpaid bills';

    public function handle(): int
    {
        $userIds = FeePeriod::where('status', '!=', 'paid')->distinct()->pluck('user_id');

        $sent = 0;
        foreach (User::enabled()->whereIn('id', $userIds)->get() as $user) {
            $bills = FeePeriod::forMember($user->id)->get();
            $outstanding = FinanceService::outstanding($bills);

            if ($outstanding <= 0) {
                continue;
            }

            try {
                $user->notify(new BillReminder($outstanding, $bills->where('status', '!=', 'paid')->count()));
                $sent++;
            } catch (Throwable $e) {
                report($e); // one bad APNs token must not abort the whole run
            }
        }

        $this->info("Sent bill reminders to {$sent} member(s).");

        return self::SUCCESS;
    }
}
