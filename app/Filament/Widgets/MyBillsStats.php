<?php

namespace App\Filament\Widgets;

use App\Models\Payment\FeePeriod;
use App\Services\FinanceService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

class MyBillsStats extends StatsOverviewWidget
{
    protected static ?int $sort = -4;

    protected ?Collection $bills = null;

    protected function getHeading(): ?string
    {
        return __('My Bills');
    }

    public static function canView(): bool
    {
        return ! (bool) auth()->user()?->is_admin;
    }

    public function getOutstanding(): float
    {
        return FinanceService::outstanding($this->bills());
    }

    public function getPaidToDate(): float
    {
        return round($this->bills()->sum(
            fn (FeePeriod $bill) => (float) $bill->allocations_sum_amount_allocated
        ), 2);
    }

    public function getCredit(): float
    {
        return (float) (auth()->user()?->credit ?? 0);
    }

    protected function bills(): Collection
    {
        return $this->bills ??= FeePeriod::forMember(auth()->id())->get();
    }

    protected function getStats(): array
    {
        $outstanding = $this->getOutstanding();
        $unpaidCount = $this->bills()->where('status', '!=', 'paid')->count();
        $billCount = $this->bills()->count();

        return [
            Stat::make(__('Outstanding'), 'MYR '.number_format($outstanding, 2))
                ->description($outstanding > 0
                    ? trans_choice(':count bill due|:count bills due', $unpaidCount)
                    : __("You're all settled"))
                ->descriptionIcon($outstanding > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle')
                ->color($outstanding > 0 ? 'danger' : 'success'),

            Stat::make(__('Available credit'), 'MYR '.number_format($this->getCredit(), 2))
                ->description(__('Applied to your next bill'))
                ->descriptionIcon('heroicon-m-wallet')
                ->color('gray'),

            Stat::make(__('Paid to date'), 'MYR '.number_format($this->getPaidToDate(), 2))
                ->description(trans_choice('Across :count bill|Across :count bills', $billCount))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
