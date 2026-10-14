<?php

namespace App\Filament\Widgets;

use App\Models\EmailBudget;
use App\Models\QueuedEmail;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Email budget against Resend's free tier.
 *
 * The normal state is deliberately colourless - if this widget is interesting, something
 * is wrong. A large queue immediately after a bill run is healthy and stays grey; only a
 * backlog that is failing to drain turns red, because that means average inflow has
 * exceeded the daily cap and the queue is compounding.
 */
class EmailBudgetStats extends StatsOverviewWidget
{
    protected static ?int $sort = -5;

    protected ?string $heading = 'Email budget';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function dailyColour(): string
    {
        $ratio = EmailBudget::usedToday() / EmailBudget::DAILY_LIMIT;

        return match (true) {
            $ratio >= 0.9 => 'danger',
            $ratio >= 0.7 => 'warning',
            default => 'gray',
        };
    }

    public function projectedMonthly(): int
    {
        $now = Carbon::now('UTC');

        return (int) round(EmailBudget::monthToDate() / $now->day * $now->daysInMonth);
    }

    public function monthlyColourFor(int $used, int $projected): string
    {
        return match (true) {
            $used >= EmailBudget::MONTHLY_LIMIT => 'danger',
            $projected > EmailBudget::MONTHLY_LIMIT => 'warning',
            default => 'gray',
        };
    }

    public function oldestPendingAt(): ?Carbon
    {
        $oldest = QueuedEmail::whereNull('sent_at')->whereNull('failed_at')
            ->orderBy('created_at')->value('created_at');

        return $oldest ? Carbon::parse($oldest) : null;
    }

    public function queueColour(): string
    {
        $oldest = $this->oldestPendingAt();

        return $oldest && $oldest->lt(now()->subDay()) ? 'danger' : 'gray';
    }

    protected function getStats(): array
    {
        $usedToday = EmailBudget::usedToday();
        $usedMonth = EmailBudget::monthToDate();
        $projected = $this->projectedMonthly();
        $pending = QueuedEmail::whereNull('sent_at')->whereNull('failed_at')->count();
        $oldest = $this->oldestPendingAt();
        $dailyColour = $this->dailyColour();

        $todayDescription = $usedToday >= EmailBudget::DAILY_LIMIT
            ? 'cap reached - mail is queued'
            : (EmailBudget::DAILY_LIMIT - $usedToday).' remaining today';

        $monthPercent = (int) round($usedMonth / EmailBudget::MONTHLY_LIMIT * 100);
        $monthlyColour = $this->monthlyColourFor($usedMonth, $projected);

        $monthDescription = match ($monthlyColour) {
            'danger' => 'monthly limit exceeded',
            'warning' => "projected {$projected} - over limit",
            default => "{$monthPercent}% used · projected {$projected}",
        };

        $queueColour = $this->queueColour();

        $queueDescription = match (true) {
            $pending === 0 => 'nothing waiting',
            $queueColour === 'danger' => 'oldest '.$oldest->diffForHumans().' - not draining',
            default => 'oldest '.$oldest->diffForHumans(),
        };

        return [
            Stat::make('Email today', $usedToday.' / '.EmailBudget::DAILY_LIMIT)
                ->description($todayDescription)
                ->descriptionIcon($dailyColour === 'gray' ? 'heroicon-m-envelope' : 'heroicon-m-exclamation-triangle')
                ->color($dailyColour),

            Stat::make('This month', number_format($usedMonth).' / '.number_format(EmailBudget::MONTHLY_LIMIT))
                ->description($monthDescription)
                ->descriptionIcon($monthlyColour === 'gray' ? 'heroicon-m-calendar' : 'heroicon-m-arrow-trending-up')
                ->color($monthlyColour),

            Stat::make('Queued', (string) $pending)
                ->description($queueDescription)
                ->descriptionIcon($queueColour === 'danger' ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-clock')
                ->color($queueColour),
        ];
    }
}
