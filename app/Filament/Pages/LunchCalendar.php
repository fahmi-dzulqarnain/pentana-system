<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use App\Models\Lunch;
use App\Services\LunchPlanningStats;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class LunchCalendar extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Welfare & Social';

    protected static ?string $navigationLabel = 'Menu Calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CalendarDays;

    protected static ?string $title = 'Menu Calendar';

    protected string $view = 'filament.pages.lunch-calendar';

    /** YYYY-MM of the displayed month. */
    public string $month = '';

    public function mount(): void
    {
        $this->month = $this->month ?: now()->format('Y-m');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMenu')->label('Add Menu')->icon('heroicon-o-plus')
                ->url(fn () => LunchResource::getUrl('create')),
        ];
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    public function goToday(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function monthStart(): Carbon
    {
        return Carbon::parse($this->month.'-01');
    }

    public function monthLabel(): string
    {
        return $this->monthStart()->format('F Y');
    }

    public function daysPlanned(): int
    {
        return app(LunchPlanningStats::class)->daysPlanned($this->monthStart());
    }

    public function workingDays(): int
    {
        return app(LunchPlanningStats::class)->workingDays($this->monthStart());
    }

    public function estimatedCost(): float
    {
        return app(LunchPlanningStats::class)->estimatedCostForMonth($this->monthStart());
    }

    public function avgOrdersPerDay(): float
    {
        return app(LunchPlanningStats::class)->avgOrdersPerDay($this->monthStart());
    }

    private function lunchesByDate(): Collection
    {
        $start = $this->monthStart()->copy()->startOfMonth();
        $end = $this->monthStart()->copy()->endOfMonth();

        return Lunch::with(['catererMeal', 'options.mealOption', 'responses'])
            ->whereBetween('date', [$start, $end])->get()
            ->keyBy(fn (Lunch $l) => $l->date->toDateString());
    }

    /** @return array<int, array<int, array{date: Carbon, inMonth: bool, lunch: ?Lunch}>> */
    public function calendarWeeks(): array
    {
        $gridStart = $this->monthStart()->copy()->startOfMonth()->startOfWeek(CarbonInterface::SUNDAY);
        $gridEnd = $this->monthStart()->copy()->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY);
        $lunches = $this->lunchesByDate();

        $weeks = [];
        $week = [];
        foreach (CarbonPeriod::create($gridStart, $gridEnd) as $day) {
            $week[] = [
                'date' => $day->copy(),
                'inMonth' => $day->month === $this->monthStart()->month,
                'lunch' => $lunches->get($day->toDateString()),
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return $weeks;
    }

    public function createUrlFor(Carbon $date): string
    {
        return LunchResource::getUrl('create', ['date' => $date->toDateString()]);
    }

    public function editUrlFor(Lunch $lunch): string
    {
        return LunchResource::getUrl('edit', ['record' => $lunch]);
    }
}
