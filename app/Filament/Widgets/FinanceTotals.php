<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Services\FinanceSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four club-wide figures at the top of the Finance summary page. Not discovered,
 * so it never lands on the dashboard; the page adds it as a header widget.
 * Tinted only when something needs the treasurer, grey otherwise (as NeedsAttention).
 */
class FinanceTotals extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canManageFinance();
    }

    protected function getColumns(): int|array|null
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $totals = FinanceSummary::totals();

        return [
            Stat::make(__('Outstanding'), self::money($totals['outstanding']))
                ->description(trans_choice('{1} :count member owes|[0,*] :count members owe', $totals['owing_members'], [
                    'count' => NeedsAttention::count($totals['owing_members']),
                ]))
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($totals['outstanding'] > 0 ? 'danger' : 'gray'),

            Stat::make(__('Collected this month'), self::money($totals['collected_this_month']))
                ->description(__(':amount all time', ['amount' => self::money($totals['collected_all_time'])]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray'),

            Stat::make(__('Credit held'), self::money($totals['credit']))
                ->description(trans_choice('{1} Paid ahead by :count member|[0,*] Paid ahead by :count members', $totals['credit_members'], [
                    'count' => NeedsAttention::count($totals['credit_members']),
                ]))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('gray'),

            Stat::make(__('Payments to verify'), NeedsAttention::count($totals['pending_count']))
                ->description($totals['pending_count'] > 0
                    ? __('oldest :age', ['age' => $totals['oldest_pending_at']->diffForHumans()])
                    : __('All checked'))
                ->descriptionIcon($totals['pending_count'] > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($totals['pending_count'] > 0 ? 'warning' : 'gray')
                ->url(FeePaymentResource::getUrl('index')),
        ];
    }

    /** MYR with two decimals, the way the app writes amounts in prose. */
    private static function money(float $amount): string
    {
        return 'MYR '.number_format($amount, 2);
    }
}
