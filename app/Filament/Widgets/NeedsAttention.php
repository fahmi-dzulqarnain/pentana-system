<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LunchCalendar;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Filament\Resources\FeePeriods\FeePeriodResource;
use App\Filament\Resources\Lunches\LunchResource;
use App\Models\ActivityRegistration;
use App\Models\Lunch;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * The admin's starting point: what is waiting on them, each tile linking to where it
 * gets done. Grey when there is nothing to do, so a busy tile stands out.
 */
class NeedsAttention extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    // Rendered with the page: this is the first thing an admin needs, not a placeholder box.
    protected static bool $isLazy = false;

    protected int|array|null $columns = ['md' => 2, 'xl' => 4];

    public static function canView(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    protected function getHeading(): ?string
    {
        return __('Needs attention');
    }

    protected function getStats(): array
    {
        return [
            $this->paymentsToVerify(),
            $this->nextLunch(),
            $this->waitlists(),
            $this->unpaidBills(),
        ];
    }

    private function paymentsToVerify(): Stat
    {
        $pending = PaymentProof::where('status', 'pending');
        $count = (clone $pending)->count();
        $oldest = (clone $pending)->oldest()->value('created_at');

        return Stat::make(__('Payments to verify'), self::count($count))
            ->description($count > 0
                ? __('oldest :age', ['age' => Carbon::parse($oldest)->diffForHumans()])
                : __('All checked'))
            ->descriptionIcon($count > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
            ->color($count > 0 ? 'warning' : 'gray')
            ->url(FeePaymentResource::getUrl('index'));
    }

    private function nextLunch(): Stat
    {
        $lunch = Lunch::where('status', 'published')
            ->where('deadline', '>=', now())
            ->orderBy('deadline')
            ->withCount(['responses as orders_count' => fn ($query) => $query->whereNotNull('meal_option_id')])
            ->first();

        if (! $lunch) {
            return Stat::make(__('Next lunch'), __('None open'))
                ->description(__('Plan the next lunch'))
                ->descriptionIcon('heroicon-m-plus')
                ->color('gray')
                ->url(LunchCalendar::getUrl());
        }

        return Stat::make(__('Next lunch'), __(':count ordered', ['count' => self::count($lunch->orders_count)]))
            ->description(__('Order by :deadline', ['deadline' => $lunch->deadline->translatedFormat('D j M, g:i A')]))
            ->descriptionIcon('heroicon-m-clock')
            ->color('gray')
            ->url(LunchResource::getUrl('edit', ['record' => $lunch]));
    }

    private function waitlists(): Stat
    {
        $waiting = ActivityRegistration::where('status', 'waitlisted')
            ->whereHas('activity', fn ($query) => $query->where('status', 'published')->where('starts_at', '>=', now()));
        $count = (clone $waiting)->count();
        $activities = (clone $waiting)->distinct()->count('activity_id');

        return Stat::make(__('On waitlists'), self::count($count))
            ->description($count > 0
                ? trans_choice('{1} for :count activity|[2,*] across :count activities', $activities, ['count' => $activities])
                : __('No one waiting'))
            ->descriptionIcon('heroicon-m-user-group')
            ->color($count > 0 ? 'warning' : 'gray')
            ->url(ActivityResource::getUrl('index'));
    }

    private function unpaidBills(): Stat
    {
        $thisMonth = FeePeriod::whereDate('month_date', now()->startOfMonth());
        $total = (clone $thisMonth)->count();
        $unpaid = (clone $thisMonth)->where('status', '!=', 'paid')->count();

        return Stat::make(__('Unpaid bills this month'), self::count($unpaid))
            ->description($total > 0
                ? __('of :total bills', ['total' => self::count($total)])
                : __('No bills issued yet'))
            ->descriptionIcon('heroicon-m-document-text')
            ->color('gray')
            ->url(FeePeriodResource::getUrl('index'));
    }

    /** Counts in the viewer's convention: 3,000 in English and Malay, 3.000 in Indonesian. */
    public static function count(int|float $value): string
    {
        return Number::format($value, locale: app()->getLocale());
    }
}
