<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\LunchMenu;
use App\Models\LunchResponse;
use App\Support\TimeFormat;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The My Lunch page as an agenda: a banner for pending votes, one row per day grouped by week, and
 * a vote panel per day. Mirrors the mobile apps' shared LunchAgenda, so the wording matches across
 * web, iOS and Android. Everything is computed from the lunches, the member's responses and now().
 */
class LunchAgenda
{
    /** Under this many seconds to the deadline, a day is urgent. */
    private const URGENT_SECONDS = 24 * 60 * 60;

    /**
     * @param  Collection<int, Lunch>  $lunches  published lunches, menus.caterer/catererMeal/options.mealOption loaded
     * @param  Collection<int, LunchResponse>  $responses  the member's responses, keyed by lunch_id
     * @return array{banner: ?array, sections: list<array{title: string, rows: list<array>}>}
     */
    public function build(Collection $lunches, Collection $responses): array
    {
        $now = now();
        $lunches = $lunches->sortBy(fn (Lunch $lunch) => $lunch->date->toDateString())->values();
        $weekStart = $now->copy()->startOfWeek()->startOfDay();

        $pending = $lunches
            ->filter(fn (Lunch $lunch) => $lunch->isOpenForVoting() && ! $responses->has($lunch->id))
            ->sortBy(fn (Lunch $lunch) => $lunch->deadline?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        $banner = null;
        if ($pending->isNotEmpty()) {
            $first = $pending->first();
            $lines = $pending->take(2)->map(fn (Lunch $lunch) => __(':day closes :when', [
                'day' => $lunch->date->translatedFormat('D'),
                'when' => $lunch->deadline ? $this->deadlinePhrase($lunch->deadline, $now) : __('soon'),
            ]))->all();
            if ($pending->count() > 2) {
                $lines[] = __('and :count more', ['count' => $pending->count() - 2]);
            }
            $banner = [
                'title' => $pending->count() === 1
                    ? __('1 lunch needs your vote')
                    : __(':count lunches need your vote', ['count' => $pending->count()]),
                'lines' => $lines,
                'action' => __('Vote for :day', ['day' => $first->date->translatedFormat('D')]),
                'lunch_id' => $first->id,
            ];
        }

        $sections = collect([
            __('This week') => fn (CarbonInterface $date) => $date->lt($weekStart->copy()->addWeek()),
            __('Next week') => fn (CarbonInterface $date) => $date->gte($weekStart->copy()->addWeek()) && $date->lt($weekStart->copy()->addWeeks(2)),
            __('Later') => fn (CarbonInterface $date) => $date->gte($weekStart->copy()->addWeeks(2)),
        ])->map(fn (callable $inSection, string $title) => [
            'title' => $title,
            'rows' => $lunches->filter(fn (Lunch $lunch) => $inSection($lunch->date->copy()->startOfDay()))
                ->map(fn (Lunch $lunch) => $this->row($lunch, $responses->get($lunch->id), $now))
                ->values()->all(),
        ])->filter(fn (array $section) => $section['rows'] !== [])->values()->all();

        return ['banner' => $banner, 'sections' => $sections];
    }

    /** The vote panel for one day, or null when that lunch isn't in the list. */
    public function panel(Collection $lunches, Collection $responses, int $lunchId): ?array
    {
        $now = now();
        $lunch = $lunches->firstWhere('id', $lunchId);
        if (! $lunch) {
            return null;
        }
        $mine = $responses->get($lunch->id);
        $open = $lunch->isOpenForVoting();

        $status = match (true) {
            ! $open => __('Ordering closed'),
            $lunch->deadline === null => __('Ordering open'),
            $mine !== null => __('Change by :when', ['when' => $this->deadlinePhrase($lunch->deadline, $now)]),
            default => __('Closes :when', ['when' => $this->deadlinePhrase($lunch->deadline, $now)]),
        };

        $next = null;
        if ($open && $mine !== null) {
            $other = $lunches
                ->filter(fn (Lunch $other) => $other->id !== $lunch->id && $other->isOpenForVoting() && ! $responses->has($other->id))
                ->sortBy(fn (Lunch $other) => $other->deadline?->getTimestamp() ?? PHP_INT_MAX)
                ->first();
            if ($other) {
                $next = ['lunch_id' => $other->id, 'label' => __('Next: :day', ['day' => $other->date->translatedFormat('D j M')])];
            }
        }

        return [
            'lunch_id' => $lunch->id,
            'title' => $lunch->date->translatedFormat('l, j M'),
            'status' => $status,
            'urgent' => $this->isUrgent($lunch, $now),
            'open' => $open,
            'menus' => $lunch->menus->map(fn (LunchMenu $menu) => [
                'heading' => $menu->label(),
                'image' => $menu->catererMeal?->image ? asset('storage/'.$menu->catererMeal->image) : null,
                'options' => $menu->options->where('enabled', true)
                    ->filter(fn ($option) => $option->mealOption !== null)
                    ->map(fn ($option) => [
                        'id' => $option->id,
                        'name' => $option->mealOption->name,
                        'selected' => $mine !== null && $mine->lunch_option_id === $option->id,
                    ])->values()->all(),
            ])->values()->all(),
            'not_attending' => $this->isNotAttending($mine),
            'summary' => $open ? null : $this->closedSummary($lunch, $mine),
            'next' => $next,
        ];
    }

    /** What the member's answer is called: the option's name, or "Not attending". */
    public function answerLabel(Lunch $lunch, ?int $lunchOptionId): string
    {
        if ($lunchOptionId === null) {
            return __('Not attending');
        }

        return $lunch->menus->flatMap->options->firstWhere('id', $lunchOptionId)?->mealOption?->name ?? __('your pick');
    }

    private function row(Lunch $lunch, ?LunchResponse $mine, CarbonInterface $now): array
    {
        $open = $lunch->isOpenForVoting();
        $isToday = $lunch->date->isSameDay($now);
        $pickedMenu = $mine?->lunch_option_id ? $this->menuOf($lunch, $mine->lunch_option_id) : null;
        // Older responses carry only meal_option_id; "not attending" is neither set.
        $pick = match (true) {
            $mine?->lunch_option_id !== null && $mine !== null => $this->answerLabel($lunch, $mine->lunch_option_id),
            $mine?->meal_option_id !== null && $mine !== null => $mine->mealOption?->name,
            default => null,
        };
        $notAttending = $this->isNotAttending($mine);

        $kind = match (true) {
            $open && $mine === null => 'needs_vote',
            $open => 'ordered',
            $lunch->date->copy()->startOfDay()->lt($now->copy()->startOfDay()) => 'past',
            default => 'closed',
        };

        $title = match (true) {
            $kind === 'needs_vote' => __('Not voted yet'),
            $pick !== null => $pick,
            $notAttending => __('Not attending'),
            $mine !== null => __('Order placed'),
            default => __("You didn't order"),
        };

        $subtitle = match ($kind) {
            'needs_vote' => $lunch->deadline ? __('Closes :when', ['when' => $this->deadlinePhrase($lunch->deadline, $now)]) : __('Ordering open'),
            'ordered' => $lunch->deadline ? __('Change by :when', ['when' => $this->deadlinePhrase($lunch->deadline, $now)]) : null,
            'closed' => $isToday ? null : __('Ordering closed'),
            default => null,
        };

        $dish = $pickedMenu?->catererMeal?->name;
        $detail = $dish && $isToday && ! $open
            ? collect([$dish, $pickedMenu->caterer?->name])->filter()->implode(' · ')
            : $dish;

        $preview = $kind === 'needs_vote'
            ? $lunch->menus->map(fn (LunchMenu $menu) => $menu->catererMeal?->name ?? $menu->caterer?->name)->filter()->implode(' '.__('or').' ')
            : null;

        return [
            'lunch_id' => $lunch->id,
            'kind' => $kind,
            'is_today' => $isToday,
            'weekday' => mb_strtoupper($lunch->date->translatedFormat('D')),
            'day' => $lunch->date->format('j'),
            'eyebrow' => $isToday ? ($pick !== null && ! $open ? __("Today · You're having") : __('Today')) : null,
            'title' => $title,
            'detail' => $detail,
            'subtitle' => $subtitle,
            'menu_preview' => $preview ?: null,
            'urgent' => $this->isUrgent($lunch, $now),
            'action' => match ($kind) {
                'needs_vote' => 'vote',
                'ordered' => 'change',
                default => 'view',
            },
            'image' => $isToday && $pickedMenu?->catererMeal?->image ? asset('storage/'.$pickedMenu->catererMeal->image) : null,
        ];
    }

    private function isNotAttending(?LunchResponse $mine): bool
    {
        return $mine !== null && $mine->lunch_option_id === null && $mine->meal_option_id === null;
    }

    private function isUrgent(Lunch $lunch, CarbonInterface $now): bool
    {
        return $lunch->isOpenForVoting()
            && $lunch->deadline !== null
            && $lunch->deadline->getTimestamp() - $now->getTimestamp() < self::URGENT_SECONDS;
    }

    /** "today 4:00 PM · in 6 h", "tomorrow 4:00 PM", "Sun 4:00 PM" within the week, else "Sun 8 Nov, 4:00 PM". */
    public function deadlinePhrase(CarbonInterface $deadline, CarbonInterface $now): string
    {
        $time = $deadline->translatedFormat(TimeFormat::time());
        $days = (int) $now->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);

        $when = match (true) {
            $days === 0 => __('today :time', ['time' => $time]),
            $days === 1 => __('tomorrow :time', ['time' => $time]),
            $days >= 2 && $days <= 6 => __(':day :time', ['day' => $deadline->translatedFormat('D'), 'time' => $time]),
            default => __(':date, :time', ['date' => $deadline->translatedFormat('D j M'), 'time' => $time]),
        };

        $remaining = $deadline->getTimestamp() - $now->getTimestamp();
        $countdown = match (true) {
            $remaining < 0 => null,
            $remaining < 3600 => __('in :count min', ['count' => max(1, intdiv($remaining, 60))]),
            $remaining < self::URGENT_SECONDS => __('in :count h', ['count' => intdiv($remaining, 3600)]),
            default => null,
        };

        return $countdown ? $when.' · '.$countdown : $when;
    }

    private function menuOf(Lunch $lunch, int $lunchOptionId): ?LunchMenu
    {
        return $lunch->menus->first(fn (LunchMenu $menu) => $menu->options->contains('id', $lunchOptionId));
    }

    private function closedSummary(Lunch $lunch, ?LunchResponse $mine): string
    {
        if ($mine === null) {
            return __('Ordering closed.').' '.__('No order was placed.');
        }
        if ($this->isNotAttending($mine)) {
            return __('Ordering closed.').' '.__('You marked yourself not attending.');
        }
        $menu = $mine->lunch_option_id ? $this->menuOf($lunch, $mine->lunch_option_id) : null;
        $name = $mine->lunch_option_id ? $this->answerLabel($lunch, $mine->lunch_option_id) : $mine->mealOption?->name;

        return __('Ordering closed.').' '.__('You ordered:').' '.($name ?? __('your pick'))
            .($menu ? ' ('.$menu->label().')' : '').'.';
    }
}
