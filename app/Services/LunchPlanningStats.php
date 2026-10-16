<?php

namespace App\Services;

use App\Models\Lunch;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class LunchPlanningStats
{
    public function workingDays(Carbon $month): int
    {
        return collect(CarbonPeriod::create($month->copy()->startOfMonth(), $month->copy()->endOfMonth()))
            ->filter(fn (Carbon $d) => $d->isWeekday())
            ->count();
    }

    public function daysPlanned(Carbon $month): int
    {
        return $this->lunchesForMonth($month)->count();
    }

    public function estimatedCostForMonth(Carbon $month): float
    {
        return round(
            $this->lunchesForMonth($month)
                ->load('menus.catererMeal.options', 'menus.options')
                ->sum(fn (Lunch $lunch) => $lunch->estimatedCost()),
            2
        );
    }

    public function avgOrdersPerDay(Carbon $month): float
    {
        $lunches = Lunch::whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->whereIn('status', ['published', 'closed'])
            ->with('responses')
            ->get();

        if ($lunches->isEmpty()) {
            return 0.0;
        }

        return round($lunches->sum(fn (Lunch $lunch) => $lunch->attendingCount()) / $lunches->count(), 1);
    }

    private function lunchesForMonth(Carbon $month): Collection
    {
        return Lunch::whereBetween('date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->get();
    }
}
