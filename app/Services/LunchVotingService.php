<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\User;
use App\Notifications\LunchPickSetByAdmin;
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

        $option = $this->enabledOption($lunch, $lunchOptionId);

        return LunchResponse::updateOrCreate(
            ['lunch_id' => $lunch->id, 'user_id' => $user->id],
            ['lunch_option_id' => $option?->id, 'meal_option_id' => $option?->meal_option_id],
        );
    }

    /**
     * An admin sets a member's pick on their behalf. Allowed any time while the lunch is published
     * (including after the deadline). $lunchOptionId === null means "not attending".
     * The member is told about it.
     *
     * @return bool true if the pick was saved, false if it was already that pick.
     *
     * @throws RuntimeException when the lunch is not published, or the option is not an enabled option on it.
     */
    public function setPickAsAdmin(Lunch $lunch, User $member, ?int $lunchOptionId): bool
    {
        if ($lunch->status !== 'published') {
            throw new RuntimeException('This lunch is not published.');
        }

        $option = $this->enabledOption($lunch, $lunchOptionId);

        $existing = LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $member->id])->first();

        if ($existing && $existing->lunch_option_id === $option?->id) {
            return false;
        }

        LunchResponse::updateOrCreate(
            ['lunch_id' => $lunch->id, 'user_id' => $member->id],
            ['lunch_option_id' => $option?->id, 'meal_option_id' => $option?->meal_option_id],
        );

        app(MemberNotifier::class)->sendAfterResponse($member->newCollection([$member]), new LunchPickSetByAdmin($lunch, $option));

        return true;
    }

    /** @throws RuntimeException when an id is given but is not an enabled option on this lunch. */
    private function enabledOption(Lunch $lunch, ?int $lunchOptionId): ?LunchOption
    {
        if ($lunchOptionId === null) {
            return null;
        }

        $option = $lunch->options()->where('id', $lunchOptionId)->where('enabled', true)->first();

        if (! $option) {
            throw new RuntimeException('That option is not available for this lunch.');
        }

        return $option;
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
