<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\User;
use RuntimeException;

class LunchVotingService
{
    /**
     * Record (or update) the user's single pick for the day.
     * $lunchOptionId === null means "not attending".
     *
     * @throws RuntimeException when the lunch is not open, or the option is not an enabled option on it.
     */
    public function respond(Lunch $lunch, User $user, ?int $lunchOptionId): LunchResponse
    {
        if (! $lunch->isOpenForVoting()) {
            throw new RuntimeException('This lunch is not open for ordering.');
        }

        $option = null;

        if ($lunchOptionId !== null) {
            $option = $lunch->options()->where('id', $lunchOptionId)->where('enabled', true)->first();

            if (! $option) {
                throw new RuntimeException('That option is not available for this lunch.');
            }
        }

        return LunchResponse::updateOrCreate(
            ['lunch_id' => $lunch->id, 'user_id' => $user->id],
            ['lunch_option_id' => $option?->id, 'meal_option_id' => $option?->meal_option_id],
        );
    }

    /**
     * Map a legacy meal_option_id to the lunch option for it, when exactly one menu offers it.
     *
     * @throws RuntimeException when zero or several enabled options match.
     */
    public function resolveLegacyMealOption(Lunch $lunch, int $mealOptionId): int
    {
        $ids = $lunch->options()
            ->where('meal_option_id', $mealOptionId)
            ->where('enabled', true)
            ->pluck('id');

        if ($ids->count() !== 1) {
            throw new RuntimeException('Please update the app to order for this day.');
        }

        return (int) $ids->first();
    }
}
