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
    // Last on the dashboard: it is deliberately quiet unless the email cap is in trouble.
    protected static ?int $sort = 50;

    protected function getHeading(): ?string
    {
        return __('Email budget');
    }

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

    public function failedCount(): int
    {
        return QueuedEmail::whereNotNull('failed_at')->count();
    }

    public function queueColour(): string
    {
        if ($this->failedCount() > 0) {
            return 'danger';
        }

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
            ? __('cap reached - mail is queued')
            : __(':count remaining today', ['count' => EmailBudget::DAILY_LIMIT - $usedToday]);

        $monthPercent = (int) round($usedMonth / EmailBudget::MONTHLY_LIMIT * 100);
        $monthlyColour = $this->monthlyColourFor($usedMonth, $projected);

        $monthDescription = match ($monthlyColour) {
            'danger' => __('monthly limit exceeded'),
            'warning' => __('projected :projected - over limit', ['projected' => $projected]),
            default => __(':percent% used · projected :projected', ['percent' => $monthPercent, 'projected' => $projected]),
        };

        $queueColour = $this->queueColour();

        $failed = $this->failedCount();

        $queueDescription = match (true) {
            $failed > 0 => __(':count permanently failed - mail was lost', ['count' => $failed]),
            $pending === 0 => __('nothing waiting'),
            $queueColour === 'danger' => __('oldest :age - not draining', ['age' => $oldest->diffForHumans()]),
            default => __('oldest :age', ['age' => $oldest->diffForHumans()]),
        };

        return [
            Stat::make(__('Email today'), $usedToday.' / '.EmailBudget::DAILY_LIMIT)
                ->description($todayDescription)
                ->descriptionIcon($dailyColour === 'gray' ? 'heroicon-m-envelope' : 'heroicon-m-exclamation-triangle')
                ->color($dailyColour),

            Stat::make(__('This month'), NeedsAttention::count($usedMonth).' / '.NeedsAttention::count(EmailBudget::MONTHLY_LIMIT))
                ->description($monthDescription)
                ->descriptionIcon($monthlyColour === 'gray' ? 'heroicon-m-calendar' : 'heroicon-m-arrow-trending-up')
                ->color($monthlyColour),

            Stat::make(__('Queued'), (string) $pending)
                ->description($queueDescription)
                ->descriptionIcon($queueColour === 'danger' ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-clock')
                ->color($queueColour),
        ];
    }
}
