<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\User;
use RuntimeException;

class LunchVotingService
{
    /**
     * Record (or update) the user's response for a lunch.
     * $mealOptionId === null means "not attending".
     *
     * @throws RuntimeException when the lunch is not open, or the option is not an enabled option on it.
     */
    public function respond(Lunch $lunch, User $user, ?int $mealOptionId): LunchResponse
    {
        if (! $lunch->isOpenForVoting()) {
            throw new RuntimeException('This lunch is not open for ordering.');
        }

        if ($mealOptionId !== null
            && ! $lunch->options()->where('meal_option_id', $mealOptionId)->where('enabled', true)->exists()) {
            throw new RuntimeException('That option is not available for this lunch.');
        }

        return LunchResponse::updateOrCreate(
            ['lunch_id' => $lunch->id, 'user_id' => $user->id],
            ['meal_option_id' => $mealOptionId],
        );
    }
}
